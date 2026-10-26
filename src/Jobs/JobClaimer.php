<?php

declare(strict_types=1);

namespace RivetCore\Jobs;

use RivetCore\Database\DatabaseInterface;

/**
 * Claims due jobs from `integration_jobs` for several workers at once without handing one job to two of them (scaling item S6).
 *
 * Strategy `skip_locked` (MariaDB 10.6+, MySQL 8.0.1+): one transaction with `SELECT ... FOR UPDATE SKIP LOCKED` and ONE `UPDATE ... WHERE job_id IN (...)`. A worker never waits for
 * another and never loses a race: rows another worker holds are simply skipped. Strategy `lease` (any other server, or when the first statement is refused): the claim is the
 * conditional `UPDATE ... WHERE job_id = ? AND status = 'pending'` that {@see JobQueue} always used, over a candidate window twice the size of the batch so a lost race still leaves
 * enough to fill the batch. In both strategies a claimed job is a LEASE: it is 'running' with a start time and a heartbeat, and {@see JobQueue::requeueStale()} (or
 * {@see \RivetCore\Rmm\Capacity\IngestQueue::reclaimStale()} with a short lease) puts the work of a worker that died back in the queue.
 *
 * An optional per-queue concurrency limit (`$maxRunning` > 0) caps how many jobs of the type may be running at once ACROSS ALL WORKERS: the count and the claim happen under a
 * short server-side named lock, so the limit is exact, not advisory. A claim that cannot take the lock within two seconds returns nothing and the caller tries again.
 *
 * @api
 */
final class JobClaimer
{
    public const SKIP_LOCKED = 'skip_locked';
    public const LEASE = 'lease';
    public const LOCK_PREFIX = 'rivetcore_job_claim_';

    private ?string $strategy;
    /** @var array<string,string> version string => strategy, shared by every instance of the process */
    private static array $detected = [];

    /**
     * @param string|null $strategy force 'skip_locked' or 'lease'; null detects it from the server version
     */
    public function __construct(private readonly DatabaseInterface $database, private readonly bool $heartbeat, ?string $strategy = null)
    {
        $this->strategy = $strategy;
    }

    /** True for MariaDB 10.6+ and MySQL 8.0.1+, the first releases with SKIP LOCKED. */
    public static function versionSupportsSkipLocked(string $version): bool
    {
        if (preg_match('/^(?:5\.5\.5-)?(\d+)\.(\d+)\.(\d+)/', $version, $m) !== 1) {
            return false;
        }
        [$major, $minor, $patch] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        if (stripos($version, 'mariadb') !== false) {
            return $major > 10 || ($major === 10 && $minor >= 6);
        }

        return $major > 8 || ($major === 8 && ($minor > 0 || $patch >= 1));
    }

    public function strategy(): string
    {
        if ($this->strategy !== null) {
            return $this->strategy;
        }
        $row = $this->database->fetchOne('SELECT VERSION() AS v');
        $version = (string) ($row['v'] ?? '');

        return $this->strategy = self::$detected[$version] ??= (self::versionSupportsSkipLocked($version) ? self::SKIP_LOCKED : self::LEASE);
    }

    /**
     * @param int $limit most jobs to claim
     * @param string|null $jobType only this type (null = any, in priority order)
     * @param int $maxRunning 0 = no limit, otherwise the most jobs of $jobType (any type when null) that may be running afterwards
     * @return list<array<string,mixed>> the claimed rows as they are now: status 'running' and the incremented attempts
     */
    public function claim(int $limit, ?string $jobType = null, int $maxRunning = 0): array
    {
        $limit = max(0, $limit);
        if ($limit === 0) {
            return [];
        }
        if ($maxRunning <= 0) {
            return $this->claimBatch($limit, $jobType);
        }
        $name = self::LOCK_PREFIX . ($jobType ?? '*');
        $got = $this->database->fetchOne("SELECT GET_LOCK(CONCAT(?, ':', MD5(IFNULL(DATABASE(), ''))), 2) AS l", [$name]);
        if ((int) ($got['l'] ?? 0) !== 1) {
            return [];
        }
        try {
            $running = (int) ($this->database->fetchOne(
                "SELECT COUNT(*) AS c FROM integration_jobs WHERE status = 'running'" . ($jobType === null ? '' : ' AND job_type = ?'),
                $jobType === null ? [] : [$jobType]
            )['c'] ?? 0);

            return $this->claimBatch(min($limit, max(0, $maxRunning - $running)), $jobType);
        } finally {
            $this->database->fetchOne("SELECT RELEASE_LOCK(CONCAT(?, ':', MD5(IFNULL(DATABASE(), '')))) AS r", [$name]);
        }
    }

    /** @return list<array<string,mixed>> */
    private function claimBatch(int $limit, ?string $jobType): array
    {
        if ($limit <= 0) {
            return [];
        }
        if ($this->strategy() === self::SKIP_LOCKED) {
            try {
                return $this->claimSkipLocked($limit, $jobType);
            } catch (DatabaseCapabilityException) {
                $this->strategy = self::LEASE;
            }
        }

        return $this->claimLease($limit, $jobType);
    }

    /**
     * Candidates come from a plain (non-locking) read in claim order; only the rows this worker is about to take are then locked, a chunk at a time, with
     * `FOR UPDATE SKIP LOCKED`, and re-checked as still pending under the lock. A single `ORDER BY priority DESC, job_id ... LIMIT n FOR UPDATE SKIP LOCKED` would
     * look simpler, but the server sorts AFTER it has locked every pending row it read, so one worker would lock the whole backlog and every other worker would skip all of it.
     * The candidate window is eight batches deep, so several workers that read the same window each find unlocked rows further down it.
     *
     * The transaction runs at READ COMMITTED (no gap locks: workers moving jobs from 'pending' to 'running' in the same index range do not deadlock each other) and, should the
     * server still pick it as a deadlock victim, is tried again: nothing was claimed, so repeating it is safe.
     *
     * @return list<array<string,mixed>>
     */
    private function claimSkipLocked(int $limit, ?string $jobType): array
    {
        $hb = $this->heartbeat ? ', heartbeat_at = NOW()' : '';
        for ($attempt = 1;; ++$attempt) {
            try {
                $ids = array_map(static fn (array $r): int => (int) $r['job_id'], $this->candidates($limit, $jobType, 8, false));
                if ($ids === []) {
                    return [];
                }
                try {
                    $this->database->execute('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
                } catch (\Throwable) {
                    // the caller is already inside a transaction (the level cannot change there): claim at the level it has
                }

                return $this->database->transaction(function () use ($limit, $ids, $hb): array {
                    $rows = [];
                    for ($pos = 0; count($rows) < $limit && $pos < count($ids);) {
                        $chunk = array_slice($ids, $pos, max(1, $limit - count($rows)));
                        $pos += count($chunk);
                        foreach ($this->database->fetchAll(
                            "SELECT * FROM integration_jobs WHERE job_id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ") AND status = 'pending' AND available_at <= NOW() FOR UPDATE SKIP LOCKED",
                            $chunk
                        ) as $r) {
                            $rows[] = $r;
                        }
                    }
                    if ($rows === []) {
                        return [];
                    }
                    $won = array_map(static fn (array $r): int => (int) $r['job_id'], $rows);
                    $this->database->execute(
                        "UPDATE integration_jobs SET status = 'running', started_at = NOW(), attempts = attempts + 1{$hb} WHERE job_id IN (" . implode(',', array_fill(0, count($won), '?')) . ')',
                        $won
                    );
                    foreach ($rows as $i => $r) {
                        $rows[$i]['status'] = 'running';
                        $rows[$i]['attempts'] = (int) $r['attempts'] + 1;
                    }
                    usort($rows, static fn (array $x, array $y): int => [(int) $y['priority'], (int) $x['job_id']] <=> [(int) $x['priority'], (int) $y['job_id']]);

                    return $rows;
                });
            } catch (\Throwable $e) {
                $m = $e->getMessage();
                if (stripos($m, 'SKIP LOCKED') !== false || (stripos($m, 'syntax') !== false && stripos($m, 'SKIP') !== false)) {
                    throw new DatabaseCapabilityException($m, 0, $e);
                }
                if ($attempt < 4 && (stripos($m, 'deadlock') !== false || stripos($m, 'lock wait timeout') !== false)) {
                    usleep(random_int(2000, 20000) * $attempt);
                    continue;
                }
                throw $e;
            }
        }
    }

    /**
     * Due pending jobs in claim order (priority, then age: FIFO inside a priority), a window of `$factor` batches.
     *
     * @return list<array<string,mixed>> full rows when $full, otherwise only job_id and priority
     */
    private function candidates(int $limit, ?string $jobType, int $factor, bool $full): array
    {
        $rows = $this->database->fetchAll(
            'SELECT ' . ($full ? '*' : 'job_id, priority') . " FROM integration_jobs WHERE status = 'pending' AND available_at <= NOW()" . ($jobType === null ? '' : ' AND job_type = ?')
            . ' ORDER BY priority DESC, job_id ASC LIMIT ?',
            $jobType === null ? [$limit * $factor] : [$jobType, $limit * $factor]
        );

        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function claimLease(int $limit, ?string $jobType): array
    {
        $hb = $this->heartbeat ? ', heartbeat_at = NOW()' : '';
        $claimed = [];
        foreach ($this->candidates($limit, $jobType, 2, true) as $job) {
            if (count($claimed) >= $limit) {
                break;
            }
            $won = $this->database->execute(
                "UPDATE integration_jobs SET status = 'running', started_at = NOW(), attempts = attempts + 1{$hb} WHERE job_id = ? AND status = 'pending'",
                [$job['job_id']]
            );
            if ($won->affectedRows === 1) {
                $job['status'] = 'running';
                $job['attempts'] = (int) $job['attempts'] + 1;
                $claimed[] = $job;
            }
        }
        usort($claimed, static fn (array $x, array $y): int => [(int) $y['priority'], (int) $x['job_id']] <=> [(int) $x['priority'], (int) $y['job_id']]);

        return $claimed;
    }
}
