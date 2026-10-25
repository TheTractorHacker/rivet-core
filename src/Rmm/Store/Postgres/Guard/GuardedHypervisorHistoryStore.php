<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\Postgres\Guard;

use RivetCore\Rmm\Store\InventoryAwareHypervisorHistoryStore;

/**
 * {@see HypervisorHistoryStore} with the degraded behaviour of {@see HotStoreGuard}: a transition that cannot be recorded is dropped and counted.
 *
 * @internal
 */
final class GuardedHypervisorHistoryStore implements InventoryAwareHypervisorHistoryStore
{
    public function __construct(private readonly InventoryAwareHypervisorHistoryStore $inner, private readonly HotStoreGuard $guard)
    {
    }

    public function recordTransition(int $vmId, int $deviceId, string $atUtc, string $from, string $to, int $ring): void
    {
        $this->guard->lossy('hypervisor_history', 'recordTransition', fn () => $this->inner->recordTransition($vmId, $deviceId, $atUtc, $from, $to, $ring));
    }

    public function recent(int $vmId, int $limit = 100): array
    {
        return $this->guard->read('hypervisor_history', 'recent', fn (): array => $this->inner->recent($vmId, $limit));
    }

    public function deleteOrphans(?\Closure $existingVmIds = null): int
    {
        return $this->guard->maintenance('hypervisor_history', 'deleteOrphans', fn (): int => $this->inner->deleteOrphans($existingVmIds), 0);
    }
}
