<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store;

use RivetCore\Contracts\ClockInterface;
use RivetCore\Database\DatabaseInterface;
use RivetCore\Rmm\Storage\PartitionMaintenance;
use RivetCore\Rmm\Storage\StorageMaintenance;
use RivetCore\Rmm\Store\MariaDb\MariaDbChangeEventStore;
use RivetCore\Rmm\Store\MariaDb\MariaDbCheckHistoryStore;
use RivetCore\Rmm\Store\MariaDb\MariaDbDeploymentHistoryStore;
use RivetCore\Rmm\Store\MariaDb\MariaDbHypervisorHistoryStore;
use RivetCore\Rmm\Store\MariaDb\MariaDbLogStore;
use RivetCore\Rmm\Store\MariaDb\MariaDbPatchHistoryStore;
use RivetCore\Rmm\Store\MariaDb\MariaDbSoftwareHistoryStore;
use RivetCore\Rmm\Store\Postgres\Guard\GuardedChangeEventStore;
use RivetCore\Rmm\Store\Postgres\Guard\GuardedCheckHistoryStore;
use RivetCore\Rmm\Store\Postgres\Guard\GuardedDeploymentHistoryStore;
use RivetCore\Rmm\Store\Postgres\Guard\GuardedHypervisorHistoryStore;
use RivetCore\Rmm\Store\Postgres\Guard\GuardedLogStore;
use RivetCore\Rmm\Store\Postgres\Guard\GuardedMetricStore;
use RivetCore\Rmm\Store\Postgres\Guard\GuardedPatchHistoryStore;
use RivetCore\Rmm\Store\Postgres\Guard\GuardedSoftwareHistoryStore;
use RivetCore\Rmm\Store\Postgres\Guard\GuardedStoreMaintenance;
use RivetCore\Rmm\Store\Postgres\Guard\HotStoreFaults;
use RivetCore\Rmm\Store\Postgres\Guard\HotStoreGuard;
use RivetCore\Rmm\Store\Postgres\Guard\WriteBehind;
use RivetCore\Rmm\Store\Postgres\PostgresChangeEventStore;
use RivetCore\Rmm\Store\Postgres\PostgresCheckHistoryStore;
use RivetCore\Rmm\Store\Postgres\PostgresConnectionInterface;
use RivetCore\Rmm\Store\Postgres\PostgresDeploymentHistoryStore;
use RivetCore\Rmm\Store\Postgres\PostgresHypervisorHistoryStore;
use RivetCore\Rmm\Store\Postgres\PostgresLogStore;
use RivetCore\Rmm\Store\Postgres\PostgresMetricStore;
use RivetCore\Rmm\Store\Postgres\PostgresPatchHistoryStore;
use RivetCore\Rmm\Store\Postgres\PostgresSoftwareHistoryStore;
use RivetCore\Rmm\Store\Postgres\PostgresStoreMaintenance;
use RivetCore\Rmm\Support\DatabaseMetricSink;
use RivetCore\Rmm\Support\Sql;

/**
 * Builds the HOT stores for a value of the `rmm_data_store` setting. `mariadb` (the default) needs nothing but the control database. `postgres` needs a
 * {@see PostgresConnectionInterface} from the edition; without one it is refused with a {@see DataStoreUnavailableException} that says so (it never falls back to MariaDB).
 * The PostgreSQL stores come back wrapped in the guards of {@see \RivetCore\Rmm\Store\Postgres\Guard\HotStoreGuard}: a check-in never fails because PostgreSQL is down.
 *
 * @api
 */
final class RmmDataStoreFactory
{
    /**
     * @param ?MetricStore $metrics the metric store the module was given as its sink (null = the edition keeps its metrics elsewhere). For `postgres` it only supplies the
     *        retention figures; the PostgreSQL metric store is built here
     * @param ?PartitionMaintenance $partitions the module's partition tool, so the maintenance and the administrator's explicit probe/convert share one instance (MariaDB only)
     * @param ?PostgresConnectionInterface $postgres the PostgreSQL connection (required for `postgres`, ignored for `mariadb`: MariaDB never touches it)
     * @param ?WriteBehind $behind holds the PostgreSQL history writes of a check-in until the control transaction commits (the module passes the one its {@see \RivetCore\Rmm\Support\TrackedDatabase} reports to)
     * @param ?HotStoreFaults $faults where the PostgreSQL stores record the writes they dropped (default: a new record on the control database; the module passes its own so the capacity panel reads the same one)
     * @throws DataStoreUnavailableException for `postgres` without a connection and for an unknown value
     */
    public static function create(string $kind, Sql $sql, ?MetricStore $metrics = null, ?PartitionMaintenance $partitions = null, ?PostgresConnectionInterface $postgres = null, ?HotStoreFaults $faults = null, ?WriteBehind $behind = null): RmmDataStores
    {
        self::assertAvailable($kind);
        if ($kind === RmmDataStoreKind::POSTGRES) {
            return self::createPostgres($sql, $metrics, self::requireConnection($postgres), $faults ?? new HotStoreFaults($sql), $behind);
        }

        return new RmmDataStores(
            $kind,
            new MariaDbCheckHistoryStore($sql),
            new MariaDbLogStore($sql),
            new MariaDbChangeEventStore($sql),
            new MariaDbSoftwareHistoryStore($sql),
            new MariaDbPatchHistoryStore($sql),
            new MariaDbDeploymentHistoryStore($sql),
            new MariaDbHypervisorHistoryStore($sql),
            new StorageMaintenance($partitions ?? new PartitionMaintenance($sql), $metrics, $sql),   // the metric store rolls its own days up
            $metrics,
        );
    }

    /**
     * The stores of a kind WITHOUT the failure guards: a PostgreSQL failure reaches the caller as an exception instead of being dropped and counted. Used by the data store
     * migration (docs/design/rmm-data-store.md section 13), which must see a failed write to the second store; the services never use it.
     *
     * @throws DataStoreUnavailableException for `postgres` without a connection and for an unknown value
     */
    public static function createUnguarded(string $kind, Sql $sql, ?MetricStore $metrics = null, ?PartitionMaintenance $partitions = null, ?PostgresConnectionInterface $postgres = null): RmmDataStores
    {
        self::assertAvailable($kind);
        if ($kind !== RmmDataStoreKind::POSTGRES) {
            return self::create($kind, $sql, $metrics, $partitions);
        }
        $pg = self::requireConnection($postgres);
        $raw = $metrics === null ? null : new PostgresMetricStore($pg, $sql->clock(), $metrics->retentionDays(),
            $metrics instanceof DatabaseMetricSink ? $metrics->dailyFromSeconds() : DatabaseMetricSink::DAILY_FROM_SECONDS, $metrics->dailyRetentionDays());

        return new RmmDataStores(RmmDataStoreKind::POSTGRES, new PostgresCheckHistoryStore($pg), new PostgresLogStore($pg), new PostgresChangeEventStore($pg), new PostgresSoftwareHistoryStore($pg),
            new PostgresPatchHistoryStore($pg), new PostgresDeploymentHistoryStore($pg), new PostgresHypervisorHistoryStore($pg), new PostgresStoreMaintenance($pg, $sql->clock(), $raw), $raw);
    }

    /**
     * The metric store an edition without a metrics subsystem of its own passes to the module as its sink (RivetMSP does this with {@see DatabaseMetricSink}).
     *
     * @throws DataStoreUnavailableException for `postgres` without a connection and for an unknown value
     */
    public static function createMetricStore(string $kind, DatabaseInterface $database, ClockInterface $clock, int $retentionDays = DatabaseMetricSink::DEFAULT_RETENTION_DAYS,
        int $dailyFromSeconds = DatabaseMetricSink::DAILY_FROM_SECONDS, int $dailyRetentionDays = DatabaseMetricSink::DEFAULT_DAILY_RETENTION_DAYS, ?PostgresConnectionInterface $postgres = null): MetricStore
    {
        self::assertAvailable($kind);
        if ($kind === RmmDataStoreKind::POSTGRES) {
            $guard = new HotStoreGuard(new HotStoreFaults(new Sql($database, $clock)));

            return new GuardedMetricStore(new PostgresMetricStore(self::requireConnection($postgres), $clock, $retentionDays, $dailyFromSeconds, $dailyRetentionDays), $guard);
        }

        return new DatabaseMetricSink($database, $clock, $retentionDays, $dailyFromSeconds, $dailyRetentionDays);
    }

    /** @throws DataStoreUnavailableException for a value that is not a store of this release */
    public static function assertAvailable(string $kind): void
    {
        if (!in_array($kind, RmmDataStoreKind::ALL, true)) {
            throw new DataStoreUnavailableException('Unknown RMM data store "' . $kind . '". Use one of: ' . implode(', ', RmmDataStoreKind::ALL) . '.');
        }
    }

    /**
     * Whether {@see \RivetCore\Rmm\Settings\RmmSettings::update()} accepts this store. `postgres` goes through {@see DataStoreSwitch} instead (connection test, schema, all-or-nothing).
     *
     * @throws DataStoreUnavailableException
     */
    public static function assertSelectable(string $kind): void
    {
        self::assertAvailable($kind);
        if (!RmmDataStoreKind::selectable($kind)) {
            throw new DataStoreUnavailableException('The RMM data store "' . $kind . '" cannot be selected in the settings yet: choosing it needs the connection test and the schema setup, which '
                . 'only DataStoreSwitch::switchTo() performs (docs/rmm/POSTGRES.md). RmmSettings::update() deliberately keeps refusing it.');
        }
    }

    private static function requireConnection(?PostgresConnectionInterface $postgres): PostgresConnectionInterface
    {
        return $postgres ?? throw new DataStoreUnavailableException('The RMM data store is "postgres" but no PostgreSQL connection was configured. The edition passes one as the "postgres" module option '
            . '(a PostgresConnectionInterface, a Closure that returns one, or a PostgresConfig); see docs/rmm/POSTGRES.md. Nothing is written to MariaDB instead.');
    }

    private static function createPostgres(Sql $sql, ?MetricStore $metrics, PostgresConnectionInterface $pg, HotStoreFaults $faults, ?WriteBehind $behind): RmmDataStores
    {
        $guard = new HotStoreGuard($faults, null, $behind);
        $raw = $metrics === null ? null : new PostgresMetricStore($pg, $sql->clock(), $metrics->retentionDays(),
            $metrics instanceof DatabaseMetricSink ? $metrics->dailyFromSeconds() : DatabaseMetricSink::DAILY_FROM_SECONDS, $metrics->dailyRetentionDays());

        return new RmmDataStores(
            RmmDataStoreKind::POSTGRES,
            new GuardedCheckHistoryStore(new PostgresCheckHistoryStore($pg), $guard),
            new GuardedLogStore(new PostgresLogStore($pg), $guard),
            new GuardedChangeEventStore(new PostgresChangeEventStore($pg), $guard),
            new GuardedSoftwareHistoryStore(new PostgresSoftwareHistoryStore($pg), $guard),
            new GuardedPatchHistoryStore(new PostgresPatchHistoryStore($pg), $guard),
            new GuardedDeploymentHistoryStore(new PostgresDeploymentHistoryStore($pg), $guard),
            new GuardedHypervisorHistoryStore(new PostgresHypervisorHistoryStore($pg), $guard),
            new GuardedStoreMaintenance(new PostgresStoreMaintenance($pg, $sql->clock(), $raw), $guard),
            $raw === null ? null : new GuardedMetricStore($raw, $guard),
        );
    }
}
