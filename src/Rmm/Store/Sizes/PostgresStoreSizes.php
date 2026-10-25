<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\Sizes;

use RivetCore\Rmm\Store\Postgres\PostgresConnectionInterface;
use RivetCore\Rmm\Store\Postgres\PostgresSchema;
use RivetCore\Rmm\Store\RmmDataStoreKind;
use RivetCore\Rmm\Store\StoreSizes;

/**
 * {@see StoreSizes} for PostgreSQL: pg_total_relation_size (heap, indexes, TOAST) of the table plus all its partitions, reltuples as the row estimate; on a TimescaleDB
 * hypertable hypertable_size() and approximate_row_count(). Any failure (server down, role cannot read the catalog) answers `available = false`.
 *
 * @api
 */
final class PostgresStoreSizes implements StoreSizes
{
    private const OLDEST = [['endpoint_agent_check_history', 'reported_at'], ['endpoint_agent_log_events', 'occurred_at'], ['rmm_software_history', 'occurred_at'], ['rmm_patch_history', '"at"']];

    /** @param \Closure(): PostgresConnectionInterface|PostgresConnectionInterface $db a closure is called on first use */
    public function __construct(private readonly PostgresConnectionInterface|\Closure $db)
    {
    }

    public function report(): array
    {
        try {
            $db = $this->db instanceof \Closure ? ($this->db)() : $this->db;
            $timescale = $db->value("SELECT 1 FROM pg_extension WHERE extname = 'timescaledb'") !== null;
            $tables = [];
            $tr = $tb = 0;
            foreach (PostgresSchema::TABLES as $t) {
                $row = $db->queryOne('SELECT c.oid::regclass::text AS q, c.reltuples::bigint AS r FROM pg_class c WHERE c.relnamespace = current_schema()::regnamespace AND c.relname = ? AND c.relkind IN (\'r\', \'p\')', [$t]);
                if ($row === null) {
                    continue;
                }
                $q = (string) $row['q'];
                $bytes = null;
                $rows = null;
                if ($timescale) {
                    try {
                        $bytes = $db->value('SELECT hypertable_size(?::regclass)', [$q]);
                        $rows = $db->value('SELECT approximate_row_count(?::regclass)', [$q]);
                    } catch (\Throwable) {
                        $bytes = $rows = null;   // not a hypertable
                    }
                }
                if ($bytes === null) {
                    $bytes = $db->value('SELECT pg_total_relation_size(?::regclass) + COALESCE((SELECT SUM(pg_total_relation_size(i.inhrelid)) FROM pg_inherits i WHERE i.inhparent = ?::regclass), 0)', [$q, $q]);
                    $rows = $db->value('SELECT GREATEST(c.reltuples, 0)::bigint + COALESCE((SELECT SUM(GREATEST(k.reltuples, 0)) FROM pg_inherits i JOIN pg_class k ON k.oid = i.inhrelid WHERE i.inhparent = c.oid), 0)::bigint FROM pg_class c WHERE c.oid = ?::regclass', [$q]);
                }
                $tables[] = ['table' => $t, 'rows' => max(0, (int) $rows), 'bytes' => max(0, (int) $bytes)];
                $tr += max(0, (int) $rows);
                $tb += max(0, (int) $bytes);
            }
            usort($tables, static fn (array $a, array $b): int => $b['bytes'] <=> $a['bytes']);
            $oldest = null;
            foreach (self::OLDEST as [$t, $c]) {
                try {
                    $v = $db->value("SELECT to_char(MIN($c) AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') FROM $t");
                } catch (\Throwable) {
                    continue;
                }
                if (is_string($v) && $v !== '' && ($oldest === null || $v < $oldest)) {
                    $oldest = $v;
                }
            }

            return ['kind' => RmmDataStoreKind::POSTGRES, 'available' => true, 'error' => null, 'tables' => $tables, 'total_rows' => $tr, 'total_bytes' => $tb, 'oldest_at' => $oldest];
        } catch (\Throwable $e) {
            return ['kind' => RmmDataStoreKind::POSTGRES, 'available' => false, 'error' => mb_substr($e->getMessage(), 0, 200), 'tables' => [], 'total_rows' => 0, 'total_bytes' => 0, 'oldest_at' => null];
        }
    }
}
