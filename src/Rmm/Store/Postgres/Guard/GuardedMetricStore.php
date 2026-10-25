<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\Postgres\Guard;

use RivetCore\Rmm\Store\HotStoreUnavailableException;
use RivetCore\Rmm\Store\MetricStore;

/**
 * {@see MetricStore} with the degraded behaviour of {@see HotStoreGuard}: an ingest that fails is dropped and counted (a check-in never fails over metrics), the
 * readers answer "no data" (the dials and graphs of every device page ask on each view, so an outage must blank them, not break the page) and the fault is counted,
 * and the retention and rollup jobs report nothing done.
 *
 * @internal
 */
final class GuardedMetricStore implements MetricStore
{
    public function __construct(private readonly MetricStore $inner, private readonly HotStoreGuard $guard)
    {
    }

    public function ingest(array $samples, int $integrationId): void
    {
        $this->guard->lossy('metrics', 'ingest', fn () => $this->inner->ingest($samples, $integrationId), null, count($samples));
    }

    public function latest(int $assetId, ?array $keys = null): array
    {
        return $this->blank(fn (): array => $this->inner->latest($assetId, $keys), []);
    }

    public function peak(int $assetId, string $key, ?string $instance, \DateTimeImmutable $since): ?array
    {
        return $this->blank(fn (): ?array => $this->inner->peak($assetId, $key, $instance, $since), null);
    }

    public function series(int $assetId, string $key, ?string $instance, \DateTimeImmutable $since, \DateTimeImmutable $until): array
    {
        return $this->blank(fn (): array => $this->inner->series($assetId, $key, $instance, $since, $until), []);
    }

    public function retentionDays(): int
    {
        return $this->inner->retentionDays();
    }

    public function dailyRetentionDays(): int
    {
        return $this->inner->dailyRetentionDays();
    }

    public function prune(int $cap = 200000, ?\Closure $pause = null, ?int $hourlyDays = null, ?int $dailyDays = null, array $skip = []): int
    {
        return $this->guard->maintenance('metrics', 'prune', fn (): int => $this->inner->prune($cap, $pause, $hourlyDays, $dailyDays, $skip), 0);
    }

    public function rollUp(int $budgetSeconds = 20, ?int $maxSlices = null): array
    {
        return $this->guard->maintenance('metrics', 'rollUp', fn (): array => $this->inner->rollUp($budgetSeconds, $maxSlices),
            ['days' => 0, 'slices' => 0, 'rows' => 0, 'watermark' => null, 'behind_days' => 0]);
    }

    public function rollupWatermark(): ?string
    {
        return $this->guard->maintenance('metrics', 'rollupWatermark', fn (): ?string => $this->inner->rollupWatermark(), null);
    }

    /**
     * @template T
     * @param \Closure(): T $read
     * @param T $empty
     * @return T
     */
    private function blank(\Closure $read, mixed $empty): mixed
    {
        try {
            return $this->guard->read('metrics', 'read', $read);
        } catch (HotStoreUnavailableException) {
            return $empty;   // already counted and logged by the guard
        }
    }
}
