<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Deploy;

use RivetCore\Rmm\Contracts\ScheduleGateInterface;
use RivetCore\Rmm\Device\DeviceRepository;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Support\Sql;

/**
 * The software work of the housekeeping pass: apply results that are waiting, work out what each device should have, ask devices what they have, and turn the
 * difference into install and removal jobs. Runs only while the `software_deploy` switch is on (and, like every scheduled job, only while the `jobs` switch is).
 *
 * DETECT FIRST, INSTALL GATED. A device is changed only when ALL of these hold: an enabled, activated assignment reaches it and its ring's delay has passed; the last
 * detection says the package is missing (or, for a removal, present); no "leave this device alone" window suppresses it (the schedule gate); it is online and announced
 * `software:install`; no install is already waiting on it; fewer than `software_max_installs` devices (and `software_max_installs_per_client` per client) are installing;
 * load shedding is at level 0 or 1; and a package that failed is not retried before {@see DeployProtocol::RETRY_AFTER_S} nor more than {@see DeployProtocol::MAX_ATTEMPTS}
 * times in a row. Until the `software_live_install` limit is 1 the scheduler queues DRY RUNS only (at most one a day per device and package).
 *
 * CAPACITY. One pass works on at most `software_batch` devices per step, halved at shed level 1; nothing is queued at level 2 and above. With no assignment at all the
 * pass costs one indexed lookup.
 *
 * @api
 */
final class DeployScheduler
{
    public function __construct(
        private readonly Sql $sql,
        private readonly RmmSettings $settings,
        private readonly DeviceRepository $devices,
        private readonly DeployService $service,
        private readonly DeployExpectations $expectations,
        private readonly ?ScheduleGateInterface $gate = null,
        private readonly ?\RivetCore\Rmm\AutoInstall\AutoInstallService $auto = null,
        private readonly ?HostedFiles $hosted = null,
    ) {
    }

    /** @return array<string,int> */
    public function run(): array
    {
        $out = ['deploy_applied' => 0, 'deploy_evaluated' => 0, 'deploy_detects_queued' => 0, 'deploy_installs_queued' => 0, 'deploy_dry_runs_queued' => 0, 'deploy_deferred' => 0, 'deploy_pruned' => 0,
            'autoinstall_queued' => 0, 'autoinstall_dry_runs' => 0, 'autoinstall_approvals' => 0, 'autoinstall_skipped' => 0, 'autoinstall_deferred' => 0];
        if (!$this->settings->featureOn(DeployProtocol::FEATURE)) {
            return $out;
        }
        $limits = $this->settings->limits();
        $shed = max(0, min(3, (int) ($this->settings->get()['shed_level'] ?? 0)));
        $batch = max(10, intdiv($limits['software_batch'], [1, 2, 4, 4][$shed]));
        if ($shed <= 1) {
            $out['deploy_applied'] = $this->service->applyPending($batch);
        }
        if ($shed <= 1 && $this->settings->featureOn('jobs') && $this->anythingToDo()) {
            $out['deploy_evaluated'] = $this->evaluateDue($batch);
            $out['deploy_detects_queued'] = $this->queueDetects($limits, $batch);
            $r = $this->queueInstalls($limits, $batch);
            $out['deploy_installs_queued'] = $r['live'];
            $out['deploy_dry_runs_queued'] = $r['dry'];
            $out['deploy_deferred'] = $r['deferred'];
        }
        if ($shed <= 1 && $this->auto !== null) {
            $a = $this->auto->advance($batch);
            $out['autoinstall_queued'] = $a['queued'];
            $out['autoinstall_dry_runs'] = $a['dry'];
            $out['autoinstall_approvals'] = $a['approvals'];
            $out['autoinstall_skipped'] = $a['skipped'];
            $out['autoinstall_deferred'] = $a['deferred'];
        }
        $out['deploy_pruned'] = $this->prune($limits);

        return $out;
    }

    private function anythingToDo(): bool
    {
        return $this->sql->one('SELECT assignment_id FROM rmm_package_assignments WHERE enabled = 1 AND activated_at IS NOT NULL LIMIT 1') !== null
            || $this->sql->one('SELECT device_id FROM rmm_package_installs WHERE expected_action IS NOT NULL LIMIT 1') !== null;
    }

    // ------------------------------------------------------------------ expectations

    private function evaluateDue(int $batch): int
    {
        $rows = $this->sql->all("SELECT d.* FROM endpoint_agent_devices d LEFT JOIN rmm_deploy_state s ON s.device_id = d.device_id
            WHERE d.link_state = 'linked' AND d.revoked_at IS NULL AND d.retired_at IS NULL AND (s.evaluated_at IS NULL OR s.evaluated_at <= ?)
            ORDER BY s.evaluated_at IS NOT NULL, s.evaluated_at, d.device_id LIMIT " . max(1, $batch), [$this->sql->utcAt(-3600)]);
        foreach ($rows as $dev) {
            $this->expectations->evaluate($dev);
        }

        return count($rows);
    }

    // ------------------------------------------------------------------ detection

    /**
     * @param array<string,int> $limits
     */
    private function queueDetects(array $limits, int $batch): int
    {
        $interval = (int) $limits['software_detect_interval_s'];
        $cfg = $this->settings->get();
        $now = $this->sql->utcNow();
        $rows = $this->sql->all("SELECT d.* FROM endpoint_agent_devices d
            JOIN rmm_device_state st ON st.device_id = d.device_id AND st.capabilities_json LIKE '%\"software:detect\"%'
            JOIN (SELECT DISTINCT device_id FROM rmm_package_installs WHERE expected_action IS NOT NULL) e ON e.device_id = d.device_id
            LEFT JOIN rmm_deploy_state s ON s.device_id = d.device_id
            WHERE d.link_state = 'linked' AND d.revoked_at IS NULL AND d.retired_at IS NULL AND d.last_checkin_at > ?
              AND (s.detected_at IS NULL OR s.detected_at <= DATE_SUB(?, INTERVAL (? + (CRC32(d.device_id) % GREATEST(1, ?))) SECOND))
              AND (s.detect_requested_at IS NULL OR s.detect_requested_at <= DATE_SUB(?, INTERVAL GREATEST(3600, ?) SECOND))
            ORDER BY s.detected_at IS NOT NULL, s.detected_at, d.device_id LIMIT " . max(1, $batch),
            [$this->sql->utcAt(-(int) $cfg['offline_after_s']), $now, $interval, intdiv($interval, 10), $now, intdiv($interval, 6)]);
        $n = 0;
        foreach ($rows as $dev) {
            $r = $this->service->queueDetect($dev, null, 'scheduler');
            if ($r['ok'] && !isset($r['skipped'])) {
                ++$n;
            }
        }

        return $n;
    }

    // ------------------------------------------------------------------ installs

    /**
     * @param array<string,int> $limits
     * @return array{live:int,dry:int,deferred:int}
     */
    private function queueInstalls(array $limits, int $batch): array
    {
        $out = ['live' => 0, 'dry' => 0, 'deferred' => 0];
        $cfg = $this->settings->get();
        $live = (int) $limits['software_live_install'] === 1;
        $now = $this->sql->time();
        $perClient = [];
        $total = 0;
        foreach ($this->sql->all("SELECT j.client_id, COUNT(*) AS c FROM rmm_package_jobs p JOIN endpoint_agent_jobs j ON j.job_id = p.job_id
            WHERE p.type = 'software_install' AND p.mode = 'run' AND j.state IN ('queued','running') GROUP BY j.client_id") as $r) {
            $perClient[(int) $r['client_id']] = (int) $r['c'];
            $total += (int) $r['c'];
        }
        // What is wrong: detected, expected, due, and not tried too often. One device may have several; it gets one job at a time.
        $rows = $this->sql->all("SELECT i.device_id, i.package_id, i.expected_action, i.expected_version_id, i.fail_count, i.last_attempt_at FROM rmm_package_installs i
            JOIN rmm_device_state st ON st.device_id = i.device_id AND st.capabilities_json LIKE '%\"software:install\"%'
            JOIN endpoint_agent_devices d ON d.device_id = i.device_id
            WHERE i.expected_action IS NOT NULL AND i.detected_at IS NOT NULL AND i.expected_since <= ?
              AND ((i.expected_action = 'install' AND COALESCE(i.satisfied, 0) = 0) OR (i.expected_action = 'uninstall' AND i.installed = 1))
              AND (i.fail_count = 0 OR (i.fail_count < ? AND (i.last_attempt_at IS NULL OR i.last_attempt_at <= ?)))
              AND d.link_state = 'linked' AND d.revoked_at IS NULL AND d.retired_at IS NULL AND d.last_checkin_at > ?
            ORDER BY COALESCE(i.last_attempt_at, '1970-01-01'), i.device_id, i.package_id LIMIT " . max(1, $batch),
            [$this->sql->utcNow(), DeployProtocol::MAX_ATTEMPTS, $this->sql->utcAt(-DeployProtocol::RETRY_AFTER_S), $this->sql->utcAt(-(int) $cfg['offline_after_s'])]);
        $doneDevices = [];
        foreach ($rows as $r) {
            $deviceId = (int) $r['device_id'];
            if (isset($doneDevices[$deviceId])) {
                continue;   // one job per device per pass
            }
            $dev = $this->devices->find($deviceId);
            $v = $dev === null || $r['expected_version_id'] === null ? null : $this->expectations->versionFor($dev, (int) $r['package_id'], (int) $r['expected_version_id'], (string) $r['expected_action']);
            if ($dev === null || $v === null) {
                continue;
            }
            $client = (int) $dev['client_id'];
            if ($this->gate !== null && $this->gate->decide($dev, ['kind' => 'software'], $now) !== ScheduleGateInterface::RUN) {
                ++$out['deferred'];
                continue;
            }
            if ($live && ($total >= (int) $limits['software_max_installs'] || ($perClient[$client] ?? 0) >= (int) $limits['software_max_installs_per_client'])) {
                ++$out['deferred'];
                continue;
            }
            $mode = $live ? 'run' : 'dry_run';
            if (!$live && $this->sql->one("SELECT 1 AS x FROM rmm_package_jobs WHERE device_id = ? AND package_id = ? AND type = 'software_install' AND mode = 'dry_run' AND source = 'scheduler' AND created_at > ?",
                [$deviceId, $r['package_id'], $this->sql->utcAt(-86400)]) !== null) {
                continue;   // one scheduler dry run a day is plenty
            }
            $q = $this->service->queueInstall($dev, $v, (string) $r['expected_action'], $mode, ['source' => 'scheduler', 'user_id' => null, 'idem' => true]);
            if ($q['ok'] && isset($q['job_id'])) {
                $doneDevices[$deviceId] = true;
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

    // ------------------------------------------------------------------ pruning

    /**
     * @param array<string,int> $limits
     */
    private function prune(array $limits): int
    {
        $n = $this->sql->run('DELETE FROM rmm_package_history WHERE at < ? LIMIT 5000', [$this->sql->utcAt(-(int) $limits['software_deploy_history_days'] * 86400)]);
        $n += $this->sql->run('DELETE FROM rmm_package_jobs WHERE applied_at IS NOT NULL AND created_at < ? LIMIT 5000', [$this->sql->utcAt(-30 * 86400)]);
        // Rows of devices that were deleted, and rows nothing expects any more and that have not been touched for a long time.
        $n += $this->sql->run('DELETE FROM rmm_package_installs WHERE expected_action IS NULL AND updated_at < ? LIMIT 5000', [$this->sql->utcAt(-90 * 86400)]);
        $n += $this->sql->run('DELETE s FROM rmm_deploy_state s WHERE NOT EXISTS (SELECT 1 FROM endpoint_agent_devices d WHERE d.device_id = s.device_id)');
        $n += $this->sql->run("DELETE a FROM rmm_package_assignments a WHERE a.scope_type = 'tag' AND NOT EXISTS (SELECT 1 FROM rmm_tags t WHERE t.tag_id = a.scope_id)");
        $n += $this->sql->run("DELETE a FROM rmm_package_assignments a WHERE a.scope_type = 'group' AND NOT EXISTS (SELECT 1 FROM rmm_groups g WHERE g.group_id = a.scope_id)");
        $n += $this->sql->run("DELETE a FROM rmm_package_assignments a WHERE a.scope_type = 'device' AND NOT EXISTS (SELECT 1 FROM endpoint_agent_devices d WHERE d.device_id = a.scope_id)");

        $n += $this->auto?->prune() ?? 0;
        $n += $this->hosted?->pruneGrants() ?? 0;

        return $n;
    }
}
