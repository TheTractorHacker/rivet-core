<?php

declare(strict_types=1);

namespace RivetCore\Retention;

use RivetCore\Database\DatabaseInterface;

/**
 * Prunes the Core-owned log tables so they cannot grow without bound. The edition decides the horizons (in days) and when
 * to call it (its hourly cron); a horizon below 1 means "keep everything" for that table. Use
 * Compliance\RetentionPolicy::effectiveDays() to turn a stored setting into the horizon to pass here, so a compliance
 * preset's minimum is always honoured.
 *
 * Only these rows are ever deleted, with fixed SQL (no identifiers come from the caller):
 *  - audit_events older than the audit horizon (which may differ from the others);
 *  - webhook_deliveries older than the horizon;
 *  - integration_jobs that are finished (completed or dead_letter) and older than the horizon.
 * Pending and running jobs are never touched.
 *
 * @api
 */
final class RetentionService
{
    public function __construct(private DatabaseInterface $database)
    {
    }

    /** Rows deleted per statement, so a large table never holds a long lock. */
    public const DEFAULT_BATCH = 5000;

    /**
     * @param int $days horizon for webhook deliveries and finished jobs (and for the audit trail when $auditDays is null)
     * @param int|null $auditDays separate horizon for the audit trail; null means "same as $days"
     * @param int|null $deliveryDays separate horizon for webhook deliveries; null means "same as $days"
     * @param int|null $jobDays separate horizon for completed/dead-letter jobs; null means "same as $days"
     * @param int $batchSize rows removed per DELETE statement (the loop repeats until a table is clear)
     * @return array<string,int> rows deleted per table; a table whose horizon is below 1 is skipped and not listed
     */
    public function prune(int $days, ?int $auditDays = null, ?int $deliveryDays = null, ?int $jobDays = null, int $batchSize = self::DEFAULT_BATCH): array
    {
        $batchSize = max(1, $batchSize);
        $deleted = [];
        foreach ($this->targets($days, $auditDays, $deliveryDays, $jobDays) as $table => [$where, $params]) {
            $total = 0;
            do {
                $n = $this->database->execute("DELETE FROM {$table} WHERE {$where} LIMIT {$batchSize}", $params)->affectedRows;
                $total += $n;
            } while ($n >= $batchSize);
            $deleted[$table] = $total;
        }

        return $deleted;
    }

    /**
     * Dry run: how many rows prune() with the same arguments WOULD delete, per table, without deleting anything.
     *
     * @return array<string,int>
     */
    public function plan(int $days, ?int $auditDays = null, ?int $deliveryDays = null, ?int $jobDays = null): array
    {
        $out = [];
        foreach ($this->targets($days, $auditDays, $deliveryDays, $jobDays) as $table => [$where, $params]) {
            $out[$table] = (int) ($this->database->fetchOne("SELECT COUNT(*) AS c FROM {$table} WHERE {$where}", $params)['c'] ?? 0);
        }

        return $out;
    }

    /** @return array<string, array{0:string, 1:list<string>}> table => [fixed WHERE clause, params] for every table with a horizon of 1+ days */
    private function targets(int $days, ?int $auditDays, ?int $deliveryDays, ?int $jobDays): array
    {
        $horizons = [
            'audit_events' => $auditDays ?? $days,
            'webhook_deliveries' => $deliveryDays ?? $days,
            'integration_jobs' => $jobDays ?? $days,
        ];
        if (max($horizons) < 1) {
            return [];
        }

        // The database clock, so the horizon agrees with the timestamps it wrote (see JobQueue).
        $now = $this->database->fetchOne('SELECT NOW() AS n');
        $cutoff = static fn (int $d): string => (new \DateTimeImmutable((string) ($now['n'] ?? 'now')))->modify("-{$d} days")->format('Y-m-d H:i:s');

        $out = [];
        if ($horizons['audit_events'] >= 1) {
            $out['audit_events'] = ['created_at < ?', [$cutoff($horizons['audit_events'])]];
        }
        if ($horizons['webhook_deliveries'] >= 1) {
            $out['webhook_deliveries'] = ['created_at < ?', [$cutoff($horizons['webhook_deliveries'])]];
        }
        if ($horizons['integration_jobs'] >= 1) {
            $out['integration_jobs'] = ["status IN ('completed', 'dead_letter') AND created_at < ?", [$cutoff($horizons['integration_jobs'])]];
        }

        return $out;
    }
}
