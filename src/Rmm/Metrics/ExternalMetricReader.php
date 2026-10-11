<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Metrics;

use RivetCore\Rmm\Contracts\ExternalMetricStoreInterface;
use RivetCore\Rmm\Contracts\RmmMetricReaderInterface;

/**
 * Reads history from an external store and the latest readings from the local sink. {@see latest()} always comes from the fallback (the
 * external store is not used for it). {@see series()} and {@see peak()} ask the store with an hour step for windows up to two days and a
 * day step beyond ({@see MetricSteps}); when the store throws, or has nothing, the fallback reader answers instead, so a page never fails
 * because the store is down. The local data is the source of truth for the recent window, so an empty answer from the store is not trusted
 * on its own.
 *
 * @api
 */
final class ExternalMetricReader implements RmmMetricReaderInterface
{
    public function __construct(private readonly ExternalMetricStoreInterface $store, private readonly RmmMetricReaderInterface $fallback)
    {
    }

    public function latest(int $assetId, ?array $keys = null): array
    {
        return $this->fallback->latest($assetId, $keys);
    }

    public function peak(int $assetId, string $key, ?string $instance, \DateTimeImmutable $since): ?array
    {
        try {
            $p = $this->store->peak($assetId, $key, $instance, $since);
        } catch (\Throwable) {
            $p = null;
        }

        return $p ?? $this->fallback->peak($assetId, $key, $instance, $since);
    }

    public function series(int $assetId, string $key, ?string $instance, \DateTimeImmutable $since, \DateTimeImmutable $until): array
    {
        try {
            $points = $this->store->series($assetId, $key, $instance, $since, $until, MetricSteps::forSpan(max(0, $until->getTimestamp() - $since->getTimestamp())));
        } catch (\Throwable) {
            $points = [];
        }

        return $points !== [] ? $points : $this->fallback->series($assetId, $key, $instance, $since, $until);
    }
}
