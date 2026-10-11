<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Storage;

use RivetCore\Rmm\Support\Sql;

/**
 * The daily tier of the database metric sink (scaling item S5): `rmm_metric_hourly` (the finest stored tier; the sink never keeps raw samples)
 * rolled up into `rmm_metric_daily`, one row per (asset, key, instance, UTC day). Charts and peaks over more than a week read the daily tier
 * ({@see \RivetCore\Rmm\Support\DatabaseMetricSink}), so a 90 day graph reads 90 rows per series, not 2,160.
 *
 * A day is rolled up only when it is complete and a grace period has passed ({@see GRACE_S}: a device that buffered samples while it was offline can
 * deliver a late hour), in slices of assets so one statement never scans a day of the whole fleet, under a time budget per run, and the watermark
 * (`rmm_storage_state`) moves only when a whole day is done. Every slice is a REPLACE-style upsert computed from the hourly rows, so running a slice twice,
 * or after a crash, gives the same rows: the job is idempotent. An hour that arrives after its day was rolled up stays in the hourly tier only.
 *
 * @api
 */
final class MetricRollup
{
    public const GRACE_S = 7200;
    public const ASSETS_PER_SLICE = 500;
    public const MAX_DAYS_PER_RUN = 40;

    private readonly StorageState $state;

    /** @param int $assetsPerSlice assets one statement covers (a seam for tests; the default suits a 10,000 device fleet) */
    public function __construct(private readonly Sql $sql, ?StorageState $state = null, private readonly int $assetsPerSlice = self::ASSETS_PER_SLICE)
    {
        $this->state = $state ?? new StorageState($sql);
    }

    /**
     * Roll up as many complete days as the budget allows.
     *
     * @param int $budgetSeconds stop starting new slices after this many seconds
     * @param int|null $maxSlices stop after this many slices (null = no limit); the next run resumes where this one stopped
     * @return array{days:int,slices:int,rows:int,watermark:?string,behind_days:int}
     */
    public function run(int $budgetSeconds = 20, ?int $maxSlices = null): array
    {
        $deadline = microtime(true) + max(1, $budgetSeconds);
        $now = $this->sql->time();
        $limit = $now - self::GRACE_S;
        $lastDay = $limit - $limit % 86400;   // the first day that is NOT complete (plus grace) yet: every day before it may be rolled up
        $mark = $this->state->get(StorageState::ROLLUP);
        $wm = $mark['watermark'] ?? null;
        $out = ['days' => 0, 'slices' => 0, 'rows' => 0, 'watermark' => $wm, 'behind_days' => 0];
        if ($wm === null) {
            $first = $this->sql->val('SELECT MIN(hour_start) FROM rmm_metric_hourly');
            if ($first === null) {
                return $out;   // nothing to roll up yet; the watermark is set by the first run that has data
            }
            $ts = Sql::ts((string) $first);
            $wm = gmdate('Y-m-d H:i:s', $ts - $ts % 86400);
        }
        $day = Sql::ts($wm);
        $detail = $mark['detail'] ?? [];
        $resumeAfter = isset($detail['day'], $detail['asset_done']) && Sql::ts((string) $detail['day']) === $day ? (int) $detail['asset_done'] : 0;
        for ($n = 0; $day < $lastDay && $n < self::MAX_DAYS_PER_RUN; ++$n) {
            $from = gmdate('Y-m-d H:i:s', $day);
            $to = gmdate('Y-m-d H:i:s', $day + 86400);
            $maxAsset = (int) $this->sql->val('SELECT COALESCE(MAX(asset_id), 0) FROM rmm_metric_hourly WHERE hour_start >= ? AND hour_start < ?', [$from, $to]);
            $done = $resumeAfter;
            $resumeAfter = 0;
            while ($done < $maxAsset) {
                if (microtime(true) > $deadline || ($maxSlices !== null && $out['slices'] >= $maxSlices)) {
                    $this->state->put(StorageState::ROLLUP, $from, ['day' => $from, 'asset_done' => $done]);
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
            $this->state->put(StorageState::ROLLUP, gmdate('Y-m-d H:i:s', $day), null);
        }
        $out['watermark'] = gmdate('Y-m-d H:i:s', $day);
        $out['behind_days'] = max(0, intdiv($lastDay - $day, 86400));

        return $out;
    }

    /** Assets in ($afterAsset, $upToAsset] of one UTC day. @return int rows written (affected rows of the upsert) */
    private function slice(string $from, string $to, int $afterAsset, int $upToAsset): int
    {
        return $this->sql->run('INSERT INTO rmm_metric_daily (asset_id, metric_key, instance, day_start, samples, sum_value, min_value, max_value)
            SELECT asset_id, metric_key, instance, ?, SUM(samples), SUM(sum_value), MIN(min_value), MAX(max_value)
            FROM rmm_metric_hourly WHERE asset_id > ? AND asset_id <= ? AND hour_start >= ? AND hour_start < ?
            GROUP BY asset_id, metric_key, instance
            ON DUPLICATE KEY UPDATE samples = VALUES(samples), sum_value = VALUES(sum_value), min_value = VALUES(min_value), max_value = VALUES(max_value)',
            [$from, $afterAsset, $upToAsset, $from, $to]);
    }
}
