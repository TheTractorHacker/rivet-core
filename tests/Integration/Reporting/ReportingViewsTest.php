<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Reporting;

use PHPUnit\Framework\TestCase;
use RivetCore\Database\DatabaseInterface;
use RivetCore\Reporting\Migration\Migration0045ReportingViews;
use RivetCore\Reporting\ReportingCatalog;
use RivetCore\Reporting\ReportingView;
use RivetCore\Tests\Support\ReportingSeed;
use RivetCore\Tests\Support\ReportingTestDb;

/**
 * The reporting views on a real MariaDB / MySQL (#91 phase 2): they exist after the migration, their columns are the frozen ones, every client-scoped view
 * carries client_id and a query for one client returns only that client's rows, and nothing secret or free-form comes through. Skipped without RIVETCORE_TEST_DB_*.
 */
final class ReportingViewsTest extends TestCase
{
    private DatabaseInterface $db;
    private \mysqli $mysqli;
    private string $schema;

    protected function setUp(): void
    {
        $t = ReportingTestDb::get();
        if ($t === null) {
            $this->markTestSkipped('RIVETCORE_TEST_DB_NAME not set, or the account cannot create the private reporting database.');
        }
        $this->db = $t['db'];
        $this->mysqli = $t['mysqli'];
        $this->schema = $t['name'];
    }

    public function testEveryViewExistsWithTheFrozenColumnsAndTypeFamilies(): void
    {
        $families = ['int' => ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint'], 'flag' => ['tinyint', 'smallint', 'int', 'bigint'], 'double' => ['double', 'float', 'decimal', 'numeric'],
            'text' => ['varchar', 'char', 'text', 'tinytext', 'mediumtext', 'longtext', 'enum'], 'datetime' => ['datetime', 'timestamp'], 'date' => ['date']];
        foreach (ReportingCatalog::views() as $v) {
            $rows = $this->db->fetchAll('SELECT COLUMN_NAME AS n, DATA_TYPE AS t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$this->schema, $v->name]);
            $this->assertSame(array_column($v->columns, 0), array_map(static fn (array $r): string => strtolower((string) $r['n']), $rows), $v->name . ' columns');
            foreach ($v->columns as $i => [$col, $type]) {
                $this->assertContains(strtolower((string) $rows[$i]['t']), $families[$type], $v->name . '.' . $col . ' is ' . $rows[$i]['t'] . ', declared ' . $type);
            }
            $isView = $this->db->fetchOne('SELECT TABLE_TYPE AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$this->schema, $v->name]);
            $this->assertSame('VIEW', $isView['t']);
        }
    }

    public function testTheMigrationIsIdempotentAndChangesNoColumn(): void
    {
        $before = $this->columnsOfAllViews();
        (new Migration0045ReportingViews())->up($this->db);
        (new Migration0045ReportingViews())->up($this->db);
        $this->assertSame($before, $this->columnsOfAllViews());
        $this->assertSame(17, count($before));
    }

    public function testEveryClientScopedViewReturnsOnlyTheRequestedClientsRows(): void
    {
        $seen = 0;
        foreach (ReportingCatalog::views() as $v) {
            if (!$v->clientScoped) {
                continue;
            }
            $this->assertArrayHasKey($v->name, ReportingSeed::EXPECTED_MARIADB, $v->name . ' has no tenant expectation in the seed');
            [$e7, $e9] = ReportingSeed::EXPECTED_MARIADB[$v->name];
            $this->assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) c FROM `{$v->name}` WHERE client_id IS NULL")['c'], $v->name . ' has rows without a client');
            $c7 = $this->db->fetchAll("SELECT * FROM `{$v->name}` WHERE client_id = ?", [7]);
            $c9 = $this->db->fetchAll("SELECT * FROM `{$v->name}` WHERE client_id = ?", [9]);
            $this->assertCount($e7, $c7, $v->name . ' client 7');
            $this->assertCount($e9, $c9, $v->name . ' client 9');
            $this->assertSame($e7 + $e9, (int) $this->db->fetchOne("SELECT COUNT(*) c FROM `{$v->name}`")['c'], $v->name . ': every row belongs to client 7 or 9');
            // the rows of one client never mention a device of the other
            foreach (['device_id' => 'device_id', 'host_device_id' => 'host_device_id'] as $col) {
                if (in_array($col, $v->columnNames(), true)) {
                    $this->assertSame([], array_intersect(array_column($c7, $col), [4, 5, 6]), $v->name . ' client 7 leaks a client 9 device');
                    $this->assertSame([], array_intersect(array_column($c9, $col), [1, 2, 3]), $v->name . ' client 9 leaks a client 7 device');
                }
            }
            ++$seen;
        }
        $this->assertSame(16, $seen);
    }

    public function testTheAuditSummaryIsTheOnlyUnscopedViewAndIsGlobal(): void
    {
        $this->assertSame(3, (int) $this->db->fetchOne('SELECT COUNT(*) c FROM rpt_audit_events')['c']);
        $cols = array_column($this->db->fetchAll('SELECT COLUMN_NAME c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$this->schema, 'rpt_audit_events']), 'c');
        $this->assertNotContains('client_id', array_map('strtolower', $cols));
    }

    public function testNothingSecretOrFreeFormComesThroughAnyView(): void
    {
        // the seed really planted the marker in the columns the views must not expose
        foreach ([['endpoint_agent_devices', 'token_hash'], ['endpoint_agent_devices', 'serial'], ['rmm_alert_meta', 'message'], ['endpoint_agent_check_history', 'detail'], ['endpoint_agent_change_records', 'new_value'],
            ['audit_events', 'summary'], ['audit_events', 'ip_address'], ['rmm_hypervisor_vms', 'tags'], ['rmm_patch_history', 'detail']] as [$t, $c]) {
            $this->assertGreaterThan(0, (int) $this->db->fetchOne("SELECT COUNT(*) c FROM `$t` WHERE `$c` LIKE '%" . ReportingSeed::SECRET . "%' OR `$c` LIKE '%203.0.113.9%' OR `$c` LIKE '%198.51.100.%'")['c'], "seed: $t.$c");
        }
        $total = 0;
        foreach (ReportingCatalog::views() as $v) {
            $rows = $this->db->fetchAll("SELECT * FROM `{$v->name}`");
            $total += count($rows);
            $dump = json_encode($rows, JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString(ReportingSeed::SECRET, $dump, $v->name);
            foreach (['203.0.113.9', '198.51.100.', 'MSG-', 'DETAIL-', 'OLD-', 'NEW-', 'ACTOR-', 'evil-', 'user-', 'SERIAL-', 'guid-'] as $needle) {
                $this->assertStringNotContainsString($needle, $dump, $v->name . ' leaks ' . $needle);
            }
        }
        $this->assertGreaterThan(60, $total);
    }

    public function testDevicesStatusAndHeadlineState(): void
    {
        $rows = [];
        foreach ($this->db->fetchAll('SELECT * FROM rpt_devices') as $r) {
            $rows[(int) $r['device_id']] = $r;
        }
        $this->assertSame(['retired', 'online', 'online', 'offline', 'online', 'never'], array_map(static fn (int $i): string => (string) $rows[$i]['status'], [1, 2, 3, 4, 5, 6]));
        $this->assertSame(1, (int) $rows[3]['is_hypervisor_host']);
        $this->assertSame(0, (int) $rows[2]['is_hypervisor_host']);
        $this->assertSame('1.2.3', $rows[2]['agent_version']);
        $this->assertSame(1, (int) $rows[2]['patch_critical_count']);
        $this->assertNull($rows[6]['asset_id']);
        $this->assertSame(70, (int) $rows[2]['site_id']);
    }

    public function testTheOfflineAfterSettingDecidesOnlineVersusOffline(): void
    {
        $this->db->execute('UPDATE endpoint_agent_settings SET offline_after_s = 7200 WHERE id = 1');
        try {
            $this->assertSame('online', $this->db->fetchOne('SELECT status FROM rpt_devices WHERE device_id = 4')['status']);
        } finally {
            $this->db->execute('UPDATE endpoint_agent_settings SET offline_after_s = 900 WHERE id = 1');
        }
        $this->assertSame('offline', $this->db->fetchOne('SELECT status FROM rpt_devices WHERE device_id = 4')['status']);
    }

    public function testAlertDurationsAndOpenFlag(): void
    {
        $r = $this->db->fetchOne('SELECT * FROM rpt_alerts WHERE alert_id = 501');
        $this->assertSame(5400, (int) $r['duration_s']);
        $this->assertSame(600, (int) $r['time_to_ack_s']);
        $this->assertSame(0, (int) $r['is_open']);
        $open = $this->db->fetchOne('SELECT * FROM rpt_alerts WHERE alert_id = 502');
        $this->assertSame(1, (int) $open['is_open']);
        $this->assertEqualsWithDelta(7200, (int) $open['duration_s'], 30);
        $this->assertNull($open['time_to_ack_s']);
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT is_open FROM rpt_alerts WHERE alert_id = 504')['is_open'], 'an acknowledged alert is still open');
    }

    public function testMetricViewsKeepOneRowPerSeriesEvenWhenAnAssetWasReEnrolled(): void
    {
        // asset 101 belongs to device 1 (retired) and device 2: 6 hourly rows, all under the newest device, never doubled
        $rows = $this->db->fetchAll('SELECT device_id, client_id, metric_name, avg_value FROM rpt_device_health_hourly WHERE asset_id = 101');
        $this->assertCount(6, $rows);
        $this->assertSame([2], array_values(array_unique(array_map('intval', array_column($rows, 'device_id')))));
        $this->assertSame([7], array_values(array_unique(array_map('intval', array_column($rows, 'client_id')))));
        $this->assertEqualsWithDelta(20.0, (float) $rows[array_search('cpu.utilization', array_column($rows, 'metric_name'), true)]['avg_value'], 0.001);
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) c FROM rpt_device_health_hourly WHERE asset_id = 999')['c'], 'metrics of an asset no device owns are not published');
        $this->assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) c FROM rpt_device_health_daily WHERE asset_id = 101')['c']);
    }

    public function testCheckResultsAreBoundedToRecentHistory(): void
    {
        $this->assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) c FROM rpt_check_results WHERE device_id = 2')['c'], 'the 40-day-old row is outside the view');
        $this->assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) c FROM endpoint_agent_check_history WHERE device_id = 2 AND detail = 'old'")['c']);
    }

    public function testPatchComplianceCountsWhatIsMissingAndHowLong(): void
    {
        $r = $this->db->fetchOne('SELECT * FROM rpt_patch_compliance WHERE device_id = 2');
        $this->assertSame(2, (int) $r['missing_important_count']);
        $this->assertSame(1, (int) $r['failed_update_count']);
        $this->assertSame('warn', $r['compliance_status']);
        $this->assertEqualsWithDelta(time() - 86400 * 20, strtotime($r['oldest_missing_important_at'] . ' UTC'), 5);
        $clean = $this->db->fetchOne('SELECT * FROM rpt_patch_compliance WHERE device_id = 3');
        $this->assertSame(0, (int) $clean['missing_important_count']);
        $this->assertNull($clean['oldest_missing_important_at']);
        $this->assertNull($clean['compliance_status']);
    }

    public function testChangeEventsTakeTheDevicesClientWhenTheRecordHasNone(): void
    {
        $this->assertSame([7, 7, 7, 9, 9, 9], array_map('intval', array_column($this->db->fetchAll('SELECT client_id FROM rpt_change_events ORDER BY device_id'), 'client_id')));
        $this->assertSame('added', $this->db->fetchOne('SELECT change_name FROM rpt_change_events WHERE device_id = 2')['change_name']);
    }

    public function testSoftwareInventoryListsOnlyWhatIsInstalled(): void
    {
        $names = array_column($this->db->fetchAll('SELECT software_name FROM rpt_software_inventory ORDER BY software_name'), 'software_name');
        $this->assertSame(['7-Zip', 'Chrome', 'curl'], $names);
    }

    /** @return array<string,list<string>> */
    private function columnsOfAllViews(): array
    {
        $out = [];
        foreach ($this->db->fetchAll("SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME LIKE 'rpt\\_%' ORDER BY TABLE_NAME, ORDINAL_POSITION", [$this->schema]) as $r) {
            $out[strtolower((string) $r['t'])][] = strtolower((string) $r['c']);
        }

        return $out;
    }
}
