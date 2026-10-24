<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Support;

use RivetCore\Contracts\ClockInterface;
use RivetCore\Database\DatabaseInterface;
use RivetCore\Rmm\Storage\MetricRollup;
use RivetCore\Rmm\Storage\StorageState;
use RivetCore\Rmm\Store\MetricStore;

/**
 * A metric sink backed by two Core tables (migration 0018), for editions without a metrics subsystem (RivetMSP): the newest reading of
 * every (asset, key, instance) in `rmm_metric_latest` and one rollup row per (asset, key, instance, hour) in `rmm_metric_hourly`
 * (sample count, sum, minimum and maximum). It implements {@see RmmMetricReaderInterface}, so the pages can show a 24 hour peak and
 * sparklines without any extra store.
 *
 * Cost and bounds: raw samples are never stored, so a device adds at most one row per metric instance per hour (about 12 rows an hour
 * for a typical machine, 14 days kept by default, see docs/rmm/CAPACITY.md). A call writes in multi-row statements of at most
 * {@see ROWS_PER_STATEMENT} rows, so an ingest batch of many check-ins costs a handful of statements. {@see prune()} deletes expired
 * rows in batches and is called by Housekeeping.
 *
 * This is also the MariaDB / MySQL implementation of {@see MetricStore} (the HOT metric store of #89): the class and its public behaviour are unchanged;
 * {@see rollUp()} and {@see rollupWatermark()} expose the daily rollup the maintenance job used to reach through {@see MetricRollup} directly.
 *
 * Rules shared with every sink: a utilization outside 0..100, a negative or non-finite value is dropped, never clamped. A retried
 * delivery of the same samples adds them twice to the rollup; that doubles count and sum together, so the mean is unchanged and the
 * minimum and maximum are unaffected.
 *
 * @api
 */
final class DatabaseMetricSink implements MetricStore
{
    public const ROWS_PER_STATEMENT = 200;
    public const DEFAULT_RETENTION_DAYS = 14;
    public const PRUNE_BATCH = 5000;
    public const DEFAULT_DAILY_RETENTION_DAYS = 400;
    /** Windows longer than a week are read from the daily tier (7 days of hourly points are 168 per series; 90 days would be 2,160). */
    public const DAILY_FROM_SECONDS = 604800;
    /**
     * Metrics worth an hourly history; the rest (totals, uptime, pending reboot) only keep their latest value. The list grows with the metric set (additive), so it is not part of the frozen API.
     *
     * @internal
     */
    public const ROLLUP_KEYS = ['cpu.utilization', 'memory.utilization', 'disk.utilization', 'disk.free_bytes', 'network.rx_bytes_per_s', 'network.tx_bytes_per_s',
        'memory.used_bytes', 'memory.available_bytes', 'cpu.core.utilization', 'disk.read_iops', 'disk.write_iops', 'disk.queue_length', 'network.errors', 'battery.charge_percent'];
    private const KEY_RE = '/^[a-z0-9_.]{1,64}$/D';

    private readonly Sql $sql;
    private readonly StorageState $state;

    /**
     * @param int $retentionDays days the hourly rollups and the latest readings are kept (the `metric_hourly_days` limit overrides it when an administrator set one)
     * @param int $dailyFromSeconds windows longer than this are answered from the daily tier ({@see \RivetCore\Rmm\Storage\MetricRollup}) where it covers them
     * @param int $dailyRetentionDays days the daily rollups are kept (the `metric_daily_days` limit overrides it)
     */
    public function __construct(DatabaseInterface $database, ClockInterface $clock, private readonly int $retentionDays = self::DEFAULT_RETENTION_DAYS, private readonly int $dailyFromSeconds = self::DAILY_FROM_SECONDS,
        private readonly int $dailyRetentionDays = self::DEFAULT_DAILY_RETENTION_DAYS)
    {
        $this->sql = new Sql($database, $clock);
        $this->state = new StorageState($this->sql);
    }

    public function ingest(array $samples, int $integrationId): void
    {
        /** @var array<string,array{0:int,1:string,2:string,3:float,4:?string,5:int}> $latest */
        $latest = [];
        /** @var array<string,array{0:int,1:string,2:string,3:int,4:int,5:float,6:float,7:float}> $hourly */
        $hourly = [];
        foreach ($samples as $s) {
            $key = $s['key'];
            $value = $s['value'];
            $asset = $s['asset_id'];
            if ($asset <= 0 || preg_match(self::KEY_RE, $key) !== 1 || !is_finite((float) $value) || $value < 0 || (str_ends_with($key, '.utilization') && $value > 100)) {
                continue;
            }
            $inst = $s['instance'] === null ? '' : mb_substr($s['instance'], 0, 64);
            $ts = $s['at']->getTimestamp();
            $id = $asset . "\0" . $key . "\0" . $inst;
            if (!isset($latest[$id]) || $ts >= $latest[$id][5]) {
                $latest[$id] = [$asset, $key, $inst, (float) $value, $s['label'] === null ? null : mb_substr($s['label'], 0, 64), $ts];
            }
            if (in_array($key, self::ROLLUP_KEYS, true)) {
                $hour = $ts - $ts % 3600;
                $hid = $id . "\0" . $hour;
                if (!isset($hourly[$hid])) {
                    $hourly[$hid] = [$asset, $key, $inst, $hour, 1, (float) $value, (float) $value, (float) $value];
                } else {
                    $h = &$hourly[$hid];
                    ++$h[4];
                    $h[5] += (float) $value;
                    $h[6] = min($h[6], (float) $value);
                    $h[7] = max($h[7], (float) $value);
                    unset($h);
                }
            }
        }
        foreach (array_chunk(array_values($latest), self::ROWS_PER_STATEMENT) as $chunk) {
            $params = [];
            foreach ($chunk as [$asset, $key, $inst, $value, $label, $ts]) {
                array_push($params, $asset, $key, $inst, $value, $label, gmdate('Y-m-d H:i:s', $ts));
            }
            $this->sql->run('INSERT INTO rmm_metric_latest (asset_id, metric_key, instance, value, label, sampled_at) VALUES '
                . implode(',', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?)'))
                . ' ON DUPLICATE KEY UPDATE value = IF(VALUES(sampled_at) >= sampled_at, VALUES(value), value), label = IF(VALUES(sampled_at) >= sampled_at, VALUES(label), label),'
                . ' sampled_at = GREATEST(sampled_at, VALUES(sampled_at))', $params);
        }
        foreach (array_chunk(array_values($hourly), self::ROWS_PER_STATEMENT) as $chunk) {
            $params = [];
            foreach ($chunk as [$asset, $key, $inst, $hour, $n, $sum, $min, $max]) {
                array_push($params, $asset, $key, $inst, gmdate('Y-m-d H:i:s', $hour), $n, $sum, $min, $max);
            }
            $this->sql->run('INSERT INTO rmm_metric_hourly (asset_id, metric_key, instance, hour_start, samples, sum_value, min_value, max_value) VALUES '
                . implode(',', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?, ?)'))
                . ' ON DUPLICATE KEY UPDATE samples = samples + VALUES(samples), sum_value = sum_value + VALUES(sum_value),'
                . ' min_value = LEAST(min_value, VALUES(min_value)), max_value = GREATEST(max_value, VALUES(max_value))', $params);
        }
    }

    public function rollUp(int $budgetSeconds = 20, ?int $maxSlices = null): array
    {
        return (new MetricRollup($this->sql, $this->state))->run($budgetSeconds, $maxSlices);
    }

    public function rollupWatermark(): ?string
    {
        return $this->state->rollupWatermark();
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
        $sql = 'SELECT metric_key, instance, value, label, sampled_at FROM rmm_metric_latest WHERE asset_id = ?';
        $params = [$assetId];
        if ($keys !== null) {
            if ($keys === []) {
                return [];
            }
            $sql .= ' AND metric_key IN (' . implode(',', array_fill(0, count($keys), '?')) . ')';
            array_push($params, ...$keys);
        }
        $out = [];
        foreach ($this->sql->all($sql . ' ORDER BY metric_key, instance', $params) as $r) {
            $out[] = ['key' => (string) $r['metric_key'], 'instance' => $r['instance'] === '' ? null : (string) $r['instance'], 'value' => self::number($r['value']),
                'at' => self::at((string) $r['sampled_at']), 'label' => $r['label'] === null ? null : (string) $r['label']];
        }

        return $out;
    }

    public function peak(int $assetId, string $key, ?string $instance, \DateTimeImmutable $since): ?array
    {
        $inst = $instance ?? '';
        $sinceTs = $since->getTimestamp();
        $split = $this->dailySplit($sinceTs, $this->sql->time());
        $from = gmdate('Y-m-d H:i:s', $sinceTs - $sinceTs % 3600);
        /** @var list<array{n:int,total:float,lo:float,hi:float,at:string}> $tiers one aggregate per tier that has data */
        $tiers = [];
        if ($split !== null) {
            // a window beyond a week: complete days from the daily tier (day-granular), the days since the rollup watermark from the hourly tier
            $dayFrom = gmdate('Y-m-d H:i:s', $sinceTs - $sinceTs % 86400);
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
        if ($tiers === []) {
            return null;
        }
        $n = 0;
        $total = 0.0;
        $lo = $tiers[0]['lo'];
        $hi = $tiers[0]['hi'];
        $at = $tiers[0]['at'];
        foreach ($tiers as $t) {
            $n += $t['n'];
            $total += $t['total'];
            $lo = min($lo, $t['lo']);
            if ($t['hi'] >= $hi) {
                $hi = $t['hi'];
                $at = $t['at'];   // on a tie the later tier (more recent data) holds the peak
            }
        }

        return ['min' => $lo, 'max' => $hi, 'avg' => $total / $n, 'samples' => $n, 'peak_at' => self::at($at)];
    }

    /** @return array{n:int,total:float,lo:float,hi:float,at:string}|null */
    private function tierAggregate(string $table, string $col, int $assetId, string $key, string $inst, string $from, ?string $before): ?array
    {
        $range = "`$col` >= ?" . ($before === null ? '' : " AND `$col` < ?");
        $params = [$assetId, $key, $inst, $from, ...($before === null ? [] : [$before])];
        $agg = $this->sql->one("SELECT COALESCE(SUM(samples), 0) AS n, COALESCE(SUM(sum_value), 0) AS total, MIN(min_value) AS lo, MAX(max_value) AS hi
            FROM `$table` WHERE asset_id = ? AND metric_key = ? AND instance = ? AND $range", $params);
        if ($agg === null || (int) $agg['n'] === 0) {
            return null;
        }
        $at = $this->sql->one("SELECT `$col` AS t FROM `$table` WHERE asset_id = ? AND metric_key = ? AND instance = ? AND $range ORDER BY max_value DESC, `$col` DESC LIMIT 1", $params);

        return ['n' => (int) $agg['n'], 'total' => (float) $agg['total'], 'lo' => (float) $agg['lo'], 'hi' => (float) $agg['hi'], 'at' => (string) ($at['t'] ?? $from)];
    }

    public function series(int $assetId, string $key, ?string $instance, \DateTimeImmutable $since, \DateTimeImmutable $until): array
    {
        $inst = $instance ?? '';
        $sinceTs = $since->getTimestamp();
        $untilTs = $until->getTimestamp();
        $from = gmdate('Y-m-d H:i:s', $sinceTs - $sinceTs % 3600);
        $to = $until->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $out = [];
        $split = $untilTs - $sinceTs > $this->dailyFromSeconds ? $this->dailySplit($sinceTs, $untilTs) : null;
        if ($split !== null) {
            $dayFrom = gmdate('Y-m-d H:i:s', $sinceTs - $sinceTs % 86400);
            foreach ($this->sql->all('SELECT day_start, samples, sum_value, min_value, max_value FROM rmm_metric_daily
                WHERE asset_id = ? AND metric_key = ? AND instance = ? AND day_start >= ? AND day_start < ? AND day_start <= ? ORDER BY day_start LIMIT 4000',
                [$assetId, $key, $inst, $dayFrom, $split, $to]) as $r) {
                $c = max(1, (int) $r['samples']);
                $out[] = ['at' => self::at((string) $r['day_start']), 'min' => (float) $r['min_value'], 'max' => (float) $r['max_value'], 'avg' => (float) $r['sum_value'] / $c, 'samples' => (int) $r['samples']];
            }
            $from = max($from, $split);
        }
        $rows = $this->sql->all('SELECT hour_start, samples, sum_value, min_value, max_value FROM rmm_metric_hourly
            WHERE asset_id = ? AND metric_key = ? AND instance = ? AND hour_start >= ? AND hour_start <= ? ORDER BY hour_start LIMIT 2000',
            [$assetId, $key, $inst, $from, $to]);
        foreach ($rows as $r) {
            $n = max(1, (int) $r['samples']);
            $out[] = ['at' => self::at((string) $r['hour_start']), 'min' => (float) $r['min_value'], 'max' => (float) $r['max_value'], 'avg' => (float) $r['sum_value'] / $n, 'samples' => (int) $r['samples']];
        }

        return $out;
    }

    /**
     * The rollup watermark (first day NOT in the daily tier, as a UTC datetime) when a window starting at $sinceTs and ending at $untilTs is long enough
     * to be answered from the daily tier and the tier has completed days inside it; otherwise null (the hourly tier answers alone).
     */
    private function dailySplit(int $sinceTs, int $untilTs): ?string
    {
        if ($untilTs - $sinceTs <= $this->dailyFromSeconds) {
            return null;
        }
        $wm = $this->state->rollupWatermark();
        if ($wm === null || Sql::ts($wm) <= $sinceTs - $sinceTs % 86400) {
            return null;
        }

        return $wm;
    }

    /**
     * Delete expired rows in batches (hourly rollups older than the retention, latest readings of assets that stopped reporting for
     * longer). Returns the rows deleted.
     *
     * @param int $cap rows one call may delete (the next call continues)
     * @param (\Closure(int):void)|null $pause called between batches with microseconds to wait
     * @param int|null $hourlyDays retention of the hourly rollups and latest readings for this call (default: the constructor's)
     * @param int|null $dailyDays retention of the daily rollups for this call
     * @param list<string> $skip tables not to delete from (they are partitioned and maintained by drop)
     */
    public function prune(int $cap = 200000, ?\Closure $pause = null, ?int $hourlyDays = null, ?int $dailyDays = null, array $skip = []): int
    {
        $days = max(1, $hourlyDays ?? $this->retentionDays);
        $total = 0;
        $targets = [['rmm_metric_hourly', 'hour_start', $days], ['rmm_metric_latest', 'sampled_at', $days], ['rmm_metric_daily', 'day_start', max(1, $dailyDays ?? $this->dailyRetentionDays)]];
        foreach ($targets as [$table, $column, $keep]) {
            if (in_array($table, $skip, true)) {
                continue;   // a partitioned table drops whole partitions instead (Storage\PartitionMaintenance)
            }
            $before = $this->sql->utcAt(-$keep * 86400);
            $done = 0;
            while ($done < $cap) {
                $n = $this->sql->run("DELETE FROM `$table` WHERE `$column` < ? LIMIT " . self::PRUNE_BATCH, [$before]);
                $done += $n;
                if ($n < self::PRUNE_BATCH) {
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

    private static function number(mixed $v): int|float
    {
        $f = (float) $v;

        return $f == floor($f) && abs($f) < 9e15 ? (int) $f : $f;
    }

    private static function at(string $utc): \DateTimeImmutable
    {
        return new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
    }
}
