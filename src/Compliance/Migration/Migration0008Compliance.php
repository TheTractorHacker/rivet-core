<?php

declare(strict_types=1);

namespace RivetCore\Compliance\Migration;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Migration\MigrationInterface;

/** compliance_attestations (manual reviews, append-only) and compliance_snapshots (saved assessments) are Core-owned.
 *
 * @internal
 */
final class Migration0008Compliance implements MigrationInterface
{
    public function id(): string
    {
        return '0008_compliance';
    }

    public function up(DatabaseInterface $database): void
    {
        $database->execute(
            "CREATE TABLE IF NOT EXISTS `compliance_attestations` (
                `attestation_id` int(11) NOT NULL AUTO_INCREMENT,
                `item_id` varchar(64) NOT NULL,
                `reviewed_by` int(11) DEFAULT NULL,
                `reviewer_name` varchar(200) NOT NULL,
                `reviewed_on` date NOT NULL,
                `next_due_on` date DEFAULT NULL,
                `note` text DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`attestation_id`),
                KEY `idx_compliance_attest_item` (`item_id`, `attestation_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
        $database->execute(
            "CREATE TABLE IF NOT EXISTS `compliance_snapshots` (
                `snapshot_id` int(11) NOT NULL AUTO_INCREMENT,
                `taken_at` datetime NOT NULL DEFAULT current_timestamp(),
                `taken_by` int(11) DEFAULT NULL,
                `trigger_type` varchar(20) NOT NULL DEFAULT 'manual',
                `app_version` varchar(40) DEFAULT NULL,
                `summary_json` longtext DEFAULT NULL,
                `results_json` longtext DEFAULT NULL,
                PRIMARY KEY (`snapshot_id`),
                KEY `idx_compliance_snapshots_taken` (`taken_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }
}
