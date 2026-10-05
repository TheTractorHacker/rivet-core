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
 */
final class RetentionService
{
    public function __construct(private DatabaseInterface $database)
    {
    }

    /**
     * @param int $days horizon for webhook deliveries and finished jobs (and for the audit trail when $auditDays is null)
     * @param int|null $auditDays separate horizon for the audit trail; null means "same as $days"
     * @return array<string,int> rows deleted per table; a table whose horizon is below 1 is skipped and not listed
     */
    public function prune(int $days, ?int $auditDays = null): array
    {
        $auditDays ??= $days;
        if ($days < 1 && $auditDays < 1) {
            return [];
        }

        // The database clock, so the horizon agrees with the timestamps it wrote (see JobQueue).
        $now = $this->database->fetchOne('SELECT NOW() AS n');
        $cutoff = static fn (int $d): string => (new \DateTimeImmutable((string) $now['n']))->modify("-{$d} days")->format('Y-m-d H:i:s');

        $deleted = [];
        if ($auditDays >= 1) {
            $deleted['audit_events'] = $this->database->execute('DELETE FROM audit_events WHERE created_at < ?', [$cutoff($auditDays)])->affectedRows;
        }
        if ($days >= 1) {
            $deleted['webhook_deliveries'] = $this->database->execute('DELETE FROM webhook_deliveries WHERE created_at < ?', [$cutoff($days)])->affectedRows;
            $deleted['integration_jobs'] = $this->database->execute(
                "DELETE FROM integration_jobs WHERE status IN ('completed', 'dead_letter') AND created_at < ?",
                [$cutoff($days)]
            )->affectedRows;
        }

        return $deleted;
    }
}
