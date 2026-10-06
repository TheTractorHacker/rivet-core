<?php

declare(strict_types=1);

namespace RivetCore\Audit\Migration;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Migration\MigrationInterface;

/**
 * audit_events is Core-owned. RivetIT already created this exact table in its
 * own 2.6.51 migration; IF NOT EXISTS makes this a no-op there and creates it
 * on RivetMSP. The definition must stay identical to RivetIT's db.sql.
 *
 * @internal
 */
final class Migration0001AuditEvents implements MigrationInterface
{
    public function id(): string
    {
        return '0001_audit_events';
    }

    public function up(DatabaseInterface $database): void
    {
        $database->execute(
            "CREATE TABLE IF NOT EXISTS `audit_events` (
                `audit_id` int(11) NOT NULL AUTO_INCREMENT,
                `event_type` varchar(100) NOT NULL,
                `actor_user_id` int(11) DEFAULT NULL,
                `entity_type` varchar(100) DEFAULT NULL,
                `entity_id` varchar(64) DEFAULT NULL,
                `action` varchar(50) NOT NULL,
                `summary` varchar(500) DEFAULT NULL,
                `metadata_json` text DEFAULT NULL,
                `ip_address` varchar(64) DEFAULT NULL,
                `user_agent` varchar(255) DEFAULT NULL,
                `request_id` varchar(64) DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`audit_id`),
                KEY `idx_audit_events_type_created` (`event_type`,`created_at`),
                KEY `idx_audit_events_entity` (`entity_type`,`entity_id`),
                KEY `idx_audit_events_actor` (`actor_user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }
}
