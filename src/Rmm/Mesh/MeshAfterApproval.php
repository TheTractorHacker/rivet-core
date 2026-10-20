<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Mesh;

use RivetCore\Rmm\Contracts\RmmAuditInterface;
use RivetCore\Rmm\Technician\ActionResult;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Support\Sql;

/**
 * "Once the device is approved, install the Mesh agent" (1.0.0-rc.27). The installer tick-box no longer puts the Mesh address, the device group id or the pin into the installer or the
 * command line (the group id is an invitation secret). It is remembered on the enrollment token (`endpoint_agent_installer_options.install_mesh_agent = 2`); when a device enrolls with
 * such a token {@see plan()} records one row, and the SERVER pushes the sealed `mesh_install` job ({@see \RivetCore\Rmm\Remote\RemoteActions::installMeshAgentAfterApproval()}) as soon as the
 * device is linked (automatic match, approved by an administrator, or its first check-in once linked) AND has announced `job:mesh_install`.
 *
 * Rules: one job per device (idempotent), only for devices whose token carried the option, nothing while the device is pending, a terminal "skipped" with the reason when the remote
 * tools are off or the Mesh install settings are incomplete, a few minutes of retry while the agent has not announced the capability and then "failed". Tokens issued before rc.27 keep
 * value 1 and the old install-time behaviour; this class never touches them.
 *
 * @api
 */
final class MeshAfterApproval
{
    public const OPTION_AFTER_APPROVAL = 2;
    /** How long to wait for the agent to announce `job:mesh_install` before giving up. */
    public const GIVE_UP_AFTER_S = 86400;
    public const MAX_REASON = 240;

    /**
     * @param \Closure(): \RivetCore\Rmm\Remote\RemoteActions $remote lazily built (the remote actions are not needed on most requests)
     */
    public function __construct(
        private readonly Sql $sql,
        private readonly RmmSettings $settings,
        private readonly MeshAgentService $meshAgents,
        private readonly RmmAuditInterface $audit,
        private readonly \Closure $remote,
    ) {
    }

    /** Whether the token was issued with the after-approval option (a pre-rc.27 token answers false). */
    public function tokenAsked(int $tokenId): bool
    {
        try {
            return (int) $this->sql->val('SELECT install_mesh_agent FROM endpoint_agent_installer_options WHERE token_id = ?', [$tokenId]) === self::OPTION_AFTER_APPROVAL;
        } catch (\Throwable) {
            return false;   // migration 0023 not applied: nothing was asked for
        }
    }

    /**
     * A device has just enrolled with a token: remember the plan when the token asked for it, and try right away (an automatically matched device is already linked).
     *
     * @param array<string,mixed> $dev the device row
     */
    public function plan(array $dev, int $tokenId): void
    {
        if (!$this->tokenAsked($tokenId)) {
            return;
        }
        $deviceId = (int) $dev['device_id'];
        $now = $this->sql->utcNow();
        $new = $this->sql->run('INSERT IGNORE INTO rmm_mesh_after_approval (device_id, token_id, state, reason, planned_at, updated_at) VALUES (?, ?, \'waiting\', ?, ?, ?)',
            [$deviceId, $tokenId, 'waiting for approval', $now, $now]);
        if ($new === 0) {
            // A reinstall with a new token that asks again: a finished-without-install plan starts over; a queued one is left alone.
            $this->sql->run("UPDATE rmm_mesh_after_approval SET token_id = ?, state = 'waiting', reason = ?, job_id = NULL, attempts = 0, planned_at = ?, updated_at = ? WHERE device_id = ? AND state IN ('skipped','failed')",
                [$tokenId, 'waiting for approval', $now, $now, $deviceId]);
        }
        $this->advance($dev);
    }

    /**
     * Take the next step for one device. Cheap when there is no plan (one primary-key read). Never throws: a check-in or an approval must not fail over this.
     *
     * @param array<string,mixed> $dev the device row (as it is NOW)
     */
    public function advance(array $dev): void
    {
        try {
            $this->step($dev);
        } catch (\Throwable) {
            // best effort: the next check-in tries again
        }
    }

    /** @param array<string,mixed> $dev */
    private function step(array $dev): void
    {
        $deviceId = (int) $dev['device_id'];
        $row = $this->sql->one('SELECT * FROM rmm_mesh_after_approval WHERE device_id = ?', [$deviceId]);
        if ($row === null || (string) $row['state'] !== 'waiting') {
            return;
        }
        if ($dev['revoked_at'] !== null || $dev['retired_at'] !== null || (string) $dev['link_state'] === 'rejected') {
            $this->finish($row, 'skipped', 'the device was rejected, revoked or retired');

            return;
        }
        if ((string) $dev['link_state'] !== 'linked' || empty($dev['asset_id'])) {
            return;   // still waiting for approval
        }
        if (!$this->settings->featureOn(\RivetCore\Rmm\Remote\RemoteProtocol::FEATURE_REMOTE_TOOLS)) {
            $this->finish($row, 'skipped', 'skipped: remote tools are off', $dev);

            return;
        }
        if (($why = $this->meshAgents->installRefusal()) !== null) {
            $this->finish($row, 'skipped', 'skipped: ' . $why, $dev);

            return;
        }
        $attempts = (int) $row['attempts'];
        if ($attempts >= 2 && (string) $row['updated_at'] > $this->sql->utcAt(-min(300, 60 * ($attempts - 1)))) {
            return;   // the first two tries (the enrollment and the first check-in) are free, then a backoff of 1, 2, 3, 4 and at most 5 minutes
        }
        $r = ($this->remote)()->installMeshAgentAfterApproval($dev);
        $this->apply($row, $dev, $r);
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $dev
     */
    private function apply(array $row, array $dev, ActionResult $r): void
    {
        $deviceId = (int) $row['device_id'];
        $now = $this->sql->utcNow();
        if ($r->ok && $r->code === 'queued') {
            $this->sql->run("UPDATE rmm_mesh_after_approval SET state = 'queued', reason = '', job_id = ?, updated_at = ? WHERE device_id = ? AND state = 'waiting'", [(string) ($r->data['job_id'] ?? ''), $now, $deviceId]);
            $this->audit->record('Mesh Install After Approval', "Mesh agent install queued after approval for device $deviceId (installer option)", (int) $dev['client_id'], (int) ($dev['asset_id'] ?? 0));

            return;
        }
        if ($r->ok && $r->code === 'already_queued') {
            $this->sql->run("UPDATE rmm_mesh_after_approval SET state = 'queued', reason = '', job_id = ?, updated_at = ? WHERE device_id = ? AND state = 'waiting'", [(string) ($r->data['job_id'] ?? ''), $now, $deviceId]);

            return;
        }
        if ($r->ok) {   // already_installed
            $this->finish($row, 'skipped', 'the Mesh agent was already installed', $dev);

            return;
        }
        if ($r->code === 'unsupported') {
            if ((string) $row['planned_at'] <= $this->sql->utcAt(-self::GIVE_UP_AFTER_S)) {
                $this->finish($row, 'failed', 'failed: the agent never announced that it can install the Mesh agent. Update the agent.', $dev);

                return;
            }
            $this->sql->run("UPDATE rmm_mesh_after_approval SET attempts = attempts + 1, reason = ?, updated_at = ? WHERE device_id = ? AND state = 'waiting'", ['waiting for the agent to announce Mesh install support', $now, $deviceId]);

            return;
        }
        if ($r->code === 'feature_disabled' || $r->code === 'not_configured') {
            $this->finish($row, 'skipped', 'skipped: ' . ($r->code === 'feature_disabled' ? 'remote tools are off' : $r->message), $dev);

            return;
        }
        $this->finish($row, 'failed', 'failed: ' . $r->message, $dev);
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed>|null $dev
     */
    private function finish(array $row, string $state, string $reason, ?array $dev = null): void
    {
        $reason = mb_substr($reason, 0, self::MAX_REASON);
        $n = $this->sql->run("UPDATE rmm_mesh_after_approval SET state = ?, reason = ?, updated_at = ? WHERE device_id = ? AND state = 'waiting'", [$state, $reason, $this->sql->utcNow(), (int) $row['device_id']]);
        if ($n > 0 && $dev !== null) {
            $this->audit->record('Mesh Install After Approval', "Mesh agent install after approval not queued for device {$row['device_id']}: $reason", (int) $dev['client_id'], (int) ($dev['asset_id'] ?? 0));
        }
    }

    /**
     * The state line for the device page, or null when the device's token never asked for the install.
     *
     * @return array{state:string,label:string}|null state is waiting_approval | waiting | queued | installing | installed | failed | skipped
     */
    public function view(int $deviceId): ?array
    {
        $row = $this->sql->one('SELECT r.*, d.link_state FROM rmm_mesh_after_approval r JOIN endpoint_agent_devices d ON d.device_id = r.device_id WHERE r.device_id = ?', [$deviceId]);
        if ($row === null) {
            return null;
        }
        if ($this->meshAgents->stateFor($deviceId)['installed'] ?? false) {
            return ['state' => 'installed', 'label' => 'Mesh agent: installed'];
        }
        $state = (string) $row['state'];
        if ($state === 'waiting') {
            return (string) $row['link_state'] !== 'linked'
                ? ['state' => 'waiting_approval', 'label' => 'Mesh agent: waiting for approval']
                : ['state' => 'waiting', 'label' => 'Mesh agent: ' . ((string) $row['reason'] !== '' ? $row['reason'] : 'queuing')];
        }
        if ($state === 'queued') {
            $job = $this->sql->one('SELECT state, reason FROM endpoint_agent_jobs WHERE job_id = ?', [(string) $row['job_id']]);
            $js = $job === null ? 'failed' : (string) $job['state'];
            if ($js === 'queued') {
                return ['state' => 'queued', 'label' => 'Mesh agent: queued'];
            }
            if ($js === 'running' || $js === 'succeeded') {
                return ['state' => 'installing', 'label' => 'Mesh agent: installing'];
            }

            return ['state' => 'failed', 'label' => 'Mesh agent: failed: ' . ($job === null ? 'the job is gone' : ((string) $job['reason'] !== '' ? str_replace('_', ' ', (string) $job['reason']) : $js))];
        }

        return ['state' => $state, 'label' => 'Mesh agent: ' . $row['reason']];
    }
}
