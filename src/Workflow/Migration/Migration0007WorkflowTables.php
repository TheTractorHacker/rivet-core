<?php

declare(strict_types=1);

namespace RivetCore\Workflow\Migration;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Migration\MigrationInterface;

/** The four workflow tables are Core-owned. RivetIT created these exact tables in 2.6.65; IF NOT EXISTS makes this a no-op there. */
final class Migration0007WorkflowTables implements MigrationInterface
{
    public function id(): string
    {
        return '0007_workflow_tables';
    }

    public function up(DatabaseInterface $database): void
    {
        $database->execute(
            "CREATE TABLE IF NOT EXISTS `workflow_templates` (
                `workflow_template_id` int(11) NOT NULL AUTO_INCREMENT,
                `name` varchar(200) NOT NULL,
                `type` enum('onboarding','offboarding') NOT NULL,
                `description` text DEFAULT NULL,
                `is_active` tinyint(1) NOT NULL DEFAULT 1,
                `created_by` int(11) DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
                `archived_at` datetime DEFAULT NULL,
                PRIMARY KEY (`workflow_template_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
        $database->execute(
            "CREATE TABLE IF NOT EXISTS `workflow_template_tasks` (
                `template_task_id` int(11) NOT NULL AUTO_INCREMENT,
                `workflow_template_id` int(11) NOT NULL,
                `title` varchar(255) NOT NULL,
                `instructions` text DEFAULT NULL,
                `category` varchar(100) DEFAULT NULL,
                `default_owner` varchar(100) DEFAULT NULL,
                `required` tinyint(1) NOT NULL DEFAULT 1,
                `sort_order` int(11) NOT NULL DEFAULT 0,
                PRIMARY KEY (`template_task_id`),
                KEY `idx_template_task_template` (`workflow_template_id`, `sort_order`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
        $database->execute(
            "CREATE TABLE IF NOT EXISTS `workflow_runs` (
                `run_id` int(11) NOT NULL AUTO_INCREMENT,
                `workflow_template_id` int(11) DEFAULT NULL,
                `contact_id` int(11) NOT NULL,
                `type` enum('onboarding','offboarding') NOT NULL,
                `status` enum('in_progress','completed_with_exceptions','completed','cancelled') NOT NULL DEFAULT 'in_progress',
                `started_by` int(11) DEFAULT NULL,
                `started_at` datetime NOT NULL DEFAULT current_timestamp(),
                `completed_at` datetime DEFAULT NULL,
                `notes` text DEFAULT NULL,
                PRIMARY KEY (`run_id`),
                KEY `idx_workflow_runs_contact` (`contact_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
        $database->execute(
            "CREATE TABLE IF NOT EXISTS `workflow_run_tasks` (
                `run_task_id` int(11) NOT NULL AUTO_INCREMENT,
                `run_id` int(11) NOT NULL,
                `title` varchar(255) NOT NULL,
                `instructions` text DEFAULT NULL,
                `category` varchar(100) DEFAULT NULL,
                `default_owner` varchar(100) DEFAULT NULL,
                `required` tinyint(1) NOT NULL DEFAULT 1,
                `sort_order` int(11) NOT NULL DEFAULT 0,
                `status` enum('pending','completed','skipped') NOT NULL DEFAULT 'pending',
                `completed_by` int(11) DEFAULT NULL,
                `completed_at` datetime DEFAULT NULL,
                `skip_reason` varchar(500) DEFAULT NULL,
                PRIMARY KEY (`run_task_id`),
                KEY `idx_run_task_run` (`run_id`, `sort_order`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }
}
