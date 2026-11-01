<?php

declare(strict_types=1);

namespace RivetCore\Audit\Migration;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Migration\MigrationInterface;

/**
 * Tamper evidence for audit_events (baseline control ERR-04). Additive and idempotent:
 *  - audit_events.prev_hash and audit_events.row_hash, both NULL for every existing row (history is never rewritten; RivetIT
 *    already has these two columns from its own chain, so the column step is a no-op there);
 *  - audit_chain_head, a single row that serialises writers (SELECT ... FOR UPDATE) and records the newest chained row;
 *  - audit_chain_checkpoint, one row per legitimate retention prune, so the chain survives pruning.
 *
 * @internal
 */
final class Migration0048AuditHashChain implements MigrationInterface
{
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    public function id(): string
    {
        return '0048_audit_hash_chain';
    }

    public function up(DatabaseInterface $database): void
    {
        foreach (['prev_hash', 'row_hash'] as $column) {
            $has = $database->fetchOne(
                "SELECT COUNT(*) AS c FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_events' AND COLUMN_NAME = ?",
                [$column]
            );
            if ((int) ($has['c'] ?? 0) === 0) {
                $database->execute("ALTER TABLE `audit_events` ADD COLUMN `$column` char(64) DEFAULT NULL");
            }
        }

        $database->execute(
            "CREATE TABLE IF NOT EXISTS `audit_chain_head` (
                `id` tinyint(4) NOT NULL,
                `head_audit_id` int(11) DEFAULT NULL,
                `head_hash` char(64) NOT NULL,
                `hash_mode` varchar(8) NOT NULL DEFAULT '',
                `key_fp` varchar(16) NOT NULL DEFAULT '',
                `updated_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
        $database->execute('INSERT IGNORE INTO `audit_chain_head` (`id`, `head_hash`) VALUES (1, ?)', [self::GENESIS]);

        $database->execute(
            "CREATE TABLE IF NOT EXISTS `audit_chain_checkpoint` (
                `checkpoint_id` int(11) NOT NULL AUTO_INCREMENT,
                `anchor_hash` char(64) NOT NULL,
                `rows_pruned` int(11) NOT NULL DEFAULT 0,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`checkpoint_id`),
                KEY `idx_audit_chain_checkpoint_anchor` (`anchor_hash`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }
}
