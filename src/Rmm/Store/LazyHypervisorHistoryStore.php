<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store;

/**
 * {@see HypervisorHistoryStore} that picks the real store on first use ({@see RmmDataStores::lazy()}): building the module must not read the settings row, because an
 * off module answers from its state file without touching the database. Every method delegates unchanged. Generated shape; keep it a plain delegate.
 *
 * @internal
 */
final class LazyHypervisorHistoryStore implements HypervisorHistoryStore
{
    /** @param \Closure(): RmmDataStores $resolve */
    public function __construct(private readonly \Closure $resolve)
    {
    }

    private function inner(): HypervisorHistoryStore
    {
        return ($this->resolve)()->hypervisorHistory();
    }

    public function recordTransition(int $vmId, int $deviceId, string $atUtc, string $from, string $to, int $ring): void
    {
        $this->inner()->recordTransition($vmId, $deviceId, $atUtc, $from, $to, $ring);
    }

    public function recent(int $vmId, int $limit = 100): array
    {
        return $this->inner()->recent($vmId, $limit);
    }

    public function deleteOrphans(): int
    {
        return $this->inner()->deleteOrphans();
    }
}
