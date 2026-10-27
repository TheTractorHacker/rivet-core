<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Patch;

use RivetCore\Rmm\Enrollment\DeviceValidator;
use RivetCore\Rmm\Job\JobService;
use RivetCore\Rmm\RmmEvent;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Support\RmmEventPublisher;
use RivetCore\Rmm\Support\Sql;

/**
 * Install gated: turn approved updates into a signed `patch_install` job and apply what the device reports back.
 *
 * SAFETY, in the order it is enforced here:
 *  - ids come only from this module's own table for the device (what its last scan listed); a REAL install needs status `approved`, a dry run also takes `available`;
 *  - `mode` is `dry_run` unless the caller says `install` (and the scheduler says `install` only while the `patch_live_install` limit is on);
 *  - at most one install job per device is waiting or running; one job carries at most {@see PatchProtocol::IDS_MAX} ids, the rest follows in a later job;
 *  - the agent re-checks each id against a fresh scan, never accepts an EULA, never removes packages and never restarts the device. A restart is a separate,
 *    confirmed `reboot` job, queued here only for a policy with `reboot_policy` = `if_required_now` and a recorded confirmation.
 *
 * @api
 */
final class PatchInstallService
{
    /** A failed update is tried again by the scheduler at most this many times (and not more often than {@see RETRY_AFTER_S}). */
    public const MAX_ATTEMPTS = 3;
    public const RETRY_AFTER_S = 43200;
    public const REBOOT_DELAY_S = 300;

    private readonly \RivetCore\Rmm\Store\PatchHistoryStore $patchHistory;

    public function __construct(
        private readonly Sql $sql,
        private readonly RmmSettings $settings,
        private readonly JobService $jobs,
        private readonly PatchScanService $scans,
        private readonly PatchPolicyStore $policies,
        private readonly ?RmmEventPublisher $events = null,
        private readonly ?PatchComplianceService $compliance = null,
        ?\RivetCore\Rmm\Store\PatchHistoryStore $history = null,
    ) {
        $this->patchHistory = $history ?? new \RivetCore\Rmm\Store\MariaDb\MariaDbPatchHistoryStore($sql);
    }

    /**
     * Queue an install (or its dry run) for a device.
     *
     * @param array<string,mixed> $dev
     * @param list<string>|null $ids update ids to install; null = every eligible update of the device
     * @param array{source?:string,user_id?:?int,reboot_policy?:string,max_minutes?:int,idem?:bool} $opts
     * @return array{ok:bool,job_id?:string,queued?:int,remaining?:int,skipped?:string,error?:string,code?:string,ids?:list<string>}
     */
    public function queue(array $dev, ?array $ids, string $mode, array $opts = []): array
    {
        if (!in_array($mode, PatchProtocol::MODES, true)) {
            return ['ok' => false, 'error' => 'mode must be dry_run or install.', 'code' => 'invalid'];
        }
        $deviceId = (int) $dev['device_id'];
        $source = $opts['source'] ?? 'manual';
        // A dry run is a rehearsal of the real thing: by default it takes exactly what a real install would. Named ids may also be ones that are only available (that is
        // how a technician asks "what would happen if I approved this?").
        $statuses = $mode === 'install' || $ids === null ? ['approved', 'failed'] : ['approved', 'available', 'failed'];
        $rows = $this->sql->all("SELECT update_id, kind, status, fail_count, last_attempt_at FROM rmm_patch_available WHERE device_id = ? AND status IN ('" . implode("','", $statuses) . "')
            ORDER BY FIELD(kind, 'critical', 'security', 'important', 'optional', 'driver', 'other'), update_id", [$deviceId]);
        $eligible = [];
        $now = $this->sql->time();
        foreach ($rows as $r) {
            if ((string) $r['status'] === 'failed') {
                // A failed update is only retried by name, or by the scheduler after a pause and a few times.
                $recent = $r['last_attempt_at'] !== null && Sql::ts((string) $r['last_attempt_at']) + self::RETRY_AFTER_S > $now;
                if ($ids === null && ($recent || (int) $r['fail_count'] >= self::MAX_ATTEMPTS)) {
                    continue;
                }
            }
            $eligible[(string) $r['update_id']] = true;
        }
        if ($ids !== null) {
            foreach ($ids as $id) {
                if (!PatchProtocol::validId($id, (string) $dev['os']) || !isset($eligible[$id])) {
                    return ['ok' => false, 'error' => 'Update ' . mb_substr((string) $id, 0, 60) . ' is not on this device\'s list or is not approved.', 'code' => 'not_found'];
                }
            }
            $pick = array_values(array_unique($ids));
        } else {
            $pick = array_keys($eligible);
        }
        if ($pick === []) {
            return ['ok' => true, 'skipped' => 'nothing_to_install', 'queued' => 0];
        }
        if ($this->scans->pendingJob($deviceId, PatchProtocol::JOB_INSTALL) !== null) {
            return ['ok' => true, 'skipped' => 'already_queued'];   // one install at a time per device
        }
        // As many as one job may carry; the rest goes in a later job.
        $take = [];
        $chars = 0;
        foreach ($pick as $id) {
            $len = strlen($id) + 1;
            if (count($take) >= PatchProtocol::IDS_MAX || $chars + $len > PatchProtocol::IDS_MAX_CHARS) {
                break;
            }
            $take[] = (string) $id;
            $chars += $len;
        }
        $remaining = count($pick) - count($take);
        // The job's own deadline is the install time plus five minutes of margin, but never more than the instance's job timeout (and the agent never runs a job longer than
        // an hour): a longer install simply continues in the next job, so the minutes are cut to fit.
        $timeoutCap = min(3600, (int) $this->settings->get()['job_max_timeout_s']);
        $maxMinutes = max(1, min(PatchProtocol::MAX_MINUTES_MAX, (int) ($opts['max_minutes'] ?? PatchProtocol::MAX_MINUTES_DEFAULT), intdiv(max(360, $timeoutCap) - 300, 60)));
        $jobId = JobService::uuid();
        $idem = null;
        if (($opts['idem'] ?? false) === true) {
            $sorted = $take;
            sort($sorted, SORT_STRING);
            $idem = hash('sha256', "patchinstall:$deviceId:$mode:" . implode(',', $sorted) . ':' . intdiv($now, 3600));
        }
        $ledger = $this->sql->run('INSERT IGNORE INTO rmm_patch_jobs (job_id, device_id, client_id, type, mode, ids_json, reboot_policy, source, idem_key, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$jobId, $deviceId, (int) $dev['client_id'], PatchProtocol::JOB_INSTALL, $mode, (string) json_encode($take), $opts['reboot_policy'] ?? 'never', $source, $idem, $opts['user_id'] ?? null, $this->sql->utcNow()]);
        if ($ledger === 0) {
            return ['ok' => true, 'skipped' => 'duplicate'];
        }
        $r = $this->jobs->create($dev, PatchProtocol::JOB_INSTALL, null, ['ids' => implode(',', $take), 'mode' => $mode, 'max_minutes' => $maxMinutes], min($timeoutCap, $maxMinutes * 60 + 300), true, (int) ($opts['user_id'] ?? 0), ['job_id' => $jobId]);
        if (!$r['ok']) {
            $this->sql->run('DELETE FROM rmm_patch_jobs WHERE job_id = ?', [$jobId]);

            return ['ok' => false, 'error' => (string) ($r['error'] ?? 'The install could not be queued.'), 'code' => (string) ($r['code'] ?? 'invalid')];
        }
        $this->sql->run('UPDATE rmm_patch_available SET last_job_id = ?, last_attempt_at = ? WHERE device_id = ? AND update_id IN (' . implode(',', array_fill(0, count($take), '?')) . ')',
            [$jobId, $this->sql->utcNow(), $deviceId, ...$take]);
        $this->events?->emit(RmmEvent::PATCH_INSTALL_STARTED, $dev, ['job_id' => $jobId, 'mode' => $mode, 'update_count' => count($take), 'source' => $source]);

        return ['ok' => true, 'job_id' => $jobId, 'queued' => count($take), 'remaining' => $remaining, 'ids' => $take];
    }

    // ------------------------------------------------------------------ results

    /**
     * Parse the output of a finished install job.
     *
     * @return array{results:list<array{id:string,title:string,result:string,code:string,detail:string,reboot_required:bool}>,reboot_required:bool,truncated:bool,ok:bool}|null null when unreadable
     */
    public static function cleanResult(string $output): ?array
    {
        $j = json_decode($output, true);
        if (!is_array($j) || array_is_list($j) || !is_array($j['results'] ?? null) || !array_is_list($j['results'])) {
            return null;
        }
        $out = [];
        foreach (array_slice($j['results'], 0, 200) as $r) {
            if (!is_array($r) || !is_string($r['id'] ?? null) || !is_string($r['result'] ?? null) || !in_array($r['result'], PatchProtocol::RESULTS, true)) {
                continue;
            }
            $code = $r['code'] ?? '';
            $out[] = ['id' => mb_substr($r['id'], 0, 120), 'title' => DeviceValidator::cleanText($r['title'] ?? null, 300) ?? '', 'result' => $r['result'],
                'code' => DeviceValidator::cleanText(is_scalar($code) ? (string) $code : '', 40) ?? '', 'detail' => DeviceValidator::cleanText($r['detail'] ?? null, 200) ?? '',
                'reboot_required' => ($r['reboot_required'] ?? false) === true];
        }

        return ['results' => $out, 'reboot_required' => ($j['reboot_required'] ?? false) === true, 'truncated' => ($j['truncated'] ?? false) === true, 'ok' => ($j['ok'] ?? false) === true];
    }

    /**
     * Apply the result of a finished `patch_install` job: history rows, update status, reboot state, events, the follow-up scan and the alert checks.
     * Idempotent (the ledger row is claimed first).
     *
     * @param array<string,mixed> $dev
     * @param array<string,mixed> $job the endpoint_agent_jobs row
     */
    public function applyJob(array $dev, array $job): bool
    {
        $led = $this->sql->one('SELECT * FROM rmm_patch_jobs WHERE job_id = ? AND type = ?', [$job['job_id'], PatchProtocol::JOB_INSTALL]);
        if ($led === null || $led['applied_at'] !== null) {
            return false;
        }
        if ($this->sql->run('UPDATE rmm_patch_jobs SET applied_at = ? WHERE job_id = ? AND applied_at IS NULL', [$this->sql->utcNow(), $job['job_id']]) !== 1) {
            return false;
        }
        $deviceId = (int) $dev['device_id'];
        $client = (int) $dev['client_id'];
        $mode = (string) $led['mode'];
        $asked = json_decode((string) $led['ids_json'], true);
        $asked = is_array($asked) ? array_values(array_filter($asked, 'is_string')) : [];
        $parsed = in_array((string) $job['state'], ['succeeded', 'failed'], true) ? self::cleanResult((string) ($job['output'] ?? '')) : null;
        $now = $this->sql->utcNow();
        $installed = $failed = $skipped = 0;
        $reboot = false;
        $firstFailure = '';
        $action = $mode === 'dry_run' ? 'dry_run' : 'install';
        $userId = $led['created_by'] === null ? null : (int) $led['created_by'];
        $titles = [];
        foreach ($this->sql->all('SELECT update_id, title FROM rmm_patch_available WHERE device_id = ?', [$deviceId]) as $t) {
            $titles[(string) $t['update_id']] = (string) $t['title'];
        }
        $this->sql->transaction(function () use ($parsed, $asked, $job, $deviceId, $client, $mode, $action, $now, $userId, $titles, &$installed, &$failed, &$skipped, &$reboot, &$firstFailure): bool {
            if ($parsed === null) {
                // The job failed as a whole (timeout, cancelled, expired, unreadable output): every update of the batch failed to run.
                $why = (string) ($job['reason'] ?? '') !== '' ? (string) $job['reason'] : 'job_' . (string) $job['state'];
                $firstFailure = mb_substr($why, 0, 40);
                foreach ($asked as $id) {
                    $this->history($deviceId, $client, $id, $titles[$id] ?? '', $action, 'failed', $why, 'The job did not finish: ' . (string) $job['state'], (string) $job['job_id'], $userId, $now);
                    ++$failed;
                }
                if ($mode === 'install' && $asked !== [] && (string) $job['state'] !== 'cancelled' && (string) $job['state'] !== 'expired') {
                    $this->markFailed($deviceId, $asked, $now);
                }

                return true;
            }
            foreach ($parsed['results'] as $r) {
                $id = $r['id'];
                $title = $r['title'] !== '' ? $r['title'] : ($titles[$id] ?? '');
                $this->history($deviceId, $client, $id, $title, $action, $r['result'], $r['code'], $r['detail'], (string) $job['job_id'], $userId, $now);
                if ($r['reboot_required']) {
                    $reboot = true;
                }
                if (in_array($r['result'], PatchProtocol::OK_RESULTS, true) || $r['result'] === 'would_install') {
                    ++$installed;
                } elseif (in_array($r['result'], PatchProtocol::FAILED_RESULTS, true)) {
                    ++$failed;
                    $firstFailure = $firstFailure !== '' ? $firstFailure : $r['result'] . ($r['code'] !== '' ? ':' . $r['code'] : '');
                } else {
                    ++$skipped;
                }
                if ($mode !== 'install') {
                    continue;
                }
                if (in_array($r['result'], PatchProtocol::OK_RESULTS, true)) {
                    $this->sql->run("UPDATE rmm_patch_available SET status = 'installed', status_source = 'install', status_at = ?, fail_count = 0 WHERE device_id = ? AND update_id = ?", [$now, $deviceId, $id]);
                } elseif ($r['result'] === 'not_applicable') {
                    $this->sql->run("UPDATE rmm_patch_available SET status = 'installed', status_source = 'not_applicable', status_at = ? WHERE device_id = ? AND update_id = ? AND status IN ('approved','failed')", [$now, $deviceId, $id]);
                } elseif (in_array($r['result'], PatchProtocol::FAILED_RESULTS, true)) {
                    $this->markFailed($deviceId, [$id], $now);
                }
            }
            if ($mode === 'install' && $parsed['reboot_required']) {
                $reboot = true;
            }
            if ($mode === 'install') {
                // Updates asked for that the device did not mention at all are treated as failed to run.
                $answered = array_column($parsed['results'], 'id');
                $silent = array_values(array_diff($asked, $answered));
                if ($silent !== [] && !$parsed['truncated']) {
                    $this->markFailed($deviceId, $silent, $now);
                    $failed += count($silent);
                    $firstFailure = $firstFailure !== '' ? $firstFailure : 'no_result';
                }
            }

            return true;
        });
        if ($mode === 'install' && $reboot) {
            $this->sql->run('UPDATE rmm_patch_scans SET reboot_pending = 1 WHERE device_id = ?', [$deviceId]);
        }
        $fields = ['job_id' => (string) $job['job_id'], 'mode' => $mode];
        if ($failed > 0) {
            $this->events?->emit(RmmEvent::PATCH_INSTALL_FAILED, $dev, $fields + ['failed' => $failed, 'installed' => $installed, 'reason' => $firstFailure]);
        } else {
            $this->events?->emit(RmmEvent::PATCH_INSTALL_FINISHED, $dev, $fields + ['installed' => $installed, 'skipped' => $skipped, 'reboot_required' => $reboot]);
        }
        $this->compliance?->evaluate($dev);
        if ($mode === 'install') {
            $this->afterInstall($dev, $led, $reboot, $failed === 0 && $installed > 0);
        }

        return true;
    }

    /**
     * @param list<string> $ids
     */
    private function markFailed(int $deviceId, array $ids, string $now): void
    {
        foreach (array_chunk($ids, 100) as $chunk) {
            $this->sql->run("UPDATE rmm_patch_available SET status = 'failed', status_at = ?, fail_count = fail_count + 1 WHERE device_id = ? AND update_id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ') AND status <> \'installed\'',
                [$now, $deviceId, ...$chunk]);
        }
    }

    private function history(int $deviceId, int $client, string $id, string $title, string $action, string $result, string $code, string $detail, string $jobId, ?int $userId, string $at): void
    {
        $this->patchHistory->record(['device_id' => $deviceId, 'client_id' => $client, 'update_id' => mb_substr($id, 0, 120), 'title' => mb_substr($title, 0, 300), 'action' => $action, 'result' => $result,
            'code' => mb_substr($code, 0, 40), 'detail' => mb_substr($detail, 0, 200), 'at' => $at, 'job_id' => $jobId, 'user_id' => $userId]);
    }

    /**
     * What follows a real install: the policy's restart decision and a scan to prove the result.
     *
     * @param array<string,mixed> $dev
     * @param array<string,mixed> $led the rmm_patch_jobs row
     */
    private function afterInstall(array $dev, array $led, bool $reboot, bool $clean): void
    {
        $this->scans->request($dev, null, 'after_install');
        if (!$reboot || (string) $led['reboot_policy'] !== 'if_required_now') {
            return;
        }
        // The only place a patch ever restarts a device: a separate, destructive `reboot` job. A manual install that asked for it was confirmed by the caller (who
        // needed the reboot grant: {@see PatchActions::install()}); a scheduled one needs the effective policy to still say `if_required_now` with a recorded confirmation.
        if (!$clean) {
            return;   // a restart only after a clean install
        }
        if ((string) $led['source'] === 'manual' && $led['created_by'] !== null && (int) $led['created_by'] > 0) {
            $by = (int) $led['created_by'];
        } else {
            $eff = $this->policies->effectiveFor($dev);
            $pid = (int) ($eff['sources']['reboot_confirm']['policy_id'] ?? 0);
            $by = (int) $this->sql->val('SELECT COALESCE(reboot_confirmed_by, 0) FROM rmm_patch_policies WHERE policy_id = ?', [$pid]);
            if ($eff['reboot_policy'] !== 'if_required_now' || !$eff['reboot_confirm'] || $by <= 0) {
                return;
            }
        }
        $this->jobs->create($dev, 'reboot', null, ['delay_s' => self::REBOOT_DELAY_S], null, true, $by);
    }
}
