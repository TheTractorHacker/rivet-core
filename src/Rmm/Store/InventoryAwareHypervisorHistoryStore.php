<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store;

/**
 * A {@see HypervisorHistoryStore} that can sweep orphans when the VM inventory is in ANOTHER database (rc.40, phase 2 of #89): the caller says which VM ids still exist.
 * The MariaDB store implements it too (it ignores the closure and joins the inventory). {@see HypervisorHistoryStore::deleteOrphans()} stays as it was.
 *
 * @api
 */
interface InventoryAwareHypervisorHistoryStore extends HypervisorHistoryStore
{
    /**
     * Delete the history of VMs that no longer exist in the inventory. Returns the rows deleted.
     *
     * @param (\Closure(list<int>):list<int>)|null $existingVmIds given some VM ids, answers which of them are still in the inventory. A store in the control database looks the
     *        inventory up itself and ignores it; a store in another database needs it, and without it deletes nothing (it cannot tell an orphan from a VM it does not know)
     */
    public function deleteOrphans(?\Closure $existingVmIds = null): int;
}
