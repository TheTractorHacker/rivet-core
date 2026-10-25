<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store;

/**
 * {@see ChangeEventStore} that picks the real store on first use ({@see RmmDataStores::lazy()}): building the module must not read the settings row, because an
 * off module answers from its state file without touching the database. Every method delegates unchanged. Generated shape; keep it a plain delegate.
 *
 * @internal
 */
final class LazyChangeEventStore implements ChangeEventStore
{
    /** @param \Closure(): RmmDataStores $resolve */
    public function __construct(private readonly \Closure $resolve)
    {
    }

    private function inner(): ChangeEventStore
    {
        return ($this->resolve)()->changes();
    }

    public function admit(int $deviceId, array $records, array $caps, int $nowTs, int $rejected, int $agentDropped, string $configVersion, ?string $monitorJson, ?int $clientId = null): array
    {
        return $this->inner()->admit($deviceId, $records, $caps, $nowTs, $rejected, $agentDropped, $configVersion, $monitorJson, $clientId);
    }

    public function dedupeKeysReceivedAt(int $deviceId, string $receivedAtUtc): array
    {
        return $this->inner()->dedupeKeysReceivedAt($deviceId, $receivedAtUtc);
    }

    public function search(\RivetCore\Rmm\Store\ChangeCriteria $criteria, int $limit, int $offset): array
    {
        return $this->inner()->search($criteria, $limit, $offset);
    }

    public function timeline(int $deviceId, string $fromUtc, string $toUtc, int $limit): array
    {
        return $this->inner()->timeline($deviceId, $fromUtc, $toUtc, $limit);
    }

    public function countMatching(int $deviceId, string $sinceUtc, string $watch, string $kind, int $changeType, string $targetContains, string $itemContains, int $stopAt): int
    {
        return $this->inner()->countMatching($deviceId, $sinceUtc, $watch, $kind, $changeType, $targetContains, $itemContains, $stopAt);
    }

    public function fleetRowCount(?array $visibleClientIds = null): int
    {
        return $this->inner()->fleetRowCount($visibleClientIds);
    }

    public function deviceLedger(int $deviceId): ?array
    {
        return $this->inner()->deviceLedger($deviceId);
    }

    public function countsByWatch(string $sinceUtc, ?array $visibleClientIds = null): array
    {
        return $this->inner()->countsByWatch($sinceUtc, $visibleClientIds);
    }

    public function ledgerTotals(): array
    {
        return $this->inner()->ledgerTotals();
    }

    public function reassignClient(int $deviceId, int $clientId): int
    {
        return $this->inner()->reassignClient($deviceId, $clientId);
    }

    public function hasLedger(): bool
    {
        return $this->inner()->hasLedger();
    }

    public function enforceLimits(string $cutoffUtc, int $deviceMaxRows, int $fleetMaxRows, int $runCap, int $batch, ?\Closure $pause = null): array
    {
        return $this->inner()->enforceLimits($cutoffUtc, $deviceMaxRows, $fleetMaxRows, $runCap, $batch, $pause);
    }
}
