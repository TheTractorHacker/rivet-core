<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm\Postgres;

use RivetCore\Rmm\Http\ApiError;
use RivetCore\Rmm\Store\HotStoreUnavailableException;
use RivetCore\Rmm\Store\Postgres\Pdo\PdoPostgresConnection;
use RivetCore\Rmm\Store\Postgres\PostgresConnectionInterface;
use RivetCore\Rmm\Support\DatabaseMetricSink;
use RivetCore\Testing\InMemoryRmmEvents;
use RivetCore\Tests\Support\AllowUsersPolicy;
use RivetCore\Tests\Support\PostgresTestDatabase;
use RivetCore\Tests\Support\RemoteTestCase;
use RivetCore\Tests\Support\RmmHarness;

/**
 * The module wired to PostgreSQL (phase 2 of #89): `rmm_data_store = postgres` with a connection from the edition, without one, with an unreachable server, and the proof
 * that MariaDB installs never touch the connection. The device protocol is the real one (check-in, logs); the control database is the real scratch MariaDB.
 */
final class PostgresModuleTest extends RemoteTestCase
{
    /** @var \Closure(): ?PostgresConnectionInterface */
    private \Closure $connection;
    private int $connectionCalls = 0;
    private ?string $pgOption = 'good';

    protected function makeHarness(): RmmHarness
    {
        $this->transferDir = sys_get_temp_dir() . '/rmm_xfer_' . bin2hex(random_bytes(4));
        $this->connection = fn () => PdoPostgresConnection::fromConfig(PostgresTestDatabase::config(['breaker_open_s' => 0]));
        $options = ['allow_linux' => true, 'transfer_dir' => $this->transferDir];
        if ($this->pgOption !== null) {
            $options['postgres'] = function (): ?PostgresConnectionInterface {
                ++$this->connectionCalls;

                return ($this->connection)();
            };
        }

        return new RmmHarness(null, null, $options, false, new DatabaseMetricSink(new \RivetCore\Tests\Support\MysqliDatabase(\RivetCore\Tests\Support\ScratchDb::connect()), new \RivetCore\Tests\Support\MutableClock()),
            new AllowUsersPolicy([1 => true, 10 => true, 11 => true, 12 => true, 16 => true]), null, new InMemoryRmmEvents());
    }

    protected function setUp(): void
    {
        if (($why = PostgresTestDatabase::unavailable()) !== null) {
            $this->markTestSkipped($why);
        }
        PostgresTestDatabase::prepare(PostgresTestDatabase::connect());
        parent::setUp();
        [$this->dev, $this->devToken] = $this->linked('PGM1');
        $this->h->q('DELETE FROM rmm_hot_store_faults');
    }

    private function useStore(string $kind): void
    {
        foreach (['rmm_metric_latest', 'rmm_metric_hourly', 'endpoint_agent_check_history', 'endpoint_agent_log_events', 'endpoint_agent_log_state'] as $t) {
            $this->h->q("DELETE FROM $t");   // what the enrolment of the test device wrote while the store was still mariadb
        }
        $this->h->q("UPDATE endpoint_agent_settings SET data_store = '$kind' WHERE id = 1");
        $this->h->module->settings()->refresh();
        $module = $this->h->module;
        foreach (['dataStores', 'resolvedStores'] as $prop) {
            (new \ReflectionProperty($module, $prop))->setValue($module, null);
        }
    }

    private function enableLogs(): void
    {
        $this->switchOn(['logs']);
        $this->assertSame([], $this->h->module->remoteSettings()->update(['logs_collect' => ['windows' => [['channel' => 'System', 'levels' => ['critical', 'error', 'warning'], 'event_ids' => [7036]]], 'linux' => []]], 1));
    }

    /** @return array{0:int,1:?array<string,mixed>,2:\RivetCore\Rmm\Http\RmmResponse} */
    private function ship(string $msg = 'The Print Spooler service entered the stopped state.'): array
    {
        $ev = ['ts' => gmdate('Y-m-d\TH:i:s\Z', time() - 30), 'level' => 'error', 'source' => 'Service Control Manager', 'event_id' => 7036, 'message' => $msg];
        $r = $this->h->api->handle($this->h->request('POST', 'agent_logs', (string) json_encode(['events' => [$ev], 'dropped' => 0, 'config_version' => 'x']), $this->devToken));

        return [$r->status, $r->body === null ? null : json_decode($r->body, true), $r];
    }

    private function pg(): PostgresConnectionInterface
    {
        return PostgresTestDatabase::connect();
    }

    // ------------------------------------------------------------------ MariaDB never connects

    public function testAMariadbInstallNeverBuildsOrOpensTheConnection(): void
    {
        $this->enableLogs();
        $this->assertSame('mariadb', $this->h->module->dataStores()->kind());
        [$c, , $j] = $this->h->checkin($this->devToken);
        $this->assertSame(200, $c);
        $this->assertSame(200, $this->ship()[0]);
        $this->h->module->logService()->stats($this->dev);
        $this->h->module->housekeeping()->run();
        $this->h->module->capacity()->build();
        $this->assertSame(0, $this->connectionCalls, 'the closure that builds the PostgreSQL connection is never called while the data store is mariadb');
        $this->assertSame(1, (int) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_log_events'), 'the log entry is in MariaDB');
        $this->assertNotSame('', (string) $j['config']['collect_interval_s'] ?? '');
    }

    // ------------------------------------------------------------------ PostgreSQL, reachable

    public function testCheckInsLogsAndMetricsLandInPostgresAndNothingInMariadb(): void
    {
        $this->enableLogs();
        $this->useStore('postgres');
        $this->assertSame('postgres', $this->h->module->dataStores()->kind());
        [$c] = $this->h->checkin($this->devToken);
        $this->assertSame(200, $c);
        [$c, $j] = $this->ship('first message');
        $this->assertSame([200, 1], [$c, $j['accepted']]);
        $this->assertSame(1, $this->ship('second message')[1]['accepted']);
        $pg = $this->pg();
        $this->assertGreaterThan(0, (int) $pg->value('SELECT COUNT(*) FROM rmm_metric_latest'), 'the check-in metrics went to PostgreSQL');
        $this->assertSame(2, (int) $pg->value('SELECT COUNT(*) FROM endpoint_agent_log_events'));
        $this->assertSame(2, (int) $pg->value('SELECT row_count FROM endpoint_agent_log_state WHERE device_id = ?', [$this->dev]));
        foreach (['rmm_metric_latest', 'rmm_metric_hourly', 'endpoint_agent_log_events', 'endpoint_agent_log_state', 'endpoint_agent_check_history'] as $t) {
            $this->assertSame('0', (string) $this->h->one("SELECT COUNT(*) FROM $t"), "$t stays empty in MariaDB");
        }
        $this->assertSame($this->h->clientA, (int) $pg->value('SELECT client_id FROM endpoint_agent_log_events LIMIT 1'), 'the device\'s client is stored with the entry');
        // the search adds the host name from the control database and scopes by the stored client
        $res = $this->h->module->logService()->search(['device_id' => $this->dev], [$this->h->clientA]);
        $this->assertSame([2, 'PGM1', $this->h->clientA], [$res['total'], $res['items'][0]['hostname'], $res['items'][0]['client_id']]);
        $this->assertSame(0, $this->h->module->logService()->search([], [$this->h->clientB])['total']);
        // the read path of the metric readers reads PostgreSQL too
        $asset = (int) $this->h->one("SELECT asset_id FROM endpoint_agent_devices WHERE device_id = {$this->dev}");
        $this->assertNotSame([], $this->h->module->dataStores()->metrics()->latest($asset));
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM rmm_hot_store_faults'), 'a healthy PostgreSQL records no fault');
    }

    public function testATransferMovesTheHistoryRowsToTheNewClient(): void
    {
        $this->enableLogs();
        $this->useStore('postgres');
        $this->ship('before the move');
        $this->assertSame(1, $this->h->module->logService()->search([], [$this->h->clientA])['total']);
        $this->assertTrue($this->h->module->deviceService()->transfer($this->dev, $this->h->clientB, 0, 1));
        $this->assertSame(0, $this->h->module->logService()->search([], [$this->h->clientA])['total']);
        $this->assertSame(1, $this->h->module->logService()->search([], [$this->h->clientB])['total'], 'the entries followed the device');
        $this->assertSame($this->h->clientB, (int) $this->pg()->value('SELECT client_id FROM endpoint_agent_log_events LIMIT 1'));
    }

    // ------------------------------------------------------------------ PostgreSQL, unreachable

    public function testWithPostgresDownCheckInsStillSucceedAndHistoryIsDroppedAndCounted(): void
    {
        $this->enableLogs();
        $this->connection = fn () => PdoPostgresConnection::fromConfig(PostgresTestDatabase::config(['port' => 1, 'connect_timeout' => 1, 'breaker_open_s' => 30]));
        $this->useStore('postgres');
        $seqBefore = (string) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_checkins WHERE device_id = {$this->dev}");
        $t = microtime(true);
        [$c, , $j] = $this->h->checkin($this->devToken, ['metrics' => ['cpu_pct' => 12.0, 'mem_pct' => 33.0, 'disk' => [['mount' => 'C:', 'used_pct' => 5.0]], 'net_rx_bps' => 1.0, 'net_tx_bps' => 2.0]]);
        $this->assertSame(200, $c, 'the check-in is answered');
        $this->assertArrayHasKey('config', $j);
        $this->assertSame((string) ((int) $seqBefore + 1), (string) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_checkins WHERE device_id = {$this->dev}"), 'and recorded in the control database');
        $this->assertLessThan(5.0, microtime(true) - $t, 'one connect timeout at most, the breaker answers the rest at once');
        foreach (['rmm_metric_latest', 'rmm_metric_hourly', 'endpoint_agent_check_history'] as $t2) {
            $this->assertSame('0', (string) $this->h->one("SELECT COUNT(*) FROM $t2"), "nothing is written to MariaDB instead ($t2)");
        }
        $this->h->module->dataStores()->checkHistory()->append($this->dev, [['key' => 'k', 'status' => 'ok', 'detail' => '', 'at' => gmdate('Y-m-d H:i:s')]]);   // lossy: no exception
        $this->h->module->dataStores()->softwareHistory()->append($this->dev, [['key' => 'k', 'name' => 'n', 'source' => 's', 'publisher' => '', 'type' => 'installed', 'old' => null, 'new' => '1']], gmdate('Y-m-d H:i:s'));
        // the faults reach the control database when the request ends; here: now
        $reflect = new \ReflectionMethod($this->h->module, 'hotStoreFaults');
        $reflect->invoke($this->h->module)->flush();
        $health = $this->h->module->dataStoreHealth();
        $this->assertSame('postgres', $health['kind']);
        $this->assertGreaterThanOrEqual(2, $health['errors']);
        $this->assertGreaterThanOrEqual(2, $health['dropped']);
        $this->assertArrayHasKey('metrics', $health['stores']);
        $this->assertStringContainsString('PostgreSQL', $health['last_error']);
        $codes = array_column($this->h->module->capacity()->build()['warnings'], 'code');
        $this->assertContains('data_store', $codes, 'the capacity panel warns');
        $this->assertArrayHasKey('unavailable', $this->h->module->capacity()->storage($this->h->module->settings()->limits()), 'and still renders');
        $this->h->module->clearDataStoreFaults();
        $this->assertSame(0, $this->h->module->dataStoreHealth()['errors']);
    }

    public function testWithPostgresDownLogAndChangeBatchesGetARetryable503AndNothingIsLost(): void
    {
        $this->enableLogs();
        $this->connection = fn () => PdoPostgresConnection::fromConfig(PostgresTestDatabase::config(['port' => 1, 'connect_timeout' => 1, 'breaker_open_s' => 30]));
        $this->useStore('postgres');
        [$c, $j, $r] = $this->ship();
        $this->assertSame([503, 'unavailable'], [$c, $j['code']]);
        $this->assertGreaterThanOrEqual(60, (int) $r->headers['Retry-After'], 'the agent backs off and keeps its batch');
        $this->assertSame('0', (string) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_log_events'), 'nothing was written to MariaDB');
        try {
            $this->h->module->changeService()->ingest(['device_id' => $this->dev, 'client_id' => $this->h->clientA, 'os' => 'windows'], ['changes' => [], 'dropped' => 0, 'config_version' => str_repeat('a', 16)]);
            $this->fail('503');
        } catch (ApiError $e) {
            $this->assertSame([503, 'unavailable'], [$e->http, $e->errCode]);
            $this->assertArrayHasKey('Retry-After', $e->headers);
        }
        // reads say why
        $this->expectException(HotStoreUnavailableException::class);
        $this->expectExceptionMessage('PostgreSQL');
        $this->h->module->logService()->search([], null);
    }

    public function testRecoveryNeedsNoRestart(): void
    {
        $this->enableLogs();
        $bad = true;
        $this->connection = function () use (&$bad) {
            return PdoPostgresConnection::fromConfig(PostgresTestDatabase::config(['port' => $bad ? 1 : (int) (getenv('RIVETCORE_TEST_PG_PORT') ?: 5432), 'connect_timeout' => 1, 'breaker_open_s' => 0]));
        };
        $this->useStore('postgres');
        $this->assertSame(503, $this->ship('while down')[0]);
        // the administrator fixes the server; the module builds a new connection on the next request (a new PHP process in production)
        $bad = false;
        $module = $this->h->module;
        foreach (['dataStores', 'resolvedStores'] as $prop) {
            (new \ReflectionProperty($module, $prop))->setValue($module, null);
        }
        $this->assertSame(200, $this->ship('after the fix')[0]);
        $this->assertSame(1, (int) $this->pg()->value('SELECT COUNT(*) FROM endpoint_agent_log_events'));
    }

    // ------------------------------------------------------------------ PostgreSQL, not configured

    public function testPostgresWithoutAConnectionIsRefusedClearlyAndNeverFallsBackToMariadb(): void
    {
        $this->pgOption = null;
        RmmHarness::closeAll();
        $this->h = $this->makeHarness();
        $this->h->enable();
        [$this->dev, $this->devToken] = $this->linked('PGM2');
        $this->enableLogs();
        $this->useStore('postgres');
        $log = tempnam(sys_get_temp_dir(), 'rmmstore');
        ini_set('error_log', (string) $log);
        try {
            $stores = $this->h->module->dataStores();
            $this->assertSame('postgres', $stores->kind(), 'no silent switch to MariaDB');
            [$c, $j] = $this->ship();
            $this->assertSame([503, 'unavailable'], [$c, $j['code']]);
            $this->assertSame(200, $this->h->checkin($this->devToken)[0], 'check-ins still work');
            $this->assertSame('0', (string) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_log_events'));
            try {
                $this->h->module->logService()->search([], null);
                $this->fail('refused');
            } catch (HotStoreUnavailableException $e) {
                $this->assertStringContainsString('no PostgreSQL connection was configured', $e->getMessage());
                $this->assertStringContainsString('"postgres" module option', $e->getMessage());
            }
            $this->assertStringContainsString('no PostgreSQL connection was configured', (string) file_get_contents((string) $log));
        } finally {
            ini_set('error_log', '/dev/null');
            @unlink((string) $log);
        }
    }

    public function testABrokenConnectionFactoryIsReportedNotThrownIntoTheCheckIn(): void
    {
        $this->enableLogs();
        $this->connection = static function (): never {
            throw new \RuntimeException('the vault is sealed');
        };
        $this->useStore('postgres');
        $this->assertSame(200, $this->h->checkin($this->devToken)[0]);
        $this->assertSame(503, $this->ship()[0]);
        try {
            $this->h->module->logService()->search([], null);
            $this->fail('refused');
        } catch (HotStoreUnavailableException $e) {
            $this->assertStringContainsString('could not be turned into a connection', $e->getMessage());
            $this->assertStringContainsString('the vault is sealed', $e->getMessage());
        }
    }
}
