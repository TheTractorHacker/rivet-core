<?php

declare(strict_types=1);

namespace RivetCore\ITSM\Migration;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Migration\MigrationInterface;

/**
 * problems and changes are Core-owned. RivetIT created these exact tables in its 2.6.62 migration; IF NOT EXISTS
 * makes this a no-op there. The edition-owned tickets.ticket_problem_id column is NOT part of this migration.
 */
final class Migration0004ProblemsAndChanges implements MigrationInterface
{
    public function id(): string
    {
        return '0004_problems_and_changes';
    }

    public function up(DatabaseInterface $database): void
    {
        $database->execute(
            "CREATE TABLE IF NOT EXISTS `changes` (
                `change_id` int(11) NOT NULL AUTO_INCREMENT,
                `title` varchar(255) NOT NULL,
                `reason` text DEFAULT NULL,
                `impact` text DEFAULT NULL,
                `risk` enum('low','medium','high') NOT NULL DEFAULT 'low',
                `implementation_plan` text DEFAULT NULL,
                `rollback_plan` text DEFAULT NULL,
                `scheduled_at` datetime DEFAULT NULL,
                `status` enum('draft','awaiting_approval','approved','scheduled','in_progress','successful','failed','rolled_back','cancelled') NOT NULL DEFAULT 'draft',
                `created_by` int(11) DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`change_id`),
                KEY `idx_changes_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
        $database->execute(
            "CREATE TABLE IF NOT EXISTS `problems` (
                `problem_id` int(11) NOT NULL AUTO_INCREMENT,
                `title` varchar(255) NOT NULL,
                `description` text DEFAULT NULL,
                `status` enum('open','investigating','resolved','closed') NOT NULL DEFAULT 'open',
                `change_problem_id` int(11) DEFAULT NULL,
                `created_by` int(11) DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                `resolved_at` datetime DEFAULT NULL,
                PRIMARY KEY (`problem_id`),
                KEY `idx_problems_status` (`status`),
                KEY `idx_problems_change` (`change_problem_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }
}
