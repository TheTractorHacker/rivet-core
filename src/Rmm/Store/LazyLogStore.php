<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store;

/**
 * {@see LogStore} that picks the real store on first use ({@see RmmDataStores::lazy()}): building the module must not read the settings row, because an
 * off module answers from its state file without touching the database. Every method delegates unchanged. Generated shape; keep it a plain delegate.
 *
 * @internal
 */
final class LazyLogStore implements LogStore
{
    /** @param \Closure(): RmmDataStores $resolve */
    public function __construct(private readonly \Closure $resolve)
    {
    }

    private function inner(): LogStore
    {
        return ($this->resolve)()->logs();
    }

    public function admit(int $deviceId, array $entries, array $caps, int $nowTs, int $rejected, int $agentDropped, ?int $clientId = null): array
    {
        return $this->inner()->admit($deviceId, $entries, $caps, $nowTs, $rejected, $agentDropped, $clientId);
    }

    public function search(\RivetCore\Rmm\Store\LogCriteria $criteria, int $limit, int $offset): array
    {
        return $this->inner()->search($criteria, $limit, $offset);
    }

    public function countMatching(int $deviceId, string $sinceUtc, int $maxLevel, string $sourceContains, ?int $code, string $messageContains, int $stopAt): int
    {
        return $this->inner()->countMatching($deviceId, $sinceUtc, $maxLevel, $sourceContains, $code, $messageContains, $stopAt);
    }

    public function fleetRowCount(): int
    {
        return $this->inner()->fleetRowCount();
    }

    public function deviceLedger(int $deviceId): ?array
    {
        return $this->inner()->deviceLedger($deviceId);
    }

    public function reassignClient(int $deviceId, int $clientId): int
    {
        return $this->inner()->reassignClient($deviceId, $clientId);
    }

    public function oldestEventAt(?int $deviceId = null): ?string
    {
        return $this->inner()->oldestEventAt($deviceId);
    }

    public function enforceLimits(string $cutoffUtc, int $deviceMaxRows, int $fleetMaxRows, int $runCap, int $batch, ?\Closure $pause = null): array
    {
        return $this->inner()->enforceLimits($cutoffUtc, $deviceMaxRows, $fleetMaxRows, $runCap, $batch, $pause);
    }
}
