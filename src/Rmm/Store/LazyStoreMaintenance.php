<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store;

/**
 * {@see StoreMaintenance} that picks the real store on first use ({@see RmmDataStores::lazy()}): building the module must not read the settings row, because an
 * off module answers from its state file without touching the database. Every method delegates unchanged. Generated shape; keep it a plain delegate.
 *
 * @internal
 */
final class LazyStoreMaintenance implements StoreMaintenance
{
    /** @param \Closure(): RmmDataStores $resolve */
    public function __construct(private readonly \Closure $resolve)
    {
    }

    private function inner(): StoreMaintenance
    {
        return ($this->resolve)()->maintenance();
    }

    public function run(array $limits, array $explicit, int $hourlyDefault, int $dailyDefault, int $rollupBudgetS = 15): array
    {
        return $this->inner()->run($limits, $explicit, $hourlyDefault, $dailyDefault, $rollupBudgetS);
    }

    public function tiers(array $limits, array $explicit): array
    {
        return $this->inner()->tiers($limits, $explicit);
    }
}
