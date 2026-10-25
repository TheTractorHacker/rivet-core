<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\Migration\DualWrite;

use RivetCore\Rmm\Store\MetricStore;

/** @internal */
final class DualWriteMetricStore implements MetricStore
{
    public function __construct(private readonly MetricStore $primary, private readonly ?MetricStore $secondary, private readonly DualWriteContext $ctx)
    {
    }

    public function ingest(array $samples, int $integrationId): void
    {
        $this->primary->ingest($samples, $integrationId);
        if ($this->secondary !== null) {
            $this->ctx->secondary('metrics.ingest', fn () => $this->secondary->ingest($samples, $integrationId));
        }
    }

    public function latest(int $assetId, ?array $keys = null): array
    {
        return $this->primary->latest($assetId, $keys);
    }

    public function peak(int $assetId, string $key, ?string $instance, \DateTimeImmutable $since): ?array
    {
        return $this->primary->peak($assetId, $key, $instance, $since);
    }

    public function series(int $assetId, string $key, ?string $instance, \DateTimeImmutable $since, \DateTimeImmutable $until): array
    {
        return $this->primary->series($assetId, $key, $instance, $since, $until);
    }

    public function retentionDays(): int
    {
        return $this->primary->retentionDays();
    }

    public function dailyRetentionDays(): int
    {
        return $this->primary->dailyRetentionDays();
    }

    public function prune(int $cap = 200000, ?\Closure $pause = null, ?int $hourlyDays = null, ?int $dailyDays = null, array $skip = []): int
    {
        return $this->ctx->paused ? 0 : $this->primary->prune($cap, $pause, $hourlyDays, $dailyDays, $skip);
    }

    public function rollUp(int $budgetSeconds = 20, ?int $maxSlices = null): array
    {
        return $this->ctx->paused ? [] : $this->primary->rollUp($budgetSeconds, $maxSlices);
    }

    public function rollupWatermark(): ?string
    {
        return $this->primary->rollupWatermark();
    }
}
