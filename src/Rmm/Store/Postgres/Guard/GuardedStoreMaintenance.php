<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\Postgres\Guard;

use RivetCore\Rmm\Store\StoreMaintenance;

/**
 * {@see StoreMaintenance} with the degraded behaviour of {@see HotStoreGuard}: a run that cannot reach the store reports nothing done (and the fault is counted),
 * so the housekeeping job never dies on an outage. {@see tiers()} throws {@see \RivetCore\Rmm\Store\HotStoreUnavailableException}: the capacity panel shows the fault.
 *
 * @internal
 */
final class GuardedStoreMaintenance implements StoreMaintenance
{
    public function __construct(private readonly StoreMaintenance $inner, private readonly HotStoreGuard $guard)
    {
    }

    public function run(array $limits, array $explicit, int $hourlyDefault, int $dailyDefault, int $rollupBudgetS = 15): array
    {
        return $this->guard->maintenance('maintenance', 'run', fn (): array => $this->inner->run($limits, $explicit, $hourlyDefault, $dailyDefault, $rollupBudgetS),
            ['rollup' => null, 'partitions' => [], 'partitioned' => [], 'retention' => []]);
    }

    public function tiers(array $limits, array $explicit): array
    {
        return $this->guard->read('maintenance', 'tiers', fn (): array => $this->inner->tiers($limits, $explicit));
    }
}
