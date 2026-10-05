<?php

declare(strict_types=1);

namespace RivetCore\Jobs\Migration;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Migration\MigrationInterface;

/**
 * integration_jobs is Core-owned. RivetIT created this exact table in its own 2.6.52 migration; IF NOT EXISTS
 * makes this a no-op there and creates it on RivetMSP. Keep identical to RivetIT's db.sql.
 */
final class Migration0002IntegrationJobs implements MigrationInterface
{
    public function id(): string
    {
        return '0002_integration_jobs';
    }

    public function up(DatabaseInterface $database): void
    {
        $database->execute(
            "CREATE TABLE IF NOT EXISTS `integration_jobs` (
                `job_id` int(11) NOT NULL AUTO_INCREMENT,
                `integration_id` int(11) DEFAULT NULL,
                `job_type` varchar(100) NOT NULL,
                `resource_type` varchar(100) DEFAULT NULL,
                `status` enum('pending','running','completed','failed','dead_letter') NOT NULL DEFAULT 'pending',
                `priority` int(11) NOT NULL DEFAULT 0,
                `attempts` int(11) NOT NULL DEFAULT 0,
                `max_attempts` int(11) NOT NULL DEFAULT 5,
                `available_at` datetime NOT NULL DEFAULT current_timestamp(),
                `started_at` datetime DEFAULT NULL,
                `completed_at` datetime DEFAULT NULL,
                `payload` text DEFAULT NULL,
                `result` text DEFAULT NULL,
                `error` text DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`job_id`),
                KEY `idx_integration_jobs_status_available` (`status`,`available_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }
}
