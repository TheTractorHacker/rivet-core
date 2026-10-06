<?php

declare(strict_types=1);

namespace RivetCore\Jobs;

/**
 * Runs queued jobs: a registry of job type => handler. A handler receives the decoded payload and the job row; it returns an array
 * (stored as the job's result) or throws to fail the attempt, in which case the queue retries with backoff and finally dead-letters.
 * A handler may throw JobRetryLater-style failures simply by throwing any exception; one that wants NO retry throws
 * PermanentJobFailure. An unknown job type is dead-lettered at once with a clear error rather than retried or dropped.
 *
 * A handler may take a third argument, a JobContext: remainingSeconds(), heartbeat(), checkpoint(). Timeouts are cooperative:
 * a job whose declared timeout (register(..., $timeoutSeconds) or setDefaultTimeout()) is spent when it returns, or that
 * calls checkpoint() too late, is recorded as a failed attempt and retried with backoff. The worker never STARTS a job once
 * the run() time budget is spent; jobs it had already claimed are handed back untouched, and it keeps a heartbeat on them
 * so requeueStale() does not reclaim work from a live worker.
 *
 * @api
 */
final class JobWorker
{
    /** @var array<string, callable(array<string,mixed>, array<string,mixed>, JobContext): (array<string,mixed>|null)> */
    private array $handlers = [];

    /** @var array<string,int> job type => timeout seconds */
    private array $timeouts = [];

    private ?int $defaultTimeout = null;

    public function __construct(private JobQueue $queue)
    {
    }

    /**
     * @param callable(array<string,mixed>, array<string,mixed>, JobContext): (array<string,mixed>|null) $handler
     * @param int|null $timeoutSeconds time budget for one run of this type; null uses the default (none unless set)
     */
    public function register(string $jobType, callable $handler, ?int $timeoutSeconds = null): self
    {
        $this->handlers[$jobType] = $handler;
        if ($timeoutSeconds !== null && $timeoutSeconds > 0) {
            $this->timeouts[$jobType] = $timeoutSeconds;
        } else {
            unset($this->timeouts[$jobType]);
        }

        return $this;
    }

    /** Timeout for every type without its own; null or below 1 means no limit. */
    public function setDefaultTimeout(?int $seconds): self
    {
        $this->defaultTimeout = $seconds !== null && $seconds > 0 ? $seconds : null;

        return $this;
    }

    public function has(string $jobType): bool
    {
        return isset($this->handlers[$jobType]);
    }

    /** @return list<string> */
    public function types(): array
    {
        return array_keys($this->handlers);
    }

    /** Effective timeout in seconds for a job type, or null for none. */
    public function timeoutFor(string $jobType): ?int
    {
        return $this->timeouts[$jobType] ?? $this->defaultTimeout;
    }

    /**
     * Claim and run due jobs until $limit jobs have run or $seconds have passed. No job is started after the budget is spent.
     *
     * @return array{claimed:int, completed:int, retrying:int, dead:int, released:int, deferred:int}
     */
    public function run(int $limit = 20, int $seconds = 50): array
    {
        $out = ['claimed' => 0, 'completed' => 0, 'retrying' => 0, 'dead' => 0, 'released' => $this->queue->requeueStale(), 'deferred' => 0];
        $deadline = microtime(true) + max(1, $seconds);
        $remaining = max(1, $limit);

        while ($remaining > 0 && microtime(true) < $deadline) {
            $jobs = $this->queue->claim(min($remaining, 10));
            if ($jobs === []) {
                break;
            }
            $pending = array_map(static fn (array $j): int => (int) $j['job_id'], $jobs);
            foreach ($jobs as $job) {
                $id = (int) $job['job_id'];
                if (microtime(true) >= $deadline) {
                    // Out of budget: give the job back, attempt not spent.
                    if ($this->queue->release($id)) {
                        $out['deferred']++;
                    }
                    $pending = array_values(array_diff($pending, [$id]));
                    continue;
                }
                $remaining--;
                $out['claimed']++;
                // Keep the rest of the batch (and this job) visibly alive while earlier jobs run.
                if ($this->queue->heartbeat($pending) < count($pending) && $this->queue->heartbeat($id) < 1) {
                    $out['claimed']--;
                    $remaining++;
                    $pending = array_values(array_diff($pending, [$id]));
                    continue; // another worker reclaimed it; do not run it twice
                }
                $this->runOne($job, $out, $deadline);
                $pending = array_values(array_diff($pending, [$id]));
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $job
     * @param array<string,int> $out
     */
    private function runOne(array $job, array &$out, float $runDeadline): void
    {
        $id = (int) $job['job_id'];
        $type = (string) $job['job_type'];
        $attempts = (int) $job['attempts'];
        $max = (int) $job['max_attempts'];
        $handler = $this->handlers[$type] ?? null;
        if ($handler === null) {
            $this->queue->markFailed($id, "No handler registered for job type '{$type}'; dead-lettered (register a handler, then retry the job)", $max, $max, $attempts);
            $out['dead']++;

            return;
        }
        $timeout = $this->timeoutFor($type);
        $started = microtime(true);
        $queue = $this->queue;
        $context = new JobContext($id, $type, $timeout !== null ? $started + $timeout : null, $runDeadline, static function () use ($queue, $id): void {
            $queue->heartbeat($id);
        });
        $payload = json_decode((string) ($job['payload'] ?? ''), true);
        try {
            $result = $handler(is_array($payload) ? $payload : [], $job, $context);
            if ($timeout !== null && microtime(true) - $started > $timeout) {
                throw new JobTimeout(sprintf("Job exceeded its %ds timeout for '%s' (ran %.1fs)", $timeout, $type, microtime(true) - $started));
            }
            $this->queue->markCompleted($id, is_array($result) ? $result : [], $attempts);
            $out['completed']++;
        } catch (PermanentJobFailure $e) {
            $this->queue->markFailed($id, mb_substr($e->getMessage(), 0, 2000), $max, $max, $attempts);
            $out['dead']++;
        } catch (\Throwable $e) {
            $this->queue->markFailed($id, mb_substr($e->getMessage(), 0, 2000), $attempts, $max, $attempts);
            $attempts >= $max ? $out['dead']++ : $out['retrying']++;
        }
    }
}
