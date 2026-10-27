<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\Postgres;

use RivetCore\Contracts\ClockInterface;
use RivetCore\Rmm\Store\MetricStore;
use RivetCore\Rmm\Store\StoreMaintenance;

/**
 * {@see StoreMaintenance} on PostgreSQL: the daily rollup, then the physical retention of the three big time series.
 *
 * Native mode keeps day partitions of `rmm_metric_hourly`, `rmm_metric_daily` and `endpoint_agent_check_history`: when the `partitioning` limit is on (or partitions
 * already exist) it creates the next `partition_ahead` spans, moving any rows the DEFAULT partition holds for the range into the new partition first, and drops
 * every partition whose upper bound is not after the retention cutoff, never one the daily rollup has not covered yet. A lagging maintenance is safe: rows past the last
 * bound sit in the DEFAULT partition until the next run. Each DDL runs under a short lock timeout and a session advisory lock, so it can neither queue behind a long
 * transaction and stall check-ins, nor run twice at once; a failed one is reported and retried by the next run.
 *
 * TimescaleDB mode: the three tables are hypertables; with the `partitioning` limit on, the run enables compression (after 7 days) and drops whole chunks older than the
 * retention, with the same rollup guard.
 *
 * With the limit off and no partitions, nothing physical happens and the caller prunes row by row (the MariaDB behaviour).
 *
 * @internal
 */
final class PostgresStoreMaintenance implements StoreMaintenance
{
    public const LOCK_KEY = 7_201_089_002;
    public const MAX_NEW = 100;
    public const COMPRESS_AFTER = '7 days';
    private const LOCK_TIMEOUT = '3000ms';

    private ?string $mode = null;

    public function __construct(private readonly PostgresConnectionInterface $db, private readonly ClockInterface $clock, private readonly ?MetricStore $metrics = null)
    {
    }

    public function run(array $limits, array $explicit, int $hourlyDefault, int $dailyDefault, int $rollupBudgetS = 15): array
    {
        $roll = $this->metrics?->rollUp($rollupBudgetS);
        $retention = [
            'rmm_metric_hourly' => $explicit['metric_hourly_days'] ?? $hourlyDefault,
            'rmm_metric_daily' => $explicit['metric_daily_days'] ?? $dailyDefault,
            'endpoint_agent_check_history' => max(1, $limits['check_history_days']),
        ];
        $guard = [];
        if ($roll !== null && $roll['watermark'] !== null) {
            $guard['rmm_metric_hourly'] = $roll['watermark'];
        }
        $maint = $this->maintain($retention, $limits['partition_span_days'], $limits['partition_ahead'], $limits['partitioning'] === 1, $guard);

        return ['rollup' => $roll, 'partitions' => $maint['tables'], 'partitioned' => $maint['partitioned'], 'retention' => $retention];
    }

    public function tiers(array $limits, array $explicit): array
    {
        $wm = $this->metrics?->rollupWatermark() ?? (new PostgresRollupState($this->db))->watermark();
        $retention = ['rmm_metric_hourly' => $explicit['metric_hourly_days'] ?? $limits['metric_hourly_days'], 'rmm_metric_daily' => $explicit['metric_daily_days'] ?? $limits['metric_daily_days'],
            'endpoint_agent_check_history' => max(1, $limits['check_history_days'])];
        $timescale = $this->mode() === PostgresSchema::MODE_TIMESCALE;
        $tables = [];
        foreach (array_keys(PostgresSchema::TIME_SERIES) as $t) {
            $n = $timescale ? $this->chunkCount($t) : count($this->partitions($t));
            $tables[$t] = ['partitioned' => $timescale ? $limits['partitioning'] === 1 : $n > 0, 'partitions' => $n, 'retention_days' => $retention[$t]];
        }

        return ['partitioning_enabled' => $limits['partitioning'] === 1, 'rollup_watermark' => $wm,
            'rollup_behind_days' => $wm === null ? null : max(0, intdiv($this->clock->now()->getTimestamp() - PostgresSql::epoch($wm), 86400)), 'tables' => $tables];
    }

    /** The storage mode recorded by the migrator ({@see PostgresSchema::MODE_NATIVE} when nothing is recorded). */
    public function mode(): string
    {
        if ($this->mode === null) {
            try {
                $v = $this->db->value('SELECT v FROM ' . PostgresMigrator::META . ' WHERE k = ?', ['storage_mode']);
            } catch (PostgresQueryException) {
                $v = null;   // not migrated yet: the other statements report that, in terms the administrator understands
            }
            $this->mode = $v === PostgresSchema::MODE_TIMESCALE ? PostgresSchema::MODE_TIMESCALE : PostgresSchema::MODE_NATIVE;
        }

        return $this->mode;
    }

    /**
     * @param array<string,int> $retentionDays table => days to keep
     * @param array<string,string> $guard table => datetime: a partition (chunk) whose upper bound is after it is NOT dropped
     * @return array{tables:array<string,array{status:string,created:int,dropped:int,message:string}>,partitioned:list<string>}
     */
    public function maintain(array $retentionDays, int $spanDays, int $ahead, bool $enabled, array $guard = []): array
    {
        $out = ['tables' => [], 'partitioned' => []];
        if (!(bool) $this->db->value('SELECT pg_try_advisory_lock(?)', [self::LOCK_KEY])) {
            return ['tables' => ['*' => ['status' => 'busy', 'created' => 0, 'dropped' => 0, 'message' => 'Another partition maintenance is running.']], 'partitioned' => []];
        }
        try {
            foreach (PostgresSchema::TIME_SERIES as $table => $col) {
                $res = ['status' => 'skipped', 'created' => 0, 'dropped' => 0, 'message' => ''];
                try {
                    $this->mode() === PostgresSchema::MODE_TIMESCALE ? $this->timescaleTable($table, $retentionDays, $enabled, $guard, $res, $out) : $this->nativeTable($table, $col, $retentionDays, max(1, $spanDays), $ahead, $enabled, $guard, $res, $out);
                } catch (PostgresException $e) {
                    $res['status'] = 'error';
                    $res['message'] = mb_substr(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 0, 200);
                }
                $out['tables'][$table] = $res;
            }
        } finally {
            try {
                $this->db->value('SELECT pg_advisory_unlock(?)', [self::LOCK_KEY]);
            } catch (PostgresException) {
                // the session is gone, and with it the lock
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------ native partitions

    /**
     * @param array<string,int> $retentionDays
     * @param array<string,string> $guard
     * @param array{status:string,created:int,dropped:int,message:string} $res
     * @param array{tables:array<string,mixed>,partitioned:list<string>} $out
     */
    private function nativeTable(string $table, string $col, array $retentionDays, int $span, int $ahead, bool $enabled, array $guard, array &$res, array &$out): void
    {
        $parts = $this->partitions($table);
        if ($parts === [] && !$enabled) {
            $res['status'] = 'not_partitioned';

            return;
        }
        $now = $this->clock->now()->getTimestamp();
        $today = intdiv($now, 86400);
        $until = ($today + $span * ($ahead + 1)) * 86400;
        $step = $span * 86400;
        if ($parts === []) {
            $first = $this->db->value('SELECT ' . PostgresSql::ts("MIN($col)") . " FROM $table");
            $startDay = $first === null ? $today : min($today, intdiv(PostgresSql::epoch((string) $first), 86400));
            $startDay -= $startDay % $span;
            $b = $startDay * 86400;
        } else {
            $b = max(array_column($parts, 'to'));
        }
        $ranges = [];
        if (($until - $b) / $step > self::MAX_NEW) {
            // A long gap (maintenance did not run for months): one catch-up partition spans it, then regular ones, so a single DDL stays small.
            $skip = $until - self::MAX_NEW * $step;
            $ranges[] = [$b, $skip];
            $b = $skip;
        }
        for (; $b < $until; $b += $step) {
            $ranges[] = [$b, $b + $step];
        }
        $existing = array_column($parts, 'name');
        foreach ($ranges as [$from, $to]) {
            $name = $table . '_p' . gmdate('Ymd', $from);
            if (in_array($name, $existing, true)) {
                continue;
            }
            $this->createPartition($table, $col, $name, $from, $to);
            ++$res['created'];
        }
        $out['partitioned'][] = $table;
        $cutoff = $now - max(1, $retentionDays[$table] ?? 1) * 86400;
        $guardTs = isset($guard[$table]) ? PostgresSql::epoch($guard[$table]) : null;
        foreach ($this->partitions($table) as $p) {
            if ($p['to'] > $cutoff) {
                break;
            }
            if ($guardTs !== null && $p['to'] > $guardTs) {
                $res['message'] = 'Not dropping ' . $p['name'] . ': the daily rollup has not covered it yet.';
                break;
            }
            $this->ddl('DROP TABLE ' . self::ident($p['name']));
            ++$res['dropped'];
        }
        // rows that arrived after the last partition (or before the first) wait in the DEFAULT partition: expire them like the others, and with the same guard
        $this->db->execute("DELETE FROM {$table}_default WHERE $col < ?", [PostgresSql::utc($guardTs === null ? $cutoff : min($cutoff, $guardTs))]);
        $res['status'] = 'ok';
    }

    /**
     * The day partitions of a table, oldest first; `from` and `to` are Unix times.
     *
     * @return list<array{name:string,from:int,to:int}>
     */
    public function partitions(string $table): array
    {
        $out = [];
        foreach ($this->db->query("SELECT c.relname AS name, pg_get_expr(c.relpartbound, c.oid) AS bound FROM pg_inherits i JOIN pg_class c ON c.oid = i.inhrelid JOIN pg_class p ON p.oid = i.inhparent
            WHERE p.relname = ? AND p.relnamespace = current_schema()::regnamespace AND pg_get_expr(c.relpartbound, c.oid) <> 'DEFAULT'", [$table]) as $r) {
            if (preg_match("/FROM \\('([^']+)'\\) TO \\('([^']+)'\\)/", (string) $r['bound'], $m) !== 1) {
                continue;
            }
            $from = strtotime($m[1]);
            $to = strtotime($m[2]);
            if ($from !== false && $to !== false) {
                $out[] = ['name' => (string) $r['name'], 'from' => $from, 'to' => $to];
            }
        }
        usort($out, static fn (array $a, array $b): int => $a['from'] <=> $b['from']);

        return $out;
    }

    private function createPartition(string $table, string $col, string $name, int $from, int $to): void
    {
        $a = PostgresSql::utc($from) . '+00';
        $b = PostgresSql::utc($to) . '+00';
        $this->db->transaction(function () use ($table, $col, $name, $a, $b): void {
            $this->db->execute("SET LOCAL lock_timeout = '" . self::LOCK_TIMEOUT . "'");
            $n = self::ident($name);
            $this->db->execute("CREATE TABLE $n (LIKE $table INCLUDING DEFAULTS INCLUDING CONSTRAINTS INCLUDING INDEXES)");
            // Writers that route a row to the DEFAULT partition wait here from now until the commit, so nothing can land in the range between the move and the attach (the
            // attach would refuse it) and no upsert can touch a row while it is being moved. Writers of other partitions are not blocked.
            $this->db->execute("LOCK TABLE {$table}_default IN ACCESS EXCLUSIVE MODE");
            // one statement: the rows leave the DEFAULT partition and enter the new table together, so a row can neither be deleted without being copied nor copied twice
            $this->db->execute("WITH moved AS (DELETE FROM {$table}_default WHERE $col >= ?::timestamptz AND $col < ?::timestamptz RETURNING *) INSERT INTO $n SELECT * FROM moved", [$a, $b]);
            $this->db->execute("ALTER TABLE $table ATTACH PARTITION $n FOR VALUES FROM ('$a') TO ('$b')");
        });
    }

    private function ddl(string $sql): void
    {
        $this->db->transaction(function () use ($sql): void {
            $this->db->execute("SET LOCAL lock_timeout = '" . self::LOCK_TIMEOUT . "'");
            $this->db->execute($sql);
        });
    }

    private static function ident(string $name): string
    {
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/D', $name) !== 1) {
            throw new PostgresQueryException('Refusing an unexpected table name.');
        }

        return $name;
    }

    // ------------------------------------------------------------------ TimescaleDB

    /**
     * @param array<string,int> $retentionDays
     * @param array<string,string> $guard
     * @param array{status:string,created:int,dropped:int,message:string} $res
     * @param array{tables:array<string,mixed>,partitioned:list<string>} $out
     */
    private function timescaleTable(string $table, array $retentionDays, bool $enabled, array $guard, array &$res, array &$out): void
    {
        if (!$enabled) {
            $res['status'] = 'not_partitioned';

            return;
        }
        // Compression is an extra (it needs the community edition and a compatible table); retention is the point. A table that cannot be compressed still gets its chunks dropped,
        // and the table only counts as managed here once the drop worked, so a failure leaves the caller's row-by-row prune running.
        try {
            $segment = match ($table) {
                'rmm_metric_hourly', 'rmm_metric_daily' => 'asset_id, metric_key, instance',
                default => 'device_id, check_key',
            };
            $col = PostgresSchema::TIME_SERIES[$table];
            $compressed = (bool) $this->db->value('SELECT compression_enabled FROM timescaledb_information.hypertables WHERE hypertable_schema = current_schema() AND hypertable_name = ?', [$table]);
            if (!$compressed) {
                $this->db->execute("ALTER TABLE $table SET (timescaledb.compress, timescaledb.compress_segmentby = '$segment', timescaledb.compress_orderby = '$col DESC')");
            }
            $this->db->value('SELECT add_compression_policy(?::regclass, INTERVAL ' . "'" . self::COMPRESS_AFTER . "'" . ', if_not_exists => TRUE)', [$table]);
        } catch (PostgresQueryException $e) {
            $res['message'] = trim('Compression is not available for ' . $table . ': ' . mb_substr($e->getMessage(), 0, 150) . ' ' . $res['message']);
        }
        $now = $this->clock->now()->getTimestamp();
        $cutoff = $now - max(1, $retentionDays[$table] ?? 1) * 86400;
        if (isset($guard[$table])) {
            $g = PostgresSql::epoch($guard[$table]);
            if ($g < $cutoff) {
                $cutoff = $g;
                $res['message'] = 'Chunks newer than the daily rollup watermark are kept.';
            }
        }
        $dropped = $this->db->query('SELECT drop_chunks(?::regclass, older_than => ?::timestamptz) AS chunk', [$table, PostgresSql::utc($cutoff) . '+00']);
        $res['dropped'] = count($dropped);
        $res['status'] = 'ok';
        $out['partitioned'][] = $table;
    }

    private function chunkCount(string $table): int
    {
        try {
            return (int) $this->db->value('SELECT COUNT(*) FROM timescaledb_information.chunks WHERE hypertable_schema = current_schema() AND hypertable_name = ?', [$table]);
        } catch (PostgresQueryException) {
            return 0;
        }
    }
}
