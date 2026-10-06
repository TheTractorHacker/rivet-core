<?php

declare(strict_types=1);

namespace RivetCore\Compliance\Migration;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Migration\MigrationInterface;

/** compliance_responsibilities: who is responsible for a section of compliance (or one item), when that is not the organization's own staff.
 *
 * @internal
 */
final class Migration0011Responsibilities implements MigrationInterface
{
    public function id(): string
    {
        return '0011_compliance_responsibilities';
    }

    public function up(DatabaseInterface $database): void
    {
        $database->execute(
            "CREATE TABLE IF NOT EXISTS `compliance_responsibilities` (
                `assign_key` varchar(120) NOT NULL,
                `party_ref` int(11) DEFAULT NULL,
                `party_name` varchar(200) NOT NULL,
                `updated_by` int(11) DEFAULT NULL,
                `updated_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`assign_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }
}
