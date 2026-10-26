<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm\Postgres;

use RivetCore\Rmm\Store\DataStoreInfo;
use RivetCore\Rmm\Store\DataStoreSwitch;
use RivetCore\Rmm\Store\Postgres\PostgresConnectionInterface;
use RivetCore\Rmm\Store\Postgres\PostgresMigrator;
use RivetCore\Rmm\Support\RmmCache;
use RivetCore\Tests\Support\ArrayPsrCache;
use RivetCore\Tests\Support\PostgresTestDatabase;
use RivetCore\Tests\Support\RmmTestCase;

/**
 * {@see DataStoreInfo} against a real PostgreSQL (the scratch server of the CI job, 18 or 16): version, schema state, storage mode, size, an unreachable server, a wrong password,
 * and that nothing secret is returned. Every test works in its OWN role and database with random names and password, so the secrets it scans for cannot be a substring of anything else.
 */
final class DataStoreInfoPostgresTest extends RmmTestCase
{
    private string $role = '';
    private string $db = '';
    private string $pw = '';
    private PostgresConnectionInterface $admin;

    protected function setUp(): void
    {
        if (($why = PostgresTestDatabase::unavailable()) !== null) {
            $this->markTestSkipped($why);
        }
        parent::setUp();
        $this->admin = PostgresTestDatabase::connect();
        $tag = bin2hex(random_bytes(5));
        $this->role = 'ru_' . $tag;
        $this->db = 'rmm_info_scratch_' . $tag;
        $this->pw = 'Pw' . bin2hex(random_bytes(10));
        $this->admin->execute('CREATE ROLE "' . $this->role . '" LOGIN PASSWORD \'' . $this->pw . '\'');
        $this->admin->execute('CREATE DATABASE "' . $this->db . '" OWNER "' . $this->role . '"');
    }

    protected function tearDown(): void
    {
        if (isset($this->admin) && $this->db !== '') {
            try {
                $this->h->q('DELETE FROM rmm_data_store_connection');
                $this->h->q('DELETE FROM rmm_store_migration');
                $this->h->q("UPDATE endpoint_agent_settings SET data_store = 'mariadb', data_store_phase = NULL WHERE id = 1");
                $this->admin->execute('DROP DATABASE IF EXISTS "' . $this->db . '" WITH (FORCE)');
                $this->admin->execute('DROP ROLE IF EXISTS "' . $this->role . '"');
            } finally {
                $this->admin->close();
            }
        }
        parent::tearDown();
    }

    /** @return array<string,mixed> */
    private function form(array $over = []): array
    {
        $c = PostgresTestDatabase::config();

        return $over + ['host' => $c->host, 'port' => $c->port, 'database' => $this->db, 'user' => $this->role, 'password' => $this->pw, 'sslmode' => 'disable', 'schema' => 'public'];
    }

    private function sw(): DataStoreSwitch
    {
        return new DataStoreSwitch($this->h->module->sql(), $this->h->module->settings(), $this->h->box, $this->h->audit);
    }

    /** A module on PostgreSQL through the supported path: save the connection, test, create the schema, switch. */
    private function usePostgres(array $over = []): void
    {
        $r = $this->sw()->switchTo('postgres', ['acknowledge_no_data_move' => true, 'connection' => $this->form($over)]);
        $this->assertTrue($r['ok'], json_encode($r['errors']));
        $this->h->module->settings()->refresh();
    }

    private function assertNoSecrets(array $info): void
    {
        $json = (string) json_encode($info);
        foreach ([$this->pw, $this->role, 'password', 'sealed', 'dsn'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $json, "the report must not contain '$needle'");
        }
        $raw = (string) $this->h->one('SELECT password_sealed FROM rmm_data_store_connection WHERE id = 1');
        if ($raw !== '') {
            $this->assertStringNotContainsString($raw, $json);
        }
    }

    private function ownConnection(): PostgresConnectionInterface
    {
        return PostgresTestDatabase::connect(['database' => $this->db, 'user' => $this->role, 'password' => $this->pw]);
    }

    public function testAPostgresInstallReportsVersionSchemaModeAndSize(): void
    {
        $this->usePostgres();
        $r = $this->h->module->dataStoreInfo()->report();
        $this->assertSame('postgres', $r['store']);
        $this->assertTrue($r['active']);
        $this->assertTrue($r['probed']);
        $this->assertNull($r['error']);
        $pg = $r['postgres'];
        $this->assertTrue($pg['reachable']);
        $this->assertTrue($pg['in_use']);
        $server = $this->admin->serverVersion();
        $this->assertMatchesRegularExpression('/^\d+\.\d+$/', $pg['server_version']);
        $this->assertStringStartsWith($pg['server_version'], $server);
        $this->assertSame($server, $pg['server_version_full']);
        $this->assertSame((int) $server, $pg['version_major']);
        $this->assertContains($pg['storage_mode'], ['native', 'timescale']);
        $this->assertSame($pg['storage_mode'] === 'timescale', $pg['timescale_version'] !== null);
        $this->assertSame($this->db, $pg['database']);
        $this->assertSame(PostgresTestDatabase::config()->host, $pg['host']);
        $this->assertSame(PostgresTestDatabase::config()->port, $pg['port']);
        $migrator = new PostgresMigrator($this->ownConnection());
        $this->assertSame(['exists' => true, 'applied' => [$migrator->latest()], 'pending' => [], 'changed' => [], 'current' => $migrator->latest(), 'up_to_date' => true], $pg['schema']);
        $this->assertGreaterThan(0, $pg['database_size_bytes']);
        $this->assertArrayNotHasKey('tables', $pg, 'tables only when asked');
        $this->assertSame('PostgreSQL ' . $pg['server_version'] . ' (' . $pg['storage_mode'] . ($pg['timescale_version'] !== null ? ' ' . $pg['timescale_version'] : '') . ') - schema ' . $migrator->latest() . ', up to date', DataStoreInfo::summaryLine($r));
        $this->assertNoSecrets($r);

        $t = $this->h->module->dataStoreInfo()->report(false, true)['postgres'];
        $this->assertNotEmpty($t['tables']);
        $this->assertSame(array_sum(array_column($t['tables'], 'bytes')), $t['total_bytes']);
        $this->assertNoSecrets($this->h->module->dataStoreInfo()->report(false, true));
    }

    public function testAPendingAndAChangedMigrationAreShown(): void
    {
        $this->usePostgres();
        $own = $this->ownConnection();
        $latest = (new PostgresMigrator($own))->latest();
        $own->execute("UPDATE rivet_core_pg_migrations SET checksum = 'tampered' WHERE id = ?", [$latest]);
        $changed = $this->h->module->dataStoreInfo()->report();
        $this->assertSame([$latest], $changed['postgres']['schema']['changed']);
        $this->assertFalse($changed['postgres']['schema']['up_to_date']);
        $this->assertSame('PostgreSQL ' . $changed['postgres']['server_version'] . ' (' . $changed['postgres']['storage_mode'] . ') - schema ' . $latest . ', 1 changed', DataStoreInfo::summaryLine($changed));

        $own->execute('DELETE FROM rivet_core_pg_migrations');
        $pending = $this->h->module->dataStoreInfo()->report();
        $this->assertSame([$latest], $pending['postgres']['schema']['pending']);
        $this->assertSame([], $pending['postgres']['schema']['applied']);
        $this->assertNull($pending['postgres']['schema']['current']);
        $this->assertFalse($pending['postgres']['schema']['up_to_date']);
        $this->assertStringContainsString('1 pending', DataStoreInfo::summaryLine($pending));
        $own->close();
    }

    public function testAnEmptyDatabaseHasNoSchemaYet(): void
    {
        $this->assertSame([], $this->sw()->saveConnection($this->form()));
        $this->h->q("UPDATE endpoint_agent_settings SET data_store = 'postgres' WHERE id = 1");
        $this->h->module->settings()->refresh();
        $r = $this->h->module->dataStoreInfo()->report();
        $this->assertTrue($r['postgres']['reachable']);
        $this->assertFalse($r['postgres']['schema']['exists']);
        $this->assertSame(['0001_hot_tables'], $r['postgres']['schema']['pending']);
        $this->assertStringEndsWith('schema not created', DataStoreInfo::summaryLine($r));
    }

    public function testAMariadbInstallWithASavedConnectionIsProbedOnlyOnRequest(): void
    {
        $this->usePostgres();
        $this->sw()->switchTo('mariadb');
        $this->h->module->settings()->refresh();
        $info = $this->h->module->dataStoreInfo();
        $quiet = $info->report();
        $this->assertSame('mariadb', $quiet['store']);
        $this->assertNull($quiet['postgres']);
        $this->assertTrue($quiet['configured']);
        $this->assertTrue($quiet['saved']['test_ok']);
        $this->assertSame($this->db, $quiet['saved']['database']);
        $this->assertSame('MariaDB (PostgreSQL configured but not in use)', DataStoreInfo::summaryLine($quiet));
        $probed = $info->report(true);
        $this->assertSame('mariadb', $probed['store']);
        $this->assertFalse($probed['postgres']['in_use']);
        $this->assertTrue($probed['postgres']['reachable']);
        $this->assertNoSecrets($quiet);
        $this->assertNoSecrets($probed);
    }

    public function testAWrongPasswordIsAnAuthenticationFailureWithoutLeaks(): void
    {
        $this->usePostgres();
        $this->admin->execute('ALTER ROLE "' . $this->role . '" PASSWORD \'' . 'Other' . bin2hex(random_bytes(8)) . '\'');
        $r = $this->h->module->dataStoreInfo()->report();
        $this->assertFalse($r['postgres']['reachable']);
        $this->assertSame('authentication failed', $r['error']);
        $this->assertSame('PostgreSQL unreachable: authentication failed', DataStoreInfo::summaryLine($r));
        $this->assertNoSecrets($r);
        $this->assertSame($this->db, $r['postgres']['database']);
    }

    public function testAnUnreachableServerAnswersQuicklyAndNeverThrows(): void
    {
        $this->usePostgres();
        // point the saved connection at a port nobody listens on (the stored row is edited directly: the supported path would try to connect)
        $this->h->q('UPDATE rmm_data_store_connection SET port = 1 WHERE id = 1');
        $this->h->module->settings()->refresh();
        $t = microtime(true);
        $r = $this->h->module->dataStoreInfo()->report();
        $first = microtime(true) - $t;
        $this->assertLessThan(5.0, $first);
        $this->assertFalse($r['postgres']['reachable']);
        $this->assertSame('connection refused', $r['error']);
        $this->assertSame('PostgreSQL unreachable: connection refused', DataStoreInfo::summaryLine($r));
        $this->assertSame(1, $r['postgres']['port']);
        $this->assertNoSecrets($r);
        // the circuit breaker is open now: the next page load does not even try
        $t = microtime(true);
        $again = $this->h->module->dataStoreInfo()->report();
        $this->assertLessThan(0.5, microtime(true) - $t);
        $this->assertStringStartsWith('connection attempts are paused after a recent failure', (string) $again['error']);
        $this->assertNoSecrets($again);
    }

    public function testABlackholeAddressIsGivenUpWithinTheConnectTimeout(): void
    {
        $this->usePostgres();
        $this->h->q("UPDATE rmm_data_store_connection SET host = '10.255.255.1', sslmode = 'disable' WHERE id = 1");
        $this->h->module->settings()->refresh();
        $t = microtime(true);
        $r = $this->h->module->dataStoreInfo()->report();
        $this->assertLessThan(5.0, microtime(true) - $t, 'a dead server never stalls a page');
        $this->assertFalse($r['postgres']['reachable']);
        $this->assertContains($r['error'], ['connection timed out', 'connection refused', 'connection failed'], (string) $r['error']);
        $this->assertNoSecrets($r);
    }

    public function testALiveAnswerIsCachedForThirtySecondsWhenThereIsASharedCache(): void
    {
        $this->usePostgres();
        $psr = new ArrayPsrCache($this->h->clock);
        $info = new DataStoreInfo($this->sw(), $this->h->module->settings(), fn (): array => [$this->ownConnection(), null], false, new RmmCache($this->h->module->sql(), $psr),
            fn (): int => $this->h->clock->now()->getTimestamp());
        $a = $info->report();
        $b = $info->report();
        $this->assertFalse($a['cached']);
        $this->assertTrue($b['cached']);
        $this->assertSame($a['postgres'], $b['postgres']);
        $this->assertNoSecrets($b);
        $this->assertStringNotContainsString($this->pw, json_encode($psr->items));
    }
}
