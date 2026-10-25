<?php

declare(strict_types=1);

namespace RivetCore\Tests\Support;

use RivetCore\Rmm\Store\Migration\HotTables;
use RivetCore\Rmm\Store\Migration\MariaDbEndpoint;
use RivetCore\Rmm\Store\Migration\PostgresEndpoint;
use RivetCore\Rmm\Store\Migration\RmmStoreMigrator;
use RivetCore\Rmm\Store\Postgres\PostgresConnectionInterface;
use RivetCore\Rmm\Support\Sql;

/** Base of the data store migration tests: a harness (MariaDB control database), a scratch PostgreSQL, both emptied, and a migrator that never sleeps. */
abstract class StoreMigrationTestCase extends RmmTestCase
{
    protected MariaDbEndpoint $maria;
    protected PostgresEndpoint $pg;
    protected PostgresConnectionInterface $pgConn;
    protected Sql $sql;
    /** @var list<array<string,mixed>> */
    protected array $events = [];

    protected function setUp(): void
    {
        if (($why = PostgresTestDatabase::unavailable()) !== null) {
            $this->markTestSkipped($why);
        }
        parent::setUp();
        $this->pgConn = PostgresTestDatabase::connect();
        PostgresTestDatabase::prepare($this->pgConn);
        $this->sql = new Sql($this->h->db, $this->h->clock);
        $this->maria = new MariaDbEndpoint($this->sql);
        $this->pg = new PostgresEndpoint($this->pgConn);
        foreach (HotTables::all() as $t) {
            $this->maria->truncate($t);
            $this->pg->truncate($t);
        }
        $this->h->q("DELETE FROM rmm_store_migration");
        $this->h->q("UPDATE endpoint_agent_settings SET data_store = 'mariadb', data_store_phase = NULL WHERE id = 1");
        $this->h->module->settings()->refresh();
        $this->events = [];
    }

    protected function migrator(?PostgresConnectionInterface $pg = null): RmmStoreMigrator
    {
        return new RmmStoreMigrator($this->sql, $this->h->module->settings(), $pg ?? $this->pgConn, $this->h->audit, static function (int $us): void {
        });
    }

    /** @return \Closure(array<string,mixed>):void */
    protected function collect(?\Closure $then = null): \Closure
    {
        return function (array $e) use ($then): void {
            $this->events[] = $e;
            if ($then !== null) {
                $then($e);
            }
        };
    }

    protected function setStore(string $kind): void
    {
        $this->h->q("UPDATE endpoint_agent_settings SET data_store = '$kind' WHERE id = 1");
        $this->h->module->settings()->refresh();
    }

    /** Make the module build its stores again (it keeps them for the life of the object). */
    protected function reresolve(): void
    {
        $this->h->module->settings()->refresh();
        $m = $this->h->module;
        foreach (['dataStores', 'resolvedStores'] as $prop) {
            (new \ReflectionProperty($m, $prop))->setValue($m, null);
        }
    }

    /** @return array<string,int> */
    protected static function opts(array $over = []): array
    {
        return $over + ['settle_s' => 0, 'verify_lag_s' => 0, 'sample' => -1, 'batch' => 500, 'pause_ms' => 0, 'actor' => 'test'];
    }
}
