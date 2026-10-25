<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\Postgres\Guard;

use RivetCore\Rmm\Store\ChangeCriteria;
use RivetCore\Rmm\Store\ChangeEventStore;

/**
 * {@see ChangeEventStore} with the degraded behaviour of {@see HotStoreGuard}: an admit that fails throws {@see \RivetCore\Rmm\Store\HotStoreUnavailableException}
 * (the change service answers a retryable 503 and the agent keeps its batch); reads throw the same; the retention job reports nothing done.
 *
 * @internal
 */
final class GuardedChangeEventStore implements ChangeEventStore
{
    private const ZERO = ['retention' => 0, 'device_cap' => 0, 'fleet_cap' => 0];

    public function __construct(private readonly ChangeEventStore $inner, private readonly HotStoreGuard $guard)
    {
    }

    public function admit(int $deviceId, array $records, array $caps, int $nowTs, int $rejected, int $agentDropped, string $configVersion, ?string $monitorJson, ?int $clientId = null): array
    {
        return $this->guard->strict('changes', 'admit', fn (): array => $this->inner->admit($deviceId, $records, $caps, $nowTs, $rejected, $agentDropped, $configVersion, $monitorJson, $clientId));
    }

    public function dedupeKeysReceivedAt(int $deviceId, string $receivedAtUtc): array
    {
        return $this->guard->read('changes', 'dedupeKeysReceivedAt', fn (): array => $this->inner->dedupeKeysReceivedAt($deviceId, $receivedAtUtc));
    }

    public function search(ChangeCriteria $criteria, int $limit, int $offset): array
    {
        return $this->guard->read('changes', 'search', fn (): array => $this->inner->search($criteria, $limit, $offset));
    }

    public function timeline(int $deviceId, string $fromUtc, string $toUtc, int $limit): array
    {
        return $this->guard->read('changes', 'timeline', fn (): array => $this->inner->timeline($deviceId, $fromUtc, $toUtc, $limit));
    }

    public function countMatching(int $deviceId, string $sinceUtc, string $watch, string $kind, int $changeType, string $targetContains, string $itemContains, int $stopAt): int
    {
        return $this->guard->read('changes', 'countMatching', fn (): int => $this->inner->countMatching($deviceId, $sinceUtc, $watch, $kind, $changeType, $targetContains, $itemContains, $stopAt));
    }

    public function fleetRowCount(?array $visibleClientIds = null): int
    {
        return $this->guard->read('changes', 'fleetRowCount', fn (): int => $this->inner->fleetRowCount($visibleClientIds));
    }

    public function deviceLedger(int $deviceId): ?array
    {
        return $this->guard->read('changes', 'deviceLedger', fn (): ?array => $this->inner->deviceLedger($deviceId));
    }

    public function countsByWatch(string $sinceUtc, ?array $visibleClientIds = null): array
    {
        return $this->guard->read('changes', 'countsByWatch', fn (): array => $this->inner->countsByWatch($sinceUtc, $visibleClientIds));
    }

    public function ledgerTotals(): array
    {
        return $this->guard->read('changes', 'ledgerTotals', fn (): array => $this->inner->ledgerTotals());
    }

    public function hasLedger(): bool
    {
        return $this->guard->read('changes', 'hasLedger', fn (): bool => $this->inner->hasLedger());
    }

    public function reassignClient(int $deviceId, int $clientId): int
    {
        return $this->guard->strict('changes', 'reassignClient', fn (): int => $this->inner->reassignClient($deviceId, $clientId));
    }

    public function enforceLimits(string $cutoffUtc, int $deviceMaxRows, int $fleetMaxRows, int $runCap, int $batch, ?\Closure $pause = null): array
    {
        return $this->guard->maintenance('changes', 'enforceLimits', fn (): array => $this->inner->enforceLimits($cutoffUtc, $deviceMaxRows, $fleetMaxRows, $runCap, $batch, $pause), self::ZERO);
    }
}
