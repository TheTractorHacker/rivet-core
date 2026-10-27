<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Contracts;

/**
 * Optional companion of {@see RmmMetricSinkInterface}: what a sink that keeps history can answer. The read model uses it for the
 * "network now versus the 24 hour peak" bar and for sparklines. An edition whose metric store is not a Core table implements it on
 * its adapter; when the module is given a sink that does not implement it, those answers are simply absent and the pages fall back to
 * the latest reading the device sent. Core's {@see \RivetCore\Rmm\Support\DatabaseMetricSink} implements both.
 *
 * Metric keys and instances are those of the sink contract (instance null means "the metric has no instance"). Windows are
 * hour-granular: a window starting at 14:20 includes the 14:00 hour.
 *
 * @api
 */
interface RmmMetricReaderInterface
{
    /**
     * The newest reading of each (key, instance) of an asset.
     *
     * @param list<string>|null $keys only these metric keys; null = all
     * @return list<array{key:string, instance:?string, value:int|float, at:\DateTimeImmutable, label:?string}>
     */
    public function latest(int $assetId, ?array $keys = null): array;

    /**
     * Minimum, maximum and mean of one metric since $since, or null when there is no data in the window.
     *
     * @return array{min:float, max:float, avg:float, samples:int, peak_at:\DateTimeImmutable}|null peak_at is the start of the hour that held the maximum
     */
    public function peak(int $assetId, string $key, ?string $instance, \DateTimeImmutable $since): ?array;

    /**
     * One point per hour that has data, oldest first.
     *
     * @return list<array{at:\DateTimeImmutable, min:float, max:float, avg:float, samples:int}>
     */
    public function series(int $assetId, string $key, ?string $instance, \DateTimeImmutable $since, \DateTimeImmutable $until): array;
}
