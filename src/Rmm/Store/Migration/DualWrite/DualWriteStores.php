<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\Migration\DualWrite;

use RivetCore\Rmm\Store\RmmDataStores;
use RivetCore\Rmm\Store\Postgres\Guard\WriteBehind;
use RivetCore\Rmm\Support\Sql;

/**
 * Builds the stores of an installation while a migration runs: the primary store's own stores with retention paused, and, in the dual-write phase, the second store
 * receiving every write after the primary (see {@see DualWriteContext} for the ordering and failure rules). Reads, row caps, the device-facing answers are the primary's.
 *
 * @internal
 */
final class DualWriteStores
{
    /**
     * @param ?RmmDataStores $secondary null in the copy phase (only the pause applies)
     * @param bool $secondaryIsPostgres the second store is in another database: its writes wait for the control transaction's commit
     */
    public static function wrap(RmmDataStores $primary, ?RmmDataStores $secondary, ?Sql $control, ?WriteBehind $behind, bool $secondaryIsPostgres): RmmDataStores
    {
        $ctx = new DualWriteContext(true, $control, $behind, $secondaryIsPostgres);
        $m = $primary->metrics();

        return new RmmDataStores(
            $primary->kind(),
            new DualWriteCheckHistoryStore($primary->checkHistory(), $secondary?->checkHistory(), $ctx),
            new DualWriteLogStore($primary->logs(), $secondary?->logs(), $ctx),
            new DualWriteChangeEventStore($primary->changes(), $secondary?->changes(), $ctx),
            new DualWriteSoftwareHistoryStore($primary->softwareHistory(), $secondary?->softwareHistory(), $ctx),
            new DualWritePatchHistoryStore($primary->patchHistory(), $secondary?->patchHistory(), $ctx),
            new DualWriteDeploymentHistoryStore($primary->deploymentHistory(), $secondary?->deploymentHistory(), $ctx),
            new DualWriteHypervisorHistoryStore($primary->hypervisorHistory(), $secondary?->hypervisorHistory(), $ctx),
            new PausedStoreMaintenance($primary->maintenance()),
            $m === null ? null : new DualWriteMetricStore($m, $secondary?->metrics(), $ctx),
        );
    }
}
