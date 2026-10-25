<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\Postgres\Guard;

use RivetCore\Rmm\Store\LogCriteria;
use RivetCore\Rmm\Store\LogStore;

/**
 * {@see LogStore} with the degraded behaviour of {@see HotStoreGuard}: an admit that fails throws {@see \RivetCore\Rmm\Store\HotStoreUnavailableException}, which the
 * log service answers with a retryable 503 so the agent keeps its batch (nothing is lost, nothing is counted as dropped); reads throw the same; the retention job
 * reports nothing done.
 *
 * @internal
 */
final class GuardedLogStore implements LogStore
{
    private const ZERO = ['retention' => 0, 'device_cap' => 0, 'fleet_cap' => 0];

    public function __construct(private readonly LogStore $inner, private readonly HotStoreGuard $guard)
    {
    }

    public function admit(int $deviceId, array $entries, array $caps, int $nowTs, int $rejected, int $agentDropped, ?int $clientId = null): array
    {
        return $this->guard->strict('logs', 'admit', fn (): array => $this->inner->admit($deviceId, $entries, $caps, $nowTs, $rejected, $agentDropped, $clientId));
    }

    public function search(LogCriteria $criteria, int $limit, int $offset): array
    {
        return $this->guard->read('logs', 'search', fn (): array => $this->inner->search($criteria, $limit, $offset));
    }

    public function countMatching(int $deviceId, string $sinceUtc, int $maxLevel, string $sourceContains, ?int $code, string $messageContains, int $stopAt): int
    {
        return $this->guard->read('logs', 'countMatching', fn (): int => $this->inner->countMatching($deviceId, $sinceUtc, $maxLevel, $sourceContains, $code, $messageContains, $stopAt));
    }

    public function fleetRowCount(): int
    {
        return $this->guard->read('logs', 'fleetRowCount', fn (): int => $this->inner->fleetRowCount());
    }

    public function deviceLedger(int $deviceId): ?array
    {
        return $this->guard->read('logs', 'deviceLedger', fn (): ?array => $this->inner->deviceLedger($deviceId));
    }

    public function oldestEventAt(?int $deviceId = null): ?string
    {
        return $this->guard->read('logs', 'oldestEventAt', fn (): ?string => $this->inner->oldestEventAt($deviceId));
    }

    public function reassignClient(int $deviceId, int $clientId): int
    {
        return $this->guard->strict('logs', 'reassignClient', fn (): int => $this->inner->reassignClient($deviceId, $clientId));
    }

    public function enforceLimits(string $cutoffUtc, int $deviceMaxRows, int $fleetMaxRows, int $runCap, int $batch, ?\Closure $pause = null): array
    {
        return $this->guard->maintenance('logs', 'enforceLimits', fn (): array => $this->inner->enforceLimits($cutoffUtc, $deviceMaxRows, $fleetMaxRows, $runCap, $batch, $pause), self::ZERO);
    }
}
