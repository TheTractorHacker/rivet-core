<?php

declare(strict_types=1);

namespace RivetCore\Jobs;

use RivetCore\Database\DatabaseInterface;

/**
 * DB-backed job queue on the Core-owned `integration_jobs` table. Foundation for async integration work
 * (directory sync, RMM, webhooks); start here and move to a long-running worker only if scale requires it.
 *
 * All timestamps come from the database clock (NOW()), so enqueue, due-checks and backoff agree no matter what
 * timezone PHP runs in.
 *
 * claim() is safe for concurrent workers: each candidate is claimed with a conditional UPDATE
 * (status must still be 'pending'), and only rows this call actually won are returned.
 */
final class JobQueue
{
    /** Minutes to wait before retry n (1-based); anything past the list waits the last value. */
    private const BACKOFF_MINUTES = [1, 5, 30, 120];

    public function __construct(private DatabaseInterface $database)
    {
    }

    public function enqueue(string $jobType, array $payload = [], ?int $integrationId = null, ?string $resourceType = null, int $priority = 0, int $maxAttempts = 5): int
    {
        $result = $this->database->execute(
            'INSERT INTO integration_jobs (integration_id, job_type, resource_type, priority, max_attempts, payload)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$integrationId, $jobType, $resourceType, $priority, $maxAttempts, json_encode($payload)]
        );

        return (int) $result->insertId;
    }

    /**
     * Claims up to $limit pending, due jobs and marks them 'running' (attempts + 1). The returned rows describe
     * the claimed state: status 'running' and the incremented attempts, so pass $job['attempts'] straight to
     * markFailed().
     *
     * @return list<array<string,mixed>>
     */
    public function claim(int $limit = 10): array
    {
        $candidates = $this->database->fetchAll(
            "SELECT * FROM integration_jobs
             WHERE status = 'pending' AND available_at <= NOW()
             ORDER BY priority DESC, job_id ASC
             LIMIT ?",
            [max(0, $limit)]
        );

        $claimed = [];
        foreach ($candidates as $job) {
            $won = $this->database->execute(
                "UPDATE integration_jobs SET status = 'running', started_at = NOW(), attempts = attempts + 1
                 WHERE job_id = ? AND status = 'pending'",
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

    public function markCompleted(int $jobId, array $result = []): void
    {
        $this->database->execute(
            "UPDATE integration_jobs SET status = 'completed', completed_at = NOW(), result = ? WHERE job_id = ?",
            [json_encode($result), $jobId]
        );
    }

    /**
     * @param int $attempts the job's attempts after claim() (1 on the first failure)
     */
    public function markFailed(int $jobId, string $error, int $attempts, int $maxAttempts): void
    {
        $status = $attempts >= $maxAttempts ? 'dead_letter' : 'pending';
        $minutes = self::BACKOFF_MINUTES[max(0, $attempts - 1)] ?? self::BACKOFF_MINUTES[array_key_last(self::BACKOFF_MINUTES)];
        $now = $this->database->fetchOne('SELECT NOW() AS n');
        $availableAt = (new \DateTimeImmutable((string) $now['n']))->modify("+{$minutes} minutes")->format('Y-m-d H:i:s');

        $this->database->execute(
            'UPDATE integration_jobs SET status = ?, error = ?, available_at = ? WHERE job_id = ?',
            [$status, $error, $availableAt, $jobId]
        );
    }

    /** Jobs stuck in 'running' (the worker died) go back to pending so another worker picks them up. @return int how many were released */
    public function requeueStale(int $minutes = 15): int
    {
        return $this->database->execute(
            "UPDATE integration_jobs SET status = 'pending', error = 'worker stopped before finishing; retrying'
             WHERE status = 'running' AND started_at < (NOW() - INTERVAL ? MINUTE)",
            [max(1, $minutes)]
        )->affectedRows;
    }

    /** @return array<string,int> counts by status */
    public function stats(): array
    {
        $out = ['pending' => 0, 'running' => 0, 'completed' => 0, 'failed' => 0, 'dead_letter' => 0];
        foreach ($this->database->fetchAll('SELECT status, COUNT(*) AS c FROM integration_jobs GROUP BY status') as $r) {
            $out[(string) $r['status']] = (int) $r['c'];
        }

        return $out;
    }

    /** @return list<array<string,mixed>> newest first, without the payload body */
    public function recent(int $limit = 50, ?string $status = null): array
    {
        $limit = max(1, min(500, $limit));
        if ($status !== null) {
            return $this->database->fetchAll('SELECT job_id, job_type, status, attempts, max_attempts, available_at, started_at, completed_at, error, created_at FROM integration_jobs WHERE status = ? ORDER BY job_id DESC LIMIT ?', [$status, $limit]);
        }

        return $this->database->fetchAll('SELECT job_id, job_type, status, attempts, max_attempts, available_at, started_at, completed_at, error, created_at FROM integration_jobs ORDER BY job_id DESC LIMIT ?', [$limit]);
    }

    /** Put a dead-lettered job back in the queue with a fresh set of attempts. */
    public function retry(int $jobId): bool
    {
        return $this->database->execute(
            "UPDATE integration_jobs SET status = 'pending', attempts = 0, available_at = NOW(), error = NULL WHERE job_id = ? AND status IN ('dead_letter','failed')",
            [$jobId]
        )->affectedRows === 1;
    }

    /** Delete finished jobs older than $days (completed only; dead letters stay until someone looks at them). @return int rows removed */
    public function purgeCompleted(int $days): int
    {
        return $days < 1 ? 0 : $this->database->execute("DELETE FROM integration_jobs WHERE status = 'completed' AND completed_at < (NOW() - INTERVAL ? DAY)", [$days])->affectedRows;
    }
}
