<?php

declare(strict_types=1);

namespace RivetCore\Compliance\Backup;

use RivetCore\Compliance\Profile\DataClass;
use RivetCore\Database\DatabaseInterface;
use RivetCore\Rmm\Store\Postgres\PostgresConnectionInterface;

/**
 * Exact row counts of the tables a restore test compares (the numbers {@see RestoreTestStore::pairCounts()} pairs up). Only tables Core knows can be counted:
 * the names are checked against {@see self::tables()}, so a caller can never make this run SQL of its own. Counting is a full COUNT(*): run it on the
 * source around the time of the backup and on the restored copy, not on a busy production table every minute.
 *
 * @api
 */
final class RowCounts
{
    /** Control-database tables worth comparing besides the data classes: the compliance evidence itself. */
    private const CONTROL_EXTRA = ['compliance_snapshots', 'compliance_attestations', 'compliance_profiles', 'compliance_restore_tests'];

    /** @return list<string> every table that can be counted */
    public static function tables(): array
    {
        $t = self::CONTROL_EXTRA;
        foreach (DataClass::ALL as $c) {
            foreach ($c['tables'] as $table) {
                $t[] = $table;
            }
        }

        return array_values(array_unique($t));
    }

    /**
     * @param list<string> $tables
     * @return array<string,int>
     */
    public static function control(DatabaseInterface $database, array $tables): array
    {
        $out = [];
        foreach (self::checked($tables) as $t) {
            $out[$t] = (int) ($database->fetchOne("SELECT COUNT(*) AS c FROM `$t`")['c'] ?? 0);
        }

        return $out;
    }

    /**
     * @param list<string> $tables
     * @return array<string,int>
     */
    public static function postgres(PostgresConnectionInterface $postgres, array $tables): array
    {
        $out = [];
        foreach (self::checked($tables) as $t) {
            $out[$t] = (int) $postgres->value("SELECT COUNT(*) FROM \"$t\"");
        }

        return $out;
    }

    /**
     * @param list<string> $tables
     * @return list<string>
     */
    private static function checked(array $tables): array
    {
        $known = self::tables();
        foreach ($tables as $t) {
            if (!in_array($t, $known, true)) {
                throw new \InvalidArgumentException('That table cannot be counted.');
            }
        }

        return array_values(array_unique($tables));
    }
}
