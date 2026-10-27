<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\Sizes;

use RivetCore\Rmm\Store\Postgres\PostgresSchema;
use RivetCore\Rmm\Store\RmmDataStoreKind;
use RivetCore\Rmm\Store\StoreSizes;
use RivetCore\Rmm\Support\Sql;

/** {@see StoreSizes} for the control database: information_schema.TABLES for the hot tables. @api */
final class MariaDbStoreSizes implements StoreSizes
{
    /** Time columns that have an index in MariaDB, so MIN() is cheap. */
    private const OLDEST = [['endpoint_agent_check_history', 'reported_at'], ['endpoint_agent_log_events', 'occurred_at'], ['rmm_software_history', 'occurred_at'], ['rmm_patch_history', 'at']];

    public function __construct(private readonly Sql $sql)
    {
    }

    public function report(): array
    {
        try {
            $names = PostgresSchema::TABLES;
            $in = implode(',', array_fill(0, count($names), '?'));
            $rows = $this->sql->all("SELECT TABLE_NAME AS t, COALESCE(TABLE_ROWS, 0) AS r, COALESCE(DATA_LENGTH, 0) + COALESCE(INDEX_LENGTH, 0) AS b FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($in)", $names);
            $tables = [];
            $tr = $tb = 0;
            foreach ($rows as $r) {
                $tables[] = ['table' => (string) $r['t'], 'rows' => (int) $r['r'], 'bytes' => (int) $r['b']];
                $tr += (int) $r['r'];
                $tb += (int) $r['b'];
            }
            usort($tables, static fn (array $a, array $b): int => $b['bytes'] <=> $a['bytes']);
            $oldest = null;
            foreach (self::OLDEST as [$t, $c]) {
                try {
                    $v = $this->sql->val("SELECT MIN(`$c`) FROM `$t`");
                } catch (\Throwable) {
                    continue;
                }
                if (is_string($v) && $v !== '' && ($oldest === null || $v < $oldest)) {
                    $oldest = $v;
                }
            }

            return ['kind' => RmmDataStoreKind::MARIADB, 'available' => true, 'error' => null, 'tables' => $tables, 'total_rows' => $tr, 'total_bytes' => $tb, 'oldest_at' => $oldest];
        } catch (\Throwable $e) {
            return ['kind' => RmmDataStoreKind::MARIADB, 'available' => false, 'error' => mb_substr($e->getMessage(), 0, 200), 'tables' => [], 'total_rows' => 0, 'total_bytes' => 0, 'oldest_at' => null];
        }
    }
}
