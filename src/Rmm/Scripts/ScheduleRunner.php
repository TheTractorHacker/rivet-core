<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Scripts;

use RivetCore\Rmm\Contracts\RmmAuditInterface;
use RivetCore\Rmm\Contracts\ScheduleGateInterface;
use RivetCore\Rmm\Job\JobExtras;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Support\Sql;

/**
 * Turns due schedules into jobs. Run from {@see \RivetCore\Rmm\Maintenance\Housekeeping}, so it only works while the module, the `jobs` and the
 * `scripts` switches are on.
 *
 * ONE RUN PER SLOT. A schedule that is due opens a run for its nominal time (`rmm_schedule_runs`, unique per schedule and slot) and moves on to the
 * next slot after now, so downtime never produces a burst of catch-up runs: a slot older than the schedule's window (`expires_s`) is recorded as
 * `missed` and nothing is queued. A run then creates one job per device of the target, each guarded by an idempotency key (schedule, slot, device) in
 * `rmm_job_extra`: crashes, overlapping cron processes and retries cannot queue a device twice, and an agent that was offline picks the job up when it
 * returns (the job waits `expires_s`).
 *
 * CAPACITY. A pass creates at most `schedule_batch` jobs per run, divided by 2 at load-shedding level 1 and by 4 at level 2, and nothing at level 3
 * (the runs wait; the window decides whether they still matter when the load drops). Devices are spread over `jitter_s` by a stable hash of the device.
 *
 * @api
 */
final class ScheduleRunner
{
    /** Schedules turned into runs, and runs advanced, per pass. */
    private const PASS_LIMIT = 50;

    public function __construct(
        private readonly Sql $sql,
        private readonly RmmSettings $settings,
        private readonly ScriptService $scripts,
        private readonly ScriptRunner $runner,
        private readonly TargetResolver $targets,
        private readonly JobExtras $extras,
        private readonly ?ScheduleGateInterface $gate = null,
        private readonly ?RmmAuditInterface $audit = null,
    ) {
    }

    /**
     * @return array<string,int>
     */
    public function run(): array
    {
        $out = ['due' => 0, 'runs_opened' => 0, 'runs_missed' => 0, 'runs_open' => 0, 'jobs_created' => 0, 'skipped' => 0, 'deferred_by_load' => 0, 'runs_closed' => 0];
        $features = $this->settings->features();
        if (!$features['jobs'] || !$features['scripts']) {
            return $out;
        }
        $now = $this->sql->time();
        $due = $this->sql->all('SELECT * FROM rmm_schedules WHERE enabled = 1 AND approved_at IS NOT NULL AND next_run_at IS NOT NULL AND next_run_at <= ? ORDER BY next_run_at, schedule_id LIMIT ' . self::PASS_LIMIT,
            [gmdate('Y-m-d H:i:s', $now)]);
        $out['due'] = count($due);
        foreach ($due as $s) {
            $r = $this->openRun($s, $now);
            $out[$r] = ($out[$r] ?? 0) + 1;
        }

        $shed = max(0, min(3, (int) ($this->settings->get()['shed_level'] ?? 0)));
        $runs = $this->sql->all("SELECT * FROM rmm_schedule_runs WHERE state = 'running' ORDER BY run_id LIMIT " . self::PASS_LIMIT);
        $out['runs_open'] = count($runs);
        if ($shed >= 3) {
            $out['deferred_by_load'] = count($runs);

            return $out;
        }
        $batch = max(10, intdiv($this->settings->limits()['schedule_batch'], [1, 2, 4][$shed]));
        foreach ($runs as $run) {
            $r = $this->advance($run, $batch, $now);
            $out['jobs_created'] += $r['created'];
            $out['skipped'] += $r['skipped'];
            $out['runs_closed'] += $r['closed'] ? 1 : 0;
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $s a due schedule row
     * @return string the counter to bump: runs_opened or runs_missed
     */
    private function openRun(array $s, int $now): string
    {
        $slot = (string) $s['next_run_at'];
        $slotTs = Sql::ts($slot);
        $missed = $now - $slotTs > max((int) $s['expires_s'], 3600) + (int) $s['jitter_s'];
        $next = $this->nextSlot($s, $slotTs, $now);

        return $this->sql->transaction(function () use ($s, $slot, $missed, $next, $now): string {
            $this->sql->run('UPDATE rmm_schedules SET next_run_at = ?, last_run_at = ? WHERE schedule_id = ?', [$next, gmdate('Y-m-d H:i:s', $now), $s['schedule_id']]);
            $n = $this->sql->run('INSERT IGNORE INTO rmm_schedule_runs (schedule_id, slot_at, state, started_at, finished_at) VALUES (?, ?, ?, ?, ?)',
                [$s['schedule_id'], $slot, $missed ? 'missed' : 'running', gmdate('Y-m-d H:i:s', $now), $missed ? gmdate('Y-m-d H:i:s', $now) : null]);
            if ($n === 0) {
                return 'runs_opened';   // the slot already has a run (a crashed pass left the schedule behind): nothing more to do
            }

            return $missed ? 'runs_missed' : 'runs_opened';
        });
    }

    /**
     * The first slot strictly after now.
     *
     * @param array<string,mixed> $s
     */
    private function nextSlot(array $s, int $slotTs, int $now): ?string
    {
        if ($s['kind'] === 'interval') {
            $iv = max(ScheduleService::INTERVAL_MIN_S, (int) $s['interval_s']);
            $n = $slotTs + $iv;
            if ($n <= $now) {
                $n = $slotTs + ((intdiv($now - $slotTs, $iv) + 1) * $iv);
            }

            return gmdate('Y-m-d H:i:s', $n);
        }
        try {
            $next = CronSchedule::parse((string) $s['cron_expr'])->next(new \DateTimeImmutable('@' . max($now, $slotTs)));
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $next?->format('Y-m-d H:i:s');
    }

    /**
     * @param array<string,mixed> $run
     * @return array{created:int,skipped:int,closed:bool}
     */
    private function advance(array $run, int $batch, int $now): array
    {
        $runId = (int) $run['run_id'];
        $s = $this->sql->one('SELECT * FROM rmm_schedules WHERE schedule_id = ?', [$run['schedule_id']]);
        if ($s === null || (int) $s['enabled'] !== 1 || $s['approved_at'] === null) {
            $this->close($runId, 'cancelled');

            return ['created' => 0, 'skipped' => 0, 'closed' => true];
        }
        [$loaded, $err] = $this->scripts->load((int) $s['script_id'], (int) $s['script_version']);
        if ($loaded === null || !hash_equals((string) $s['body_sha256'], (string) $loaded['version']['body_sha256'])) {
            // The script changed under an approved schedule, was retired or failed its integrity check: stop it rather than run something nobody approved.
            $this->sql->run('UPDATE rmm_schedules SET enabled = 0, updated_at = ? WHERE schedule_id = ?', [$this->sql->utcNow(), $s['schedule_id']]);
            $this->close($runId, 'failed');
            $this->audit?->record('Schedule Stopped', 'Schedule #' . $s['schedule_id'] . ' was switched off: ' . ($err ?? 'the pinned script text no longer matches what was approved.'), 0, 0);

            return ['created' => 0, 'skipped' => 0, 'closed' => true];
        }
        $slot = (string) $run['slot_at'];
        $slotTs = Sql::ts($slot);
        $elapsed = $now - $slotTs;
        $jitter = (int) $s['jitter_s'];
        $window = $slotTs + $jitter + (int) $s['expires_s'];
        $platforms = ScriptLanguage::platforms((string) $loaded['script']['language']);
        $schedule = ScheduleService::row($s);

        $idem = "NOT EXISTS (SELECT 1 FROM rmm_job_extra e WHERE e.idem_key = SHA2(CONCAT('schedule:', ?, ':', ?, ':device:', d.device_id), 256))";
        $extra = [$idem, [(int) $s['schedule_id'], $slot]];
        if ($jitter > 0) {
            $extra[0] .= ' AND (CRC32(CONCAT(?, \':\', d.device_id)) % (? + 1)) <= ?';
            array_push($extra[1], (int) $s['schedule_id'], $jitter, max(0, $elapsed));
        }
        // A persistent cursor only works when no device can be left behind: no jitter (every device is eligible at once) and no gate (nothing is deferred).
        $useCursor = $jitter === 0 && $this->gate === null;
        $pageCursor = $useCursor ? (int) $run['cursor_device_id'] : 0;

        $params = json_decode((string) ($s['params_json'] ?? '{}'), true);
        /** @var array{cursor:int,remaining:int,deferred:bool,targeted:int,created:int,overlap:int,gate:int,other:int} $st */
        $st = ['cursor' => $pageCursor, 'remaining' => $batch, 'deferred' => false, 'targeted' => 0, 'created' => 0, 'overlap' => 0, 'gate' => 0, 'other' => 0];
        $exhausted = false;
        while ($st['remaining'] > 0) {
            $pageSize = min(500, max(50, $st['remaining']));
            $devices = $this->targets->devices((string) $s['target_type'], (int) $s['target_id'], null, $platforms, $st['cursor'], $pageSize, $extra);
            // One transaction per page: a commit per job would cost a log flush each. A failure rolls the whole page back, markers included, and the next pass redoes it.
            $work = function () use ($devices, $s, $loaded, $params, $slot, $slotTs, $runId, $schedule, &$st): bool {
                $this->processPage($devices, $s, $loaded, is_array($params) ? $params : [], $slot, $slotTs, $runId, $schedule, $st);

                return true;
            };
            $this->runner->batch(fn (): mixed => $this->sql->transaction($work));
            if (count($devices) < $pageSize) {
                $exhausted = true;
                break;
            }
        }
        $pageCursor = $st['cursor'];
        $created = $st['created'];
        $targeted = $st['targeted'];
        $deferred = $st['deferred'];
        $skipped = ['overlap' => $st['overlap'], 'gate' => $st['gate'], 'other' => $st['other']];

        $state = null;
        if ($exhausted && $elapsed >= $jitter && !$deferred) {
            $state = 'done';
        } elseif ($now > $window) {
            $state = 'expired';   // the window closed with devices still deferred or not yet reached
        }
        $this->sql->run('UPDATE rmm_schedule_runs SET cursor_device_id = ?, targeted = targeted + ?, jobs_created = jobs_created + ?, skipped_overlap = skipped_overlap + ?, skipped_gate = skipped_gate + ?, skipped_other = skipped_other + ?'
            . ($state === null ? '' : ', state = ?, finished_at = ?') . ' WHERE run_id = ?',
            [$useCursor ? $pageCursor : 0, $targeted, $created, $skipped['overlap'], $skipped['gate'], $skipped['other'], ...($state === null ? [] : [$state, gmdate('Y-m-d H:i:s', $now)]), $runId]);

        return ['created' => $created, 'skipped' => array_sum($skipped), 'closed' => $state !== null];
    }

    /**
     * @param list<array<string,mixed>> $devices
     * @param array<string,mixed> $s the schedule row
     * @param array{script:array<string,mixed>,version:array<string,mixed>,schema:list<array<string,mixed>>} $loaded
     * @param array<array-key,mixed> $params
     * @param array<string,mixed> $schedule the schedule as the API shows it
     * @param array{cursor:int,remaining:int,deferred:bool,targeted:int,created:int,overlap:int,gate:int,other:int} $st
     */
    private function processPage(array $devices, array $s, array $loaded, array $params, string $slot, int $slotTs, int $runId, array $schedule, array &$st): void
    {
        foreach ($devices as $d) {
            $id = (int) $d['device_id'];
            $st['cursor'] = $id;
            $key = JobExtras::idemKey((int) $s['schedule_id'], $slot, $id);
            $decision = $this->gate === null ? ScheduleGateInterface::RUN : $this->gate->decide($d, $schedule, $slotTs);
            if ($decision === ScheduleGateInterface::DEFER) {
                $st['deferred'] = true;   // not marked: asked again next pass, until the window closes
                continue;
            }
            ++$st['targeted'];
            --$st['remaining'];
            if ($decision === ScheduleGateInterface::SKIP) {
                $this->extras->markHandled($key, $id, (int) $s['schedule_id'], $runId);
                ++$st['gate'];
            } elseif ($s['overlap'] === 'skip' && $this->unfinished((int) $s['schedule_id'], $id)) {
                $this->extras->markHandled($key, $id, (int) $s['schedule_id'], $runId);
                ++$st['overlap'];
            } else {
                $prep = $this->runner->prepare($loaded, $d, $params, (int) $s['timeout_s']);
                $r = $prep['ok'] ? $this->runner->dispatch($loaded, $d, $prep, (int) $s['created_by'], 'schedule',
                    ['schedule_id' => (int) $s['schedule_id'], 'run_id' => $runId, 'idem_key' => $key, 'expires_in_s' => (int) $s['expires_s']]) : ['ok' => false];
                if (!$r['ok']) {
                    $this->extras->markHandled($key, $id, (int) $s['schedule_id'], $runId);
                    ++$st['other'];
                } elseif (empty($r['duplicate'])) {
                    ++$st['created'];
                }
            }
            if ($st['remaining'] <= 0) {
                break;
            }
        }
    }

    private function unfinished(int $scheduleId, int $deviceId): bool
    {
        return $this->sql->one("SELECT e.job_id FROM rmm_job_extra e JOIN endpoint_agent_jobs j ON j.job_id = e.job_id
            WHERE e.schedule_id = ? AND e.device_id = ? AND j.state IN ('queued','running') LIMIT 1", [$scheduleId, $deviceId]) !== null;
    }

    private function close(int $runId, string $state): void
    {
        $this->sql->run('UPDATE rmm_schedule_runs SET state = ?, finished_at = ? WHERE run_id = ?', [$state, $this->sql->utcNow(), $runId]);
    }

    /** Remove run records and job sidecars older than the job retention (the jobs themselves are pruned by the job retention too). */
    public function prune(int $days): int
    {
        $before = $this->sql->utcAt(-$days * 86400);
        $n = $this->sql->run("DELETE FROM rmm_schedule_runs WHERE state <> 'running' AND started_at < ? LIMIT 5000", [$before]);
        $n += $this->sql->run('DELETE FROM rmm_job_extra WHERE created_at < ? LIMIT 5000', [$before]);

        return $n;
    }
}
