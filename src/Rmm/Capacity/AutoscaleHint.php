<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Capacity;

/**
 * The `rmm.ingest` autoscaling hint (scaling item S6): from the queue depth, the arrival and completion rates and the measured capacity of ONE worker, how many
 * workers the backlog calls for and whether to add or remove one. A pure function (same inputs, same output) so it is unit-testable and an autoscaler, a systemd
 * template unit counter or a human can all read the same number; {@see exposition()} renders it in the Prometheus text format for a scraper or a node_exporter
 * textfile collector.
 *
 * The model: the workers must absorb the arrivals AND clear the existing backlog within {@see DRAIN_TARGET_S}, with {@see HEADROOM} to spare:
 * `desired = ceil((arrival + pending / 60) x 1.5 / per_worker)`, at least 1. The hint is `up` when the oldest pending job has waited {@see URGENT_AGE_S} seconds or
 * more, or when more than one batch is pending and the backlog is not shrinking; `down` when nothing is pending, the queue has been calm and the
 * desired number is below the current one; `hold` otherwise. When the caller does not say how many workers run, the count is estimated from the jobs running right now
 * and only ever used to suggest `up` (an idle estimate would otherwise always say `down`).
 *
 * @api
 */
final class AutoscaleHint
{
    public const DRAIN_TARGET_S = 60;
    public const HEADROOM = 1.5;
    public const URGENT_AGE_S = 30;
    /** Jobs one drain() worker finishes per second (26 to 31 on the scratch host with 2 and 4 workers, docs/rmm/CAPACITY.md section 14; a conservative round number); override with the measured value of your hardware. */
    public const DEFAULT_PER_WORKER_JOBS_PER_S = 25.0;

    /**
     * @param array{pending:int,running:int,oldest_pending_age_s:int,dead_letter?:int,arrival_per_s:float,completion_per_s:float,per_worker_jobs_per_s?:float,workers?:?int,batch?:int,max_workers?:int} $f
     * @return array{hint:string,reason:string,workers_desired:int,workers_current:?int,pending:int,running:int,oldest_pending_age_s:int,arrival_per_s:float,completion_per_s:float,drain_eta_s:?int,per_worker_jobs_per_s:float}
     */
    public static function compute(array $f): array
    {
        $perWorker = max(1.0, (float) ($f['per_worker_jobs_per_s'] ?? self::DEFAULT_PER_WORKER_JOBS_PER_S));
        $pending = max(0, $f['pending']);
        $arrival = max(0.0, $f['arrival_per_s']);
        $completion = max(0.0, $f['completion_per_s']);
        $batch = max(1, (int) ($f['batch'] ?? IngestQueue::BATCH));
        $max = max(1, (int) ($f['max_workers'] ?? 64));
        $desired = (int) min($max, max(1, ceil(($arrival + $pending / self::DRAIN_TARGET_S) * self::HEADROOM / $perWorker)));
        $known = $f['workers'] ?? null;
        $current = $known ?? ($f['running'] > 0 ? (int) ceil($f['running'] / $batch) : null);
        $net = $completion - $arrival;
        $eta = $pending === 0 ? 0 : ($net > 0.001 ? (int) ceil($pending / $net) : null);
        $hint = 'hold';
        $reason = 'The queue keeps up.';
        if ($f['oldest_pending_age_s'] >= self::URGENT_AGE_S) {
            $hint = 'up';
            $reason = 'The oldest pending job has waited ' . $f['oldest_pending_age_s'] . ' s.';
        } elseif ($pending > $batch && $eta === null) {
            $hint = 'up';
            $reason = 'The backlog of ' . $pending . ' jobs is not shrinking.';
        } elseif ($pending === 0 && $f['running'] === 0 && $known !== null && $known > $desired && $arrival * self::HEADROOM / $perWorker < $known - 1) {
            $hint = 'down';
            $reason = 'The queue is empty and ' . $desired . ' worker(s) would carry the arrival rate.';
        }

        return ['hint' => $hint, 'reason' => $reason, 'workers_desired' => $desired, 'workers_current' => $current, 'pending' => $pending, 'running' => $f['running'],
            'oldest_pending_age_s' => $f['oldest_pending_age_s'], 'arrival_per_s' => round($arrival, 3), 'completion_per_s' => round($completion, 3), 'drain_eta_s' => $eta,
            'per_worker_jobs_per_s' => $perWorker];
    }

    /**
     * Prometheus text exposition of the hint and the queue gauges.
     *
     * @param array<string,mixed> $hint {@see compute()}
     * @param int $deadLetter dead-lettered rmm.ingest jobs
     */
    public static function exposition(array $hint, int $deadLetter = 0): string
    {
        $up = ['up' => 1, 'hold' => 0, 'down' => -1][(string) $hint['hint']] ?? 0;
        $rows = [
            ['rmm_ingest_pending_jobs', 'Pending rmm.ingest jobs.', $hint['pending']],
            ['rmm_ingest_running_jobs', 'Running rmm.ingest jobs.', $hint['running']],
            ['rmm_ingest_dead_letter_jobs', 'Dead-lettered rmm.ingest jobs.', $deadLetter],
            ['rmm_ingest_oldest_pending_age_seconds', 'Age of the oldest pending rmm.ingest job.', $hint['oldest_pending_age_s']],
            ['rmm_ingest_arrival_per_second', 'rmm.ingest jobs enqueued per second (last minute).', $hint['arrival_per_s']],
            ['rmm_ingest_completion_per_second', 'rmm.ingest jobs completed per second (last minute).', $hint['completion_per_s']],
            ['rmm_ingest_desired_workers', 'Workers the backlog calls for.', $hint['workers_desired']],
            ['rmm_ingest_scale_hint', 'Autoscaling hint: 1 add workers, 0 hold, -1 remove one.', $up],
        ];
        $out = '';
        foreach ($rows as [$name, $help, $v]) {
            $out .= "# HELP $name $help\n# TYPE $name gauge\n$name " . (is_float($v) ? rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.') : (string) (int) $v) . "\n";
        }

        return $out;
    }
}
