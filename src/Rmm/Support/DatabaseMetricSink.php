<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Support;

use RivetCore\Contracts\ClockInterface;
use RivetCore\Database\DatabaseInterface;
use RivetCore\Rmm\Contracts\RmmMetricReaderInterface;
use RivetCore\Rmm\Contracts\RmmMetricSinkInterface;

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
 * Rules shared with every sink: a utilization outside 0..100, a negative or non-finite value is dropped, never clamped. A retried
 * delivery of the same samples adds them twice to the rollup; that doubles count and sum together, so the mean is unchanged and the
 * minimum and maximum are unaffected.
 *
 * @api
 */
final class DatabaseMetricSink implements RmmMetricSinkInterface, RmmMetricReaderInterface
{
    public const ROWS_PER_STATEMENT = 200;
    public const DEFAULT_RETENTION_DAYS = 14;
    public const PRUNE_BATCH = 5000;
    /** Metrics worth an hourly history; the rest (totals, uptime, pending reboot) only keep their latest value. */
    public const ROLLUP_KEYS = ['cpu.utilization', 'memory.utilization', 'disk.utilization', 'disk.free_bytes', 'network.rx_bytes_per_s', 'network.tx_bytes_per_s'];
    private const KEY_RE = '/^[a-z0-9_.]{1,64}$/';

    private readonly Sql $sql;

    public function __construct(DatabaseInterface $database, ClockInterface $clock, private readonly int $retentionDays = self::DEFAULT_RETENTION_DAYS)
    {
        $this->sql = new Sql($database, $clock);
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
        $from = gmdate('Y-m-d H:i:s', $since->getTimestamp() - $since->getTimestamp() % 3600);
        $agg = $this->sql->one('SELECT COALESCE(SUM(samples), 0) AS n, COALESCE(SUM(sum_value), 0) AS total, MIN(min_value) AS lo, MAX(max_value) AS hi
            FROM rmm_metric_hourly WHERE asset_id = ? AND metric_key = ? AND instance = ? AND hour_start >= ?', [$assetId, $key, $instance ?? '', $from]);
        if ($agg === null || (int) $agg['n'] === 0) {
            return null;
        }
        $at = $this->sql->one('SELECT hour_start FROM rmm_metric_hourly WHERE asset_id = ? AND metric_key = ? AND instance = ? AND hour_start >= ?
            ORDER BY max_value DESC, hour_start DESC LIMIT 1', [$assetId, $key, $instance ?? '', $from]);

        return ['min' => (float) $agg['lo'], 'max' => (float) $agg['hi'], 'avg' => (float) $agg['total'] / (int) $agg['n'], 'samples' => (int) $agg['n'],
            'peak_at' => self::at((string) ($at['hour_start'] ?? $from))];
    }

    public function series(int $assetId, string $key, ?string $instance, \DateTimeImmutable $since, \DateTimeImmutable $until): array
    {
        $from = gmdate('Y-m-d H:i:s', $since->getTimestamp() - $since->getTimestamp() % 3600);
        $rows = $this->sql->all('SELECT hour_start, samples, sum_value, min_value, max_value FROM rmm_metric_hourly
            WHERE asset_id = ? AND metric_key = ? AND instance = ? AND hour_start >= ? AND hour_start <= ? ORDER BY hour_start LIMIT 2000',
            [$assetId, $key, $instance ?? '', $from, $until->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')]);
        $out = [];
        foreach ($rows as $r) {
            $n = max(1, (int) $r['samples']);
            $out[] = ['at' => self::at((string) $r['hour_start']), 'min' => (float) $r['min_value'], 'max' => (float) $r['max_value'], 'avg' => (float) $r['sum_value'] / $n, 'samples' => (int) $r['samples']];
        }

        return $out;
    }

    /**
     * Delete expired rows in batches (hourly rollups older than the retention, latest readings of assets that stopped reporting for
     * longer). Returns the rows deleted.
     *
     * @param int $cap rows one call may delete (the next call continues)
     * @param (\Closure(int):void)|null $pause called between batches with microseconds to wait
     */
    public function prune(int $cap = 200000, ?\Closure $pause = null): int
    {
        $days = max(1, $this->retentionDays);
        $total = 0;
        foreach ([['rmm_metric_hourly', 'hour_start'], ['rmm_metric_latest', 'sampled_at']] as [$table, $column]) {
            $before = $this->sql->utcAt(-$days * 86400);
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
