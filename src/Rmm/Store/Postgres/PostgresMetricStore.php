<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\Postgres;

use RivetCore\Contracts\ClockInterface;
use RivetCore\Rmm\Store\MetricStore;
use RivetCore\Rmm\Support\DatabaseMetricSink;
use RivetCore\Rmm\Support\MetricMath;

/**
 * {@see MetricStore} on PostgreSQL: the same tiers, rules and answers as {@see DatabaseMetricSink} (the rules that need no database live in {@see MetricMath}, shared),
 * with the MySQL idioms translated: `ON DUPLICATE KEY UPDATE x = VALUES(x)` is `ON CONFLICT ... DO UPDATE SET x = EXCLUDED.x`, `IF()` is `CASE`, `DELETE ... LIMIT` is a
 * delete joined to a bounded sub-select on the primary key (also correct on a partitioned table), `INSERT ... SELECT ... ON DUPLICATE KEY` is the same with `ON CONFLICT`.
 * The rollup watermark is `rmm_metric_rollup_state`, next to the tiers.
 *
 * Differences a caller can see (docs/design/rmm-data-store.md, "Equivalence rules"): the `rows` figure of {@see rollUp()} counts rows written once (MariaDB counts an
 * updated row twice); string keys compare case-sensitively (every key is validated to lower case before it gets here); keys and instances are listed in the order
 * MariaDB's `utf8mb4_general_ci` gives for ASCII.
 *
 * @internal
 */
final class PostgresMetricStore implements MetricStore
{
    private const GRACE_S = 7200;   // as MetricRollup::GRACE_S
    private const ASSETS_PER_SLICE = 500;
    private const MAX_DAYS_PER_RUN = 40;
    private const ORDER_KEY = 'upper(metric_key) COLLATE "C", metric_key COLLATE "C", upper(instance) COLLATE "C", instance COLLATE "C"';

    private readonly PostgresRollupState $state;

    public function __construct(private readonly PostgresConnectionInterface $db, private readonly ClockInterface $clock, private readonly int $retentionDays = DatabaseMetricSink::DEFAULT_RETENTION_DAYS,
        private readonly int $dailyFromSeconds = DatabaseMetricSink::DAILY_FROM_SECONDS, private readonly int $dailyRetentionDays = DatabaseMetricSink::DEFAULT_DAILY_RETENTION_DAYS,
        private readonly int $assetsPerSlice = self::ASSETS_PER_SLICE)
    {
        $this->state = new PostgresRollupState($db);
    }

    public function ingest(array $samples, int $integrationId): void
    {
        [$latest, $hourly] = MetricMath::fold($samples);
        $latest = array_values($latest);
        $hourly = array_values($hourly);
        // one lock order for every writer, so two check-ins that share a series cannot deadlock
        usort($latest, static fn (array $a, array $b): int => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);
        usort($hourly, static fn (array $a, array $b): int => [$a[0], $a[1], $a[2], $a[3]] <=> [$b[0], $b[1], $b[2], $b[3]]);
        if ($latest === [] && $hourly === []) {
            return;
        }
        if ($latest !== [] && $hourly !== [] && count($latest) <= DatabaseMetricSink::ROWS_PER_STATEMENT && count($hourly) <= DatabaseMetricSink::ROWS_PER_STATEMENT) {
            // The shape of a check-in: one statement, one round trip, atomic by itself (a data-modifying CTE always runs to completion).
            $this->db->execute('WITH latest AS (' . self::latestSql(count($latest)) . ' RETURNING 1) ' . self::hourlySql(count($hourly)), [...self::latestParams($latest), ...self::hourlyParams($hourly)]);

            return;
        }
        $this->db->transaction(function () use ($latest, $hourly): void {
            foreach (array_chunk($latest, DatabaseMetricSink::ROWS_PER_STATEMENT) as $chunk) {
                $this->db->execute(self::latestSql(count($chunk)), self::latestParams($chunk));
            }
            foreach (array_chunk($hourly, DatabaseMetricSink::ROWS_PER_STATEMENT) as $chunk) {
                $this->db->execute(self::hourlySql(count($chunk)), self::hourlyParams($chunk));
            }
        });
    }

    private static function latestSql(int $rows): string
    {
        return 'INSERT INTO rmm_metric_latest (asset_id, metric_key, instance, value, label, sampled_at) VALUES ' . PostgresSql::rows($rows, 6)
            . ' ON CONFLICT (asset_id, metric_key, instance) DO UPDATE SET'
            . ' value = CASE WHEN EXCLUDED.sampled_at >= rmm_metric_latest.sampled_at THEN EXCLUDED.value ELSE rmm_metric_latest.value END,'
            . ' label = CASE WHEN EXCLUDED.sampled_at >= rmm_metric_latest.sampled_at THEN EXCLUDED.label ELSE rmm_metric_latest.label END,'
            . ' sampled_at = GREATEST(rmm_metric_latest.sampled_at, EXCLUDED.sampled_at)';
    }

    private static function hourlySql(int $rows): string
    {
        return 'INSERT INTO rmm_metric_hourly (asset_id, metric_key, instance, hour_start, samples, sum_value, min_value, max_value) VALUES ' . PostgresSql::rows($rows, 8)
            . ' ON CONFLICT (asset_id, metric_key, instance, hour_start) DO UPDATE SET samples = rmm_metric_hourly.samples + EXCLUDED.samples,'
            . ' sum_value = rmm_metric_hourly.sum_value + EXCLUDED.sum_value, min_value = LEAST(rmm_metric_hourly.min_value, EXCLUDED.min_value),'
            . ' max_value = GREATEST(rmm_metric_hourly.max_value, EXCLUDED.max_value)';
    }

    /**
     * @param list<array{0:int,1:string,2:string,3:float,4:?string,5:int}> $rows
     * @return list<mixed>
     */
    private static function latestParams(array $rows): array
    {
        $params = [];
        foreach ($rows as [$asset, $key, $inst, $value, $label, $ts]) {
            array_push($params, $asset, $key, $inst, $value, $label, PostgresSql::utc($ts));
        }

        return $params;
    }

    /**
     * @param list<array{0:int,1:string,2:string,3:int,4:int,5:float,6:float,7:float}> $rows
     * @return list<mixed>
     */
    private static function hourlyParams(array $rows): array
    {
        $params = [];
        foreach ($rows as [$asset, $key, $inst, $hour, $n, $sum, $min, $max]) {
            array_push($params, $asset, $key, $inst, PostgresSql::utc($hour), $n, $sum, $min, $max);
        }

        return $params;
    }

    public function retentionDays(): int
    {
        return $this->retentionDays;
    }

    public function dailyRetentionDays(): int
    {
        return $this->dailyRetentionDays;
    }

    public function latest(int $assetId, ?array $keys = null): array
    {
        $sql = 'SELECT metric_key, instance, value, label, ' . PostgresSql::ts('sampled_at', 'sampled_at') . ' FROM rmm_metric_latest WHERE asset_id = ?';
        $params = [$assetId];
        if ($keys !== null) {
            if ($keys === []) {
                return [];
            }
            $sql .= ' AND metric_key IN (' . PostgresSql::in(count($keys)) . ')';
            array_push($params, ...$keys);
        }
        $out = [];
        foreach ($this->db->query($sql . ' ORDER BY ' . self::ORDER_KEY, $params) as $r) {
            $out[] = ['key' => (string) $r['metric_key'], 'instance' => $r['instance'] === '' ? null : (string) $r['instance'], 'value' => MetricMath::number($r['value']),
                'at' => MetricMath::at((string) $r['sampled_at']), 'label' => $r['label'] === null ? null : (string) $r['label']];
        }

        return $out;
    }

    public function peak(int $assetId, string $key, ?string $instance, \DateTimeImmutable $since): ?array
    {
        $inst = $instance ?? '';
        $sinceTs = $since->getTimestamp();
        $split = $this->dailySplit($sinceTs, $this->clock->now()->getTimestamp());
        $from = PostgresSql::utc($sinceTs - $sinceTs % 3600);
        $tiers = [];
        if ($split !== null) {
            $dayFrom = PostgresSql::utc($sinceTs - $sinceTs % 86400);
            $from = max($from, $split);
            $tier = $this->tierAggregate('rmm_metric_daily', 'day_start', $assetId, $key, $inst, $dayFrom, $split);
            if ($tier !== null) {
                $tiers[] = $tier;
            }
        }
        $tier = $this->tierAggregate('rmm_metric_hourly', 'hour_start', $assetId, $key, $inst, $from, null);
        if ($tier !== null) {
            $tiers[] = $tier;
        }

        return MetricMath::combine($tiers);
    }

    public function series(int $assetId, string $key, ?string $instance, \DateTimeImmutable $since, \DateTimeImmutable $until): array
    {
        $inst = $instance ?? '';
        $sinceTs = $since->getTimestamp();
        $untilTs = $until->getTimestamp();
        $from = PostgresSql::utc($sinceTs - $sinceTs % 3600);
        $to = $until->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $out = [];
        $split = $untilTs - $sinceTs > $this->dailyFromSeconds ? $this->dailySplit($sinceTs, $untilTs) : null;
        if ($split !== null) {
            $dayFrom = PostgresSql::utc($sinceTs - $sinceTs % 86400);
            foreach ($this->db->query('SELECT ' . PostgresSql::ts('day_start', 'day_start') . ', samples, sum_value, min_value, max_value FROM rmm_metric_daily
                WHERE asset_id = ? AND metric_key = ? AND instance = ? AND day_start >= ? AND day_start < ? AND day_start <= ? ORDER BY rmm_metric_daily.day_start LIMIT 4000',
                [$assetId, $key, $inst, $dayFrom, $split, $to]) as $r) {
                $c = max(1, (int) $r['samples']);
                $out[] = ['at' => MetricMath::at((string) $r['day_start']), 'min' => (float) $r['min_value'], 'max' => (float) $r['max_value'], 'avg' => (float) $r['sum_value'] / $c, 'samples' => (int) $r['samples']];
            }
            $from = max($from, $split);
        }
        foreach ($this->db->query('SELECT ' . PostgresSql::ts('hour_start', 'hour_start') . ', samples, sum_value, min_value, max_value FROM rmm_metric_hourly
            WHERE asset_id = ? AND metric_key = ? AND instance = ? AND hour_start >= ? AND hour_start <= ? ORDER BY rmm_metric_hourly.hour_start LIMIT 2000',
            [$assetId, $key, $inst, $from, $to]) as $r) {
            $n = max(1, (int) $r['samples']);
            $out[] = ['at' => MetricMath::at((string) $r['hour_start']), 'min' => (float) $r['min_value'], 'max' => (float) $r['max_value'], 'avg' => (float) $r['sum_value'] / $n, 'samples' => (int) $r['samples']];
        }

        return $out;
    }

    public function prune(int $cap = 200000, ?\Closure $pause = null, ?int $hourlyDays = null, ?int $dailyDays = null, array $skip = []): int
    {
        $days = max(1, $hourlyDays ?? $this->retentionDays);
        $total = 0;
        $targets = [['rmm_metric_hourly', 'hour_start', $days], ['rmm_metric_latest', 'sampled_at', $days], ['rmm_metric_daily', 'day_start', max(1, $dailyDays ?? $this->dailyRetentionDays)]];
        foreach ($targets as [$table, $column, $keep]) {
            if (in_array($table, $skip, true)) {
                continue;
            }
            $before = PostgresSql::utc($this->clock->now()->getTimestamp() - $keep * 86400);
            $done = 0;
            while ($done < $cap) {
                $n = $this->deleteBatch($table, $column, $before, DatabaseMetricSink::PRUNE_BATCH);
                $done += $n;
                if ($n < DatabaseMetricSink::PRUNE_BATCH) {
                    break;
                }
                if ($pause !== null) {
                    $pause(20000);
                }
            }
            $total += $done;
        }

        return $total;
    }

    public function rollUp(int $budgetSeconds = 20, ?int $maxSlices = null): array
    {
        // The algorithm of Storage\MetricRollup, step for step (grace period, slices of assets, a watermark that moves only when a whole day is done); the
        // equivalence test feeds both the same hours and compares the daily tier.
        $deadline = microtime(true) + max(1, $budgetSeconds);
        $now = $this->clock->now()->getTimestamp();
        $limit = $now - self::GRACE_S;
        $lastDay = $limit - $limit % 86400;
        $mark = $this->state->get();
        $wm = $mark['watermark'] ?? null;
        $out = ['days' => 0, 'slices' => 0, 'rows' => 0, 'watermark' => $wm, 'behind_days' => 0];
        if ($wm === null) {
            $first = $this->db->value('SELECT ' . PostgresSql::ts('MIN(hour_start)') . ' FROM rmm_metric_hourly');
            if ($first === null) {
                return $out;
            }
            $ts = PostgresSql::epoch((string) $first);
            $wm = PostgresSql::utc($ts - $ts % 86400);
        }
        $day = PostgresSql::epoch($wm);
        $detail = $mark['detail'] ?? [];
        $resumeAfter = isset($detail['day'], $detail['asset_done']) && PostgresSql::epoch((string) $detail['day']) === $day ? (int) $detail['asset_done'] : 0;
        for ($n = 0; $day < $lastDay && $n < self::MAX_DAYS_PER_RUN; ++$n) {
            $from = PostgresSql::utc($day);
            $to = PostgresSql::utc($day + 86400);
            $range = $this->db->queryOne('SELECT COALESCE(MIN(asset_id), 0) AS lo, COALESCE(MAX(asset_id), 0) AS hi FROM rmm_metric_hourly WHERE hour_start >= ? AND hour_start < ?', [$from, $to]) ?? ['lo' => 0, 'hi' => 0];
            $maxAsset = (int) $range['hi'];
            $done = max($resumeAfter, (int) $range['lo'] - 1);
            $resumeAfter = 0;
            while ($done < $maxAsset) {
                if (microtime(true) > $deadline || ($maxSlices !== null && $out['slices'] >= $maxSlices)) {
                    $this->state->put(PostgresRollupState::ROLLUP, $from, ['day' => $from, 'asset_done' => $done]);
                    $out['watermark'] = $from;
                    $out['behind_days'] = intdiv($lastDay - $day, 86400);

                    return $out;
                }
                $out['rows'] += $this->slice($from, $to, $done, $done + $this->assetsPerSlice);
                ++$out['slices'];
                $done += $this->assetsPerSlice;
            }
            $day += 86400;
            ++$out['days'];
            $this->state->put(PostgresRollupState::ROLLUP, PostgresSql::utc($day), null);
        }
        $out['watermark'] = PostgresSql::utc($day);
        $out['behind_days'] = max(0, intdiv($lastDay - $day, 86400));

        return $out;
    }

    public function rollupWatermark(): ?string
    {
        return $this->state->watermark();
    }

    private function slice(string $from, string $to, int $afterAsset, int $upToAsset): int
    {
        return $this->db->execute('INSERT INTO rmm_metric_daily (asset_id, metric_key, instance, day_start, samples, sum_value, min_value, max_value)
            SELECT asset_id, metric_key, instance, ?::timestamptz, SUM(samples)::bigint, SUM(sum_value), MIN(min_value), MAX(max_value)
            FROM rmm_metric_hourly WHERE asset_id > ? AND asset_id <= ? AND hour_start >= ? AND hour_start < ?
            GROUP BY asset_id, metric_key, instance
            ON CONFLICT (asset_id, metric_key, instance, day_start) DO UPDATE SET samples = EXCLUDED.samples, sum_value = EXCLUDED.sum_value, min_value = EXCLUDED.min_value, max_value = EXCLUDED.max_value',
            [$from, $afterAsset, $upToAsset, $from, $to]);
    }

    /** @return array{n:int,total:float,lo:float,hi:float,at:string}|null */
    private function tierAggregate(string $table, string $col, int $assetId, string $key, string $inst, string $from, ?string $before): ?array
    {
        $range = "$col >= ?" . ($before === null ? '' : " AND $col < ?");
        $params = [$assetId, $key, $inst, $from, ...($before === null ? [] : [$before])];
        $agg = $this->db->queryOne("SELECT COALESCE(SUM(samples), 0)::bigint AS n, COALESCE(SUM(sum_value), 0) AS total, MIN(min_value) AS lo, MAX(max_value) AS hi
            FROM $table WHERE asset_id = ? AND metric_key = ? AND instance = ? AND $range", $params);
        if ($agg === null || (int) $agg['n'] === 0) {
            return null;
        }
        $at = $this->db->queryOne('SELECT ' . PostgresSql::ts($col, 't') . " FROM $table WHERE asset_id = ? AND metric_key = ? AND instance = ? AND $range ORDER BY max_value DESC, $col DESC LIMIT 1", $params);

        return ['n' => (int) $agg['n'], 'total' => (float) $agg['total'], 'lo' => (float) $agg['lo'], 'hi' => (float) $agg['hi'], 'at' => (string) ($at['t'] ?? $from)];
    }

    private function dailySplit(int $sinceTs, int $untilTs): ?string
    {
        if ($untilTs - $sinceTs <= $this->dailyFromSeconds) {
            return null;
        }
        $wm = $this->state->watermark();
        if ($wm === null || PostgresSql::epoch($wm) <= $sinceTs - $sinceTs % 86400) {
            return null;
        }

        return $wm;
    }

    /** Delete up to $n rows older than $before, joined back on the primary key (correct on a partitioned table and on a hypertable). */
    private function deleteBatch(string $table, string $column, string $before, int $n): int
    {
        $pk = $table === 'rmm_metric_latest' ? ['asset_id', 'metric_key', 'instance'] : ['asset_id', 'metric_key', 'instance', $column];
        $cols = implode(', ', $pk);
        $on = implode(' AND ', array_map(static fn (string $c): string => "t.$c = d.$c", $pk));

        return $this->db->execute("DELETE FROM $table t USING (SELECT $cols FROM $table WHERE $column < ? LIMIT " . max(1, $n) . ") d WHERE $on", [$before]);
    }
}
