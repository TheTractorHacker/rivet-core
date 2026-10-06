<?php

declare(strict_types=1);

namespace RivetCore\Jobs;

/**
 * Passed to a job handler as its third argument. Timeouts are cooperative: PHP cannot safely interrupt a handler, so a
 * long handler calls checkpoint() between units of work. checkpoint() records a heartbeat (so the job is not reclaimed as
 * abandoned) and throws JobTimeout once the job's declared timeout is spent.
 *
 * @api
 */
final class JobContext
{
    /**
     * @param float|null $jobDeadline unix time (microseconds) after which the job counts as timed out; null = no per-job timeout
     * @param float|null $runDeadline unix time after which the worker run is over (no new jobs are started)
     * @param (callable(): void)|null $heartbeat
     */
    public function __construct(
        private int $jobId,
        private string $jobType,
        private ?float $jobDeadline,
        private ?float $runDeadline,
        private $heartbeat = null,
    ) {
    }

    public function jobId(): int
    {
        return $this->jobId;
    }

    public function jobType(): string
    {
        return $this->jobType;
    }

    /** Seconds left for this job: the smaller of its own timeout budget and the worker run budget; null when neither applies. */
    public function remainingSeconds(): ?float
    {
        $now = microtime(true);
        $left = null;
        foreach ([$this->jobDeadline, $this->runDeadline] as $deadline) {
            if ($deadline !== null) {
                $r = max(0.0, $deadline - $now);
                $left = $left === null ? $r : min($left, $r);
            }
        }

        return $left;
    }

    /** True once the job's own declared timeout has passed (the run budget does not make a job "expired"). */
    public function expired(): bool
    {
        return $this->jobDeadline !== null && microtime(true) >= $this->jobDeadline;
    }

    public function heartbeat(): void
    {
        if ($this->heartbeat !== null) {
            ($this->heartbeat)();
        }
    }

    /** Heartbeat, then throw JobTimeout if the job's timeout is spent. */
    public function checkpoint(): void
    {
        $this->heartbeat();
        if ($this->expired()) {
            throw new JobTimeout("Job {$this->jobId} ({$this->jobType}) exceeded its time budget");
        }
    }
}
