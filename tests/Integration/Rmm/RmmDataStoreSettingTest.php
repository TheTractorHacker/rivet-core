<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Migration\Migration0042RmmDataStore;
use RivetCore\Rmm\Store\DataStoreUnavailableException;
use RivetCore\Rmm\Store\MariaDb\MariaDbLogStore;
use RivetCore\Rmm\Store\RmmDataStoreFactory;
use RivetCore\Rmm\Store\RmmDataStoreKind;
use RivetCore\Rmm\Support\DatabaseMetricSink;
use RivetCore\Tests\Support\RmmTestCase;

/** The `rmm_data_store` setting (#89): default mariadb, postgres cannot be selected in the settings yet (phase 3) but runs when the edition gives a connection, the migration is additive and idempotent, the wiring follows the setting. */
final class RmmDataStoreSettingTest extends RmmTestCase
{
    public function testTheDefaultIsMariadbAndTheColumnIsAdditive(): void
    {
        $s = $this->h->module->settings();
        $this->assertSame('mariadb', $this->h->one("SELECT data_store FROM endpoint_agent_settings WHERE id = 1"));
        $this->assertSame(RmmDataStoreKind::MARIADB, $s->dataStore());
        $this->assertSame('mariadb', $this->h->module->dataStores()->kind());
    }

    public function testMariadbIsAccepted(): void
    {
        $s = $this->h->module->settings();
        $this->assertSame([], $s->update(['data_store' => 'mariadb']));
        $this->assertSame('mariadb', $s->dataStore());
    }

    public function testPostgresIsRefusedWithAClearMessageAndNothingIsWritten(): void
    {
        $s = $this->h->module->settings();
        $e = $s->update(['data_store' => 'postgres', 'max_devices' => 77]);
        $this->assertCount(1, $e);
        $this->assertStringContainsString('"postgres" cannot be selected in the settings yet', $e[0]);
        $this->assertSame('mariadb', $s->dataStore());
        $this->assertSame(0, (int) $this->h->one('SELECT max_devices FROM endpoint_agent_settings WHERE id = 1'), 'a refused save writes nothing');
    }

    public function testAnUnknownValueAndANonStringAreRefused(): void
    {
        $s = $this->h->module->settings();
        foreach (['mongo', '', 'MARIADB', ['postgres'], null, 5] as $bad) {
            $e = $s->update(['data_store' => $bad]);
            $this->assertCount(1, $e, var_export($bad, true));
            $this->assertStringContainsString('RMM data store', $e[0]);
        }
        $this->assertSame('mariadb', $s->dataStore());
    }

    public function testAHandEditedPostgresWithoutAConnectionNeverFallsBackToMariadb(): void
    {
        $this->h->q("UPDATE endpoint_agent_settings SET data_store = 'postgres' WHERE id = 1");
        $this->h->module->settings()->refresh();
        $log = tempnam(sys_get_temp_dir(), 'rmmstore');
        ini_set('error_log', (string) $log);
        try {
            $module = $this->h->module;
            foreach (['dataStores', 'resolvedStores'] as $prop) {
                (new \ReflectionProperty($module, $prop))->setValue($module, null);   // the harness may have resolved the stores before the row was edited
            }
            $this->assertSame('postgres', $module->settings()->dataStore());
            $stores = $module->dataStores();
            $this->assertSame('postgres', $stores->kind(), 'the administrator chose postgres: the module does not quietly use MariaDB, which would hide that the history is not where it was put');
            $this->assertStringContainsString('no PostgreSQL connection was configured', (string) file_get_contents((string) $log));
            try {
                $stores->logs()->fleetRowCount();
                $this->fail('a read says why it cannot be answered');
            } catch (\RivetCore\Rmm\Store\HotStoreUnavailableException $e) {
                $this->assertStringContainsString('no PostgreSQL connection was configured', $e->getMessage());
            }
            $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_log_state'), 'nothing was written to MariaDB');
        } finally {
            ini_set('error_log', '/dev/null');
            @unlink((string) $log);
        }
    }

    public function testAnInstallWithoutTheColumnIsOnMariadbAndStaysThere(): void
    {
        $this->h->q('ALTER TABLE endpoint_agent_settings DROP COLUMN data_store');
        try {
            $s = $this->h->module->settings();
            $s->refresh();
            $this->assertSame('mariadb', $s->dataStore());
            $this->assertSame([], $s->update(['data_store' => 'mariadb']), 'mariadb on an install that has no column yet is a no-op');
            $this->assertCount(1, $s->update(['data_store' => 'postgres']));
        } finally {
            (new Migration0042RmmDataStore())->up($this->h->db);
        }
    }

    public function testTheMigrationIsIdempotentAndKeepsTheValue(): void
    {
        $m = new Migration0042RmmDataStore();
        $this->assertSame('0042_rmm_data_store', $m->id());
        $m->up($this->h->db);
        $m->up($this->h->db);
        $this->h->q("UPDATE endpoint_agent_settings SET data_store = 'mariadb', max_devices = 12 WHERE id = 1");
        $m->up($this->h->db);
        $this->assertSame('12', (string) $this->h->one('SELECT max_devices FROM endpoint_agent_settings WHERE id = 1'));
        $col = $this->h->rows("SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'endpoint_agent_settings' AND COLUMN_NAME = 'data_store'");
        // MariaDB reports a string default quoted ('mariadb'), MySQL unquoted (mariadb).
        $this->assertSame(['varchar(16)', 'NO', 'mariadb'], [$col[0]['COLUMN_TYPE'], $col[0]['IS_NULLABLE'], trim((string) $col[0]['COLUMN_DEFAULT'], "'")]);
    }

    public function testTheFactoryRefusesPostgresWithoutAConnectionAndUnknownKindsAndBuildsEveryMariadbStore(): void
    {
        $sql = $this->h->module->sql();
        foreach (['mysql', ''] as $kind) {
            try {
                RmmDataStoreFactory::create($kind, $sql);
                $this->fail("$kind must be refused");
            } catch (DataStoreUnavailableException $e) {
                $this->assertStringContainsString('Unknown RMM data store', $e->getMessage());
            }
        }
        try {
            RmmDataStoreFactory::create('postgres', $sql);
            $this->fail('postgres needs a connection');
        } catch (DataStoreUnavailableException $e) {
            $this->assertStringContainsString('no PostgreSQL connection was configured', $e->getMessage());
            $this->assertStringContainsString('"postgres" module option', $e->getMessage());
        }
        try {
            RmmDataStoreFactory::createMetricStore('postgres', $this->h->db, $this->h->clock);
            $this->fail('postgres needs a connection');
        } catch (DataStoreUnavailableException $e) {
            $this->assertStringContainsString('no PostgreSQL connection was configured', $e->getMessage());
        }
        $this->assertSame([true, true, false], [RmmDataStoreKind::selectable('mariadb'), RmmDataStoreKind::available('postgres'), RmmDataStoreKind::selectable('postgres')]);
        $this->assertInstanceOf(DatabaseMetricSink::class, RmmDataStoreFactory::createMetricStore('mariadb', $this->h->db, $this->h->clock));
        $stores = RmmDataStoreFactory::create('mariadb', $sql);
        $this->assertNull($stores->metrics());
        $this->assertInstanceOf(MariaDbLogStore::class, $stores->logs());
        $this->assertFalse(RmmDataStoreKind::available('mongo'));
    }

    public function testTheModuleServicesUseTheModulesStores(): void
    {
        $module = $this->h->module;
        $r = new \ReflectionProperty($module->logService(), 'store');
        $this->assertSame($module->dataStores()->logs(), $r->getValue($module->logService()));
        $r = new \ReflectionProperty($module->changeService(), 'store');
        $this->assertSame($module->dataStores()->changes(), $r->getValue($module->changeService()));
        $r = new \ReflectionProperty($module->software(), 'history');
        $this->assertSame($module->dataStores()->softwareHistory(), $r->getValue($module->software()));
        $this->assertInstanceOf(\RivetCore\Rmm\Storage\StorageMaintenance::class, $module->storage(), 'the MariaDB maintenance behind the lazy delegate');
    }
}
