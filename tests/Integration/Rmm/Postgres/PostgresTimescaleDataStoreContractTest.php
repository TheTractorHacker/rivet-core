<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm\Postgres;

use RivetCore\Rmm\Store\Postgres\PostgresMigrator;
use RivetCore\Rmm\Store\Postgres\PostgresSchema;
use RivetCore\Tests\Support\PostgresTestDatabase;

/** The same contract on TimescaleDB hypertables; skips when the extension is not installed in the scratch database (the plain PostgreSQL job). */
final class PostgresTimescaleDataStoreContractTest extends PostgresDataStoreContractTest
{
    protected function storageMode(): ?string
    {
        return PostgresSchema::MODE_TIMESCALE;
    }

    protected function setUp(): void
    {
        if (PostgresTestDatabase::unavailable() === null && !(new PostgresMigrator(PostgresTestDatabase::connect()))->timescaleInstalled()) {
            if (getenv('RIVETCORE_TEST_PG_MODE') === 'timescale') {
                $this->fail('RIVETCORE_TEST_PG_MODE=timescale (the TimescaleDB job) but the extension is not installed in the scratch database.');
            }
            $this->markTestSkipped('TimescaleDB is not installed in the scratch PostgreSQL (use the timescale/timescaledb image; CI job "postgres / timescaledb").');
        }
        parent::setUp();
        $this->assertSame(PostgresSchema::MODE_TIMESCALE, (new PostgresMigrator($this->pg))->storedMode());
    }
}
