<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm\Postgres;

use RivetCore\Rmm\Store\Postgres\Pdo\PdoPostgresConnection;
use RivetCore\Rmm\Store\Postgres\PostgresConnectionInterface;
use RivetCore\Rmm\Store\RmmDataStoreKind;
use RivetCore\Rmm\Support\DatabaseMetricSink;
use RivetCore\Tests\Support\DataStoreRetentionCases;
use RivetCore\Tests\Support\MutableClock;
use RivetCore\Tests\Support\MysqliDatabase;
use RivetCore\Tests\Support\PostgresTestDatabase;
use RivetCore\Tests\Support\RmmHarness;
use RivetCore\Tests\Support\ScratchDb;

/** The same retention check cases against the PostgreSQL RMM data store: the check reads the ACTIVE store through the store interfaces. */
final class DataStoreRetentionPostgresTest extends DataStoreRetentionCases
{
    protected function makeHarness(): RmmHarness
    {
        if (($why = PostgresTestDatabase::unavailable()) !== null) {
            $this->markTestSkipped($why);
        }
        PostgresTestDatabase::prepare(PostgresTestDatabase::connect());
        $connection = fn (): ?PostgresConnectionInterface => PdoPostgresConnection::fromConfig(PostgresTestDatabase::config(['breaker_open_s' => 0]));

        return new RmmHarness(null, null, ['postgres' => $connection], false, new DatabaseMetricSink(new MysqliDatabase(ScratchDb::connect()), new MutableClock()));
    }

    protected function activateStore(): void
    {
        $this->h->q("UPDATE endpoint_agent_settings SET data_store = 'postgres' WHERE id = 1");
        $this->h->module->settings()->refresh();
        $module = $this->h->module;
        foreach (['dataStores', 'resolvedStores'] as $prop) {
            (new \ReflectionProperty($module, $prop))->setValue($module, null);
        }
    }

    protected function expectedKind(): string
    {
        return RmmDataStoreKind::POSTGRES;
    }

    public function testTheLogRowsLiveInPostgresAndNotInMariadb(): void
    {
        $this->h->module->dataStores()->logs()->admit($this->dev, [['ts' => time() - 100, 'level' => 4, 'source' => 'S', 'code' => null, 'message' => 'only in pg', 'hash' => substr(hash('sha256', 'x'), 0, 32)]],
            ['rate_per_min' => 1000, 'device_max_rows' => 1000, 'fleet_max_rows' => 100000], time(), 0, 0);
        self::assertSame(1, (int) PostgresTestDatabase::connect()->value('SELECT COUNT(*) FROM endpoint_agent_log_events'));
        self::assertSame('0', (string) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_log_events'));
        $r = $this->check([new \RivetCore\Compliance\Profile\Requirement('retention_min', 'rmm_logs', 7)], true)->run();
        self::assertStringContainsString('the oldest stored log event is', (string) $r->detail, 'the evidence comes from PostgreSQL');
    }
}
