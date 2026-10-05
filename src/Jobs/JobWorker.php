<?php

declare(strict_types=1);

namespace RivetCore\Jobs;

/**
 * Runs queued jobs: a registry of job type => handler. A handler receives the decoded payload and the job row; it returns an array
 * (stored as the job's result) or throws to fail the attempt, in which case the queue retries with backoff and finally dead-letters.
 * A handler may throw JobRetryLater-style failures simply by throwing any exception; one that wants NO retry throws
 * PermanentJobFailure. An unknown job type fails loudly rather than being dropped.
 */
final class JobWorker
{
    /** @var array<string, callable(array<string,mixed>, array<string,mixed>): (array<string,mixed>|null)> */
    private array $handlers = [];

    public function __construct(private JobQueue $queue)
    {
    }

    /** @param callable(array<string,mixed>, array<string,mixed>): (array<string,mixed>|null) $handler */
    public function register(string $jobType, callable $handler): self
    {
        $this->handlers[$jobType] = $handler;

        return $this;
    }

    /** @return list<string> */
    public function types(): array
    {
        return array_keys($this->handlers);
    }

    /**
     * Claim and run due jobs until $limit jobs have run or $seconds have passed.
     *
     * @return array{claimed:int, completed:int, retrying:int, dead:int, released:int}
     */
    public function run(int $limit = 20, int $seconds = 50): array
    {
        $out = ['claimed' => 0, 'completed' => 0, 'retrying' => 0, 'dead' => 0, 'released' => $this->queue->requeueStale()];
        $deadline = time() + max(1, $seconds);
        $remaining = max(1, $limit);

        while ($remaining > 0 && time() < $deadline) {
            $jobs = $this->queue->claim(min($remaining, 10));
            if ($jobs === []) {
                break;
            }
            foreach ($jobs as $job) {
                $remaining--;
                $out['claimed']++;
                $this->runOne($job, $out);
            }
        }

        return $out;
    }

    /** @param array<string,mixed> $job @param array<string,int> $out */
    private function runOne(array $job, array &$out): void
    {
        $id = (int) $job['job_id'];
        $attempts = (int) $job['attempts'];
        $max = (int) $job['max_attempts'];
        $handler = $this->handlers[(string) $job['job_type']] ?? null;
        if ($handler === null) {
            $this->queue->markFailed($id, "No handler registered for job_type '{$job['job_type']}'", $max, $max);
            $out['dead']++;

            return;
        }
        $payload = json_decode((string) ($job['payload'] ?? ''), true);
        try {
            $result = $handler(is_array($payload) ? $payload : [], $job);
            $this->queue->markCompleted($id, is_array($result) ? $result : []);
            $out['completed']++;
        } catch (PermanentJobFailure $e) {
            $this->queue->markFailed($id, mb_substr($e->getMessage(), 0, 2000), $max, $max);
            $out['dead']++;
        } catch (\Throwable $e) {
            $this->queue->markFailed($id, mb_substr($e->getMessage(), 0, 2000), $attempts, $max);
            $attempts >= $max ? $out['dead']++ : $out['retrying']++;
        }
    }
}
