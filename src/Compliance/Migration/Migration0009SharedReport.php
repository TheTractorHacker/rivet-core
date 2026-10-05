<?php

declare(strict_types=1);

namespace RivetCore\Compliance\Migration;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Migration\MigrationInterface;

/** compliance_shared_report: at most one row (id 1), the snapshot an administrator chose to show on the portal. */
final class Migration0009SharedReport implements MigrationInterface
{
    public function id(): string
    {
        return '0009_compliance_shared_report';
    }

    public function up(DatabaseInterface $database): void
    {
        $database->execute(
            "CREATE TABLE IF NOT EXISTS `compliance_shared_report` (
                `shared_id` tinyint(4) NOT NULL,
                `snapshot_id` int(11) NOT NULL,
                `note` text DEFAULT NULL,
                `published_by` int(11) DEFAULT NULL,
                `published_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`shared_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }
}
