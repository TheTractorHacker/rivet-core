<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Migration;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Migration\MigrationInterface;

/**
 * Per-OS check targeting (1.0.0-rc.24): one new Core-owned table, `rmm_device_checkset`, holding a short hash of the check keys last delivered to
 * each device. The check-in compares the keys it is about to deliver with it and only when they differ cleans up the rows of checks that no longer
 * apply (see Checks\CheckReconciler). It alters no existing table, inserts no row and is idempotent. The first check-in of every device after the
 * upgrade finds no row, so it also cleans up what the old, OS-blind delivery left behind.
 *
 * @internal
 */
final class Migration0031CheckSet implements MigrationInterface
{
    public function id(): string
    {
        return '0031_rmm_check_set';
    }

    /** @return array<string,string> */
    public static function tables(): array
    {
        return ['rmm_device_checkset' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `rmm_device_checkset` (
  `device_id` int(11) NOT NULL,
  `set_hash` char(16) NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`device_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL];
    }

    public function up(DatabaseInterface $database): void
    {
        foreach (self::tables() as $ddl) {
            $database->execute($ddl);
        }
    }
}
