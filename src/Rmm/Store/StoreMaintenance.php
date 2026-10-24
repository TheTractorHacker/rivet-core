<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store;

/**
 * The physical half of retention for the HOT stores: roll finished days up, then keep the time partitions of the big history tables (create the next
 * ones, drop the expired ones). It reports WHICH tiers were trimmed that way so the caller does not also delete from them row by row.
 *
 * What "partition" means is the store's business: MariaDB uses RANGE partitions (docs/rmm/STORAGE.md), a Postgres store would use declarative
 * partitions or TimescaleDB chunks.
 *
 * @api
 */
interface StoreMaintenance
{
    /**
     * @param array<string,int> $limits {@see \RivetCore\Rmm\Settings\RmmSettings::limits()}
     * @param array<string,int> $explicit {@see \RivetCore\Rmm\Settings\RmmSettings::explicitLimits()}
     * @param int $hourlyDefault retention of the hourly tier when the administrator set none
     * @param int $dailyDefault retention of the daily tier when the administrator set none
     * @return array{rollup:?array<string,mixed>,partitions:array<string,mixed>,partitioned:list<string>,retention:array<string,int>}
     */
    public function run(array $limits, array $explicit, int $hourlyDefault, int $dailyDefault, int $rollupBudgetS = 15): array;
}
