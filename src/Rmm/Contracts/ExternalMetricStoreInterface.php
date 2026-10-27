<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Contracts;

use RivetCore\Rmm\Metrics\ExternalStoreException;

/**
 * Optional: an external time-series store for metric history (VictoriaMetrics, InfluxDB, ClickHouse, TimescaleDB), for fleets beyond what
 * MariaDB serves from the hourly rollup (see docs/rmm/EXTERNAL_METRICS.md). Core never uses one unless an edition wires it:
 * {@see \RivetCore\Rmm\Metrics\ExternalMetricSink} buffers samples in the database outbox and calls {@see write()} from a cron job,
 * {@see \RivetCore\Rmm\Metrics\ExternalMetricReader} answers series and peak from {@see series()} and {@see peak()}.
 *
 * Rules every adapter keeps (checked by Testing\ExternalMetricStoreConformanceTestCase):
 *  - A point is one reading of (asset, key, instance) at one second. The series is (asset, key, instance); instance null and the empty
 *    string are the same series ("no instance"). Two writes of a point with the same series and second are ONE point: re-delivering a
 *    batch (the outbox does after a crash or a lost response) never changes what is read back (count, min, max, avg).
 *  - The sink drops a utilization outside 0..100, a negative and a non-finite value BEFORE the store; an adapter must still cope with
 *    them being absent and must never invent a value for a missing sample.
 *  - Keys, instances and labels are untrusted text. An adapter must never let them change the meaning of a query or a statement, never
 *    let one series leak into another, and throws only {@see ExternalStoreException}. A value an adapter cannot represent (control
 *    characters, absurd length) is skipped on write and simply has no data on read.
 *  - Windows are step-aligned: a bucket of width $stepSeconds starts at a multiple of the step counted from the Unix epoch and holds
 *    [start, start + step). series() returns one point per bucket that has data, oldest first, covering $since up to and including the
 *    bucket that holds $until. peak() aligns $since down to a whole hour (a whole day when the window is longer than two days) and covers up to now.
 *  - Reads never throw for an unknown asset, key or instance: series() is [] and peak() is null.
 *
 * @api
 */
interface ExternalMetricStoreInterface
{
    /**
     * Store one batch. Safe to call again with the same batch (idempotent for identical points).
     *
     * @param list<array{asset_id:int, key:string, instance:?string, value:int|float, at:\DateTimeImmutable, label:?string}> $points
     * @throws ExternalStoreException on any failure; {@see ExternalStoreException::$permanent} is true when the batch can never be accepted
     */
    public function write(array $points): void;

    /**
     * @return list<array{at:\DateTimeImmutable, min:float, max:float, avg:float, samples:int}> at is the start of the bucket
     * @throws ExternalStoreException when the store cannot be queried
     */
    public function series(int $assetId, string $key, ?string $instance, \DateTimeImmutable $since, \DateTimeImmutable $until, int $stepSeconds): array;

    /**
     * Minimum, maximum and mean of one metric since $since, or null when there is no data in the window.
     *
     * @return array{min:float, max:float, avg:float, samples:int, peak_at:\DateTimeImmutable}|null peak_at is the start of the step that held the maximum
     *         (one hour for windows up to two days, else one day: {@see \RivetCore\Rmm\Metrics\MetricSteps}); on a tie the latest step
     * @throws ExternalStoreException when the store cannot be queried
     */
    public function peak(int $assetId, string $key, ?string $instance, \DateTimeImmutable $since): ?array;

    /** Whether the store answers. Never throws. */
    public function ping(): bool;
}
