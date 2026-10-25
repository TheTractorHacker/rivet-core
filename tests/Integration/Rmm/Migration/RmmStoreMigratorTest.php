<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm\Migration;

use RivetCore\Rmm\Store\Migration\HotTables;
use RivetCore\Rmm\Store\Migration\MigrationException;
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
        $this->assertStringNotContainsString(PostgresTestDatabase_pass(), json_encode($this->events));
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
}

function PostgresTestDatabase_pass(): string
{
    return \RivetCore\Tests\Support\PostgresTestDatabase::config()->password();
}
