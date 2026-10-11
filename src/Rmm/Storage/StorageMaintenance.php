<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Storage;

/**
 * The storage half of {@see \RivetCore\Rmm\Maintenance\Housekeeping} (scaling item S5): roll finished days up into the daily tier, then keep the
 * time partitions of the history tables (create the next ones, drop the expired ones), in that order so a partition is never dropped before its hours
 * are in the daily tier. Returns what it did and WHICH tables are partitioned, so the caller prunes only the others with batched DELETEs.
 *
 * @api
 */
final class StorageMaintenance
{
    public function __construct(private readonly PartitionMaintenance $partitions, private readonly ?MetricRollup $rollup = null)
    {
    }

    /**
     * @param array<string,int> $limits {@see \RivetCore\Rmm\Settings\RmmSettings::limits()}
     * @param array<string,int> $explicit {@see \RivetCore\Rmm\Settings\RmmSettings::explicitLimits()}
     * @param int $hourlyDefault retention of the hourly tier when the administrator set none (the sink's constructor value)
     * @param int $dailyDefault retention of the daily tier when the administrator set none
     * @return array{rollup:?array<string,mixed>,partitions:array<string,mixed>,partitioned:list<string>,retention:array<string,int>}
     */
    public function run(array $limits, array $explicit, int $hourlyDefault, int $dailyDefault, int $rollupBudgetS = 15): array
    {
        $roll = $this->rollup?->run($rollupBudgetS);
        $retention = [
            'rmm_metric_hourly' => $explicit['metric_hourly_days'] ?? $hourlyDefault,
            'rmm_metric_daily' => $explicit['metric_daily_days'] ?? $dailyDefault,
            'endpoint_agent_check_history' => max(1, $limits['check_history_days']),
        ];
        $guard = [];
        if ($roll !== null && $roll['watermark'] !== null) {
            $guard['rmm_metric_hourly'] = $roll['watermark'];
        }
        $maint = $this->partitions->maintain($retention, $limits['partition_span_days'], $limits['partition_ahead'], $limits['partitioning'] === 1, $guard);

        return ['rollup' => $roll, 'partitions' => $maint['tables'], 'partitioned' => $maint['partitioned'], 'retention' => $retention];
    }
}
