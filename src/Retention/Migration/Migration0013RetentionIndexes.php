<?php

declare(strict_types=1);

namespace RivetCore\Retention\Migration;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Migration\MigrationInterface;

/**
 * Indexes for the retention prune (`created_at < ?`) and the audit date filters: audit_events and webhook_deliveries had
 * no index that starts with created_at, and integration_jobs has none for `status IN (...) AND created_at < ?`.
 * Additive and idempotent: each index is only created when information_schema says it is missing.
 *
 * @internal
 */
final class Migration0013RetentionIndexes implements MigrationInterface
{
    /** table => [index name => column list] */
    private const INDEXES = [
        'audit_events' => ['idx_audit_events_created' => '`created_at`'],
        'webhook_deliveries' => ['idx_webhook_deliveries_created' => '`created_at`'],
        'integration_jobs' => ['idx_integration_jobs_status_created' => '`status`, `created_at`'],
    ];

    public function id(): string
    {
        return '0013_retention_indexes';
    }

    public function up(DatabaseInterface $database): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $name => $columns) {
                $has = $database->fetchOne(
                    'SELECT COUNT(*) AS c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
                    [$table, $name]
                );
                if ((int) ($has['c'] ?? 0) === 0) {
                    $database->execute("ALTER TABLE `$table` ADD INDEX `$name` ($columns)");
                }
            }
        }
    }
}
