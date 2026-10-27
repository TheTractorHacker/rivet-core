<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store;

/**
 * The metric store the module hands to the check-in, the ingest queue and the readers: it asks the `rmm_data_store` setting (through $resolve) on first use and then
 * delegates to the store of that kind, so one wiring serves MariaDB and PostgreSQL and building the module still reads nothing from the database.
 *
 * @internal
 */
final class LazyMetricStore implements MetricStore
{
    /** @param \Closure(): RmmDataStores $resolve */
    public function __construct(private readonly \Closure $resolve)
    {
    }

    public function ingest(array $samples, int $integrationId): void
    {
        $this->store()->ingest($samples, $integrationId);
    }

    public function latest(int $assetId, ?array $keys = null): array
    {
        return $this->store()->latest($assetId, $keys);
    }

    public function peak(int $assetId, string $key, ?string $instance, \DateTimeImmutable $since): ?array
    {
        return $this->store()->peak($assetId, $key, $instance, $since);
    }

    public function series(int $assetId, string $key, ?string $instance, \DateTimeImmutable $since, \DateTimeImmutable $until): array
    {
        return $this->store()->series($assetId, $key, $instance, $since, $until);
    }

    public function retentionDays(): int
    {
        return $this->store()->retentionDays();
    }

    public function dailyRetentionDays(): int
    {
        return $this->store()->dailyRetentionDays();
    }

    public function prune(int $cap = 200000, ?\Closure $pause = null, ?int $hourlyDays = null, ?int $dailyDays = null, array $skip = []): int
    {
        return $this->store()->prune($cap, $pause, $hourlyDays, $dailyDays, $skip);
    }

    public function rollUp(int $budgetSeconds = 20, ?int $maxSlices = null): array
    {
        return $this->store()->rollUp($budgetSeconds, $maxSlices);
    }

    public function rollupWatermark(): ?string
    {
        return $this->store()->rollupWatermark();
    }

    private function store(): MetricStore
    {
        return ($this->resolve)()->metrics() ?? throw new \LogicException('The resolved RMM data stores have no metric store.');
    }
}
