<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Device;

use RivetCore\Rmm\Checks\CheckEvaluator;
use RivetCore\Rmm\Contracts\RmmAssetsInterface;
use RivetCore\Rmm\Contracts\RmmAuditInterface;
use RivetCore\Rmm\Contracts\RmmBridgeInterface;
use RivetCore\Rmm\Contracts\RmmTenancyInterface;
use RivetCore\Rmm\Enrollment\EnrollmentService;
use RivetCore\Rmm\Job\JobService;
use RivetCore\Rmm\Link\RmmLinker;
use RivetCore\Rmm\RmmProtocol;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Support\RmmCache;
use RivetCore\Rmm\Support\Sql;

/**
 * The administrator operations on a device: revoke, allow re-enrollment, rotate the credential, retire, transfer to another
 * client, change ring, and approve or reject a pending device (delegating to {@see EnrollmentService::resolvePending()}).
 *
 * @api
 */
final class DeviceService
{
    public function __construct(
        private readonly Sql $sql,
        private readonly DeviceRepository $devices,
        private readonly RmmSettings $settings,
        private readonly JobService $jobs,
        private readonly CheckEvaluator $checks,
        private readonly RmmLinker $linker,
        private readonly RmmBridgeInterface $bridge,
        private readonly RmmAssetsInterface $assets,
        private readonly RmmTenancyInterface $tenancy,
        private readonly RmmAuditInterface $audit,
        private readonly EnrollmentService $enrollment,
        private readonly ?RmmCache $cache = null,
    ) {
    }

    /**
     * Approve a pending device by linking it to an existing asset (it may cross clients: the device follows the asset).
     *
     * @return array{ok:bool,message:string}
     */
    public function approve(int $deviceId, int $assetId, int $userId): array
    {
        return $this->enrollment->resolvePending($deviceId, 'link', $assetId, $userId);
    }

    /**
     * Approve a pending device by creating an asset for it in the device's client.
     *
     * @return array{ok:bool,message:string}
     */
    public function approveWithNewAsset(int $deviceId, int $userId): array
    {
        return $this->enrollment->resolvePending($deviceId, 'create_asset', null, $userId);
    }

    /**
     * Reject a pending device: its credential is revoked and it stays out.
     *
     * @return array{ok:bool,message:string}
     */
    public function reject(int $deviceId, int $userId): array
    {
        return $this->enrollment->resolvePending($deviceId, 'reject', null, $userId);
    }

    public function revoke(int $deviceId, string $reason, int $userId): bool
    {
        $dev = $this->devices->find($deviceId);
        if ($dev === null || $dev['revoked_at'] !== null) {
            return false;
        }
        // The credential hash stays so the device is told "revoked" (not just "unknown") on its next call.
        $this->sql->run('UPDATE endpoint_agent_devices SET revoked_at = ?, revoked_reason = ? WHERE device_id = ?', [$this->sql->utcNow(), mb_substr($reason, 0, 100), $deviceId]);
        $this->jobs->cancelAllQueued($deviceId, 'device_revoked');
        $this->audit->record(($dev['kind'] ?? 'agent') === 'panel' ? 'Panel Device Revoked' : 'Device Revoked', "Device $deviceId revoked by user $userId: " . mb_substr($reason, 0, 100), (int) $dev['client_id'], (int) $dev['asset_id']);
        $this->cache?->invalidate(RmmCache::GROUP_FLEET, true);

        return true;
    }

    /** Clear a revocation so the device may enroll again with a fresh enrollment token. */
    public function allowReenroll(int $deviceId, int $userId): bool
    {
        $dev = $this->devices->find($deviceId);
        if ($dev === null || ($dev['revoked_at'] === null && $dev['retired_at'] === null)) {
            return false;
        }
        $this->sql->run("UPDATE endpoint_agent_devices SET revoked_at = NULL, revoked_reason = NULL, retired_at = NULL, link_state = IF(link_state = 'rejected', 'pending_approval', link_state), token_hash = '' WHERE device_id = ?", [$deviceId]);
        $this->audit->record('Device Re-enrollment Allowed', "Device $deviceId may re-enroll (user $userId)", (int) $dev['client_id'], (int) $dev['asset_id']);
        $this->cache?->invalidate(RmmCache::GROUP_FLEET, true);

        return true;
    }

    /** Invalidate the current credential. The agent gets 401 invalid_token and must re-enroll (a new enrollment token is needed). */
    public function rotate(int $deviceId, int $userId): bool
    {
        $dev = $this->devices->find($deviceId);
        if ($dev === null) {
            return false;
        }
        $this->sql->run("UPDATE endpoint_agent_devices SET token_hash = '', token_issued_at = NULL, token_expires_at = NULL WHERE device_id = ?", [$deviceId]);
        $this->audit->record('Device Credential Rotated', "Device $deviceId credential invalidated by user $userId; re-enrollment required", (int) $dev['client_id'], (int) $dev['asset_id']);

        return true;
    }

    /**
     * Retirement: the credential is revoked, queued jobs are cancelled, monitoring stops (the RMM link is removed and open alerts
     * resolved). The asset is kept. A remote-access agent installed separately (MeshCentral) is NOT touched.
     */
    public function retire(int $deviceId, int $userId): bool
    {
        $dev = $this->devices->find($deviceId);
        if ($dev === null || $dev['retired_at'] !== null) {
            return false;
        }
        $now = $this->sql->utcNow();
        $this->sql->run("UPDATE endpoint_agent_devices SET retired_at = ?, revoked_at = COALESCE(revoked_at, ?), revoked_reason = COALESCE(revoked_reason, 'retired') WHERE device_id = ?", [$now, $now, $deviceId]);
        $this->jobs->cancelAllQueued($deviceId, 'device_retired');
        $this->checks->resolveAllOpen($deviceId, 'device retired');
        $this->linker->drop($deviceId);
        $this->audit->record('Device Retired', "Device $deviceId retired by user $userId", (int) $dev['client_id'], (int) $dev['asset_id']);
        $this->cache?->invalidate(RmmCache::GROUP_FLEET, true);

        return true;
    }

    /** Move a device (and its asset) to another client. Open alerts follow it. False when the device or the client does not exist. */
    public function transfer(int $deviceId, int $clientId, int $locationId, int $userId): bool
    {
        $dev = $this->devices->find($deviceId);
        if ($dev === null || $this->tenancy->clientName($clientId) === null) {
            return false;
        }
        if ($locationId > 0 && !$this->tenancy->locationInClient($locationId, $clientId)) {
            $locationId = 0;
        }
        $this->sql->transaction(function () use ($deviceId, $clientId, $locationId, $dev): void {
            $this->sql->run('UPDATE endpoint_agent_devices SET client_id = ?, location_id = ? WHERE device_id = ?', [$clientId, $locationId, $deviceId]);
            $this->sql->run('UPDATE endpoint_agent_jobs SET client_id = ? WHERE device_id = ?', [$clientId, $deviceId]);
            if (!empty($dev['asset_id'])) {
                $this->assets->moveToClient((int) $dev['asset_id'], $clientId, $locationId);
                $this->bridge->reassignAlerts($this->settings->integrationId(), (int) $dev['asset_id'], $clientId);
            }
        });
        $this->audit->record('Device Transferred', "Device $deviceId moved from department {$dev['client_id']} to $clientId by user $userId", $clientId, (int) $dev['asset_id']);
        $this->cache?->invalidate(RmmCache::GROUP_FLEET, true);

        return true;
    }

    /** False for an unknown ring or a device that does not exist. */
    public function setRing(int $deviceId, string $ring): bool
    {
        if (!in_array($ring, RmmProtocol::RINGS, true) || $this->devices->find($deviceId) === null) {
            return false;
        }
        $this->sql->run('UPDATE endpoint_agent_devices SET ring = ? WHERE device_id = ?', [$ring, $deviceId]);

        return true;
    }

    /**
     * device_automation_disabled (1.0.0-rc.31): for a device another tool already manages (a second RMM/patch
     * agent), so RivetCore's own automation never fires on it: scheduled and policy scripts and patch installs
     * ({@see \RivetCore\Rmm\Scripts\TargetResolver}, {@see \RivetCore\Rmm\Patch\PatchScheduler::queueInstalls()}), and since 1.0.0-rc.38 also the software
     * deployment scheduler (detection, install and uninstall by assignment), the enrollment auto-install and the Mesh install after approval.
     * Monitoring, inventory, patch scanning and a technician's own deliberate "run now" on one device are unaffected.
     *
     * rc.38 (security review RC36 M3): turning the switch ON also cancels the jobs that automation already queued for the device
     * (see {@see cancelAutomatedQueued()}), and every flip, either way, writes an audit row naming the user. Setting the value it already has changes
     * nothing and writes nothing. False for a device that does not exist.
     *
     * @param int|null $userId who flipped it (for the audit row); null when unknown
     */
    public function setAutomationDisabled(int $deviceId, bool $disabled, ?int $userId = null): bool
    {
        $dev = $this->devices->find($deviceId);
        if ($dev === null) {
            return false;
        }
        $was = (int) ($dev['device_automation_disabled'] ?? 0) === 1;
        $this->sql->run('UPDATE endpoint_agent_devices SET device_automation_disabled = ? WHERE device_id = ?', [$disabled ? 1 : 0, $deviceId]);
        if ($was === $disabled) {
            return true;
        }
        $by = $userId === null ? 'an administrator' : "user $userId";
        if ($disabled) {
            $n = $this->cancelAutomatedQueued($deviceId);
            $this->audit->record('Device Automation Disabled', "Automation disabled for device $deviceId by $by: scheduled scripts, patch installs and software deployment no longer run on it" . ($n > 0 ? "; $n queued automated job(s) cancelled" : ''),
                (int) $dev['client_id'], (int) ($dev['asset_id'] ?? 0));
        } else {
            $this->audit->record('Device Automation Enabled', "Automation re-enabled for device $deviceId by $by", (int) $dev['client_id'], (int) ($dev['asset_id'] ?? 0));
        }

        return true;
    }

    /**
     * Cancel the jobs of this device that are still `queued` and were queued by automation: a scheduled script run (a schedule id on the job), a software install,
     * uninstall or detect from the deployment scheduler or the enrollment auto-install, a patch install from the patch scheduler. A job a technician queued on purpose
     * (single run, bulk run, manual software or patch action, an approved run) stays. A job already `running` is not touched. Returns how many were cancelled.
     */
    private function cancelAutomatedQueued(int $deviceId): int
    {
        $now = $this->sql->utcNow();
        $total = 0;
        foreach ([
            'SELECT job_id FROM rmm_job_extra WHERE device_id = ? AND schedule_id IS NOT NULL AND schedule_id > 0',
            "SELECT job_id FROM rmm_package_jobs WHERE device_id = ? AND source IN ('scheduler','autoinstall')",
            "SELECT job_id FROM rmm_patch_jobs WHERE device_id = ? AND source = 'scheduler'",
        ] as $select) {
            try {
                $total += $this->sql->run("UPDATE endpoint_agent_jobs SET state = 'cancelled', reason = 'automation_disabled', finished_at = ? WHERE device_id = ? AND state = 'queued' AND job_id IN ($select)",
                    [$now, $deviceId, $deviceId]);
            } catch (\Throwable) {
                // a module whose table does not exist on this install has queued nothing to cancel
            }
        }

        return $total;
    }
}
