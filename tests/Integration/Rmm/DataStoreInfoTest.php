<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Store\DataStoreInfo;
use RivetCore\Rmm\Store\DataStoreSwitch;
use RivetCore\Rmm\Store\Postgres\PostgresConnectionException;
use RivetCore\Rmm\Store\Postgres\PostgresConnectionInterface;
use RivetCore\Rmm\Support\RmmCache;
use RivetCore\Tests\Support\ArrayPsrCache;
use RivetCore\Tests\Support\RmmTestCase;

/**
 * {@see DataStoreInfo} against the MariaDB control database with a FAKE PostgreSQL connector: a MariaDB install never reaches the connector, nothing secret is returned, every failure
 * is an answer, and the shared cache is honoured. The same class against a real PostgreSQL is in Postgres/DataStoreInfoPostgresTest.
 */
final class DataStoreInfoTest extends RmmTestCase
{
    private const PASSWORD = 'Pw-7f3a91c2d4e85b60';
    private const USER = 'role_a41c9e07';

    private int $targets = 0;

    protected function tearDown(): void
    {
        if (isset($this->h)) {
            $this->h->q('DELETE FROM rmm_data_store_connection');
            $this->h->q("UPDATE endpoint_agent_settings SET data_store = 'mariadb', data_store_phase = NULL WHERE id = 1");
        }
        parent::tearDown();
    }

    private function sw(): DataStoreSwitch
    {
        return new DataStoreSwitch($this->h->module->sql(), $this->h->module->settings(), $this->h->box, $this->h->audit);
    }

    private function save(): void
    {
        $this->assertSame([], $this->sw()->saveConnection(['host' => 'db.internal.example', 'port' => 5433, 'database' => 'rmm_data', 'user' => self::USER, 'password' => self::PASSWORD, 'sslmode' => 'verify-full']));
    }

    private function setStore(string $kind): void
    {
        $this->h->q("UPDATE endpoint_agent_settings SET data_store = '$kind' WHERE id = 1");
        $this->h->module->settings()->refresh();
    }

    /** @param \Closure(): array{0:?PostgresConnectionInterface,1:?\RivetCore\Rmm\Store\Postgres\PostgresConfig} $target */
    private function info(\Closure $target, ?RmmCache $cache = null, bool $supplied = false): DataStoreInfo
    {
        return new DataStoreInfo($this->sw(), $this->h->module->settings(), function () use ($target): array {
            ++$this->targets;

            return $target();
        }, $supplied, $cache, fn (): int => $this->h->clock->now()->getTimestamp());
    }

    private function assertNoSecrets(array $info): void
    {
        $json = (string) json_encode($info);
        foreach ([self::PASSWORD, self::USER, 'password', 'sealed', 'dsn'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $json, "the report must not contain '$needle'");
        }
    }

    public function testAMariadbInstallNeverReachesPostgres(): void
    {
        $info = $this->info(static fn (): array => throw new \LogicException('the PostgreSQL connector must not be used'));
        $r = $info->report();
        $this->assertSame(0, $this->targets);
        $this->assertSame('mariadb', $r['store']);
        $this->assertTrue($r['active']);
        $this->assertNull($r['postgres']);
        $this->assertFalse($r['configured']);
        $this->assertFalse($r['probed']);
        $this->assertNull($r['error']);
        $this->assertSame('MariaDB (PostgreSQL not in use)', DataStoreInfo::summaryLine($r));
        $this->assertSame($r, array_replace($r, $info->report(false, true)), 'asking for tables changes nothing on MariaDB');
        $this->assertSame(0, $this->targets);
    }

    public function testASavedConnectionIsReportedWithoutBeingUsedAndWithoutSecrets(): void
    {
        $this->save();
        $r = $this->info(static fn (): array => throw new \LogicException('must not connect'))->report();
        $this->assertSame(0, $this->targets);
        $this->assertTrue($r['configured']);
        $this->assertSame(['host' => 'db.internal.example', 'port' => 5433, 'database' => 'rmm_data', 'tested_at' => null, 'test_ok' => false, 'storage_mode' => null, 'server_version' => null], $r['saved']);
        $this->assertSame('MariaDB (PostgreSQL configured but not in use)', DataStoreInfo::summaryLine($r));
        $this->assertNoSecrets($r);
    }

    public function testTheModuleWiringMakesNoPostgresConnectionOnMariadbEvenWhenAskedForTheReport(): void
    {
        $this->save();
        $statementsBefore = $this->h->counting->statements;
        $r = $this->h->module->dataStoreInfo()->report();
        $this->assertSame('mariadb', $r['store']);
        $this->assertNull($r['postgres']);
        $this->assertLessThanOrEqual(3, $this->h->counting->statements - $statementsBefore, 'the settings row and the saved connection row only');
        $this->assertSame($this->h->module->dataStoreInfo(), $this->h->module->dataStoreInfo());
    }

    public function testProbeFromAMariadbInstallAsksTheConnectorAndMarksItNotInUse(): void
    {
        $this->save();
        $fake = new FakePg('18.6 (Debian 18.6-1.pgdg13+1)');
        $r = $this->info(static fn (): array => [$fake, null])->report(true);
        $this->assertSame(1, $this->targets);
        $this->assertSame('mariadb', $r['store']);
        $this->assertTrue($r['probed']);
        $this->assertFalse($r['postgres']['in_use']);
        $this->assertTrue($r['postgres']['reachable']);
        $this->assertSame('18.6', $r['postgres']['server_version']);
        $this->assertSame(18, $r['postgres']['version_major']);
        $this->assertSame(6, $r['postgres']['version_minor']);
        $this->assertSame('18.6 (Debian 18.6-1.pgdg13+1)', $r['postgres']['server_version_full']);
        $this->assertStringStartsWith('MariaDB (PostgreSQL configured but not in use; 18.6 (native) - schema', DataStoreInfo::summaryLine($r));
        $this->assertTrue($fake->closed);
        $this->assertNoSecrets($r);
    }

    public function testEveryFailureIsAnAnswerNeverAnExceptionAndNeverLeaks(): void
    {
        $this->setStore('postgres');
        $leaky = [
            'FATAL: password authentication failed for user "' . self::USER . '" (password ' . self::PASSWORD . ')' => 'authentication failed',
            'connection to server at "10.0.0.5", port 5432 failed: Connection refused' => 'connection refused',
            'SQLSTATE[08006] timeout expired' => 'connection timed out',
            'PostgreSQL is unavailable (the last connection attempt failed; not trying again for 12 more seconds).' => 'connection attempts are paused after a recent failure (retry in 12 s)',
            'something unexpected with ' . self::PASSWORD . ' in it' => 'connection failed',
        ];
        foreach ($leaky as $message => $expected) {
            $r = $this->info(static fn (): array => [new FakePg(null, new PostgresConnectionException($message)), null])->report();
            $this->assertSame('postgres', $r['store']);
            $this->assertFalse($r['postgres']['reachable']);
            $this->assertSame($expected, $r['error']);
            $this->assertSame('PostgreSQL unreachable: ' . $expected, DataStoreInfo::summaryLine($r));
            $this->assertNoSecrets($r);
        }
        $r = $this->info(static fn (): array => [new FakePg(null, new \RuntimeException('boom ' . self::PASSWORD)), null])->report();
        $this->assertFalse($r['postgres']['reachable']);
        $this->assertSame('the data store could not be read', $r['error']);
        $this->assertNoSecrets($r);
        // the connector itself blows up, or there is none
        $r = $this->info(static fn (): array => throw new \RuntimeException(self::PASSWORD))->report();
        $this->assertFalse($r['postgres']['reachable']);
        $this->assertSame('no PostgreSQL connection is configured', $r['error']);
        $this->assertNoSecrets($r);
        $r = $this->info(static fn (): array => [null, null])->report();
        $this->assertSame('PostgreSQL unreachable: no PostgreSQL connection is configured', DataStoreInfo::summaryLine($r));
    }

    public function testPostgresStoreWithASavedRowButNoUsableConnectionSaysSo(): void
    {
        $this->save();
        $this->setStore('postgres');
        $r = $this->info(static fn (): array => [null, null])->report();
        $this->assertSame('the saved PostgreSQL connection cannot be used', $r['error']);
        $this->assertSame('db.internal.example', $r['postgres']['host']);
        $this->assertSame(5433, $r['postgres']['port']);
        $this->assertNoSecrets($r);
    }

    public function testASharedCacheKeepsALiveAnswerForThirtySecondsAndNoCacheMeansNoCaching(): void
    {
        $this->setStore('postgres');
        $fake = new FakePg('18.6');
        $cache = new RmmCache($this->h->module->sql(), new ArrayPsrCache($this->h->clock));
        $info = $this->info(static fn (): array => [$fake, null], $cache);
        $first = $info->report();
        $this->assertFalse($first['cached']);
        $this->assertSame(1, $fake->lives);
        $second = $info->report();
        $this->assertTrue($second['cached']);
        $this->assertSame(1, $fake->lives, 'the second call did not ask the server');
        $this->assertSame($first['checked_at'], $second['checked_at']);
        $this->h->clock->advance(DataStoreInfo::CACHE_TTL_S - 1);
        $this->assertTrue($info->report()['cached']);
        $this->h->clock->advance(2);
        $third = $info->report();
        $this->assertFalse($third['cached']);
        $this->assertSame(2, $fake->lives);
        // the tables flag is a different entry
        $info->report(false, true);
        $this->assertSame(3, $fake->lives);

        // the database-backed RmmCache is not used for this (it would add queries to a page load)
        $plain = new FakePg('18.6');
        $db = $this->info(static fn (): array => [$plain, null], new RmmCache($this->h->module->sql()));
        $db->report();
        $db->report();
        $this->assertSame(2, $plain->lives);
        $none = new FakePg('18.6');
        $nc = $this->info(static fn (): array => [$none, null]);
        $nc->report();
        $nc->report();
        $this->assertSame(2, $none->lives);
    }

    public function testACacheThatFailsNeverHidesTheAnswer(): void
    {
        $this->setStore('postgres');
        $psr = new ArrayPsrCache($this->h->clock);
        $psr->failing = true;
        $fake = new FakePg('18.6');
        $r = $this->info(static fn (): array => [$fake, null], new RmmCache($this->h->module->sql(), $psr))->report();
        $this->assertTrue($r['postgres']['reachable']);
        $this->assertNull($r['error']);
    }

    public function testAnOpaqueEditionConnectionSupplyingTheDatabaseNameAndAnOlderServer(): void
    {
        $this->setStore('postgres');
        $r = $this->info(static fn (): array => [new FakePg('16.4'), null], null, true)->report();
        $this->assertTrue($r['configured'], 'supplied by the edition');
        $this->assertSame('16.4', $r['postgres']['server_version']);
        $this->assertSame('fake_db', $r['postgres']['database']);
        $this->assertNull($r['postgres']['host']);
    }

    public function testSummaryLineVariants(): void
    {
        $schema = ['exists' => true, 'applied' => ['0001_hot_tables'], 'pending' => [], 'changed' => [], 'current' => '0001_hot_tables', 'up_to_date' => true];
        $ok = ['store' => 'postgres', 'configured' => true, 'error' => null, 'postgres' => ['reachable' => true, 'server_version' => '18.6', 'storage_mode' => 'native', 'timescale_version' => null, 'schema' => $schema]];
        $this->assertSame('PostgreSQL 18.6 (native) - schema 0001_hot_tables, up to date', DataStoreInfo::summaryLine($ok));
        $ts = $ok;
        $ts['postgres']['storage_mode'] = 'timescale';
        $ts['postgres']['timescale_version'] = '2.31.0';
        $this->assertSame('PostgreSQL 18.6 (timescale 2.31.0) - schema 0001_hot_tables, up to date', DataStoreInfo::summaryLine($ts));
        $p = $ok;
        $p['postgres']['schema']['pending'] = ['0002_x', '0003_y'];
        $this->assertSame('PostgreSQL 18.6 (native) - schema 0001_hot_tables, 2 pending', DataStoreInfo::summaryLine($p));
        $c = $ok;
        $c['postgres']['schema']['changed'] = ['0001_hot_tables'];
        $this->assertSame('PostgreSQL 18.6 (native) - schema 0001_hot_tables, 1 changed', DataStoreInfo::summaryLine($c));
        $n = $ok;
        $n['postgres']['schema'] = ['exists' => false, 'applied' => [], 'pending' => ['0001_hot_tables'], 'changed' => [], 'current' => null, 'up_to_date' => false];
        $this->assertSame('PostgreSQL 18.6 (native) - schema not created', DataStoreInfo::summaryLine($n));
        $this->assertSame('PostgreSQL unreachable: connection refused', DataStoreInfo::summaryLine(['store' => 'postgres', 'error' => 'connection refused', 'postgres' => ['reachable' => false]]));
        $this->assertSame('PostgreSQL unreachable: no answer', DataStoreInfo::summaryLine(['store' => 'postgres']));
        $this->assertSame('MariaDB (PostgreSQL not in use)', DataStoreInfo::summaryLine(['store' => 'mariadb', 'configured' => false]));
        $this->assertSame('MariaDB (PostgreSQL configured but not in use)', DataStoreInfo::summaryLine(['store' => 'mariadb', 'configured' => true]));
        $this->assertSame('MariaDB (PostgreSQL not in use)', DataStoreInfo::summaryLine([]));
    }
}

/** A scripted PostgreSQL connection: answers the few reads the report makes, or throws. */
final class FakePg implements PostgresConnectionInterface
{
    public int $lives = 0;
    public bool $closed = false;

    public function __construct(private readonly ?string $version, private readonly ?\Throwable $fail = null)
    {
    }

    public function query(string $sql, array $params = []): array
    {
        if ($this->fail !== null) {
            throw $this->fail;
        }
        if (str_contains($sql, 'rivet_core_pg_migrations')) {
            return [['id' => '0001_hot_tables', 'checksum' => 'x']];
        }

        return [];
    }

    public function queryOne(string $sql, array $params = []): ?array
    {
        return null;
    }

    public function value(string $sql, array $params = []): mixed
    {
        if ($this->fail !== null) {
            throw $this->fail;
        }

        return match (true) {
            str_contains($sql, 'current_database') && !str_contains($sql, 'pg_database_size') => 'fake_db',
            str_contains($sql, 'pg_database_size') => 123456,
            str_contains($sql, 'to_regclass') => true,
            default => null,
        };
    }

    public function execute(string $sql, array $params = []): int
    {
        return 0;
    }

    public function transaction(callable $callback): mixed
    {
        return $callback();
    }

    public function inTransaction(): bool
    {
        return false;
    }

    public function serverVersion(): string
    {
        if ($this->fail !== null) {
            throw $this->fail;
        }

        return (string) $this->version;
    }

    public function close(): void
    {
        $this->closed = true;
        ++$this->lives;   // one live check = one session closed
    }
}
