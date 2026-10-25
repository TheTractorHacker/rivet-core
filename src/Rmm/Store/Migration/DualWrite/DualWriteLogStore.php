<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\Migration\DualWrite;

use RivetCore\Rmm\Store\ClientScopedLogStore;
use RivetCore\Rmm\Store\LogCriteria;
use RivetCore\Rmm\Store\LogStore;

/** @internal */
final class DualWriteLogStore implements ClientScopedLogStore
{
    public function __construct(private readonly LogStore $primary, private readonly ?LogStore $secondary, private readonly DualWriteContext $ctx)
    {
    }

    public function admit(int $deviceId, array $entries, array $caps, int $nowTs, int $rejected, int $agentDropped, ?int $clientId = null): array
    {
        $result = self::admitOn($this->primary, $deviceId, $entries, $caps, $nowTs, $rejected, $agentDropped, $clientId);
        if ($this->secondary !== null) {
            // The second store decides by its own ledger; what it decides is not used (the answer is the primary's) and a difference is what the verification looks for.
            $this->ctx->secondary('log_events.admit', fn () => self::admitOn($this->secondary, $deviceId, $entries, $caps, $nowTs, $rejected, $agentDropped, $clientId));
        }

        return $result;
    }

    private static function admitOn(LogStore $s, int $deviceId, array $entries, array $caps, int $nowTs, int $rejected, int $agentDropped, ?int $clientId): array
    {
        return $s instanceof ClientScopedLogStore ? $s->admit($deviceId, $entries, $caps, $nowTs, $rejected, $agentDropped, $clientId) : $s->admit($deviceId, $entries, $caps, $nowTs, $rejected, $agentDropped);
    }

    public function search(LogCriteria $criteria, int $limit, int $offset): array
    {
        return $this->primary->search($criteria, $limit, $offset);
    }

    public function countMatching(int $deviceId, string $sinceUtc, int $maxLevel, string $sourceContains, ?int $code, string $messageContains, int $stopAt): int
    {
        return $this->primary->countMatching($deviceId, $sinceUtc, $maxLevel, $sourceContains, $code, $messageContains, $stopAt);
    }

    public function fleetRowCount(): int
    {
        return $this->primary->fleetRowCount();
    }

    public function deviceLedger(int $deviceId): ?array
    {
        return $this->primary->deviceLedger($deviceId);
    }

    public function oldestEventAt(?int $deviceId = null): ?string
    {
        return $this->primary->oldestEventAt($deviceId);
    }

    public function enforceLimits(string $cutoffUtc, int $deviceMaxRows, int $fleetMaxRows, int $runCap, int $batch, ?\Closure $pause = null): array
    {
        return $this->ctx->paused ? ['retention' => 0, 'device_cap' => 0, 'fleet_cap' => 0] : $this->primary->enforceLimits($cutoffUtc, $deviceMaxRows, $fleetMaxRows, $runCap, $batch, $pause);
    }

    public function reassignClient(int $deviceId, int $clientId): int
    {
        $n = $this->primary instanceof ClientScopedLogStore ? $this->primary->reassignClient($deviceId, $clientId) : 0;
        if ($this->secondary instanceof ClientScopedLogStore) {
            $this->ctx->secondary('log_events.reassign', fn () => $this->secondary->reassignClient($deviceId, $clientId));
        }

        return $n;
    }
}
