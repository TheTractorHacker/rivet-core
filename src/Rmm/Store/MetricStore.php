<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store;

use RivetCore\Rmm\Contracts\RmmMetricReaderInterface;
use RivetCore\Rmm\Contracts\RmmMetricSinkInterface;

/**
 * HOT store: the metric tiers (`rmm_metric_latest`, `rmm_metric_hourly`, `rmm_metric_daily`). It IS the existing sink and reader contract
 * ({@see RmmMetricSinkInterface}, {@see RmmMetricReaderInterface}, so an edition that already wires a sink keeps working and a
 * `CompositeMetricSink` can hold one) plus the two operations the maintenance job needs: roll finished days up and delete what is past retention.
 *
 * Rules (checked by Testing\RmmDataStoreContractTestCase): a utilization outside 0..100, a negative and a non-finite value is dropped, never clamped; a
 * reading older than the stored one never replaces it; only the keys in the rollup list gain an hourly history; a re-delivered batch doubles count and sum
 * together (the mean is unchanged); peak() and series() answer from the daily tier for windows longer than a week once the rollup has reached them.
 *
 * Nothing here touches control data, so an implementation may live in another database.
 *
 * @api
 */
interface MetricStore extends RmmMetricSinkInterface, RmmMetricReaderInterface
{
    /** Days the hourly rollups and the latest readings are kept when no explicit limit is given. */
    public function retentionDays(): int;

    /** Days the daily rollups are kept when no explicit limit is given. */
    public function dailyRetentionDays(): int;

    /**
     * Delete expired rows in batches (hourly rollups and latest readings older than the retention, daily rollups older than theirs). Returns the rows deleted.
     *
     * @param int $cap rows one call may delete (the next call continues)
     * @param (\Closure(int):void)|null $pause called between batches with the microseconds to wait
     * @param int|null $hourlyDays retention of the hourly rollups and latest readings for this call (default: the store's)
     * @param int|null $dailyDays retention of the daily rollups for this call
     * @param list<string> $skip tiers (by the names {@see StoreMaintenance::run()} reports as `partitioned`) that the maintenance already trimmed by dropping whole partitions
     */
    public function prune(int $cap = 200000, ?\Closure $pause = null, ?int $hourlyDays = null, ?int $dailyDays = null, array $skip = []): int;

    /**
     * Roll as many complete days as the budget allows from the hourly tier into the daily tier. Idempotent; resumes where the last run stopped.
     *
     * @param int $budgetSeconds stop starting new slices after this many seconds
     * @param int|null $maxSlices stop after this many slices (null = no limit)
     * @return array{days:int,slices:int,rows:int,watermark:?string,behind_days:int}
     */
    public function rollUp(int $budgetSeconds = 20, ?int $maxSlices = null): array;

    /** The first UTC day NOT yet in the daily tier (a database datetime), or null when no rollup has run. */
    public function rollupWatermark(): ?string;
}
