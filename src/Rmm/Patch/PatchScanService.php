<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Patch;

use RivetCore\Rmm\Device\DeviceRepository;
use RivetCore\Rmm\Enrollment\DeviceValidator;
use RivetCore\Rmm\Job\JobService;
use RivetCore\Rmm\RmmEvent;
use RivetCore\Rmm\Support\RmmEventPublisher;
use RivetCore\Rmm\Support\Sql;

/**
 * Detect first: queue the read-only `patch_scan` job on a device and apply what it reports (tables rmm_patch_scans and rmm_patch_available).
 *
 * The report is the JSON output of the agent's job (see docs/rmm/PATCHING.md for the shape). It is validated defensively: every string is cut and cleaned, an
 * id that is not valid for the device's operating system is dropped, counts are recomputed here and never taken from the agent. A scan that was cut short by
 * the agent (`truncated`) never marks the updates it did not list as installed.
 *
 * CAPACITY. A scan is applied from the job report path; at load-shedding level 2 and above that work is left for the next housekeeping pass (the job row
 * keeps the output and rmm_patch_jobs.applied_at stays NULL, see {@see applyPending()}). The scheduler asks for no new scans at those levels either.
 *
 * @api
 */
final class PatchScanService
{
    public const MAX_UPDATES = 1000;
    private const CHUNK = 100;

    public function __construct(
        private readonly Sql $sql,
        private readonly JobService $jobs,
        private readonly DeviceRepository $devices,
        private readonly ?RmmEventPublisher $events = null,
        private readonly ?PatchApprovalService $approvals = null,
        private readonly ?PatchComplianceService $compliance = null,
    ) {
    }

    // ------------------------------------------------------------------ queueing

    /**
     * Queue a scan unless one is already waiting or running.
     *
     * @param array<string,mixed> $dev the device row
     * @return array{ok:bool,job_id?:string,skipped?:string,error?:string,code?:string}
     */
    public function request(array $dev, ?int $userId, string $source = 'manual'): array
    {
        $deviceId = (int) $dev['device_id'];
        $pending = $this->pendingJob($deviceId, PatchProtocol::JOB_SCAN);
        if ($pending !== null) {
            return ['ok' => true, 'skipped' => 'already_queued', 'job_id' => $pending];
        }
        $this->sql->run('INSERT IGNORE INTO rmm_patch_scans (device_id, client_id, scan_state) VALUES (?, ?, ?)', [$deviceId, (int) $dev['client_id'], 'never']);
        $now = $this->sql->utcNow();
        $this->sql->run('UPDATE rmm_patch_scans SET requested_at = ?, client_id = ? WHERE device_id = ?', [$now, (int) $dev['client_id'], $deviceId]);
        $jobId = JobService::uuid();
        // The ledger row first: a result can never arrive for a job the ledger does not know.
        $this->sql->run('INSERT INTO rmm_patch_jobs (job_id, device_id, client_id, type, mode, source, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$jobId, $deviceId, (int) $dev['client_id'], PatchProtocol::JOB_SCAN, '', $source, $userId, $now]);
        $r = $this->jobs->create($dev, PatchProtocol::JOB_SCAN, null, ['categories' => '', 'max_items' => 500], null, false, $userId ?? 0, ['job_id' => $jobId]);
        if (!$r['ok']) {
            $this->sql->run('DELETE FROM rmm_patch_jobs WHERE job_id = ?', [$jobId]);

            return ['ok' => false, 'error' => (string) ($r['error'] ?? 'The scan could not be queued.'), 'code' => (string) ($r['code'] ?? 'invalid')];
        }

        return ['ok' => true, 'job_id' => $jobId];
    }

    /** The id of a scan or install job of this device that is still queued or running (and not older than a day), else null. */
    public function pendingJob(int $deviceId, string $type): ?string
    {
        $r = $this->sql->one("SELECT p.job_id FROM rmm_patch_jobs p JOIN endpoint_agent_jobs j ON j.job_id = p.job_id
            WHERE p.device_id = ? AND p.type = ? AND j.state IN ('queued','running') AND p.created_at > ? ORDER BY p.created_at DESC LIMIT 1", [$deviceId, $type, $this->sql->utcAt(-86400)]);

        return $r === null ? null : (string) $r['job_id'];
    }

    // ------------------------------------------------------------------ the report

    /**
     * Validate the JSON a `patch_scan` job returned.
     *
     * @return array{ok:true,manager:string,scan_state:string,wu_state:string,reboot_pending:bool,last_install_at:?string,truncated:bool,scanned_at:string,updates:list<array<string,mixed>>,dropped:int}|array{ok:false,error:string}
     */
    public static function cleanReport(mixed $raw, string $os): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw) || array_is_list($raw)) {
            return ['ok' => false, 'error' => 'unreadable'];
        }
        if (($raw['ok'] ?? false) !== true) {
            $d = DeviceValidator::cleanText($raw['detail'] ?? null, 200);

            return ['ok' => false, 'error' => $d ?? 'scan_failed'];
        }
        $manager = $raw['manager'] ?? null;
        if (!is_string($manager) || !in_array($manager, PatchProtocol::MANAGERS, true) || ($os === 'windows') !== ($manager === 'wua')) {
            return ['ok' => false, 'error' => 'unknown_manager'];
        }
        $state = $raw['scan_state'] ?? 'ok';
        $wu = $raw['wu_state'] ?? 'n/a';
        $list = $raw['updates'] ?? [];
        if (!is_array($list) || !array_is_list($list)) {
            return ['ok' => false, 'error' => 'unreadable'];
        }
        $out = [];
        $dropped = 0;
        $seen = [];
        foreach ($list as $u) {
            if (count($out) >= self::MAX_UPDATES) {
                ++$dropped;
                continue;
            }
            $c = is_array($u) ? self::cleanUpdate($u, $os) : null;
            if ($c === null || isset($seen[$c['update_id']])) {
                ++$dropped;
                continue;
            }
            $seen[$c['update_id']] = true;
            $out[] = $c;
        }
        $last = self::time($raw['last_install_at'] ?? null);
        $at = self::time($raw['scanned_at'] ?? null);

        return ['ok' => true, 'manager' => $manager, 'scan_state' => in_array($state, ['ok', 'partial'], true) ? $state : 'partial',
            'wu_state' => is_string($wu) && in_array($wu, PatchProtocol::WU_STATES, true) ? $wu : 'n/a', 'reboot_pending' => ($raw['reboot_pending'] ?? false) === true,
            'last_install_at' => $last, 'truncated' => ($raw['truncated'] ?? false) === true || $dropped > 0, 'scanned_at' => $at ?? gmdate('Y-m-d H:i:s'),
            'updates' => $out, 'dropped' => $dropped];
    }

    /**
     * @param array<array-key,mixed> $u
     * @return array<string,mixed>|null
     */
    private static function cleanUpdate(array $u, string $os): ?array
    {
        $id = $u['id'] ?? null;
        if (!PatchProtocol::validId($id, $os)) {
            return null;
        }
        $kind = $u['kind'] ?? 'other';
        $sev = $u['severity'] ?? 'unspecified';
        $size = $u['size'] ?? 0;
        $kb = DeviceValidator::cleanText($u['kb'] ?? null, 24) ?? '';

        return [
            'update_id' => (string) $id,
            'kb' => preg_match('/^[A-Za-z0-9._-]{1,24}$/D', $kb) === 1 ? $kb : '',
            'title' => DeviceValidator::cleanText($u['title'] ?? null, 300) ?? (string) $id,
            'kind' => is_string($kind) && in_array($kind, PatchProtocol::KINDS, true) ? $kind : 'other',
            'severity' => is_string($sev) && in_array($sev, PatchProtocol::SEVERITIES, true) ? $sev : 'unspecified',
            'size_bytes' => is_int($size) && $size > 0 ? min($size, 1 << 40) : 0,
            'reboot_required' => ($u['reboot_required'] ?? false) === true,
            'released_at' => self::time($u['released_at'] ?? null),
            'eula_accepted' => ($u['eula_accepted'] ?? true) !== false,
            'version' => DeviceValidator::cleanText($u['version'] ?? null, 80) ?? '',
            'current_version' => DeviceValidator::cleanText($u['current'] ?? null, 80) ?? '',
        ];
    }

    private static function time(mixed $v): ?string
    {
        if (!is_string($v) || $v === '' || strlen($v) > 40) {
            return null;
        }
        $t = strtotime($v);
        if ($t === false || $t < 315532800 || $t > time() + 86400) {   // 1980 .. tomorrow
            return null;
        }

        return gmdate('Y-m-d H:i:s', $t);
    }

    // ------------------------------------------------------------------ applying

    /**
     * Store a validated report as the device's current patch state.
     *
     * @param array<string,mixed> $dev
     * @param array{ok:true,manager:string,scan_state:string,wu_state:string,reboot_pending:bool,last_install_at:?string,truncated:bool,scanned_at:string,updates:list<array<string,mixed>>,dropped:int} $r
     * @return array{total:int,new:int,security:int,gone:int}
     */
    public function apply(array $dev, array $r, ?string $jobId): array
    {
        $deviceId = (int) $dev['device_id'];
        $client = (int) $dev['client_id'];
        $now = $this->sql->utcNow();
        $new = 0;
        $gone = 0;
        $this->sql->transaction(function () use ($deviceId, $client, $r, $jobId, $now, &$new, &$gone): bool {
            $known = [];
            foreach ($this->sql->all('SELECT update_id, status FROM rmm_patch_available WHERE device_id = ?', [$deviceId]) as $k) {
                $known[(string) $k['update_id']] = (string) $k['status'];
            }
            $listed = [];
            foreach (array_chunk($r['updates'], self::CHUNK) as $chunk) {
                $ph = [];
                $p = [];
                foreach ($chunk as $u) {
                    $listed[$u['update_id']] = true;
                    if (!isset($known[$u['update_id']])) {
                        ++$new;
                    }
                    $ph[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
                    array_push($p, $deviceId, $client, $u['update_id'], $u['kb'], $u['title'], $u['kind'], $u['severity'], $u['size_bytes'], $u['reboot_required'] ? 1 : 0,
                        $u['released_at'], $u['eula_accepted'] ? 1 : 0, $u['version'], $u['current_version'], 'available', $now, $now);
                }
                $this->sql->run('INSERT INTO rmm_patch_available (device_id, client_id, update_id, kb, title, kind, severity, size_bytes, reboot_required, released_at, eula_accepted, version, current_version, status, first_seen_at, last_seen_at)
                    VALUES ' . implode(', ', $ph) . " ON DUPLICATE KEY UPDATE client_id = VALUES(client_id), kb = VALUES(kb), title = VALUES(title), kind = VALUES(kind), severity = VALUES(severity),
                    size_bytes = VALUES(size_bytes), reboot_required = VALUES(reboot_required), released_at = VALUES(released_at), eula_accepted = VALUES(eula_accepted), version = VALUES(version),
                    current_version = VALUES(current_version), last_seen_at = VALUES(last_seen_at), status = IF(status = 'installed', 'available', status)", $p);
            }
            // Rows the device no longer lists: installed (outside this module, or by it). Only when the list is complete.
            if (!$r['truncated']) {
                $absent = [];
                $drop = [];
                foreach ($known as $id => $status) {
                    if (isset($listed[$id])) {
                        continue;
                    }
                    if ($status === 'declined') {
                        $drop[] = $id;
                    } elseif ($status !== 'installed') {
                        $absent[] = $id;
                    }
                }
                foreach (array_chunk($absent, self::CHUNK) as $ids) {
                    $gone += $this->sql->run("UPDATE rmm_patch_available SET status = 'installed', status_source = IF(status = 'approved', status_source, 'scan'), status_at = ? WHERE device_id = ? AND update_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')',
                        [$now, $deviceId, ...$ids]);
                }
                foreach (array_chunk($drop, self::CHUNK) as $ids) {
                    $this->sql->run('DELETE FROM rmm_patch_available WHERE device_id = ? AND update_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', [$deviceId, ...$ids]);
                }
            }
            $c = $this->counts($deviceId);
            $this->sql->run('INSERT INTO rmm_patch_scans (device_id, client_id, scanned_at, manager, scan_state, wu_state, reboot_pending, last_install_at, total_count, critical_count, security_count, optional_count, driver_count, reboot_required_count, truncated, job_id, error)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE client_id = VALUES(client_id), scanned_at = VALUES(scanned_at), manager = VALUES(manager), scan_state = VALUES(scan_state),
                wu_state = VALUES(wu_state), reboot_pending = VALUES(reboot_pending), last_install_at = VALUES(last_install_at), total_count = VALUES(total_count), critical_count = VALUES(critical_count),
                security_count = VALUES(security_count), optional_count = VALUES(optional_count), driver_count = VALUES(driver_count), reboot_required_count = VALUES(reboot_required_count),
                truncated = VALUES(truncated), job_id = VALUES(job_id), error = VALUES(error), evaluated_at = NULL',
                [$deviceId, $client, $r['scanned_at'], $r['manager'], $r['scan_state'], $r['wu_state'], $r['reboot_pending'] ? 1 : 0, $r['last_install_at'], $c['total'], $c['critical'], $c['security'], $c['optional'], $c['driver'], $c['reboot'],
                    $r['truncated'] ? 1 : 0, $jobId, '']);

            return true;
        });
        $c = $this->counts($deviceId);
        $this->events?->emit(RmmEvent::PATCH_SCAN_COMPLETED, $dev, ['manager' => $r['manager'], 'scan_state' => $r['scan_state'], 'total' => $c['total'], 'security' => $c['security'] + $c['critical'],
            'reboot_pending' => $r['reboot_pending'], 'new' => $new]);
        $this->approvals?->autoApprove($dev);
        $this->compliance?->evaluate($dev);
        $this->sql->run('UPDATE rmm_patch_scans SET evaluated_at = ? WHERE device_id = ?', [$this->sql->utcNow(), $deviceId]);

        return ['total' => $c['total'], 'new' => $new, 'security' => $c['security'] + $c['critical'], 'gone' => $gone];
    }

    /**
     * Counts of the updates still missing on a device (status available, approved, declined or failed; not installed).
     *
     * @return array{total:int,critical:int,security:int,optional:int,driver:int,reboot:int}
     */
    private function counts(int $deviceId): array
    {
        $out = ['total' => 0, 'critical' => 0, 'security' => 0, 'optional' => 0, 'driver' => 0, 'reboot' => 0];
        foreach ($this->sql->all("SELECT kind, reboot_required, COUNT(*) AS c FROM rmm_patch_available WHERE device_id = ? AND status <> 'installed' GROUP BY kind, reboot_required", [$deviceId]) as $r) {
            $n = (int) $r['c'];
            $out['total'] += $n;
            if ((int) $r['reboot_required'] === 1) {
                $out['reboot'] += $n;
            }
            match ((string) $r['kind']) {
                'critical' => $out['critical'] += $n,
                'security' => $out['security'] += $n,
                'optional' => $out['optional'] += $n,
                'driver' => $out['driver'] += $n,
                default => null,
            };
        }

        return $out;
    }

    /**
     * Record that a scan could not be done (the agent said why).
     *
     * @param array<string,mixed> $dev
     */
    public function recordFailure(array $dev, string $error, ?string $jobId): void
    {
        $deviceId = (int) $dev['device_id'];
        $this->sql->run('INSERT INTO rmm_patch_scans (device_id, client_id, scan_state, error, job_id) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE scan_state = ?, error = ?, job_id = ?',
            [$deviceId, (int) $dev['client_id'], 'failed', mb_substr($error, 0, 240), $jobId, 'failed', mb_substr($error, 0, 240), $jobId]);
    }

    /**
     * Apply the result of a finished `patch_scan` job (from the report path, or later by {@see applyPending()}). Idempotent: the job's ledger row is claimed
     * with a conditional UPDATE.
     *
     * @param array<string,mixed> $dev
     * @param array<string,mixed> $job the endpoint_agent_jobs row
     */
    public function applyJob(array $dev, array $job): bool
    {
        $claimed = $this->sql->run('UPDATE rmm_patch_jobs SET applied_at = ? WHERE job_id = ? AND type = ? AND applied_at IS NULL', [$this->sql->utcNow(), $job['job_id'], PatchProtocol::JOB_SCAN]);
        if ($claimed !== 1) {
            return false;
        }
        if ((string) $job['state'] !== 'succeeded') {
            $why = (string) ($job['reason'] ?? '') !== '' ? (string) $job['reason'] : (string) $job['state'];
            $parsed = json_decode((string) ($job['output'] ?? ''), true);
            $this->recordFailure($dev, is_array($parsed) && is_string($parsed['detail'] ?? null) ? (string) $parsed['detail'] : $why, (string) $job['job_id']);
            $this->compliance?->evaluate($dev);

            return true;
        }
        $r = self::cleanReport((string) ($job['output'] ?? ''), (string) ($dev['os'] ?? ''));
        if (!$r['ok']) {
            $this->recordFailure($dev, $r['error'], (string) $job['job_id']);
            $this->compliance?->evaluate($dev);

            return true;
        }
        $this->apply($dev, $r, (string) $job['job_id']);

        return true;
    }

    /**
     * Apply finished scan and install results that were not applied yet (a pass during load shedding, a listener that failed, a job whose late result came after
     * the sweep). Bounded.
     *
     * @return int jobs applied
     */
    public function applyPending(int $limit, ?PatchInstallService $installs): int
    {
        $rows = $this->sql->all("SELECT j.* FROM rmm_patch_jobs p JOIN endpoint_agent_jobs j ON j.job_id = p.job_id
            WHERE p.applied_at IS NULL AND j.state IN ('succeeded','failed','timed_out','cancelled','expired')
              AND NOT (COALESCE(j.reason, '') IN ('result_lost','no_result_by_deadline') AND j.finished_at > ?) ORDER BY p.created_at LIMIT " . max(1, $limit), [$this->sql->utcAt(-3600)]);   // a lost result may still arrive late: give it an hour
        $n = 0;
        foreach ($rows as $j) {
            $dev = $this->devices->find((int) $j['device_id']);
            if ($dev === null) {
                $this->sql->run('UPDATE rmm_patch_jobs SET applied_at = ? WHERE job_id = ?', [$this->sql->utcNow(), $j['job_id']]);
                continue;
            }
            if ($j['type'] === PatchProtocol::JOB_SCAN) {
                $n += $this->applyJob($dev, $j) ? 1 : 0;
            } elseif ($installs !== null) {
                $n += $installs->applyJob($dev, $j) ? 1 : 0;
            }
        }

        return $n;
    }
}
