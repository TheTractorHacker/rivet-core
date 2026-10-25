<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\Migration\DualWrite;

use RivetCore\Rmm\Store\StoreMaintenance;

/**
 * The maintenance of the primary store while a migration runs: nothing is pruned, rolled up or re-partitioned until the cut-over (a delete between the copy and the
 * verification would make the two stores differ for no fault of the copy). The housekeeping job reports "nothing done" and tries again on its next run.
 *
 * @internal
 */
final class PausedStoreMaintenance implements StoreMaintenance
{
    public function __construct(private readonly StoreMaintenance $primary)
    {
    }

    public function run(array $limits, array $explicit, int $hourlyDefault, int $dailyDefault, int $rollupBudgetS = 15): array
    {
        return ['rollup' => null, 'partitions' => [], 'partitioned' => [], 'retention' => [], 'paused' => 'a data store migration is running'];
    }

    public function tiers(array $limits, array $explicit): array
    {
        return $this->primary->tiers($limits, $explicit);
    }
}
