<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm\Migration;

use RivetCore\Rmm\Store\Migration\HotTables;
use RivetCore\Rmm\Store\Migration\MigrationException;
use RivetCore\Rmm\Store\Migration\MigrationRuns;
use RivetCore\Tests\Support\FlakyPostgres;
use RivetCore\Tests\Support\PostgresTestDatabase;
use RivetCore\Tests\Support\StoreMigrationFixture as F;
use RivetCore\Tests\Support\StoreMigrationTestCase;

/** The data store migration in both directions on real MariaDB and PostgreSQL (phase 3 of #89). */
final class RmmStoreMigratorTest extends StoreMigrationTestCase
{
    private function now(): int
    {
        return $this->h->clock->now()->getTimestamp();
    }

    public function testMariadbToPostgresCopiesEverythingVerifiesAndCutsOver(): void
    {
        $before = F::fill($this->maria, $this->now());
        $digests = [];
        foreach (HotTables::all() as $t) {
            $digests[$t->name] = F::digest($this->maria, $t);
        }
        $r = $this->migrator()->migrate(self::opts(['to' => 'postgres']), $this->collect());
        $this->assertTrue($r['ok']);
        $this->assertSame('postgres', $this->h->module->settings()->dataStore());
        $this->assertNull($this->h->module->settings()->get(true)['data_store_phase']);
        $this->assertSame($before['endpoint_agent_check_history'], $this->pg->count(HotTables::get('endpoint_agent_check_history')));
        foreach (HotTables::all() as $t) {
            $this->assertSame($digests[$t->name], F::digest($this->pg, $t), $t->name . ' is identical in the target (ids aside)');
            $this->assertSame($digests[$t->name], F::digest($this->maria, $t), $t->name . ' is untouched in the source');
        }
        $run = (new \RivetCore\Rmm\Store\Migration\MigrationRuns($this->sql))->current();
        $this->assertSame('cutover', $run['state']);
        $this->assertNotNull($run['rollback_until']);
        $this->assertSame(0, $run['dual_write']);
        $names = array_column($this->events, 'event');
        $this->assertContains('dual_write_on', $names);
        $this->assertContains('cutover', $names);
        $actions = array_column($this->h->audit->records(), 'action');
        $this->assertContains('rmm.store_migration.start', $actions);
        $this->assertContains('rmm.store_migration.cutover', $actions);
        $this->assertStringNotContainsString(PostgresTestDatabase::config()->password(), json_encode($this->events));
    }

    public function testDryRunWritesNothingAndTheTargetMustBeEmpty(): void
    {
        F::fill($this->maria, $this->now());
        $r = $this->migrator()->migrate(self::opts(['to' => 'postgres', 'dry_run' => true]), $this->collect());
        $this->assertTrue($r['ok']);
        $this->assertGreaterThan(5000, $r['total_rows']);
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM rmm_store_migration'));
        $this->assertSame(0, $this->pg->count(HotTables::get('endpoint_agent_check_history')));
        F::fill($this->pg, $this->now());
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('already holds history');
        $this->migrator()->migrate(self::opts(['to' => 'postgres']));
    }

    private function runs(): MigrationRuns
    {
        return new MigrationRuns($this->sql);
    }

    /** @return array<string,string> */
    private function digests(\RivetCore\Rmm\Store\Migration\StoreEndpoint $ep): array
    {
        $d = [];
        foreach (HotTables::all() as $t) {
            $d[$t->name] = F::digest($ep, $t);
        }

        return $d;
    }

    public function testPostgresToMariadbWorksTheSameWay(): void
    {
        $this->setStore('postgres');
        F::fill($this->pg, $this->now());
        $want = $this->digests($this->pg);
        $r = $this->migrator()->migrate(self::opts(['to' => 'mariadb']), $this->collect());
        $this->assertTrue($r['ok']);
        $this->assertSame('mariadb', $this->h->module->settings()->dataStore());
        $this->assertSame($want, $this->digests($this->maria));
        $this->assertSame($want, $this->digests($this->pg), 'the old store is untouched');
    }

    public function testFiftyThousandRowsBothWays(): void
    {
        ini_set('memory_limit', '1G');
        $n = array_sum(F::fill($this->maria, $this->now(), 10));
        $this->assertGreaterThan(50000, $n);
        $want = $this->digests($this->maria);
        $this->migrator()->migrate(self::opts(['to' => 'postgres', 'sample' => 20, 'batch' => 2000]));
        $this->assertSame($want, $this->digests($this->pg));
        // and back: empty the old store (explicit, forced because the rollback period is open), then migrate the other way
        $this->migrator()->purgeOld(['yes' => true, 'force' => true]);
        $this->assertSame(0, array_sum(F::counts($this->maria)));
        $r = $this->migrator()->migrate(self::opts(['to' => 'mariadb', 'sample' => 20, 'batch' => 2000]));
        $this->assertTrue($r['ok']);
        $this->assertSame($want, $this->digests($this->maria));
        $this->assertSame('mariadb', $this->h->module->settings()->dataStore());
    }

    public function testAKilledRunResumesFromItsCheckpointAndWritesNothingTwice(): void
    {
        F::fill($this->maria, $this->now(), 2);
        $want = $this->digests($this->maria);
        $batches = 0;
        $kill = $this->collect(function (array $e) use (&$batches): void {
            if ($e['event'] === 'batch' && $e['table'] === 'endpoint_agent_log_events' && ++$batches === 2) {
                throw new \RuntimeException('killed');
            }
        });
        try {
            $this->migrator()->migrate(self::opts(['to' => 'postgres', 'batch' => 500]), $kill);
            $this->fail('the run was killed');
        } catch (\RuntimeException $e) {
            $this->assertSame('killed', $e->getMessage());
        }
        $run = $this->runs()->current();
        $this->assertSame('failed', $run['state']);
        $this->assertGreaterThan(0, $run['checkpoints']['t']['endpoint_agent_check_history']['rows'], 'the tables before the kill are recorded as done');
        $this->assertTrue($run['checkpoints']['t']['endpoint_agent_check_history']['done']);
        $this->assertSame('copy:postgres', $this->h->module->settings()->get(true)['data_store_phase'], 'retention stays paused');
        $this->assertSame('mariadb', $this->h->module->settings()->dataStore(), 'nothing is switched');
        try {
            $this->migrator()->migrate(self::opts(['to' => 'postgres']));
            $this->fail('a second start is refused');
        } catch (MigrationException $e) {
            $this->assertStringContainsString('already in progress', $e->getMessage());
        }
        $this->events = [];
        $r = $this->migrator()->migrate(self::opts(['to' => 'postgres', 'resume' => true, 'batch' => 500]), $this->collect());
        $this->assertTrue($r['ok']);
        $this->assertSame($want, $this->digests($this->pg), 'no row is missing and none is doubled');
        $logBatches = count(array_filter($this->events, static fn (array $e): bool => $e['event'] === 'batch' && $e['table'] === 'endpoint_agent_check_history'));
        $this->assertSame(0, $logBatches, 'a table that was finished is not copied again');
        $this->assertSame('copy', explode(':', 'copy:x')[0]);
        $this->assertSame(MigrationRuns::CUTOVER, $this->runs()->current()['state']);
    }

    public function testAKillAtTheLastStepResumesToTheCutover(): void
    {
        F::fill($this->maria, $this->now());
        try {
            $this->migrator()->migrate(self::opts(['to' => 'postgres']), $this->collect(function (array $e): void {
                if ($e['event'] === 'phase' && $e['phase'] === 'cutover') {
                    throw new \RuntimeException('killed before the cut-over');
                }
            }));
        } catch (\RuntimeException) {
        }
        $this->assertSame('mariadb', $this->h->module->settings()->dataStore());
        $this->assertSame(1, $this->runs()->current()['dual_write'], 'dual-write stays on while the run is stopped, so nothing is lost');
        $r = $this->migrator()->migrate(self::opts(['to' => 'postgres', 'resume' => true]));
        $this->assertTrue($r['ok']);
        $this->assertSame('postgres', $this->h->module->settings()->dataStore());
    }

    public function testWritesDuringTheMigrationGoToBothStoresInOrderAndALaggardIsRepaired(): void
    {
        F::fill($this->maria, $this->now());
        $now = $this->now();
        $atTail = null;
        $cb = $this->collect(function (array $e) use ($now, &$atTail): void {
            if ($e['event'] === 'dual_write_on') {
                $this->reresolve();
                $stores = $this->h->module->dataStores();
                $this->assertSame('mariadb', $stores->kind());
                $stores->checkHistory()->append(5, [['key' => 'dual', 'status' => 'ok', 'detail' => 'written through both', 'at' => gmdate('Y-m-d H:i:s', $now)]]);
                $caps = ['rate_per_min' => 100000, 'device_max_rows' => 100000, 'fleet_max_rows' => 1000000];
                $stores->logs()->admit(6, [['ts' => $now, 'level' => 2, 'source' => 'dual', 'code' => 1, 'message' => 'dual log', 'hash' => md5('dual-log')]], $caps, $now, 0, 0, 2);
                $this->assertSame(0, $stores->checkHistory()->deleteOlderThan('2100-01-01 00:00:00', 1000), 'retention is paused during the migration');
                // a worker that had not yet seen the flag wrote to the old store only:
                $this->maria->insert(\RivetCore\Rmm\Store\Migration\HotTables::get('endpoint_agent_check_history'),
                    [['device_id' => 5, 'check_key' => 'lag', 'status' => 'ok', 'detail' => 'laggard', 'reported_at' => gmdate('Y-m-d H:i:s', $now)]], 'ignore', false);
            }
            if ($e['event'] === 'phase' && $e['phase'] === 'tail') {
                $atTail = [$this->pg->count(\RivetCore\Rmm\Store\Migration\HotTables::get('endpoint_agent_check_history')), $this->pgConn->value("SELECT COUNT(*) FROM endpoint_agent_check_history WHERE check_key = 'dual'"),
                    $this->pgConn->value("SELECT COUNT(*) FROM endpoint_agent_log_events WHERE source = 'dual'")];
            }
        });
        $this->migrator()->migrate(self::opts(['to' => 'postgres']), $cb);
        $this->assertSame([1, 1], [(int) $atTail[1], (int) $atTail[2]], 'the dual write reached the second store at once, before any catch-up pass');
        $one = static fn (string $sql) => (int) $sql;
        $this->assertSame('1', (string) $this->pgConn->value("SELECT COUNT(*) FROM endpoint_agent_check_history WHERE check_key = 'dual'"), 'written once, not twice');
        $this->assertSame('1', (string) $this->pgConn->value("SELECT COUNT(*) FROM endpoint_agent_check_history WHERE check_key = 'lag'"), 'the laggard row was repaired by the tail pass, once');
        $this->assertSame('1', (string) $this->pgConn->value("SELECT COUNT(*) FROM endpoint_agent_log_events WHERE source = 'dual' AND client_id = 2"), 'the denormalised client id travelled');
        $this->assertSame(F::counts($this->maria), F::counts($this->pg));
        $this->assertSame((int) $this->h->one("SELECT row_count FROM endpoint_agent_log_state WHERE device_id = 6"), (int) $this->pgConn->value('SELECT row_count FROM endpoint_agent_log_state WHERE device_id = 6'), 'the ledger agrees');
    }

    public function testACorruptedRowIsDetectedAndNothingIsSwitched(): void
    {
        F::fill($this->maria, $this->now());
        $cb = $this->collect(function (array $e): void {
            if ($e['event'] === 'phase' && $e['phase'] === 'verify') {
                $this->pgConn->execute("UPDATE endpoint_agent_check_history SET detail = 'tampered' WHERE hist_id = 10");
            }
        });
        try {
            $this->migrator()->migrate(self::opts(['to' => 'postgres']), $cb);
            $this->fail('a corrupted copy must not be cut over');
        } catch (MigrationException $e) {
            $this->assertStringContainsString('Verification found differences', $e->getMessage());
            $this->assertStringContainsString('checksum', $e->getMessage());
        }
        $this->assertSame('mariadb', $this->h->module->settings()->dataStore());
        $run = $this->runs()->current();
        $this->assertSame('failed', $run['state']);
        $this->assertSame(1, $run['dual_write']);
    }

    public function testAMissingRowIsDetectedByTheCounts(): void
    {
        F::fill($this->maria, $this->now());
        $cb = $this->collect(function (array $e): void {
            if ($e['event'] === 'phase' && $e['phase'] === 'tail') {
                $this->pgConn->execute('DELETE FROM rmm_package_history WHERE id = 3');
            }
        });
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('row_count');
        $this->migrator()->migrate(self::opts(['to' => 'postgres', 'sample' => 0]), $cb);
    }

    public function testPostgresGoingDownMidwayLeavesTheSourceAloneAndResumes(): void
    {
        F::fill($this->maria, $this->now());
        $want = $this->digests($this->maria);
        $flaky = new FlakyPostgres($this->pgConn);
        $n = 0;
        $cb = $this->collect(function (array $e) use ($flaky, &$n): void {
            if ($e['event'] === 'batch' && $e['table'] === 'endpoint_agent_log_events' && ++$n === 2) {
                $flaky->down = true;
            }
        });
        try {
            $this->migrator($flaky)->migrate(self::opts(['to' => 'postgres', 'batch' => 500]), $cb);
            $this->fail('PostgreSQL was down');
        } catch (\RivetCore\Rmm\Store\Postgres\PostgresConnectionException $e) {
            $this->assertStringContainsString('closed the connection', $e->getMessage());
        }
        $this->assertGreaterThan(0, $flaky->refused);
        $this->assertSame($want, $this->digests($this->maria), 'the source is untouched');
        $this->assertSame('mariadb', $this->h->module->settings()->dataStore());
        $run = $this->runs()->current();
        $this->assertSame('failed', $run['state']);
        $this->assertStringContainsString('closed the connection', $run['last_error']);
        $flaky->down = false;
        $this->assertTrue($this->migrator($flaky)->migrate(self::opts(['to' => 'postgres', 'resume' => true, 'batch' => 500]))['ok']);
        $this->assertSame($want, $this->digests($this->pg));
    }

    public function testRollbackBringsBackTheRowsWrittenAfterTheCutover(): void
    {
        F::fill($this->maria, $this->now());
        $this->migrator()->migrate(self::opts(['to' => 'postgres']));
        $this->reresolve();
        $now = $this->now();
        $stores = $this->h->module->dataStores();
        $this->assertSame('postgres', $stores->kind());
        $stores->checkHistory()->append(9, [['key' => 'after', 'status' => 'ok', 'detail' => 'written after the cut-over', 'at' => gmdate('Y-m-d H:i:s', $now + 1)]]);
        $caps = ['rate_per_min' => 100000, 'device_max_rows' => 100000, 'fleet_max_rows' => 1000000];
        $stores->logs()->admit(9, [['ts' => $now, 'level' => 2, 'source' => 'after', 'code' => 1, 'message' => 'after cutover', 'hash' => md5('after')]], $caps, $now + 1, 0, 0, 1);
        $this->assertSame('0', (string) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_check_history WHERE check_key = 'after'"), 'MariaDB does not have it yet');

        $r = $this->migrator()->rollback(self::opts(), $this->collect());
        $this->assertTrue($r['ok']);
        $this->assertSame('mariadb', $this->h->module->settings()->dataStore());
        $this->assertSame('1', (string) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_check_history WHERE check_key = 'after'"));
        $this->assertSame('1', (string) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_log_events WHERE source = 'after'"));
        $this->assertSame((int) $this->h->one('SELECT row_count FROM endpoint_agent_log_state WHERE device_id = 9'), (int) $this->pgConn->value('SELECT row_count FROM endpoint_agent_log_state WHERE device_id = 9'));
        $states = array_column($this->runs()->history(5), 'state');
        $this->assertSame(['cutover', 'rolled_back'], $states, 'the rollback run, then the migration it undid');
        $this->assertContains('rmm.store_migration.rollback', array_column($this->h->audit->records(), 'action'));
        $this->assertGreaterThan(0, array_sum(F::counts($this->pg)), 'the new store is kept');
    }

    public function testRollbackIsRefusedAfterThePeriodUnlessForcedAndPurgeNeedsConfirmation(): void
    {
        F::fill($this->maria, $this->now());
        $this->migrator()->migrate(self::opts(['to' => 'postgres']));
        $this->h->q("UPDATE rmm_store_migration SET rollback_until = '2000-01-01 00:00:00'");
        try {
            $this->migrator()->rollback(self::opts());
            $this->fail('the period is over');
        } catch (MigrationException $e) {
            $this->assertStringContainsString('rollback period ended', $e->getMessage());
        }
        try {
            $this->migrator()->purgeOld([]);
            $this->fail('purge needs yes');
        } catch (MigrationException $e) {
            $this->assertStringContainsString('Pass yes', $e->getMessage());
        }
        $this->h->q("UPDATE rmm_store_migration SET rollback_until = DATE_ADD(NOW(), INTERVAL 3 DAY)");
        try {
            $this->migrator()->purgeOld(['yes' => true]);
            $this->fail('inside the period purge needs force');
        } catch (MigrationException $e) {
            $this->assertStringContainsString('rollback period lasts', $e->getMessage());
        }
        $this->assertGreaterThan(0, array_sum(F::counts($this->maria)), 'nothing is deleted automatically');
        $r = $this->migrator()->purgeOld(['yes' => true, 'force' => true]);
        $this->assertSame('mariadb', $r['purged']);
        $this->assertSame(0, array_sum(F::counts($this->maria)));
        $this->assertGreaterThan(0, array_sum(F::counts($this->pg)), 'the active store is not touched');
        $this->assertContains('rmm.store_migration.purge', array_column($this->h->audit->records(), 'action'));
    }

    public function testRerunningAfterTheCutoverAndAbortingAreHandled(): void
    {
        F::fill($this->maria, $this->now());
        $this->migrator()->migrate(self::opts(['to' => 'postgres']));
        try {
            $this->migrator()->migrate(self::opts(['to' => 'postgres']));
            $this->fail('already there');
        } catch (MigrationException $e) {
            $this->assertStringContainsString('Choose two different stores', $e->getMessage());
        }
        try {
            $this->migrator()->migrate(self::opts(['to' => 'mariadb', 'resume' => true]));
            $this->fail('nothing to resume');
        } catch (MigrationException $e) {
            $this->assertStringContainsString('no migration to resume', strtolower($e->getMessage()));
        }
        $this->setStore('mariadb');
        $this->migrator()->purgeOld(['yes' => true, 'force' => true]);   // the old (postgres, from this setting's view) store is emptied so a new run can start
        $this->assertSame(0, array_sum(F::counts($this->pg)));
        F::fill($this->maria, $this->now());
        try {
            $this->migrator()->migrate(self::opts(['to' => 'postgres']), $this->collect(function (array $e): void {
                if ($e['event'] === 'phase' && $e['phase'] === 'catchup') {
                    throw new \RuntimeException('stop');
                }
            }));
        } catch (\RuntimeException) {
        }
        $this->migrator()->abort('test');
        $this->assertNull($this->h->module->settings()->get(true)['data_store_phase']);
        $this->assertSame('aborted', $this->runs()->current()['state']);
    }
}
