<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Patch;

use RivetCore\Rmm\Alerting\MaintenanceService;
use RivetCore\Rmm\Contracts\ScheduleGateInterface;
use RivetCore\Rmm\Device\DeviceRepository;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Support\Sql;

/**
 * The patch work of the housekeeping pass: apply results that are waiting, ask devices for a scan when theirs is due, approve what policies and rules allow
 * by now, and turn approved updates into install jobs where a device's policy and window say so. Runs only while the `patching` switch is on (and, like every
 * scheduled job, only while the `jobs` switch is).
 *
 * DETECT FIRST, INSTALL GATED. A device is installed on only when ALL of these hold: its effective policy has `install_enabled`; the update is approved; its window
 * is open (a daily window, a Phase 3 maintenance window, or none); no "leave this device alone" window suppresses it (the schedule gate); it is online and announced
 * `patch:install`; no install is already waiting on it; fewer than `patch_max_installs` devices (and `patch_max_installs_per_client` per client) are installing;
 * and load shedding is at level 0 or 1. Until the `patch_live_install` limit is 1 the scheduler queues DRY RUNS only (at most one a day per device).
 *
 * CAPACITY. One pass works on at most `patch_batch` devices per step, halved at shed level 1; nothing is queued at level 2 and above. Devices are spread by a stable
 * hash of the device id (scan time within the interval, start inside the window).
 *
 * @api
 */
final class PatchScheduler
{
    public function __construct(
        private readonly Sql $sql,
        private readonly RmmSettings $settings,
        private readonly DeviceRepository $devices,
        private readonly PatchScanService $scans,
        private readonly PatchInstallService $installs,
        private readonly PatchApprovalService $approvals,
        private readonly PatchComplianceService $compliance,
        private readonly PatchPolicyStore $policies,
        private readonly ?MaintenanceService $maintenance = null,
        private readonly ?ScheduleGateInterface $gate = null,
    ) {
    }

    /** @return array<string,int> */
    public function run(): array
    {
        $out = ['patch_applied' => 0, 'patch_scans_queued' => 0, 'patch_evaluated' => 0, 'patch_approved' => 0, 'patch_installs_queued' => 0, 'patch_dry_runs_queued' => 0, 'patch_install_deferred' => 0, 'patch_pruned' => 0];
        if (!$this->settings->featureOn(PatchProtocol::FEATURE)) {
            return $out;
        }
        $limits = $this->settings->limits();
        $shed = max(0, min(3, (int) ($this->settings->get()['shed_level'] ?? 0)));
        $batch = max(10, intdiv($limits['patch_batch'], [1, 2, 4, 4][$shed]));
        if ($shed <= 1) {
            $out['patch_applied'] = $this->scans->applyPending($batch, $this->installs);
        }
        if ($shed <= 1 && $this->settings->featureOn('jobs')) {
            $out['patch_scans_queued'] = $this->queueDueScans($limits, $batch);
            [$out['patch_evaluated'], $out['patch_approved']] = $this->evaluateDue($batch);
            $r = $this->queueInstalls($limits, $batch);
            $out['patch_installs_queued'] = $r['live'];
            $out['patch_dry_runs_queued'] = $r['dry'];
            $out['patch_install_deferred'] = $r['deferred'];
        }
        $out['patch_pruned'] = $this->prune($limits);

        return $out;
    }

    // ------------------------------------------------------------------ scans

    /**
     * @param array<string,int> $limits
     */
    private function queueDueScans(array $limits, int $batch): int
    {
        $interval = (int) $limits['patch_scan_interval_s'];
        $cfg = $this->settings->get();
        $now = $this->sql->utcNow();
        $rows = $this->sql->all("SELECT d.* FROM endpoint_agent_devices d
            JOIN rmm_device_state st ON st.device_id = d.device_id AND st.capabilities_json LIKE '%\"patch:scan\"%'
            LEFT JOIN rmm_patch_scans s ON s.device_id = d.device_id
            WHERE d.link_state = 'linked' AND d.revoked_at IS NULL AND d.retired_at IS NULL AND d.last_checkin_at > ?
              AND (s.device_id IS NULL OR ((s.scanned_at IS NULL OR s.scanned_at <= DATE_SUB(?, INTERVAL (? + (CRC32(d.device_id) % GREATEST(1, ?))) SECOND))
                   AND (s.requested_at IS NULL OR s.requested_at <= DATE_SUB(?, INTERVAL GREATEST(3600, ?) SECOND))))
            ORDER BY s.scanned_at IS NOT NULL, s.scanned_at, d.device_id LIMIT " . max(1, $batch),
            [$this->sql->utcAt(-(int) $cfg['offline_after_s']), $now, $interval, intdiv($interval, 10), $now, intdiv($interval, 6)]);
        $n = 0;
        foreach ($rows as $dev) {
            $r = $this->scans->request($dev, null, 'scheduler');
            if ($r['ok'] && !isset($r['skipped'])) {
                ++$n;
            }
        }

        return $n;
    }

    // ------------------------------------------------------------------ approvals and alerts

    /** @return array{0:int,1:int} [devices evaluated, rows approved] */
    private function evaluateDue(int $batch): array
    {
        $rows = $this->sql->all('SELECT device_id FROM rmm_patch_scans WHERE scanned_at IS NOT NULL AND (evaluated_at IS NULL OR evaluated_at <= ?) ORDER BY evaluated_at, device_id LIMIT ' . max(1, $batch),
            [$this->sql->utcAt(-3600)]);
        $evaluated = 0;
        $approved = 0;
        foreach ($rows as $r) {
            $dev = $this->devices->find((int) $r['device_id']);
            if ($dev !== null && $dev['retired_at'] === null && $dev['revoked_at'] === null) {
                $approved += $this->approvals->autoApprove($dev);
                $this->compliance->evaluate($dev);
                ++$evaluated;
            }
            $this->sql->run('UPDATE rmm_patch_scans SET evaluated_at = ? WHERE device_id = ?', [$this->sql->utcNow(), $r['device_id']]);
        }

        return [$evaluated, $approved];
    }

    // ------------------------------------------------------------------ installs

    /**
     * @param array<string,int> $limits
     * @return array{live:int,dry:int,deferred:int}
     */
    private function queueInstalls(array $limits, int $batch): array
    {
        $out = ['live' => 0, 'dry' => 0, 'deferred' => 0];
        $shed = max(0, min(3, (int) ($this->settings->get()['shed_level'] ?? 0)));
        $cfg = $this->settings->get();
        $live = (int) $limits['patch_live_install'] === 1;
        $now = $this->sql->time();
        $perClient = [];
        $total = 0;
        foreach ($this->sql->all("SELECT j.client_id, COUNT(*) AS c FROM rmm_patch_jobs p JOIN endpoint_agent_jobs j ON j.job_id = p.job_id
            WHERE p.type = 'patch_install' AND p.mode = 'install' AND j.state IN ('queued','running') GROUP BY j.client_id") as $r) {
            $perClient[(int) $r['client_id']] = (int) $r['c'];
            $total += (int) $r['c'];
        }
        $rows = $this->sql->all("SELECT a.device_id FROM rmm_patch_available a JOIN rmm_device_state st ON st.device_id = a.device_id AND st.capabilities_json LIKE '%\"patch:install\"%'
            JOIN endpoint_agent_devices d ON d.device_id = a.device_id
            WHERE a.status IN ('approved','failed') AND (a.status = 'approved' OR (a.fail_count < ? AND (a.last_attempt_at IS NULL OR a.last_attempt_at <= ?)))
              AND d.link_state = 'linked' AND d.revoked_at IS NULL AND d.retired_at IS NULL AND d.last_checkin_at > ?
            GROUP BY a.device_id ORDER BY MIN(COALESCE(a.last_attempt_at, '1970-01-01')), a.device_id LIMIT " . max(1, $batch),
            [PatchInstallService::MAX_ATTEMPTS, $this->sql->utcAt(-PatchInstallService::RETRY_AFTER_S), $this->sql->utcAt(-(int) $cfg['offline_after_s'])]);
        foreach ($rows as $r) {
            $dev = $this->devices->find((int) $r['device_id']);
            if ($dev === null) {
                continue;
            }
            $client = (int) $dev['client_id'];
            $eff = $this->policies->effectiveFor($dev);
            if (!$eff['install_enabled'] || !$this->windowOpen($dev, $eff, $now)) {
                ++$out['deferred'];
                continue;
            }
            if ($live && ($total >= (int) $limits['patch_max_installs'] || ($perClient[$client] ?? 0) >= (int) $limits['patch_max_installs_per_client'])) {
                ++$out['deferred'];
                continue;
            }
            $mode = $live ? 'install' : 'dry_run';
            if (!$live && $this->sql->one("SELECT 1 AS x FROM rmm_patch_jobs WHERE device_id = ? AND type = 'patch_install' AND mode = 'dry_run' AND source = 'scheduler' AND created_at > ?", [$dev['device_id'], $this->sql->utcAt(-86400)]) !== null) {
                continue;   // one scheduler dry run a day is plenty
            }
            $q = $this->installs->queue($dev, null, $mode, ['source' => 'scheduler', 'user_id' => null, 'reboot_policy' => $eff['reboot_policy'], 'max_minutes' => $eff['max_minutes'], 'idem' => true]);
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
     * Whether the device's install window is open now (and not suppressed by someone else's "leave this device alone" window).
     *
     * @param array<string,mixed> $dev
     * @param array<string,mixed> $eff
     */
    private function windowOpen(array $dev, array $eff, int $now): bool
    {
        $w = $eff['window'];
        switch ($w['mode']) {
            case 'maintenance':
                $win = $w['maintenance_id'] === null || $this->maintenance === null ? null : $this->maintenance->find($w['maintenance_id']);

                // The administrator chose this window for these machines: it is open or it is not (the schedule gate is not asked, a "suppress" window is exactly the maintenance time).
                return $win !== null && $win['enabled'] && $win['active_now'];
            case 'daily':
                $start = PatchPolicyResolver::dailyWindowStart($w, $now);
                if ($start === null || !$this->spread((int) $dev['device_id'], $w['minutes'] * 60, $now - $start)) {
                    return false;
                }
                break;
        }

        return $this->gate === null || $this->gate->decide($dev, ['kind' => 'patch'], $now) === ScheduleGateInterface::RUN;
    }

    /** A device starts a little after its window opens, spread over at most ten minutes (and a quarter of the window), by a stable hash of its id. */
    private function spread(int $deviceId, int $windowSeconds, int $elapsed): bool
    {
        $span = max(0, min(600, intdiv(max(0, $windowSeconds), 4)));

        return $span === 0 || $elapsed < 0 || (crc32((string) $deviceId) % ($span + 1)) <= $elapsed;
    }

    // ------------------------------------------------------------------ pruning

    /**
     * @param array<string,int> $limits
     */
    private function prune(array $limits): int
    {
        $n = $this->sql->run('DELETE FROM rmm_patch_history WHERE at < ? LIMIT 5000', [$this->sql->utcAt(-(int) $limits['patch_history_days'] * 86400)]);
        $n += $this->sql->run("DELETE FROM rmm_patch_available WHERE status = 'installed' AND status_at < ? LIMIT 5000", [$this->sql->utcAt(-14 * 86400)]);
        $n += $this->sql->run('DELETE FROM rmm_patch_jobs WHERE applied_at IS NOT NULL AND created_at < ? LIMIT 5000', [$this->sql->utcAt(-30 * 86400)]);
        $n += $this->policies->dropOrphanAssignments();

        return $n;
    }
}
