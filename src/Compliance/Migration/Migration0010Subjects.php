<?php

declare(strict_types=1);

namespace RivetCore\Compliance\Migration;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Migration\MigrationInterface;

/**
 * Compliance for subjects other than the installation itself (an MSP's customers). subject_id 0 is the installation, so every existing
 * row keeps its meaning. compliance_subjects holds the chosen frameworks and what is shared with that subject.
 *
 * @internal
 */
final class Migration0010Subjects implements MigrationInterface
{
    public function id(): string
    {
        return '0010_compliance_subjects';
    }

    public function up(DatabaseInterface $database): void
    {
        foreach (['compliance_attestations', 'compliance_snapshots'] as $table) {
            $has = $database->fetchOne(
                'SELECT 1 AS x FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
                [$table, 'subject_id']
            );
            if ($has === null) {
                $database->execute("ALTER TABLE `$table` ADD COLUMN `subject_id` int(11) NOT NULL DEFAULT 0");
                $database->execute("ALTER TABLE `$table` ADD INDEX `idx_{$table}_subject` (`subject_id`)");
            }
        }
        $database->execute(
            "CREATE TABLE IF NOT EXISTS `compliance_subjects` (
                `subject_id` int(11) NOT NULL,
                `frameworks` varchar(100) NOT NULL DEFAULT '',
                `shared_snapshot_id` int(11) DEFAULT NULL,
                `shared_note` text DEFAULT NULL,
                `shared_by` int(11) DEFAULT NULL,
                `shared_at` datetime DEFAULT NULL,
                `updated_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`subject_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }
}
