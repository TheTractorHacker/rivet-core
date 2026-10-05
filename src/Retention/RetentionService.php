<?php

declare(strict_types=1);

namespace RivetCore\Retention;

use RivetCore\Database\DatabaseInterface;

/**
 * Prunes the Core-owned log tables so they cannot grow without bound. The edition decides the horizon (its existing
 * "log retention" setting, in days) and when to call it (its hourly cron); a horizon below 1 means "keep everything"
 * and does nothing, matching how the legacy logs behave.
 *
 * Only these rows are ever deleted, with fixed SQL (no identifiers come from the caller):
 *  - audit_events and webhook_deliveries older than the horizon;
 *  - integration_jobs that are finished (completed or dead_letter) and older than the horizon.
 * Pending and running jobs are never touched.
 */
final class RetentionService
{
    public function __construct(private DatabaseInterface $database)
    {
    }

    /**
     * @return array<string,int> rows deleted per table (empty when $days < 1)
     */
    public function prune(int $days): array
    {
        if ($days < 1) {
            return [];
        }

        // The database clock, so the horizon agrees with the timestamps it wrote (see JobQueue).
        $now = $this->database->fetchOne('SELECT NOW() AS n');
        $cutoff = (new \DateTimeImmutable((string) $now['n']))->modify("-{$days} days")->format('Y-m-d H:i:s');

        return [
            'audit_events' => $this->database->execute('DELETE FROM audit_events WHERE created_at < ?', [$cutoff])->affectedRows,
            'webhook_deliveries' => $this->database->execute('DELETE FROM webhook_deliveries WHERE created_at < ?', [$cutoff])->affectedRows,
            'integration_jobs' => $this->database->execute(
                "DELETE FROM integration_jobs WHERE status IN ('completed', 'dead_letter') AND created_at < ?",
                [$cutoff]
            )->affectedRows,
        ];
    }
}
