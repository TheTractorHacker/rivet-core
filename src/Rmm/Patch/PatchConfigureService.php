<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Patch;

use RivetCore\Rmm\Contracts\RmmAuditInterface;
use RivetCore\Rmm\Device\DeviceRepository;
use RivetCore\Rmm\Device\DeviceState;
use RivetCore\Rmm\Job\JobService;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Support\Sql;

/**
 * Windows Update control (1.0.0-rc.65): keep the Windows Update POLICY of managed Windows devices where the patch policy says, and report what is there.
 *
 *  - The policy keys are `windows_update_mode`, `windows_feature_target` and `windows_quality_defer_days` ({@see PatchPolicySettings}). All three default to "touch nothing".
 *  - The device reports the policy state in every scan (`windows_update`), {@see PatchScanService::storeWindowsState()} keeps the last report per device in ONE durable ledger row
 *    (rmm_patch_jobs, type `wu_state`, no migration; its `ids_json` is the state, `created_at` the time it was reported, `applied_at` the last time the reconciler looked).
 *  - The reconciler ({@see reconcileDue()}, called by {@see PatchScheduler}) queues a `patch_configure` job only for a Windows device whose effective policy asks for something
 *    (or that still carries values Core wrote earlier) and whose REPORTED state differs. Never for a device marked `device_automation_disabled` (managed by another RMM), a device
 *    whose agent did not announce `patch:configure`, a device something else manages (WSUS, a group policy, MDM: it is reported, never fought), or a device that has no report yet.
 *  - Until the `patch_live_install` limit is 1 the job is a DRY RUN: the agent reads the state and reports what it WOULD change. At most one per device and day.
 *  - A live job is destructive (offered once, never re-sent after a lost acknowledgement), at most one waiting per device, capped like installs (`patch_max_installs` for the
 *    fleet, `patch_max_installs_per_client`), and does not repeat for the same request after an `externally_managed`, `cannot_hold` or failed answer for a day.
 *  - Every queued job and every result is audited with the policy and the user (or "the scheduler").
 *
 * @api
 */
final class PatchConfigureService
{
    /** A live job for one device is not queued more often than this (the idempotency key buckets by it). */
    public const RETRY_S = 3600;
    /** A dry run, and a repeat of a request that was answered externally_managed / cannot_hold / failed, wait this long. */
    public const QUIET_S = 86400;
    /** The reconciler looks at a device at most this often. */
    public const LOOK_S = 600;

    public function __construct(
        private readonly Sql $sql,
        private readonly RmmSettings $settings,
        private readonly JobService $jobs,
        private readonly PatchScanService $scans,
        private readonly PatchPolicyStore $policies,
        private readonly DeviceRepository $devices,
        private readonly RmmAuditInterface $audit,
        private readonly ?PatchComplianceService $compliance = null,
    ) {
    }

    // ------------------------------------------------------------------ the reported state

    /**
     * The last state a device reported, with the time.
     *
     * @return array{state:array<string,mixed>,at:string}|null
     */
    public function state(int $deviceId): ?array
    {
        $r = $this->sql->one('SELECT ids_json, created_at FROM rmm_patch_jobs WHERE job_id = ?', [PatchProtocol::wuStateRowId($deviceId)]);
        if ($r === null) {
            return null;
        }
        $s = WindowsUpdatePlan::cleanState(json_decode((string) $r['ids_json'], true));

        return $s === null ? null : ['state' => $s, 'at' => (string) $r['created_at']];
    }

    /**
     * Whether the device's agent announced the capability.
     *
     * @param array<string,mixed> $dev
     */
    public function capable(array $dev): bool
    {
        return DeviceState::announces((new DeviceState($this->sql))->get((int) $dev['device_id']), PatchProtocol::CAP_CONFIGURE);
    }

    // ------------------------------------------------------------------ queueing

    /**
     * Queue a `patch_configure` job that brings a device to the state its effective policy asks for. The parameters are the DESIRED state taken from the policy, never a value
     * a caller typed.
     *
     * @param array<string,mixed> $dev
     * @param array{source?:string,user_id?:?int,idem?:bool} $opts
     * @return array{ok:bool,job_id?:string,skipped?:string,status?:string,error?:string,code?:string,changes?:list<string>}
     */
    public function queue(array $dev, string $mode, array $opts = []): array
    {
        if (!in_array($mode, PatchProtocol::CONFIGURE_MODES, true)) {
            return ['ok' => false, 'error' => 'mode must be dry_run or apply.', 'code' => 'invalid'];
        }
        if (($dev['os'] ?? '') !== 'windows') {
            return ['ok' => false, 'error' => 'Windows Update control is for Windows devices.', 'code' => 'unsupported'];
        }
        if ((int) ($dev['device_automation_disabled'] ?? 0) === 1) {
            return ['ok' => true, 'skipped' => 'automation_disabled'];   // another RMM manages this device: Core stays out of it
        }
        $deviceId = (int) $dev['device_id'];
        $eff = $this->policies->effectiveFor($dev);
        $st = $this->state($deviceId);
        $plan = WindowsUpdatePlan::evaluate($eff['windows'], $st['state'] ?? null, $this->capable($dev));
        if ($plan['status'] === 'unsupported_agent') {
            return ['ok' => false, 'error' => 'This device\'s agent does not support Windows Update control yet. Update the agent first.', 'code' => 'unsupported'];
        }
        if (!$plan['needs']) {
            return ['ok' => true, 'skipped' => $plan['status'], 'status' => $plan['status']];
        }
        if ($this->scans->pendingJob($deviceId, PatchProtocol::LEDGER_CONFIGURE) !== null) {
            return ['ok' => true, 'skipped' => 'already_queued'];   // one at a time per device
        }
        $w = $eff['windows'];
        $params = ['mode' => $mode, 'update_mode' => $w['update_mode'], 'feature_target' => $w['feature_target'], 'defer_days' => $w['quality_defer_days']];
        $source = $opts['source'] ?? 'manual';
        $now = $this->sql->time();
        $idem = null;
        if (($opts['idem'] ?? false) === true) {
            $idem = hash('sha256', "patchconfigure:$deviceId:" . json_encode($params) . ':' . intdiv($now, $mode === 'dry_run' ? self::QUIET_S : self::RETRY_S));
        }
        $policyId = (int) ($eff['sources']['windows_update_mode']['policy_id'] ?? $eff['sources']['windows_feature_target']['policy_id'] ?? $eff['sources']['windows_quality_defer_days']['policy_id'] ?? 0);
        $jobId = JobService::uuid();
        $userId = $opts['user_id'] ?? null;
        $ledger = $this->sql->run('INSERT IGNORE INTO rmm_patch_jobs (job_id, device_id, client_id, type, mode, ids_json, reboot_policy, source, idem_key, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$jobId, $deviceId, (int) $dev['client_id'], PatchProtocol::LEDGER_CONFIGURE, $mode === 'apply' ? 'install' : 'dry_run', (string) json_encode(['params' => $params, 'policy_id' => $policyId, 'plan' => $plan['changes']]), 'never', $source, $idem, $userId, $this->sql->utcNow()]);
        if ($ledger === 0) {
            return ['ok' => true, 'skipped' => 'duplicate'];
        }
        $timeoutCap = min(600, (int) $this->settings->get()['job_max_timeout_s']);
        $r = $this->jobs->create($dev, PatchProtocol::JOB_CONFIGURE, null, $params, $timeoutCap, true, (int) ($userId ?? 0), ['job_id' => $jobId]);
        if (!$r['ok']) {
            $this->sql->run('DELETE FROM rmm_patch_jobs WHERE job_id = ?', [$jobId]);

            return ['ok' => false, 'error' => (string) ($r['error'] ?? 'The job could not be queued.'), 'code' => (string) ($r['code'] ?? 'invalid')];
        }
        $this->audit->record($mode === 'apply' ? 'Patch Configure Queued' : 'Patch Configure Dry Run Queued',
            ($userId === null ? 'The scheduler' : "User $userId") . ' queued a Windows Update policy ' . ($mode === 'apply' ? 'CHANGE' : 'dry run') . " on device $deviceId (job $jobId) for patch policy #$policyId: update mode {$w['update_mode']}, feature target {$w['feature_target']}, quality deferral {$w['quality_defer_days']} days; due: " . implode(', ', $plan['changes']),
            (int) $dev['client_id'], (int) ($dev['asset_id'] ?? 0));

        return ['ok' => true, 'job_id' => $jobId, 'changes' => $plan['changes']];
    }

    /**
     * The reconciler: look at devices that reported a Windows Update state and queue what their policy needs.
     *
     * @param array<string,int> $limits
     * @return array{live:int,dry:int,skipped:int}
     */
    public function reconcileDue(array $limits, int $batch): array
    {
        $out = ['live' => 0, 'dry' => 0, 'skipped' => 0];
        $live = (int) $limits['patch_live_install'] === 1;
        $cfg = $this->settings->get();
        $perClient = [];
        $total = 0;
        foreach ($this->sql->all("SELECT j.client_id, COUNT(*) AS c FROM rmm_patch_jobs p JOIN endpoint_agent_jobs j ON j.job_id = p.job_id
            WHERE p.type = 'configure' AND p.mode = 'install' AND j.state IN ('queued','running') GROUP BY j.client_id") as $r) {
            $perClient[(int) $r['client_id']] = (int) $r['c'];
            $total += (int) $r['c'];
        }
        $rows = $this->sql->all("SELECT w.device_id FROM rmm_patch_jobs w
            JOIN endpoint_agent_devices d ON d.device_id = w.device_id
            JOIN rmm_device_state st ON st.device_id = d.device_id AND st.capabilities_json LIKE '%\"patch:configure\"%'
            WHERE w.type = 'wu_state' AND w.applied_at <= ? AND d.os = 'windows' AND d.link_state = 'linked' AND d.revoked_at IS NULL AND d.retired_at IS NULL
              AND d.device_automation_disabled = 0 AND d.last_checkin_at > ?
            ORDER BY w.applied_at, w.device_id LIMIT " . max(1, $batch), [$this->sql->utcAt(-self::LOOK_S), $this->sql->utcAt(-(int) $cfg['offline_after_s'])]);
        foreach ($rows as $r) {
            $deviceId = (int) $r['device_id'];
            $this->sql->run('UPDATE rmm_patch_jobs SET applied_at = ? WHERE job_id = ?', [$this->sql->utcNow(), PatchProtocol::wuStateRowId($deviceId)]);
            $dev = $this->devices->find($deviceId);
            if ($dev === null) {
                continue;
            }
            $client = (int) $dev['client_id'];
            if ($live && ($total >= (int) $limits['patch_max_installs'] || ($perClient[$client] ?? 0) >= (int) $limits['patch_max_installs_per_client'])) {
                ++$out['skipped'];
                continue;
            }
            $mode = $live ? 'apply' : 'dry_run';
            $eff = $this->policies->effectiveFor($dev);
            $st = $this->state($deviceId);
            $plan = WindowsUpdatePlan::evaluate($eff['windows'], $st['state'] ?? null, true);
            if (!$plan['needs']) {
                continue;
            }
            if ($this->recentlyRefused($deviceId, $eff['windows'], $mode)) {
                ++$out['skipped'];
                continue;
            }
            $q = $this->queue($dev, $mode, ['source' => 'scheduler', 'user_id' => null, 'idem' => true]);
            if ($q['ok'] && isset($q['job_id'])) {
                if ($live) {
                    ++$out['live'];
                    ++$total;
                    $perClient[$client] = ($perClient[$client] ?? 0) + 1;
                } else {
                    ++$out['dry'];
                }
            }
        }

        return $out;
    }

    /**
     * Whether the same request was already answered `externally_managed`, `cannot_hold` or with a failure within a day, or (dry run) a dry run was queued within a day.
     *
     * @param array{update_mode:string,feature_target:string,quality_defer_days:int,active:bool} $w
     */
    private function recentlyRefused(int $deviceId, array $w, string $mode): bool
    {
        $params = ['mode' => $mode, 'update_mode' => $w['update_mode'], 'feature_target' => $w['feature_target'], 'defer_days' => $w['quality_defer_days']];
        foreach ($this->sql->all("SELECT mode, ids_json, created_at FROM rmm_patch_jobs WHERE device_id = ? AND type = 'configure' AND created_at > ? ORDER BY created_at DESC LIMIT 10", [$deviceId, $this->sql->utcAt(-self::QUIET_S)]) as $row) {
            $j = json_decode((string) $row['ids_json'], true);
            if (!is_array($j) || ($j['params'] ?? null) !== $params) {
                continue;
            }
            $outcome = (string) ($j['result']['outcome'] ?? '');
            if ($mode === 'dry_run' || in_array($outcome, ['externally_managed', 'cannot_hold', 'failed'], true)) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------ results

    /**
     * Apply the result of a finished `patch_configure` job: record it on the ledger row, keep the state it reports, audit it and ask for a scan to prove a change.
     * Idempotent (the ledger row is claimed first).
     *
     * @param array<string,mixed> $dev
     * @param array<string,mixed> $job the endpoint_agent_jobs row
     */
    public function applyJob(array $dev, array $job): bool
    {
        $led = $this->sql->one('SELECT * FROM rmm_patch_jobs WHERE job_id = ? AND type = ?', [$job['job_id'], PatchProtocol::LEDGER_CONFIGURE]);
        if ($led === null || $led['applied_at'] !== null) {
            return false;
        }
        if ($this->sql->run('UPDATE rmm_patch_jobs SET applied_at = ? WHERE job_id = ? AND applied_at IS NULL', [$this->sql->utcNow(), $job['job_id']]) !== 1) {
            return false;
        }
        $deviceId = (int) $dev['device_id'];
        $meta = json_decode((string) $led['ids_json'], true);
        $meta = is_array($meta) ? $meta : [];
        $parsed = (string) $job['state'] === 'succeeded' ? WindowsUpdatePlan::cleanResult((string) ($job['output'] ?? '')) : null;
        if ($parsed === null) {
            $detail = $this->failureDetail($job);
            $meta['result'] = ['outcome' => 'failed', 'detail' => $detail];
            $this->sql->run('UPDATE rmm_patch_jobs SET ids_json = ? WHERE job_id = ?', [(string) json_encode($meta), $job['job_id']]);
            $this->audit->record('Patch Configure Failed', "Windows Update policy job {$job['job_id']} on device $deviceId did not finish: $detail", (int) $dev['client_id'], (int) ($dev['asset_id'] ?? 0));
            $this->compliance?->evaluate($dev);

            return true;
        }
        $meta['result'] = ['outcome' => $parsed['outcome'], 'mode' => $parsed['mode'], 'changes' => $parsed['changes'], 'detail' => $parsed['detail']];
        $this->sql->run('UPDATE rmm_patch_jobs SET ids_json = ? WHERE job_id = ?', [(string) json_encode($meta), $job['job_id']]);
        $state = $parsed['after'] ?? $parsed['before'];
        if ($state !== null) {
            $this->scans->storeWindowsState($dev, $state, false);
        }
        $by = $led['created_by'] === null ? 'the scheduler' : 'user ' . (int) $led['created_by'];
        $changes = implode(', ', array_map(static fn (array $c): string => $c['key'] . ' ' . json_encode($c['from']) . ' -> ' . json_encode($c['to']), $parsed['changes']));
        $title = match ($parsed['outcome']) {
            'changed', 'restored' => 'Patch Configure Applied',
            'would_change', 'would_restore' => 'Patch Configure Dry Run',
            'externally_managed' => 'Patch Configure Skipped',
            default => 'Patch Configure Result',
        };
        $this->audit->record($title, "Windows Update policy on device $deviceId (job {$job['job_id']}, queued by $by, patch policy #" . (int) ($meta['policy_id'] ?? 0) . '): ' . $parsed['outcome']
            . ($changes !== '' ? " ($changes)" : '') . ($state !== null && $state['external'] !== [] ? ' [managed by: ' . implode(', ', $state['external']) . ']' : '') . ($parsed['detail'] !== '' ? ' ' . $parsed['detail'] : ''),
            (int) $dev['client_id'], (int) ($dev['asset_id'] ?? 0));
        if (in_array($parsed['outcome'], ['changed', 'restored'], true)) {
            $this->scans->request($dev, null, 'after_configure');
        }
        $this->compliance?->evaluate($dev);

        return true;
    }

    /** @param array<string,mixed> $job */
    private function failureDetail(array $job): string
    {
        $out = json_decode((string) ($job['output'] ?? ''), true);
        if (is_array($out) && is_string($out['detail'] ?? null) && preg_match('/^[a-z][a-z0-9_]{0,39}$/D', $out['detail']) === 1) {
            return $out['detail'];
        }
        $why = (string) ($job['reason'] ?? '');

        return $why !== '' ? mb_substr($why, 0, 40) : 'job_' . (string) $job['state'];
    }

    /**
     * The last configure answer of a device, for the summary.
     *
     * @return array{outcome:string,mode:string,at:string,changes:list<array<string,mixed>>,detail:string}|null
     */
    public function lastResult(int $deviceId): ?array
    {
        foreach ($this->sql->all("SELECT ids_json, mode, created_at FROM rmm_patch_jobs WHERE device_id = ? AND type = 'configure' AND applied_at IS NOT NULL ORDER BY created_at DESC LIMIT 5", [$deviceId]) as $r) {
            $j = json_decode((string) $r['ids_json'], true);
            if (is_array($j) && is_array($j['result'] ?? null)) {
                return ['outcome' => (string) ($j['result']['outcome'] ?? ''), 'mode' => (string) ($j['result']['mode'] ?? ($r['mode'] === 'install' ? 'apply' : 'dry_run')), 'at' => (string) $r['created_at'],
                    'changes' => is_array($j['result']['changes'] ?? null) ? $j['result']['changes'] : [], 'detail' => (string) ($j['result']['detail'] ?? '')];
            }
        }

        return null;
    }
}
