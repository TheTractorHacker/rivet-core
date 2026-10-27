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
        // the readers of every device page answer "no data" instead of breaking the page
        $asset = (int) $this->h->one("SELECT asset_id FROM endpoint_agent_devices WHERE device_id = {$this->dev}");
        $metrics = $this->h->module->dataStores()->metrics();
        $this->assertSame([], $metrics->latest($asset));
        $this->assertNull($metrics->peak($asset, 'cpu.utilization', null, new \DateTimeImmutable('-1 day')));
        $this->assertSame([], $metrics->series($asset, 'cpu.utilization', null, new \DateTimeImmutable('-1 day'), new \DateTimeImmutable()));
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

    public function testATransferDuringAnOutageIsRecordedAndTheHousekeepingFinishesIt(): void
    {
        $this->enableLogs();
        $good = $this->connection;
        $this->useStore('postgres');
        $this->ship('before the move');
        $this->connection = fn () => PdoPostgresConnection::fromConfig(PostgresTestDatabase::config(['port' => 1, 'connect_timeout' => 1, 'breaker_open_s' => 0]));
        $this->useStore('postgres');   // a new request: the module resolves the stores again, now against an unreachable server
        $this->assertTrue($this->h->module->deviceService()->transfer($this->dev, $this->h->clientB, 0, 1), 'the transfer itself succeeds');
        $this->assertSame($this->h->clientB, (int) $this->h->one("SELECT client_id FROM endpoint_agent_devices WHERE device_id = {$this->dev}"));
        $this->assertSame([[$this->dev, $this->h->clientB]], array_map(static fn (array $r): array => [(int) $r['device_id'], (int) $r['client_id']], $this->h->rows('SELECT device_id, client_id FROM rmm_pending_client_moves')), 'the move of the history is recorded');
        $this->assertSame($this->h->clientA, (int) $this->pg()->value('SELECT client_id FROM endpoint_agent_log_events LIMIT 1'), 'the rows still say client A');
        // the server is back: the next housekeeping run finishes the move
        $this->connection = $good;
        $this->useStore('postgres');
        $out = $this->h->module->housekeeping()->run();
        $this->assertSame(1, $out['client_moves_retried']);
        $this->assertSame('0', (string) $this->h->one('SELECT COUNT(*) FROM rmm_pending_client_moves'));
        $this->assertSame($this->h->clientB, (int) $this->pg()->value('SELECT client_id FROM endpoint_agent_log_events LIMIT 1'));
        $this->assertSame(1, $this->h->module->logService()->search([], [$this->h->clientB])['total']);
        $this->assertSame(0, $this->h->module->logService()->search([], [$this->h->clientA])['total']);
    }

    public function testTheSoftwareInventoryCommitsFirstAndTheHistoryFollowsAndAnOutageOnlyLosesTheHistory(): void
    {
        $this->switchOn(['inventory_software']);
        $good = $this->connection;
        $this->useStore('postgres');
        $item = static fn (array $i): array => ['name' => $i[1], 'version' => $i[2], 'publisher' => $i[3], 'source' => $i[0]];
        $hash = static fn (array $list): string => \RivetCore\Rmm\Software\SoftwareHash::of(array_map(static fn (array $i): array => ['source' => $i[0], 'name' => $i[1], 'version' => $i[2], 'publisher' => $i[3]], $list));
        $send = fn (array $sw): array => $this->h->checkin($this->devToken, ['platform' => 'windows', 'capabilities' => ['software_inventory'], 'software' => $sw]);
        $base = [['registry', 'Bash', '5.2', 'GNU'], ['registry', 'Nano', '7.2', 'GNU']];
        $this->assertSame(200, $send(['mode' => 'full', 'hash' => $hash($base), 'count' => 2, 'truncated' => false, 'items' => array_map($item, $base)])[0]);
        $after = [['registry', 'Bash', '5.3', 'GNU'], ['registry', 'Git', '2.43', 'Git']];
        $delta = fn (array $from, array $to, array $changed, array $removed): array => ['mode' => 'delta', 'base_hash' => $hash($from), 'hash' => $hash($to), 'count' => count($to), 'truncated' => false,
            'items' => array_map($item, $changed), 'removed' => $removed];
        $this->assertSame(200, $send($delta($base, $after, [$after[0], $after[1]], [['source' => 'registry', 'name' => 'Nano']]))[0]);
        $this->assertSame(3, (int) $this->pg()->value('SELECT COUNT(*) FROM rmm_software_history'), 'upgraded, installed, removed: in PostgreSQL');
        $this->assertSame('0', (string) $this->h->one('SELECT COUNT(*) FROM rmm_software_history'), 'and not in MariaDB');
        // now the server is gone: the inventory (control data) is still updated in full, only the history entry is lost, and the check-in is answered
        $this->connection = fn () => PdoPostgresConnection::fromConfig(PostgresTestDatabase::config(['port' => 1, 'connect_timeout' => 1, 'breaker_open_s' => 0]));
        $this->useStore('postgres');
        $third = [['registry', 'Bash', '5.4', 'GNU'], ['registry', 'Git', '2.43', 'Git']];
        $this->assertSame(200, $send($delta($after, $third, [$third[0]], []))[0]);
        $this->assertSame(['Bash@5.4', 'Git@2.43'], array_map(static fn (array $r): string => $r['name'] . '@' . $r['version'], $this->h->rows("SELECT name, version FROM rmm_device_software WHERE device_id = {$this->dev} AND removed_at IS NULL ORDER BY name")));
        $this->assertSame(3, (int) $this->pg()->value('SELECT COUNT(*) FROM rmm_software_history'), 'the entry for 5.3 > 5.4 could not be written');
        (new \ReflectionMethod($this->h->module, 'hotStoreFaults'))->invoke($this->h->module)->flush();
        $this->assertGreaterThanOrEqual(1, $this->h->module->dataStoreHealth()['stores']['software_history']['dropped']);
        $this->connection = $good;
    }

    public function testHistoryWritesWaitForTheControlCommitAndAreDroppedWithARollback(): void
    {
        $this->useStore('postgres');
        $sql = $this->h->module->sql();
        $stores = $this->h->module->dataStores();
        $entry = [['key' => 'k', 'status' => 'ok', 'detail' => 'd', 'at' => gmdate('Y-m-d H:i:s')]];
        $count = fn (): int => (int) $this->pg()->value('SELECT COUNT(*) FROM endpoint_agent_check_history');
        try {
            $sql->transaction(function () use ($stores, $entry, $count): void {
                $stores->checkHistory()->append($this->dev, $entry);
                $this->assertSame(0, $count(), 'held while the control transaction is open');
                throw new \RuntimeException('the check-in failed');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame(0, $count(), 'and dropped with the rollback: no history for a check-in that did not happen');
        $sql->transaction(function () use ($stores, $entry, $count): void {
            $stores->checkHistory()->append($this->dev, $entry);
            $this->assertSame(0, $count());
        });
        $this->assertSame(1, $count(), 'written right after the commit');
        $stores->checkHistory()->append($this->dev, $entry);
        $this->assertSame(2, $count(), 'outside a transaction: at once');
    }

    public function testTheHistoryOfAStoreInAnotherDatabaseIsNeverWrittenWhileTheControlTransactionIsOpen(): void
    {
        $this->switchOn(['inventory_software']);
        $inTransaction = [];
        $this->connection = function () use (&$inTransaction): PostgresConnectionInterface {
            $real = PdoPostgresConnection::fromConfig(PostgresTestDatabase::config(['breaker_open_s' => 0]));

            return new class($real, $this->h->mysqli, $inTransaction) implements PostgresConnectionInterface {
                /** @param list<int> $seen */
                public function __construct(private readonly PostgresConnectionInterface $inner, private readonly \mysqli $control, private array &$seen)
                {
                }

                private function probe(string $sql): void
                {
                    if (str_contains($sql, 'rmm_software_history')) {
                        $this->seen[] = (int) $this->control->query('SELECT @@in_transaction')->fetch_row()[0];
                    }
                }

                public function query(string $sql, array $params = []): array
                {
                    $this->probe($sql);

                    return $this->inner->query($sql, $params);
                }

                public function queryOne(string $sql, array $params = []): ?array
                {
                    $this->probe($sql);

                    return $this->inner->queryOne($sql, $params);
                }

                public function value(string $sql, array $params = []): mixed
                {
                    $this->probe($sql);

                    return $this->inner->value($sql, $params);
                }

                public function execute(string $sql, array $params = []): int
                {
                    $this->probe($sql);

                    return $this->inner->execute($sql, $params);
                }

                public function transaction(callable $callback): mixed
                {
                    return $this->inner->transaction($callback);
                }

                public function inTransaction(): bool
                {
                    return $this->inner->inTransaction();
                }

                public function serverVersion(): string
                {
                    return $this->inner->serverVersion();
                }

                public function close(): void
                {
                    $this->inner->close();
                }
            };
        };
        $this->useStore('postgres');
        $item = static fn (array $i): array => ['name' => $i[1], 'version' => $i[2], 'publisher' => $i[3], 'source' => $i[0]];
        $hash = static fn (array $list): string => \RivetCore\Rmm\Software\SoftwareHash::of(array_map(static fn (array $i): array => ['source' => $i[0], 'name' => $i[1], 'version' => $i[2], 'publisher' => $i[3]], $list));
        $send = fn (array $sw): array => $this->h->checkin($this->devToken, ['platform' => 'windows', 'capabilities' => ['software_inventory'], 'software' => $sw]);
        $base = [['registry', 'Bash', '5.2', 'GNU']];
        $send(['mode' => 'full', 'hash' => $hash($base), 'count' => 1, 'truncated' => false, 'items' => array_map($item, $base)]);
        $after = [['registry', 'Bash', '5.3', 'GNU']];
        $send(['mode' => 'delta', 'base_hash' => $hash($base), 'hash' => $hash($after), 'count' => 1, 'truncated' => false, 'items' => array_map($item, $after), 'removed' => []]);
        $this->assertNotSame([], $inTransaction, 'the history append happened');
        $this->assertSame([], array_filter($inTransaction), 'and never while the control database transaction was open');
    }

    public function testARuleThatCannotCountBecauseTheStoreWentAwayDoesNotFailAStoredBatch(): void
    {
        $this->enableLogs();
        // the server answers the insert and the ledger, then "goes away" for the statement the rule uses to count
        $this->connection = static function (): PostgresConnectionInterface {
            $real = PdoPostgresConnection::fromConfig(PostgresTestDatabase::config(['breaker_open_s' => 0]));

            return new class($real) implements PostgresConnectionInterface {
                public function __construct(private readonly PostgresConnectionInterface $inner)
                {
                }

                private function check(string $sql): void
                {
                    if (str_contains($sql, 'SELECT 1 FROM endpoint_agent_log_events WHERE device_id')) {
                        throw new \RivetCore\Rmm\Store\Postgres\PostgresConnectionException('the server went away');
                    }
                }

                public function query(string $sql, array $params = []): array
                {
                    $this->check($sql);

                    return $this->inner->query($sql, $params);
                }

                public function queryOne(string $sql, array $params = []): ?array
                {
                    $this->check($sql);

                    return $this->inner->queryOne($sql, $params);
                }

                public function value(string $sql, array $params = []): mixed
                {
                    $this->check($sql);

                    return $this->inner->value($sql, $params);
                }

                public function execute(string $sql, array $params = []): int
                {
                    $this->check($sql);

                    return $this->inner->execute($sql, $params);
                }

                public function transaction(callable $callback): mixed
                {
                    return $this->inner->transaction($callback);
                }

                public function inTransaction(): bool
                {
                    return $this->inner->inTransaction();
                }

                public function serverVersion(): string
                {
                    return $this->inner->serverVersion();
                }

                public function close(): void
                {
                    $this->inner->close();
                }
            };
        };
        $this->useStore('postgres');
        [$c] = $this->h->tech('POST', ['logs', 'rules'], $this->admin, ['name' => 'Spooler', 'min_level' => 'error', 'contains' => 'spooler', 'severity' => 'fail', 'threshold' => 3, 'window_min' => 10]);   // a threshold above one counts the window in the store
        $this->assertContains($c, [200, 201]);
        [$status, $j] = $this->ship('The Print Spooler service entered the stopped state.');
        $this->assertSame([200, 1], [$status, $j['accepted']], 'the batch is stored and accepted; only its rule evaluation was skipped');
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
