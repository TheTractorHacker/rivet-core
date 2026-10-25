<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store;

/**
 * HOT store (uncertain: a ring of at most a few dozen rows per VM, see the design note): the VM state-change history (`rmm_hypervisor_vm_history`).
 * The VM inventory itself (`rmm_hypervisor_vms`, hosts, storage) is control data.
 *
 * @api
 */
interface HypervisorHistoryStore
{
    /** Record one state change of a VM and trim that VM's history to the newest $ring entries. */
    public function recordTransition(int $vmId, int $deviceId, string $atUtc, string $from, string $to, int $ring): void;

    /**
     * The newest changes of a VM, newest first.
     *
     * @return list<array{at:string,from_state:string,to_state:string}>
     */
    public function recent(int $vmId, int $limit = 100): array;

    /** Delete the history of VMs that no longer exist in the inventory. Returns the rows deleted. */
    public function deleteOrphans(): int;
}
