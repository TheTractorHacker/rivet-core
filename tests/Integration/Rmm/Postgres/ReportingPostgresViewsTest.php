<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm\Postgres;

use PHPUnit\Framework\TestCase;
use RivetCore\Reporting\ReportingCatalog;
use RivetCore\Reporting\ReportingSchema;
use RivetCore\Rmm\Store\Postgres\PostgresConnectionInterface;
use RivetCore\Rmm\Store\Postgres\PostgresMigrator;
use RivetCore\Tests\Support\PostgresTestDatabase;
use RivetCore\Tests\Support\ReportingSeed;

/**
 * The `rpt_` views in the PostgreSQL data store (#91 phase 2): created by the PostgreSQL migrator, the frozen columns, the denormalised client where the hot table
 * has one, time-bounded check results, and no secret or free-form column. The hot tables there have no device table to join, so the metric, check and software
 * views carry device_id / asset_id only; scoping by client is done in the model through rpt_devices (documented in docs/reporting/DATA_DICTIONARY.md).
 */
final class ReportingPostgresViewsTest extends TestCase
{
    private PostgresConnectionInterface $db;

    protected function setUp(): void
    {
        if (($why = PostgresTestDatabase::unavailable()) !== null) {
            $this->markTestSkipped($why);
        }
        $this->db = PostgresTestDatabase::connect();
        PostgresTestDatabase::prepare($this->db);
        ReportingSeed::postgres($this->db);
    }

    public function testTheMigratorCreatesTheViewsAndTheirColumnsAreTheFrozenOnes(): void
    {
        $m = new PostgresMigrator($this->db);
        $this->assertSame([], $m->status()['pending']);
        $this->assertContains('0002_reporting_views', $m->status()['applied']);
        $families = ['int' => ['smallint', 'integer', 'bigint'], 'double' => ['double precision', 'numeric', 'real'], 'text' => ['character varying', 'text', 'character'], 'datetime' => ['timestamp with time zone', 'timestamp without time zone'], 'flag' => ['smallint', 'integer', 'bigint']];
        $seen = [];
        foreach (ReportingCatalog::views() as $v) {
            if (!$v->inPostgres()) {
                $this->assertNull($this->db->value('SELECT to_regclass(?)', [$v->name]), $v->name . ' is a control-database view and must not exist in the PostgreSQL store');
                continue;
            }
            $rows = $this->db->query('SELECT column_name AS n, data_type AS t FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? ORDER BY ordinal_position', [$v->name]);
            $this->assertSame($v->columnNames('postgres'), array_column($rows, 'n'), $v->name);
            foreach ($v->columnsFor('postgres') as $i => [$col, $type]) {
                $this->assertContains($rows[$i]['t'], $families[$type], $v->name . '.' . $col . ' is ' . $rows[$i]['t'] . ', declared ' . $type);
            }
            $this->assertSame('v', $this->db->value("SELECT relkind FROM pg_class WHERE oid = to_regclass(?)", [$v->name]));
            $seen[] = $v->name;
        }
        $this->assertSame(ReportingSchema::viewNames('postgres'), $seen);
    }

    public function testRunningTheViewStatementsAgainChangesNothing(): void
    {
        $cols = fn (): array => $this->db->query("SELECT table_name, column_name FROM information_schema.columns WHERE table_name LIKE 'rpt\\_%' AND table_schema = current_schema() ORDER BY table_name, ordinal_position");
        $before = $cols();
        foreach (ReportingSchema::postgresStatements() as $sql) {
            $this->db->execute($sql);
            $this->db->execute($sql);
        }
        $this->assertSame($before, $cols());
        $this->assertCount(6, array_unique(array_column($before, 'table_name')));
    }

    public function testTheClientBearingViewsReturnOnlyTheRequestedClientsRows(): void
    {
        $count = fn (string $view, ?int $client): int => (int) $this->db->value("SELECT COUNT(*) FROM $view WHERE client_id " . ($client === null ? 'IS NULL' : '= ' . $client));
        $this->assertSame(2, $count('rpt_patch_history', 7));
        $this->assertSame(2, $count('rpt_patch_history', 9));
        $this->assertSame(0, $count('rpt_patch_history', null));
        // change events: one record has no client (written before the denormalisation): it shows to NO client's filter, and only to an unfiltered administrator
        $this->assertSame(2, $count('rpt_change_events', 7));
        $this->assertSame(3, $count('rpt_change_events', 9));
        $this->assertSame(1, $count('rpt_change_events', null));
        $this->assertSame([2, 3], array_map('intval', array_column($this->db->query('SELECT device_id FROM rpt_change_events WHERE client_id = 7 ORDER BY device_id'), 'device_id')));
        $this->assertSame([4, 5, 6], array_map('intval', array_column($this->db->query('SELECT device_id FROM rpt_change_events WHERE client_id = 9 ORDER BY device_id'), 'device_id')));
        $this->assertSame([2, 3], array_map('intval', array_column($this->db->query('SELECT device_id FROM rpt_patch_history WHERE client_id = 7 ORDER BY device_id'), 'device_id')));
    }

    public function testTheViewsWithoutAClientColumnAreKeyedForTheModelRelation(): void
    {
        foreach (['rpt_device_health_hourly', 'rpt_device_health_daily'] as $v) {
            $cols = array_column($this->db->query('SELECT column_name FROM information_schema.columns WHERE table_name = ?', [$v]), 'column_name');
            $this->assertContains('asset_id', $cols);
            $this->assertNotContains('client_id', $cols);
            $this->assertNotContains('device_id', $cols);
        }
        foreach (['rpt_check_results', 'rpt_software_changes'] as $v) {
            $cols = array_column($this->db->query('SELECT column_name FROM information_schema.columns WHERE table_name = ?', [$v]), 'column_name');
            $this->assertContains('device_id', $cols);
            $this->assertNotContains('client_id', $cols);
        }
        $this->assertSame(6, (int) $this->db->value('SELECT COUNT(*) FROM rpt_device_health_hourly WHERE asset_id = 101'));
        $this->assertSame(4, (int) $this->db->value("SELECT COUNT(*) FROM rpt_device_health_daily WHERE asset_id IN (101, 102)"));
        $this->assertEqualsWithDelta(20.0, (float) $this->db->value("SELECT avg_value FROM rpt_device_health_hourly WHERE asset_id = 101 AND metric_name = 'cpu.utilization' LIMIT 1"), 0.001);
    }

    public function testCheckResultsAreBoundedToRecentHistory(): void
    {
        $this->assertSame(2, (int) $this->db->value('SELECT COUNT(*) FROM rpt_check_results WHERE device_id = 2'));
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM endpoint_agent_check_history WHERE device_id = 2 AND detail = 'old'"));
    }

    public function testNothingSecretOrFreeFormComesThroughAnyView(): void
    {
        foreach ([['endpoint_agent_change_records', 'new_value'], ['rmm_patch_history', 'detail'], ['endpoint_agent_check_history', 'detail']] as [$t, $c]) {
            $this->assertGreaterThan(0, (int) $this->db->value("SELECT COUNT(*) FROM $t WHERE $c LIKE '%" . ReportingSeed::SECRET . "%'"), "seed: $t.$c");
        }
        foreach (ReportingSchema::viewNames('postgres') as $v) {
            $dump = json_encode($this->db->query("SELECT * FROM $v"), JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString(ReportingSeed::SECRET, $dump, $v);
            foreach (['OLD-', 'NEW-', 'ACTOR-', 'evil-', 'DETAIL-', 'PD-', 'JOB-'] as $needle) {
                $this->assertStringNotContainsString($needle, $dump, "$v leaks $needle");
            }
        }
    }

    public function testTimestampsAreUtcWhateverTheSessionZone(): void
    {
        $this->db->execute("SET TIME ZONE 'America/Chicago'");
        try {
            $r = $this->db->queryOne("SELECT to_char(hour_start AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS u FROM rpt_device_health_hourly WHERE asset_id = 101 ORDER BY hour_start LIMIT 1");
            $this->assertSame(gmdate('Y-m-d H:00:00', time() - 3 * 3600), $r['u'], 'the stored instant is the UTC hour that was inserted');
        } finally {
            $this->db->execute("SET TIME ZONE 'UTC'");
        }
    }
}
