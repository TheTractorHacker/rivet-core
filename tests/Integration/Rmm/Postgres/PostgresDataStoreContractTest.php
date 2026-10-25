<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm\Postgres;

use RivetCore\Rmm\Store\Postgres\Pdo\PdoPostgresConnection;
use RivetCore\Rmm\Store\Postgres\PostgresConnectionInterface;
use RivetCore\Rmm\Store\RmmDataStoreFactory;
use RivetCore\Rmm\Store\RmmDataStoreKind;
use RivetCore\Rmm\Store\RmmDataStores;
use RivetCore\Rmm\Support\DatabaseMetricSink;
use RivetCore\Tests\Integration\Rmm\MariaDbDataStoreContractTest;
use RivetCore\Tests\Support\PostgresTestDatabase;
use RivetCore\Tests\Support\RmmHarness;

/**
 * The shared contract of the HOT RMM stores ({@see \RivetCore\Testing\RmmDataStoreContractTestCase}) against the PostgreSQL implementation (phase 2 of #89): the SAME
 * cases as {@see MariaDbDataStoreContractTest}, whose control seeding (devices, packages, VMs: always MariaDB) it inherits. Only the stores differ, and they are the ones
 * the factory builds, guards included. Skips with a clear message without a scratch PostgreSQL.
 *
 * With RIVETCORE_TEST_PG_MODE=timescale (the CI job on a TimescaleDB image) the very same cases run on hypertables: {@see PostgresTimescaleDataStoreContractTest}.
 */
class PostgresDataStoreContractTest extends MariaDbDataStoreContractTest
{
    protected ?PostgresConnectionInterface $pg = null;

    /** The storage mode the tests ask the migrator for (null = what RIVETCORE_TEST_PG_MODE says, default auto). */
    protected function storageMode(): ?string
    {
        return null;
    }

    protected function setUp(): void
    {
        if (($why = PostgresTestDatabase::unavailable()) !== null) {
            $this->markTestSkipped($why);
        }
        parent::setUp();
        $this->pg = PostgresTestDatabase::connect();
        PostgresTestDatabase::prepare($this->pg, $this->storageMode());
        $this->h->q('DELETE FROM rmm_hot_store_faults');
    }

    protected function tearDown(): void
    {
        if ($this->pg !== null) {
            $this->pg->close();
            $this->pg = null;
        }
        parent::tearDown();
    }

    protected function expectedKind(): string
    {
        return RmmDataStoreKind::POSTGRES;
    }

    protected function stores(): RmmDataStores
    {
        assert($this->pg !== null);

        return $this->stores ??= RmmDataStoreFactory::create(RmmDataStoreKind::POSTGRES, $this->h->module->sql(), new DatabaseMetricSink($this->h->db, $this->h->clock), null, $this->pg);
    }

    public function testNothingWasWrittenToMariaDbWhileThePostgresStoresRan(): void
    {
        $s = $this->stores();
        $dev = $this->device(1, 'PG-ONLY');
        $s->checkHistory()->append($dev, [['key' => 'k', 'status' => 'ok', 'detail' => '', 'at' => '2030-01-01 00:00:00']]);
        $s->logs()->admit($dev, [['ts' => 1_900_000_000, 'level' => 4, 'source' => 'S', 'code' => null, 'message' => 'm', 'hash' => str_repeat('a', 32)]], ['rate_per_min' => 100, 'device_max_rows' => 100, 'fleet_max_rows' => 100], 1_900_000_100, 0, 0, 1);
        $s->metrics()?->ingest([['asset_id' => 4242, 'key' => 'cpu.utilization', 'instance' => null, 'value' => 5, 'at' => new \DateTimeImmutable('@' . (time() - 60)), 'label' => null]], 1);
        foreach (['endpoint_agent_check_history', 'endpoint_agent_log_events', 'endpoint_agent_log_state', 'rmm_metric_latest', 'rmm_metric_hourly'] as $t) {
            $this->assertSame('0', (string) $this->h->one("SELECT COUNT(*) FROM $t"), "$t stays empty in MariaDB");
        }
        $this->assertSame(1, (int) $this->pg->value('SELECT COUNT(*) FROM endpoint_agent_check_history'));
        $this->assertSame(1, (int) $this->pg->value('SELECT COUNT(*) FROM endpoint_agent_log_events'));
        $this->assertSame(1, (int) $this->pg->value('SELECT COUNT(*) FROM rmm_metric_latest'));
    }
}
