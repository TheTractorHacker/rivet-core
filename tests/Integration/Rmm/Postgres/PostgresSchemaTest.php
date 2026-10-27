<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm\Postgres;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Store\Postgres\PostgresConnectionInterface;
use RivetCore\Rmm\Store\Postgres\PostgresMigrator;
use RivetCore\Rmm\Store\Postgres\PostgresQueryException;
use RivetCore\Rmm\Store\Postgres\PostgresSchema;
use RivetCore\Tests\Support\PostgresTestDatabase;

/** The schema and the migrator of the PostgreSQL data store, against a real PostgreSQL. */
final class PostgresSchemaTest extends TestCase
{
    private PostgresConnectionInterface $db;

    protected function setUp(): void
    {
        if (($why = PostgresTestDatabase::unavailable()) !== null) {
            $this->markTestSkipped($why);
        }
        $this->db = PostgresTestDatabase::connect();
    }

    public function testTheMigratorCreatesEveryHotTableAndIsIdempotent(): void
    {
        $first = PostgresTestDatabase::reset($this->db);
        $this->assertSame(['0001_hot_tables', '0002_reporting_views'], $first['applied']);
        foreach (PostgresSchema::TABLES as $t) {
            $this->assertTrue((bool) $this->db->value('SELECT to_regclass(?) IS NOT NULL', [$t]), "$t exists");
        }
        $m = new PostgresMigrator($this->db);
        $before = $this->db->query('SELECT id, checksum, applied_at FROM ' . PostgresMigrator::LEDGER);
        $this->assertSame([], $m->migrate()['applied'], 'a second run applies nothing');
        $this->assertSame([], $m->migrate()['applied']);
        $this->assertEquals($before, $this->db->query('SELECT id, checksum, applied_at FROM ' . PostgresMigrator::LEDGER), 'and changes nothing in the ledger');
        // running the statements again by hand (a crashed migrator, a restored dump) is harmless too: IF NOT EXISTS everywhere
        foreach (PostgresSchema::statements($first['mode']) as $sql) {
            if (!str_starts_with($sql, 'SELECT create_hypertable')) {
                $this->db->execute($sql);
            }
        }
        $s = $m->status();
        $this->assertSame([], $s['pending']);
        $this->assertSame([], $s['changed']);
        $this->assertSame($first['mode'], $s['mode']);
        $this->assertSame($first['mode'], $m->storedMode());
    }

    public function testTheStorageModeIsDecidedOnceByDetectionAndRecorded(): void
    {
        $m = new PostgresMigrator($this->db);
        $r = PostgresTestDatabase::reset($this->db, PostgresMigrator::MODE_AUTO);
        $this->assertSame($m->timescaleInstalled() ? PostgresSchema::MODE_TIMESCALE : PostgresSchema::MODE_NATIVE, $r['mode'], 'auto follows pg_extension');
        $this->assertSame($r['timescale_available'], $m->timescaleInstalled());
        // a later run asking for another mode does not change the recorded one
        $other = $r['mode'] === PostgresSchema::MODE_NATIVE ? PostgresSchema::MODE_TIMESCALE : PostgresSchema::MODE_NATIVE;
        try {
            $again = $m->migrate($other);
            $this->assertSame($r['mode'], $again['mode']);
        } catch (PostgresQueryException) {
            $this->fail('an existing install must ignore the requested mode');
        }
    }

    public function testTimescaleModeIsRefusedWhenTheExtensionIsNotThere(): void
    {
        if ((new PostgresMigrator($this->db))->timescaleInstalled()) {
            $this->markTestSkipped('TimescaleDB is installed here; the refusal is tested on the plain PostgreSQL job.');
        }
        $this->expectException(PostgresQueryException::class);
        $this->expectExceptionMessage('extension is not installed');
        PostgresTestDatabase::reset($this->db, PostgresSchema::MODE_TIMESCALE);
    }

    public function testNativeModeCreatesPartitionedParentsWithADefaultPartitionAndTimescaleCreatesHypertables(): void
    {
        $r = PostgresTestDatabase::reset($this->db);
        foreach (array_keys(PostgresSchema::TIME_SERIES) as $t) {
            if ($r['mode'] === PostgresSchema::MODE_NATIVE) {
                $this->assertSame('p', $this->db->value('SELECT relkind FROM pg_class WHERE oid = to_regclass(?)', [$t]), "$t is a partitioned table");
                $this->assertTrue((bool) $this->db->value('SELECT to_regclass(?) IS NOT NULL', [$t . '_default']));
            } else {
                $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM timescaledb_information.hypertables WHERE hypertable_name = ?', [$t]));
            }
        }
        // every primary key of a time series contains its time column (the rule of partitioning and of hypertables)
        $this->assertSame(['asset_id', 'metric_key', 'instance', 'hour_start'], $this->primaryKey('rmm_metric_hourly'));
        $this->assertSame(['hist_id', 'reported_at'], $this->primaryKey('endpoint_agent_check_history'));
    }

    public function testTheIndexesMatchTheAccessPatterns(): void
    {
        PostgresTestDatabase::reset($this->db);
        $idx = fn (string $t): array => array_map(static fn (array $r): string => (string) $r['indexdef'], $this->db->query('SELECT indexdef FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ?', [$t]));
        $has = function (string $table, string $needle) use ($idx): bool {
            foreach ($idx($table) as $d) {
                if (str_contains($d, $needle)) {
                    return true;
                }
            }

            return false;
        };
        $this->assertTrue($has('endpoint_agent_log_events', 'UNIQUE INDEX') && $has('endpoint_agent_log_events', '(device_id, dedupe)'), 'the dedupe key');
        $this->assertTrue($has('endpoint_agent_log_events', '(device_id, occurred_at)'));
        $this->assertTrue($has('endpoint_agent_check_history', '(device_id, check_key, reported_at, hist_id)'), 'the page lookup');
        $this->assertTrue($has('endpoint_agent_check_history', 'USING brin (reported_at)'), 'BRIN on the append-ordered time column');
        $this->assertTrue($has('rmm_metric_hourly', 'USING brin (hour_start)'));
        $this->assertTrue($has('rmm_patch_history', 'USING brin (at)'));
    }

    public function testTypesAreTheDocumentedOnes(): void
    {
        PostgresTestDatabase::reset($this->db);
        $type = fn (string $t, string $c): string => (string) $this->db->value('SELECT data_type FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?', [$t, $c]);
        $this->assertSame('bigint', $type('endpoint_agent_log_events', 'event_id'));
        $this->assertSame('bigint', $type('endpoint_agent_check_history', 'hist_id'));
        $this->assertSame('bigint', $type('rmm_hypervisor_vm_history', 'vm_id'));
        $this->assertSame('timestamp with time zone', $type('endpoint_agent_log_events', 'occurred_at'));
        $this->assertSame('timestamp with time zone', $type('rmm_patch_history', 'at'));
        $this->assertSame('bytea', $type('endpoint_agent_log_events', 'dedupe'));
        $this->assertSame('double precision', $type('rmm_metric_hourly', 'sum_value'));
        $this->assertSame('jsonb', $type('rmm_metric_rollup_state', 'detail'));
        $this->assertSame('text', $type('endpoint_agent_change_state', 'monitor_json'), 'the agent status block is stored byte for byte');
    }

    public function testALaterMigrationRunsOnceAndAChangedMigrationIsReported(): void
    {
        PostgresTestDatabase::reset($this->db);
        $extra = ['id' => '0002_probe', 'statements' => static fn (string $mode): array => ['CREATE TABLE IF NOT EXISTS rc_probe_migration (id integer)']];
        $base = ['id' => '0001_hot_tables', 'statements' => static fn (string $mode): array => PostgresSchema::statements($mode)];
        $m = new PostgresMigrator($this->db, [$base, $extra]);
        $this->assertSame(['0002_probe'], $m->migrate()['applied']);
        $this->assertSame([], $m->migrate()['applied']);
        $this->assertSame('0002_probe', $m->latest());
        $changed = new PostgresMigrator($this->db, [$base, ['id' => '0002_probe', 'statements' => static fn (string $mode): array => ['CREATE TABLE IF NOT EXISTS rc_probe_migration (id integer, x integer)']]]);
        $this->assertSame(['0002_probe'], $changed->status()['changed'], 'an applied id whose statements changed is flagged, not re-run');
        $this->db->execute('DROP TABLE rc_probe_migration');
    }

    public function testAFailingMigrationLeavesNothingBehind(): void
    {
        PostgresTestDatabase::reset($this->db);
        $bad = ['id' => '0002_bad', 'statements' => static fn (string $mode): array => ['CREATE TABLE rc_half_done (id integer)', 'SELECT * FROM rc_no_such_table']];
        $m = new PostgresMigrator($this->db, [['id' => '0001_hot_tables', 'statements' => static fn (string $mode): array => PostgresSchema::statements($mode)], $bad]);
        try {
            $m->migrate();
            $this->fail('the bad migration must fail');
        } catch (PostgresQueryException) {
        }
        $this->assertFalse((bool) $this->db->value('SELECT to_regclass(?) IS NOT NULL', ['rc_half_done']), 'DDL is transactional: the first statement was rolled back');
        $this->assertNull($this->db->value('SELECT 1 FROM ' . PostgresMigrator::LEDGER . ' WHERE id = ?', ['0002_bad']));
    }

    public function testTwoUpdatersAtOnceRunTheMigrationsOnce(): void
    {
        PostgresTestDatabase::reset($this->db);
        foreach (array_reverse(PostgresSchema::TABLES) as $t) {
            $this->db->execute("DROP TABLE IF EXISTS $t CASCADE");
        }
        $this->db->execute('DROP SEQUENCE IF EXISTS endpoint_agent_check_history_hist_id_seq');
        $this->db->execute('DROP TABLE ' . PostgresMigrator::LEDGER);
        $this->db->execute('DROP TABLE ' . PostgresMigrator::META);
        $code = 'require "' . dirname(__DIR__, 4) . '/vendor/autoload.php"; $c = \RivetCore\Tests\Support\PostgresTestDatabase::connect(); echo json_encode((new \RivetCore\Rmm\Store\Postgres\PostgresMigrator($c))->migrate()["applied"]);';
        $procs = [];
        for ($i = 0; $i < 3; ++$i) {
            $procs[] = $p = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $outs[] = $pipes;
        }
        $applied = [];
        foreach ($procs as $i => $p) {
            $applied[] = json_decode((string) stream_get_contents($outs[$i][1]), true);
            proc_close($p);
        }
        $this->assertCount(1, array_filter($applied, static fn ($a): bool => $a === ['0001_hot_tables', '0002_reporting_views']), 'exactly one updater applied the migration: ' . json_encode($applied));
        $this->assertCount(2, array_filter($applied, static fn ($a): bool => $a === []));
        $this->assertSame(2, (int) $this->db->value('SELECT COUNT(*) FROM ' . PostgresMigrator::LEDGER));
    }

    /** @return list<string> */
    private function primaryKey(string $table): array
    {
        return array_map(static fn (array $r): string => (string) $r['attname'], $this->db->query("SELECT a.attname FROM pg_index i JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)
            WHERE i.indrelid = to_regclass(?) AND i.indisprimary ORDER BY array_position(i.indkey::int2[], a.attnum)", [$table]));
    }
}
