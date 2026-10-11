<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Storage;

use RivetCore\Rmm\Support\Sql;

/**
 * Time partitioning of the big history tables (scaling item S5): `rmm_metric_hourly`, `rmm_metric_daily` and `endpoint_agent_check_history` can be RANGE
 * partitioned by day (or by a span of days), so retention drops a whole partition (a metadata operation) instead of deleting millions of rows in batches.
 *
 * It is OPT-IN (`partitioning` limit, default 0) and PROBED. {@see probe()} reports, per server and per table, whether partitioning can be used and why not:
 *  - the server must support it (MariaDB with the `partition` plugin active; MySQL 8 natively) and the account must be allowed to create, alter and drop
 *    tables (the probe really creates, partitions and drops a one-column scratch table; nothing else is touched);
 *  - a partitioned InnoDB table cannot have foreign keys (in either direction) and every unique key must contain the partition column. The primary key of
 *    `endpoint_agent_check_history` (`hist_id`) is extended with `reported_at` when the table is converted (the id stays unique because it is AUTO_INCREMENT).
 *
 * Where it is not possible nothing changes: {@see \RivetCore\Rmm\Maintenance\Housekeeping} keeps pruning that table with batched DELETEs, exactly as before.
 *
 * Layout of a converted table: `p_old` (everything before the first named boundary), one partition per span named `pYYYYMMDD` after its first day, and
 * `p_max` (MAXVALUE). {@see maintain()} keeps `ahead` partitions created in front of now by REORGANIZE of the (normally empty) `p_max`, and drops every
 * partition whose upper boundary is not after the retention cutoff. A lagging maintenance is safe: rows past the last boundary simply sit in `p_max`
 * until the next run splits it; a partition is never dropped before its hours are in the daily tier (watermark guard) and never while it still holds
 * data newer than the cutoff. Each ALTER runs with a short `lock_wait_timeout` and under a server-side named lock, so it can neither queue behind a long
 * transaction and stall check-ins, nor run twice at once; a failed ALTER is reported and retried by the next run.
 *
 * @api
 */
final class PartitionMaintenance
{
    public const LOCK = 'rivetcore_rmm_partitions';
    public const LOCK_WAIT_S = 3;
    public const OLD = 'p_old';
    public const MAX = 'p_max';
    /** Most partitions one REORGANIZE creates. */
    public const MAX_NEW = 100;

    /** @var array<string,array{column:string,retention:string,span_min:int,pk:list<string>}> table => partition column, the limit that holds its retention in days, minimum span, primary key columns */
    public const TABLES = [
        'rmm_metric_hourly' => ['column' => 'hour_start', 'retention' => 'metric_hourly_days', 'span_min' => 1, 'pk' => ['asset_id', 'metric_key', 'instance', 'hour_start']],
        'rmm_metric_daily' => ['column' => 'day_start', 'retention' => 'metric_daily_days', 'span_min' => 7, 'pk' => ['asset_id', 'metric_key', 'instance', 'day_start']],
        'endpoint_agent_check_history' => ['column' => 'reported_at', 'retention' => 'check_history_days', 'span_min' => 1, 'pk' => ['hist_id']],
    ];

    private readonly StorageState $state;
    /** @var array<string,mixed>|null */
    private ?array $probed = null;

    public function __construct(private readonly Sql $sql, ?StorageState $state = null)
    {
        $this->state = $state ?? new StorageState($sql);
    }

    // ------------------------------------------------------------------ the probe

    /**
     * What this server and this account can do, and the state of every table. Creates and drops a scratch table to prove the privileges.
     *
     * @return array{supported:bool,server:string,version:string,reasons:list<string>,tables:array<string,array{exists:bool,partitioned:bool,convertible:bool,reasons:list<string>,warnings:list<string>,partitions:int}>}
     */
    public function probe(): array
    {
        $version = (string) $this->sql->val('SELECT VERSION()');
        $maria = stripos($version, 'mariadb') !== false;
        $out = ['supported' => true, 'server' => $maria ? 'mariadb' : 'mysql', 'version' => $version, 'reasons' => [], 'tables' => []];
        if ($maria) {
            $plugin = $this->sql->val("SELECT PLUGIN_STATUS FROM information_schema.PLUGINS WHERE PLUGIN_NAME = 'partition'");
            if ($plugin !== null && strtoupper((string) $plugin) !== 'ACTIVE') {
                $out['supported'] = false;
                $out['reasons'][] = 'The MariaDB partition plugin is not active.';
            }
        }
        if ($out['supported']) {
            $why = $this->tryScratchTable();
            if ($why !== null) {
                $out['supported'] = false;
                $out['reasons'][] = $why;
            }
        }
        foreach (self::TABLES as $table => $spec) {
            $out['tables'][$table] = $this->inspect($table, $spec, $out['supported']);
        }
        $this->state->put(StorageState::PROBE, null, ['at' => $this->sql->utcNow()] + $out);
        $this->probed = $out;

        return $out;
    }

    /** Really create, partition, extend and drop a scratch table; null when it all worked, otherwise the reason. */
    private function tryScratchTable(): ?string
    {
        $name = 'rmm_partition_probe_' . bin2hex(random_bytes(4));
        $created = false;
        try {
            $this->sql->run("CREATE TABLE `$name` (`id` int NOT NULL, `at` datetime NOT NULL, PRIMARY KEY (`id`, `at`)) ENGINE=InnoDB");
            $created = true;
            $this->sql->run("ALTER TABLE `$name` PARTITION BY RANGE COLUMNS(`at`) (PARTITION p1 VALUES LESS THAN ('2030-01-01 00:00:00'), PARTITION " . self::MAX . ' VALUES LESS THAN (MAXVALUE))');
            $this->sql->run("ALTER TABLE `$name` REORGANIZE PARTITION " . self::MAX . " INTO (PARTITION p2 VALUES LESS THAN ('2030-01-02 00:00:00'), PARTITION " . self::MAX . ' VALUES LESS THAN (MAXVALUE))');
            $this->sql->run("ALTER TABLE `$name` DROP PARTITION p1");

            return null;
        } catch (\Throwable $e) {
            return 'This account or server cannot partition tables: ' . mb_substr(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 0, 200);
        } finally {
            if ($created) {
                try {
                    $this->sql->run("DROP TABLE IF EXISTS `$name`");
                } catch (\Throwable) {
                    // reported by the next probe if it is still there
                }
            }
        }
    }

    /**
     * @param array{column:string,retention:string,span_min:int,pk:list<string>} $spec
     * @return array{exists:bool,partitioned:bool,convertible:bool,reasons:list<string>,warnings:list<string>,partitions:int}
     */
    private function inspect(string $table, array $spec, bool $serverOk): array
    {
        $r = ['exists' => false, 'partitioned' => false, 'convertible' => false, 'reasons' => [], 'warnings' => [], 'partitions' => 0];
        $meta = $this->sql->one('SELECT TABLE_ROWS AS n, ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
        if ($meta === null) {
            $r['reasons'][] = 'The table does not exist (migration 0021 / earlier migrations not applied).';

            return $r;
        }
        $r['exists'] = true;
        if (strcasecmp((string) $meta['engine'], 'InnoDB') !== 0) {
            $r['reasons'][] = 'The table is not InnoDB.';
        }
        $parts = (int) $this->sql->val('SELECT COUNT(*) FROM information_schema.PARTITIONS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND PARTITION_NAME IS NOT NULL', [$table]);
        $r['partitioned'] = $parts > 0;
        $r['partitions'] = $parts;
        $fks = (int) $this->sql->val('SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL
            AND (TABLE_NAME = ? OR REFERENCED_TABLE_NAME = ?)', [$table, $table]);
        if ($fks > 0) {
            $r['reasons'][] = 'The table has foreign keys (or is referenced by one); partitioned InnoDB tables cannot.';
        }
        foreach ($this->sql->all('SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME) AS cols FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND NON_UNIQUE = 0
            AND INDEX_NAME <> \'PRIMARY\' GROUP BY INDEX_NAME', [$table]) as $u) {
            if (!in_array($spec['column'], explode(',', (string) $u['cols']), true)) {
                $r['reasons'][] = 'The unique key ' . $u['INDEX_NAME'] . ' does not contain ' . $spec['column'] . '.';
            }
        }
        if ((int) ($meta['n'] ?? 0) > 5000000 && !$r['partitioned']) {
            $r['warnings'][] = 'About ' . (int) $meta['n'] . ' rows: the conversion copies the whole table. Run it off hours; check-ins write to this table only through short statements, but they wait for the copy.';
        }
        if (!$serverOk) {
            $r['reasons'][] = 'Partitioning is not available on this server/account (see the server reasons).';
        }
        $r['convertible'] = $r['reasons'] === [];

        return $r;
    }

    // ------------------------------------------------------------------ conversion

    /**
     * Convert one table to RANGE COLUMNS partitions: `p_old`, one partition per span from the oldest row to now plus $ahead spans, and `p_max`.
     * Does nothing (and says why) when the probe says the table cannot be converted or it already is partitioned.
     *
     * @return array{ok:bool,message:string}
     */
    public function convert(string $table, int $spanDays, int $ahead): array
    {
        if (!$this->lock()) {
            return ['ok' => false, 'message' => 'Another partition maintenance is running.'];
        }
        try {
            return $this->convertLocked($table, $spanDays, $ahead);
        } finally {
            $this->unlock();
        }
    }

    /** @return array{ok:bool,message:string} */
    private function convertLocked(string $table, int $spanDays, int $ahead): array
    {
        $spec = self::TABLES[$table] ?? null;
        if ($spec === null) {
            return ['ok' => false, 'message' => 'Not a partitionable RMM table.'];
        }
        $spanDays = max($spec['span_min'], $spanDays);
        $probe = $this->probed ?? $this->probe();
        $t = $probe['tables'][$table];
        if ($t['partitioned']) {
            return ['ok' => true, 'message' => 'Already partitioned.'];
        }
        if (!$t['convertible']) {
            return ['ok' => false, 'message' => implode(' ', array_merge($probe['reasons'], $t['reasons']))];
        }
        $col = $spec['column'];
        $first = $this->sql->val("SELECT MIN(`$col`) FROM `$table`");
        $now = $this->sql->time();
        $start = $first === null ? $now : min($now, Sql::ts((string) $first));
        $startDay = intdiv($start, 86400);
        $startDay -= $startDay % $spanDays;
        $endDay = intdiv($now, 86400) + $spanDays * ($ahead + 1);
        $parts = [];
        $firstBoundary = gmdate('Y-m-d H:i:s', $startDay * 86400);
        $parts[] = 'PARTITION ' . self::OLD . " VALUES LESS THAN ('$firstBoundary')";
        for ($d = $startDay; $d < $endDay; $d += $spanDays) {
            $parts[] = 'PARTITION ' . self::name($d) . " VALUES LESS THAN ('" . gmdate('Y-m-d H:i:s', ($d + $spanDays) * 86400) . "')";
        }
        $parts[] = 'PARTITION ' . self::MAX . ' VALUES LESS THAN (MAXVALUE)';
        $pk = '';
        if (!in_array($col, $spec['pk'], true)) {
            $pk = 'DROP PRIMARY KEY, ADD PRIMARY KEY (' . implode(', ', array_map(static fn (string $c): string => "`$c`", [...$spec['pk'], $col])) . ') ';
        }
        $ddl = "ALTER TABLE `$table` $pk" . "PARTITION BY RANGE COLUMNS(`$col`) (" . implode(', ', $parts) . ')';
        try {
            $this->alter($ddl);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'The ALTER failed: ' . mb_substr(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 0, 200)];
        }

        return ['ok' => true, 'message' => 'Converted into ' . count($parts) . ' partitions.'];
    }

    // ------------------------------------------------------------------ maintenance

    /**
     * Keep the partitions of every PARTITIONED table: create the next ones, drop the expired ones. Tables that are not partitioned are skipped (the caller
     * prunes them with batched DELETEs). With `$autoConvert` a table that can be converted is converted first (only when the `partitioning` limit is on).
     *
     * @param array<string,int> $retentionDays table => days to keep
     * @param array<string,string> $guard table => datetime: a partition whose upper boundary is after it is NOT dropped (the daily rollup watermark)
     * @return array{tables:array<string,array{status:string,created:int,dropped:int,message:string}>,partitioned:list<string>}
     */
    public function maintain(array $retentionDays, int $spanDays, int $ahead, bool $autoConvert = false, array $guard = []): array
    {
        $out = ['tables' => [], 'partitioned' => []];
        if (!$this->lock()) {
            return ['tables' => ['*' => ['status' => 'busy', 'created' => 0, 'dropped' => 0, 'message' => 'Another partition maintenance is running.']], 'partitioned' => []];
        }
        try {
            foreach (self::TABLES as $table => $spec) {
                $res = ['status' => 'skipped', 'created' => 0, 'dropped' => 0, 'message' => ''];
                $span = max($spec['span_min'], $spanDays);
                $existing = $this->partitions($table);
                if ($existing === [] && $autoConvert) {
                    $probe = $this->probed ?? $this->probe();
                    if (($probe['tables'][$table]['convertible'] ?? false) === true) {
                        $c = $this->convertLocked($table, $span, $ahead);
                        $res['message'] = $c['message'];
                        $existing = $this->partitions($table);
                    } else {
                        $res['message'] = implode(' ', array_merge($probe['reasons'], $probe['tables'][$table]['reasons'] ?? []));
                    }
                }
                if ($existing === []) {
                    $res['status'] = 'not_partitioned';
                    $out['tables'][$table] = $res;
                    continue;
                }
                $out['partitioned'][] = $table;
                try {
                    $res['created'] = $this->extend($table, $spec['column'], $existing, $span, $ahead);
                    $cutoff = $this->sql->time() - max(1, $retentionDays[$table] ?? 1) * 86400;
                    $res['dropped'] = $this->dropExpired($table, $this->partitions($table), $cutoff, $guard[$table] ?? null, $res['message']);
                    $res['status'] = 'ok';
                } catch (\Throwable $e) {
                    $res['status'] = 'error';
                    $res['message'] = mb_substr(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 0, 200);
                }
                $out['tables'][$table] = $res;
            }
        } finally {
            $this->unlock();
        }
        if ($out['partitioned'] !== [] || $autoConvert) {
            $this->state->put(StorageState::MAINTENANCE, null, ['at' => $this->sql->utcNow()] + $out);
        }

        return $out;
    }

    /** @return bool whether the table is currently partitioned (cheap, information_schema) */
    public function isPartitioned(string $table): bool
    {
        return $this->partitions($table) !== [];
    }

    /**
     * Partitions of a table, oldest first. `bound` is the upper boundary as a Unix timestamp (null for p_max).
     *
     * @return list<array{name:string,bound:?int,rows:int}>
     */
    public function partitions(string $table): array
    {
        $out = [];
        foreach ($this->sql->all('SELECT PARTITION_NAME AS name, PARTITION_DESCRIPTION AS descr, TABLE_ROWS AS n FROM information_schema.PARTITIONS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND PARTITION_NAME IS NOT NULL ORDER BY PARTITION_ORDINAL_POSITION', [$table]) as $p) {
            $descr = trim((string) $p['descr'], "'");
            $out[] = ['name' => (string) $p['name'], 'bound' => strtoupper($descr) === 'MAXVALUE' || $descr === '' ? null : Sql::ts($descr), 'rows' => (int) $p['n']];
        }

        return $out;
    }

    /**
     * @param list<array{name:string,bound:?int,rows:int}> $existing
     * @return int partitions created
     */
    private function extend(string $table, string $col, array $existing, int $span, int $ahead): int
    {
        $last = 0;
        $names = [];
        foreach ($existing as $p) {
            $names[$p['name']] = true;
            if ($p['bound'] !== null) {
                $last = max($last, $p['bound']);
            }
        }
        if ($last === 0) {
            $last = (intdiv($this->sql->time(), 86400)) * 86400;
        }
        $until = (intdiv($this->sql->time(), 86400) + $span * ($ahead + 1)) * 86400;
        $new = [];
        $step = $span * 86400;
        $b = $last;
        if (($until - $b) / $step > self::MAX_NEW) {
            // A long gap (maintenance did not run for months): one catch-up partition spans it, then regular ones, so a single ALTER stays small.
            $skip = $until - self::MAX_NEW * $step;
            $name = self::name(intdiv($b, 86400));
            if (!isset($names[$name])) {
                $new[] = 'PARTITION ' . $name . " VALUES LESS THAN ('" . gmdate('Y-m-d H:i:s', $skip) . "')";
            }
            $b = $skip;
        }
        for (; $b < $until; $b += $step) {
            $name = self::name(intdiv($b, 86400));
            if (isset($names[$name])) {
                continue;
            }
            $new[] = 'PARTITION ' . $name . " VALUES LESS THAN ('" . gmdate('Y-m-d H:i:s', $b + $step) . "')";
        }
        if ($new === []) {
            return 0;
        }
        $this->alter("ALTER TABLE `$table` REORGANIZE PARTITION " . self::MAX . ' INTO (' . implode(', ', $new) . ', PARTITION ' . self::MAX . ' VALUES LESS THAN (MAXVALUE))');

        return count($new);
    }

    /**
     * @param list<array{name:string,bound:?int,rows:int}> $parts
     * @return int partitions dropped
     */
    private function dropExpired(string $table, array $parts, int $cutoff, ?string $guard, string &$message): int
    {
        $guardTs = $guard === null ? null : Sql::ts($guard);
        $dropped = 0;
        foreach ($parts as $p) {
            if ($p['bound'] === null || $p['bound'] > $cutoff) {
                break;   // oldest first: nothing after this one is expired either
            }
            if ($guardTs !== null && $p['bound'] > $guardTs) {
                $message = 'Not dropping ' . $p['name'] . ': the daily rollup has not covered it yet.';
                break;
            }
            $this->alter("ALTER TABLE `$table` DROP PARTITION `" . $p['name'] . '`');
            ++$dropped;
        }

        return $dropped;
    }

    /** Run an ALTER with a short metadata lock wait so it cannot sit in the lock queue and stall every statement behind it. */
    private function alter(string $ddl): void
    {
        $previous = $this->sql->val('SELECT @@SESSION.lock_wait_timeout');
        $this->sql->run('SET SESSION lock_wait_timeout = ' . self::LOCK_WAIT_S);
        try {
            $this->sql->run($ddl);
        } finally {
            $this->sql->run('SET SESSION lock_wait_timeout = ' . max(1, (int) $previous));
        }
    }

    private function lock(): bool
    {
        return (int) $this->sql->val('SELECT GET_LOCK(?, 0)', [self::LOCK]) === 1;
    }

    private function unlock(): void
    {
        $this->sql->val('SELECT RELEASE_LOCK(?)', [self::LOCK]);
    }

    private static function name(int $epochDay): string
    {
        return 'p' . gmdate('Ymd', $epochDay * 86400);
    }
}
