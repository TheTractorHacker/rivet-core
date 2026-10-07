<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Capacity;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Jobs\JobContext;
use RivetCore\Jobs\JobQueue;
use RivetCore\Jobs\JobWorker;
use RivetCore\Rmm\Checkin\CheckinService;
use RivetCore\Rmm\Contracts\RmmMetricSinkInterface;
use RivetCore\Rmm\RmmState;
use RivetCore\Rmm\Settings\RmmSettings;

/**
 * Queued ingest (scaling item S2): with `ingest_mode = queued` a check-in stores its idempotent (device_id, seq) row and the cheap
 * device-state writes inside the request, and hands the heavy part (inventory apply, metric samples to the edition's sink, link
 * health, check evaluation) to Core's {@see JobQueue} as ONE `rmm.ingest` job, written in the same transaction as the check-in row.
 * So a duplicate delivery of a (device_id, seq) can never enqueue twice, and a rolled-back request leaves no job.
 *
 * Two ways to run the jobs, which can be used together:
 *  - {@see register()}: the handler for Core's generic {@see JobWorker} (one job per call);
 *  - {@see drain()}: claims up to {@see BATCH} `rmm.ingest` jobs at once and merges the samples of all of them into ONE sink call.
 *
 * RETRIES. Every step of the heavy part is idempotent except the sink write, which is therefore done LAST. A failure anywhere before
 * it leaves nothing half-done that a retry cannot repeat; a failure of the sink call itself (assumed atomic) leaves no samples. The one
 * residual window (the worker dies after the sink returned and before the job is marked completed) can store one check-in's samples
 * twice; they are identical points, which the edition's rollups tolerate.
 *
 * DISABLED MODULE. While the module is off the handler RELEASES a job (back to pending, no attempt spent, not claimable for
 * {@see RELEASE_DELAY_S}) instead of failing it, so queued work survives a disable and is processed after the re-enable. Core's worker
 * would dead-letter a job with no handler; registering a releasing handler for every `rmm.*` type prevents that.
 *
 * @api
 */
final class IngestQueue
{
    public const JOB_TYPE = 'rmm.ingest';
    /** Every job type this module owns (the releasing handler is registered for each). */
    public const JOB_TYPES = [self::JOB_TYPE];
    /** Lower than the default 0 of interactive jobs: webhooks and automations are claimed first. */
    public const PRIORITY = -10;
    public const BATCH = 50;
    public const RELEASE_DELAY_S = 60;
    /** Completed rmm.ingest jobs are kept this long (the latency metric reads them) and then deleted: at 2.7 KB each they would otherwise be the biggest table. */
    public const COMPLETED_RETENTION_S = 600;
    public const PRUNE_BATCH = 5000;
    public const PRUNE_RUN_CAP = 200000;

    public function __construct(
        private readonly JobQueue $queue,
        private readonly DatabaseInterface $db,
        private readonly CheckinService $checkin,
        private readonly RmmMetricSinkInterface $metrics,
        private readonly RmmSettings $settings,
        private readonly RmmState $state,
    ) {
    }

    /**
     * Enqueue the heavy part of one check-in.
     *
     * @param array<string,mixed> $work
     */
    public function enqueue(array $work): int
    {
        $integration = (int) ($this->settings->get()['integration_id'] ?? 0);

        return $this->queue->enqueue(self::JOB_TYPE, $work, $integration > 0 ? $integration : null, 'rmm_checkin', self::PRIORITY);
    }

    /**
     * The backlog signal of the load shedder and the capacity panel.
     *
     * @return array{pending:int,running:int,dead_letter:int,oldest_pending_age_s:int,avg_latency_s:?float}
     */
    public function backlog(): array
    {
        $out = ['pending' => 0, 'running' => 0, 'dead_letter' => 0, 'oldest_pending_age_s' => 0, 'avg_latency_s' => null];
        $rows = $this->db->fetchAll(
            "SELECT status, COUNT(*) AS c, TIMESTAMPDIFF(SECOND, MIN(created_at), NOW()) AS age FROM integration_jobs
             WHERE job_type = ? AND status IN ('pending','running','dead_letter') GROUP BY status",
            [self::JOB_TYPE]
        );
        foreach ($rows as $r) {
            $status = (string) $r['status'];
            if (isset($out[$status])) {
                $out[$status] = (int) $r['c'];
            }
            if ($status === 'pending') {
                $out['oldest_pending_age_s'] = max(0, (int) $r['age']);
            }
        }
        // Ingest latency: enqueue to completion of the most recent finished jobs.
        $lat = $this->db->fetchOne(
            "SELECT AVG(TIMESTAMPDIFF(SECOND, created_at, completed_at)) AS l FROM
             (SELECT created_at, completed_at FROM integration_jobs WHERE job_type = ? AND status = 'completed' ORDER BY job_id DESC LIMIT 200) t",
            [self::JOB_TYPE]
        );
        if ($lat !== null && $lat['l'] !== null) {
            $out['avg_latency_s'] = round((float) $lat['l'], 2);
        }

        return $out;
    }

    /**
     * Delete completed `rmm.ingest` jobs older than {@see COMPLETED_RETENTION_S}, in batches of {@see PRUNE_BATCH} (at most {@see PRUNE_RUN_CAP} per call).
     * Core's own `JobQueue::purgeCompleted()` keeps a day at the least, which at 10,000 devices is several gigabytes of finished ingest jobs.
     * Pending, running and dead-lettered jobs and every other job type are never touched.
     *
     * @return int rows removed
     */
    public function pruneCompleted(): int
    {
        $total = 0;
        while ($total < self::PRUNE_RUN_CAP) {
            $n = $this->db->execute(
                "DELETE FROM integration_jobs WHERE job_type = ? AND status = 'completed' AND completed_at < (NOW() - INTERVAL ? SECOND) LIMIT " . self::PRUNE_BATCH,
                [self::JOB_TYPE, self::COMPLETED_RETENTION_S]
            )->affectedRows;
            $total += $n;
            if ($n < self::PRUNE_BATCH) {
                break;
            }
        }

        return $total;
    }

    // ------------------------------------------------------------------ the generic worker

    /** Register the handler on Core's job worker (releasing while the module is off). */
    public function register(JobWorker $worker): void
    {
        foreach (self::JOB_TYPES as $type) {
            $worker->register($type, fn (array $payload, array $job, JobContext $ctx): array => $this->handle($payload, $job), 120);
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $job the claimed row
     * @return array<string,mixed> the job result
     */
    public function handle(array $payload, array $job): array
    {
        if (!$this->state->enabled()) {
            $this->release((int) $job['job_id']);

            return ['released' => true];
        }
        $samples = $this->checkin->applyWork($payload, true);
        if ($samples === null) {
            return ['skipped' => 'device_gone'];
        }
        if ($samples !== []) {
            $this->metrics->ingest($samples, $this->settings->integrationId());
        }

        return ['device_id' => (int) ($payload['device_id'] ?? 0), 'seq' => (int) ($payload['seq'] ?? 0), 'samples' => count($samples)];
    }

    /** Hand a running job back untouched and keep it unclaimable for a while, in ONE statement (no window in which another worker could take it). */
    private function release(int $jobId): void
    {
        $hb = $this->queue->supportsHeartbeat() ? ', heartbeat_at = NULL' : '';
        $this->db->execute(
            "UPDATE integration_jobs SET status = 'pending', attempts = GREATEST(attempts - 1, 0), started_at = NULL{$hb}, available_at = NOW() + INTERVAL ? SECOND
             WHERE job_id = ? AND status = 'running'",
            [self::RELEASE_DELAY_S, $jobId]
        );
    }

    // ------------------------------------------------------------------ the batching worker

    /**
     * Claim up to $max due `rmm.ingest` jobs, apply them, and deliver the samples of all of them in ONE sink call.
     * Does nothing while the module is off. A job that fails is marked failed (retried with the queue's backoff) on its own; a failing
     * sink call fails every job of the batch that was waiting for it.
     *
     * @return array{claimed:int,completed:int,failed:int,skipped:int,samples:int,sink_calls:int}
     */
    public function drain(int $max = self::BATCH): array
    {
        $out = ['claimed' => 0, 'completed' => 0, 'failed' => 0, 'skipped' => 0, 'samples' => 0, 'sink_calls' => 0];
        if (!$this->state->enabled()) {
            return $out;
        }
        $jobs = $this->claim(max(1, min(self::BATCH, $max)));
        $out['claimed'] = count($jobs);
        $waiting = [];
        $merged = [];
        foreach ($jobs as $job) {
            $id = (int) $job['job_id'];
            $attempts = (int) $job['attempts'];
            $payload = json_decode((string) ($job['payload'] ?? ''), true);
            try {
                $samples = $this->checkin->applyWork(is_array($payload) ? $payload : [], true);
            } catch (\Throwable $e) {
                $this->queue->markFailed($id, mb_substr($e->getMessage(), 0, 2000), $attempts, (int) $job['max_attempts'], $attempts);
                ++$out['failed'];
                continue;
            }
            if ($samples === null) {
                $this->queue->markCompleted($id, ['skipped' => 'device_gone'], $attempts);
                ++$out['skipped'];
                continue;
            }
            foreach ($samples as $s) {
                $merged[] = $s;
            }
            $waiting[] = $job;
        }
        $deliverError = null;
        if ($merged !== []) {
            try {
                $this->metrics->ingest($merged, $this->settings->integrationId());
                ++$out['sink_calls'];
                $out['samples'] = count($merged);
            } catch (\Throwable $e) {
                $deliverError = mb_substr($e->getMessage(), 0, 2000);
            }
        }
        foreach ($waiting as $job) {
            $id = (int) $job['job_id'];
            $attempts = (int) $job['attempts'];
            if ($deliverError !== null) {
                $this->queue->markFailed($id, $deliverError, $attempts, (int) $job['max_attempts'], $attempts);
                ++$out['failed'];
            } else {
                $this->queue->markCompleted($id, ['batch' => count($jobs)], $attempts);
                ++$out['completed'];
            }
        }

        return $out;
    }

    /**
     * Claim due rmm.ingest jobs with the same conditional UPDATE {@see JobQueue::claim()} uses (only the jobs this call wins are returned).
     *
     * @return list<array<string,mixed>>
     */
    private function claim(int $limit): array
    {
        $candidates = $this->db->fetchAll(
            "SELECT * FROM integration_jobs WHERE job_type = ? AND status = 'pending' AND available_at <= NOW() ORDER BY priority DESC, job_id ASC LIMIT ?",
            [self::JOB_TYPE, $limit]
        );
        $hb = $this->queue->supportsHeartbeat() ? ', heartbeat_at = NOW()' : '';
        $claimed = [];
        foreach ($candidates as $job) {
            $won = $this->db->execute(
                "UPDATE integration_jobs SET status = 'running', started_at = NOW(), attempts = attempts + 1{$hb} WHERE job_id = ? AND status = 'pending'",
                [$job['job_id']]
            );
            if ($won->affectedRows === 1) {
                $job['status'] = 'running';
                $job['attempts'] = (int) $job['attempts'] + 1;
                $claimed[] = $job;
            }
        }

        return $claimed;
    }
}
