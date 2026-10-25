<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\Migration\DualWrite;

use RivetCore\Rmm\Store\ChangeCriteria;
use RivetCore\Rmm\Store\ChangeEventStore;
use RivetCore\Rmm\Store\ClientScopedChangeEventStore;

/** @internal */
final class DualWriteChangeEventStore implements ClientScopedChangeEventStore
{
    public function __construct(private readonly ChangeEventStore $primary, private readonly ?ChangeEventStore $secondary, private readonly DualWriteContext $ctx)
    {
    }

    public function admit(int $deviceId, array $records, array $caps, int $nowTs, int $rejected, int $agentDropped, string $configVersion, ?string $monitorJson, ?int $clientId = null): array
    {
        $result = self::admitOn($this->primary, $deviceId, $records, $caps, $nowTs, $rejected, $agentDropped, $configVersion, $monitorJson, $clientId);
        if ($this->secondary !== null) {
            $this->ctx->secondary('change_records.admit', fn () => self::admitOn($this->secondary, $deviceId, $records, $caps, $nowTs, $rejected, $agentDropped, $configVersion, $monitorJson, $clientId));
        }

        return $result;
    }

    private static function admitOn(ChangeEventStore $s, int $deviceId, array $records, array $caps, int $nowTs, int $rejected, int $agentDropped, string $configVersion, ?string $monitorJson, ?int $clientId): array
    {
        return $s instanceof ClientScopedChangeEventStore ? $s->admit($deviceId, $records, $caps, $nowTs, $rejected, $agentDropped, $configVersion, $monitorJson, $clientId)
            : $s->admit($deviceId, $records, $caps, $nowTs, $rejected, $agentDropped, $configVersion, $monitorJson);
    }

    public function dedupeKeysReceivedAt(int $deviceId, string $receivedAtUtc): array
    {
        return $this->primary->dedupeKeysReceivedAt($deviceId, $receivedAtUtc);
    }

    public function search(ChangeCriteria $criteria, int $limit, int $offset): array
    {
        return $this->primary->search($criteria, $limit, $offset);
    }

    public function timeline(int $deviceId, string $fromUtc, string $toUtc, int $limit): array
    {
        return $this->primary->timeline($deviceId, $fromUtc, $toUtc, $limit);
    }

    public function countMatching(int $deviceId, string $sinceUtc, string $watch, string $kind, int $changeType, string $targetContains, string $itemContains, int $stopAt): int
    {
        return $this->primary->countMatching($deviceId, $sinceUtc, $watch, $kind, $changeType, $targetContains, $itemContains, $stopAt);
    }

    public function fleetRowCount(?array $visibleClientIds = null): int
    {
        return $this->primary->fleetRowCount($visibleClientIds);
    }

    public function deviceLedger(int $deviceId): ?array
    {
        return $this->primary->deviceLedger($deviceId);
    }

    public function countsByWatch(string $sinceUtc, ?array $visibleClientIds = null): array
    {
        return $this->primary->countsByWatch($sinceUtc, $visibleClientIds);
    }

    public function ledgerTotals(): array
    {
        return $this->primary->ledgerTotals();
    }

    public function hasLedger(): bool
    {
        return $this->primary->hasLedger();
    }

    public function enforceLimits(string $cutoffUtc, int $deviceMaxRows, int $fleetMaxRows, int $runCap, int $batch, ?\Closure $pause = null): array
    {
        return $this->ctx->paused ? ['retention' => 0, 'device_cap' => 0, 'fleet_cap' => 0] : $this->primary->enforceLimits($cutoffUtc, $deviceMaxRows, $fleetMaxRows, $runCap, $batch, $pause);
    }

    public function reassignClient(int $deviceId, int $clientId): int
    {
        $n = $this->primary instanceof ClientScopedChangeEventStore ? $this->primary->reassignClient($deviceId, $clientId) : 0;
        if ($this->secondary instanceof ClientScopedChangeEventStore) {
            $this->ctx->secondary('change_records.reassign', fn () => $this->secondary->reassignClient($deviceId, $clientId));
        }

        return $n;
    }
}
