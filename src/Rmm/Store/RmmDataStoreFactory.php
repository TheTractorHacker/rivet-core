<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store;

use RivetCore\Contracts\ClockInterface;
use RivetCore\Database\DatabaseInterface;
use RivetCore\Rmm\Storage\MetricRollup;
use RivetCore\Rmm\Storage\PartitionMaintenance;
use RivetCore\Rmm\Storage\StorageMaintenance;
use RivetCore\Rmm\Store\MariaDb\MariaDbChangeEventStore;
use RivetCore\Rmm\Store\MariaDb\MariaDbCheckHistoryStore;
use RivetCore\Rmm\Store\MariaDb\MariaDbDeploymentHistoryStore;
use RivetCore\Rmm\Store\MariaDb\MariaDbHypervisorHistoryStore;
use RivetCore\Rmm\Store\MariaDb\MariaDbLogStore;
use RivetCore\Rmm\Store\MariaDb\MariaDbPatchHistoryStore;
use RivetCore\Rmm\Store\MariaDb\MariaDbSoftwareHistoryStore;
use RivetCore\Rmm\Support\DatabaseMetricSink;
use RivetCore\Rmm\Support\Sql;

/**
 * Builds the HOT stores for a value of the `rmm_data_store` setting. `mariadb` (the default) is the only store this release provides; `postgres` is reserved and
 * refused with a {@see DataStoreUnavailableException} until phase 2 of #89 ships it.
 *
 * @api
 */
final class RmmDataStoreFactory
{
    /**
     * @param ?MetricStore $metrics the metric store the module was given as its sink (null = the edition keeps its metrics elsewhere)
     * @param ?PartitionMaintenance $partitions the module's partition tool, so the maintenance and the administrator's explicit probe/convert share one instance (MariaDB only)
     * @throws DataStoreUnavailableException for `postgres` and for an unknown value
     */
    public static function create(string $kind, Sql $sql, ?MetricStore $metrics = null, ?PartitionMaintenance $partitions = null): RmmDataStores
    {
        self::assertAvailable($kind);

        return new RmmDataStores(
            $kind,
            new MariaDbCheckHistoryStore($sql),
            new MariaDbLogStore($sql),
            new MariaDbChangeEventStore($sql),
            new MariaDbSoftwareHistoryStore($sql),
            new MariaDbPatchHistoryStore($sql),
            new MariaDbDeploymentHistoryStore($sql),
            new MariaDbHypervisorHistoryStore($sql),
            new StorageMaintenance($partitions ?? new PartitionMaintenance($sql), $metrics !== null ? new MetricRollup($sql) : null, $sql),
            $metrics,
        );
    }

    /**
     * The metric store an edition without a metrics subsystem of its own passes to the module as its sink (RivetMSP does this with {@see DatabaseMetricSink}).
     *
     * @throws DataStoreUnavailableException for `postgres` and for an unknown value
     */
    public static function createMetricStore(string $kind, DatabaseInterface $database, ClockInterface $clock, int $retentionDays = DatabaseMetricSink::DEFAULT_RETENTION_DAYS,
        int $dailyFromSeconds = DatabaseMetricSink::DAILY_FROM_SECONDS, int $dailyRetentionDays = DatabaseMetricSink::DEFAULT_DAILY_RETENTION_DAYS): MetricStore
    {
        self::assertAvailable($kind);

        return new DatabaseMetricSink($database, $clock, $retentionDays, $dailyFromSeconds, $dailyRetentionDays);
    }

    /** @throws DataStoreUnavailableException */
    public static function assertAvailable(string $kind): void
    {
        if (!in_array($kind, RmmDataStoreKind::ALL, true)) {
            throw new DataStoreUnavailableException('Unknown RMM data store "' . $kind . '". Use one of: ' . implode(', ', RmmDataStoreKind::ALL) . '.');
        }
        if (!RmmDataStoreKind::available($kind)) {
            throw new DataStoreUnavailableException('The RMM data store "' . $kind . '" is not available yet in this release. MariaDB / MySQL is the only store; see docs/design/rmm-data-store.md (issue #89).');
        }
    }
}
