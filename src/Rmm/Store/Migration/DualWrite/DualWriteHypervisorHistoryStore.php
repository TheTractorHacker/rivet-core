<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\Migration\DualWrite;

use RivetCore\Rmm\Store\HypervisorHistoryStore;
use RivetCore\Rmm\Store\InventoryAwareHypervisorHistoryStore;

/**
 * While a migration runs the per-VM ring is not trimmed (a trim deletes rows that may already be copied); the next transition after the migration trims as always.
 *
 * @internal
 */
final class DualWriteHypervisorHistoryStore implements InventoryAwareHypervisorHistoryStore
{
    private const NO_TRIM = 1000000;

    public function __construct(private readonly HypervisorHistoryStore $primary, private readonly ?HypervisorHistoryStore $secondary, private readonly DualWriteContext $ctx)
    {
    }

    public function recordTransition(int $vmId, int $deviceId, string $atUtc, string $from, string $to, int $ring): void
    {
        $ring = $this->ctx->paused ? self::NO_TRIM : $ring;
        $this->primary->recordTransition($vmId, $deviceId, $atUtc, $from, $to, $ring);
        if ($this->secondary !== null) {
            $this->ctx->secondary('hypervisor_history.record', fn () => $this->secondary->recordTransition($vmId, $deviceId, $atUtc, $from, $to, $ring));
        }
    }

    public function recent(int $vmId, int $limit = 100): array
    {
        return $this->primary->recent($vmId, $limit);
    }

    public function deleteOrphans(?\Closure $existingVmIds = null): int
    {
        if ($this->ctx->paused) {
            return 0;
        }

        return $this->primary instanceof InventoryAwareHypervisorHistoryStore ? $this->primary->deleteOrphans($existingVmIds) : $this->primary->deleteOrphans();
    }
}
