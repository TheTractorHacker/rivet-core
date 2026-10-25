<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm\Migration;

use RivetCore\Rmm\Store\DataStoreSwitch;
use RivetCore\Rmm\Store\Postgres\Pdo\PdoPostgresConnection;
use RivetCore\Rmm\Store\Postgres\PostgresConfig;
use RivetCore\Tests\Support\PostgresTestDatabase;
use RivetCore\Tests\Support\RmmTestCase;

/** The setting, the connection test and the credential rules of {@see DataStoreSwitch} (phase 3 of #89). */
final class DataStoreSwitchTest extends RmmTestCase
{
    private int $connects = 0;
    private DataStoreSwitch $sw;

    protected function setUp(): void
    {
        if (($why = PostgresTestDatabase::unavailable()) !== null) {
            $this->markTestSkipped($why);
        }
        parent::setUp();
        PostgresTestDatabase::prepare(PostgresTestDatabase::connect());
        $this->connects = 0;
        $this->sw = new DataStoreSwitch($this->h->module->sql(), $this->h->module->settings(), $this->h->box, $this->h->audit, function (PostgresConfig $c) {
            ++$this->connects;

            return new PdoPostgresConnection($c);
        });
    }

    protected function tearDown(): void
    {
        if (!isset($this->sw)) {
            parent::tearDown();

            return;
        }
        $this->h->q('DELETE FROM rmm_data_store_connection');
        $this->h->q('DELETE FROM rmm_store_migration');
        $this->h->q("UPDATE endpoint_agent_settings SET data_store = 'mariadb', data_store_phase = NULL WHERE id = 1");
        parent::tearDown();
    }

    /** @return array<string,mixed> */
    private function form(array $over = []): array
    {
        $c = PostgresTestDatabase::config();

        return $over + ['host' => $c->host, 'port' => $c->port, 'database' => $c->database, 'user' => $c->user, 'password' => $c->password(), 'sslmode' => 'disable', 'schema' => 'public'];
    }

    public function testThePasswordIsSealedAndNeverReturned(): void
    {
        $pw = 'S3cr3t-' . bin2hex(random_bytes(8));   // unique: the password of the CI database (for example "rivet") is a substring of other text
        $this->assertSame([], $this->sw->saveConnection($this->form(['password' => $pw])));
        $raw = (string) $this->h->one('SELECT password_sealed FROM rmm_data_store_connection WHERE id = 1');
        $this->assertNotSame('', $raw);
        $this->assertStringNotContainsString($pw, $raw);
        $status = json_encode($this->sw->status());
        $this->assertStringNotContainsString($raw, $status);
        $this->assertStringNotContainsString($pw, $status);
        $this->assertTrue($this->sw->connectionSettings()['password_set']);
        $this->assertSame($pw, $this->sw->postgresConfig()?->password());
        foreach ($this->h->audit->records() as $r) {
            $this->assertStringNotContainsString($pw, json_encode($r));
        }
    }

    public function testABlankPasswordKeepsTheStoredOneForTheSameTarget(): void
    {
        $this->sw->saveConnection($this->form());
        $this->assertSame([], $this->sw->saveConnection($this->form(['password' => ''])));
        $this->assertSame($this->form()['password'], $this->sw->postgresConfig()?->password());
        $t = $this->sw->testConnection($this->form(['password' => '']));
        $this->assertTrue($t['ok'], json_encode($t));
        $this->assertSame(1, $this->connects);
    }

    public function testAChangedTargetWithABlankPasswordIsRefusedAndNeverConnects(): void
    {
        $this->sw->saveConnection($this->form());
        foreach ([['host' => 'attacker.example'], ['port' => 5999], ['user' => 'someone_else'], ['database' => 'other_db']] as $change) {
            $t = $this->sw->testConnection($this->form(['password' => ''] + $change));
            $this->assertFalse($t['ok']);
            $this->assertStringContainsString('Enter the password again', $t['checks'][0]['message']);
            $e = $this->sw->saveConnection($this->form(['password' => ''] + $change));
            $this->assertStringContainsString('Enter the password again', $e[0]);
            $s = $this->sw->switchTo('postgres', ['acknowledge_no_data_move' => true, 'connection' => $this->form(['password' => ''] + $change)]);
            $this->assertFalse($s['ok']);
        }
        $this->assertSame(0, $this->connects, 'the stored password never reached a connector');
        $this->assertSame('mariadb', $this->h->module->settings()->dataStore());
        $this->assertSame($this->form()['host'], $this->sw->connectionSettings()['host'], 'nothing was overwritten');
    }

    public function testReusingTheStoredPasswordOnARemoteHostNeedsVerifiedTls(): void
    {
        $this->sw->saveConnection($this->form(['host' => 'db.internal.example', 'password' => 'x', 'sslmode' => 'verify-full']));
        $e = $this->sw->saveConnection(['host' => 'db.internal.example', 'password' => '', 'sslmode' => 'prefer'] + $this->form(['host' => 'db.internal.example']));
        $this->assertStringContainsString('verified TLS', $e[0]);
        $this->assertSame([], $this->sw->saveConnection(['host' => 'db.internal.example', 'password' => '', 'sslmode' => 'verify-ca'] + $this->form(['host' => 'db.internal.example'])));
        $this->assertSame(0, $this->connects);
    }

    public function testUpdateStillRefusesPostgresAndSwitchingNeedsTheAcknowledgement(): void
    {
        $e = $this->h->module->settings()->update(['data_store' => 'postgres']);
        $this->assertStringContainsString('cannot be selected in the settings yet', $e[0]);
        $this->sw->saveConnection($this->form());
        $r = $this->sw->switchTo('postgres');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('does not move existing history', $r['errors'][0]);
        $this->assertSame('mariadb', $this->h->module->settings()->dataStore());
    }

    public function testASuccessfulSwitchTestsMigratesTheSchemaAndWritesTheSetting(): void
    {
        $r = $this->sw->switchTo('postgres', ['acknowledge_no_data_move' => true, 'connection' => $this->form()]);
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertTrue($r['test']['ok']);
        $this->assertSame('postgres', $this->h->module->settings()->dataStore());
        $this->assertNotNull($this->sw->status()['left_behind']);
        $this->assertTrue($this->sw->connectionSettings()['test_ok']);
        $back = $this->sw->switchTo('mariadb');
        $this->assertTrue($back['ok']);
        $this->assertSame('mariadb', $this->h->module->settings()->dataStore());
    }

    public function testAFailedTestChangesNothing(): void
    {
        $r = $this->sw->switchTo('postgres', ['acknowledge_no_data_move' => true, 'connection' => $this->form(['password' => 'wrong-password'])]);
        $this->assertFalse($r['ok']);
        $this->assertStringNotContainsString('wrong-password', json_encode($r));
        $this->assertSame('mariadb', $this->h->module->settings()->dataStore());
        $this->assertNull($this->sw->connectionSettings(), 'a failed switch stores nothing');
    }

    public function testAfterTheSwitchTheModuleUsesTheStoredConnectionAndTheCapacityPanelReadsPostgres(): void
    {
        $r = $this->sw->switchTo('postgres', ['acknowledge_no_data_move' => true, 'connection' => $this->form()]);
        $this->assertTrue($r['ok'], json_encode($r));
        $m = $this->h->module;
        $m->settings()->refresh();
        foreach (['dataStores', 'resolvedStores', 'pgConnection'] as $prop) {
            (new \ReflectionProperty($m, $prop))->setValue($m, null);
        }
        $stores = $m->dataStores();
        $this->assertSame('postgres', $stores->kind());
        $stores->checkHistory()->append(3, [['key' => 'k', 'status' => 'ok', 'detail' => 'through the stored connection', 'at' => gmdate('Y-m-d H:i:s')]]);
        $this->assertSame('1', (string) PostgresTestDatabase::connect()->value("SELECT COUNT(*) FROM endpoint_agent_check_history WHERE detail = 'through the stored connection'"));
        $panel = $m->capacity();
        $sizes = $panel->storeSizes();
        $this->assertSame('postgres', $sizes['kind']);
        $this->assertTrue($sizes['available']);
        $this->assertSame('0', (string) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_check_history WHERE detail = 'through the stored connection'"), 'MariaDB has none');
    }

    public function testTheConnectionCannotBeChangedWhileAMigrationRuns(): void
    {
        $this->sw->saveConnection($this->form());
        $this->h->q("INSERT INTO rmm_store_migration (from_store, to_store, mode, state, started_at, updated_at) VALUES ('mariadb', 'postgres', 'full', 'copying', NOW(), NOW())");
        $e = $this->sw->saveConnection($this->form(['host' => 'elsewhere.example', 'password' => 'x']));
        $this->assertStringContainsString('migration is in progress', $e[0]);
        $this->assertSame($this->form()['host'], $this->sw->connectionSettings()['host']);
        $this->h->q("DELETE FROM rmm_store_migration");
    }

    public function testSwitchingBackOnAMariadbInstallLeavesNoFalseBanner(): void
    {
        $this->assertTrue($this->sw->switchTo('mariadb')['ok']);
        $this->assertNull($this->sw->status()['left_behind']);
    }
}
