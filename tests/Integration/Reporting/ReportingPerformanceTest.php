<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Reporting;

use PHPUnit\Framework\TestCase;
use RivetCore\Database\DatabaseInterface;
use RivetCore\Tests\Support\ReportingTestDb;

/**
 * EXPLAIN-level sanity of the views over the big tables on a populated synthetic dataset (about 600,000 rows: 200,000 hourly metric rows, 146,000 daily rows,
 * 200,000 check results, 50,000 change records, 20,000 alerts): a view filtered by date and device, asset or client must use an index of the big table, never read
 * all of it. Timings are printed to STDERR. A private database, so the tenant tests' counts are not disturbed.
 */
final class ReportingPerformanceTest extends TestCase
{
    private static bool $loaded = false;
    private DatabaseInterface $db;
    /** @var list<string> */
    private static array $report = [];

    protected function setUp(): void
    {
        $t = ReportingTestDb::get('rpt_perf', false);
        if ($t === null) {
            $this->markTestSkipped('RIVETCORE_TEST_DB_NAME not set, or the account cannot create the private reporting database.');
        }
        $this->db = $t['db'];
        if (!self::$loaded) {
            self::$loaded = true;
            $this->load();
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$report !== []) {
            fwrite(STDERR, "\nreporting views, EXPLAIN and timing on the synthetic dataset:\n" . implode("\n", self::$report) . "\n");
        }
    }

    private function load(): void
    {
        $db = $this->db;
        $db->execute('DROP TABLE IF EXISTS perf_digits');
        $db->execute('CREATE TABLE perf_digits (d int NOT NULL PRIMARY KEY)');
        $db->execute('INSERT INTO perf_digits VALUES (0),(1),(2),(3),(4),(5),(6),(7),(8),(9)');
        $n = '(SELECT a.d + 10 * b.d + 100 * c.d + 1000 * d.d + 10000 * e.d + 100000 * f.d AS n FROM perf_digits a, perf_digits b, perf_digits c, perf_digits d, perf_digits e, perf_digits f)';
        $db->execute('INSERT IGNORE INTO endpoint_agent_settings (id) VALUES (1)');
        $db->execute("INSERT INTO endpoint_agent_devices (device_id, install_id, hostname, client_id, asset_id, link_state, last_checkin_at) SELECT n + 1, CONCAT('00000000-0000-4000-8000-', LPAD(n + 1, 12, '0')), CONCAT('host', n), 1 + (n % 10), 1001 + n, 'linked', UTC_TIMESTAMP() FROM $n x WHERE n < 400");
        $hour = "DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-%d %H:00:00')";
        $db->execute("INSERT INTO rmm_metric_hourly (asset_id, metric_key, instance, hour_start, samples, sum_value, min_value, max_value)
            SELECT 1001 + (n % 400), ELT(1 + (n DIV 400) % 3, 'cpu.utilization', 'memory.utilization', 'disk.free_bytes'), '', $hour - INTERVAL (n DIV 1200) HOUR, 12, 600, 10, 90 FROM $n x WHERE n < 201600");
        $db->execute("INSERT INTO rmm_metric_daily (asset_id, metric_key, instance, day_start, samples, sum_value, min_value, max_value)
            SELECT 1001 + (n % 400), 'disk.free_bytes', 'C:', DATE(UTC_TIMESTAMP()) - INTERVAL (n DIV 400) DAY, 288, 28800, 10, 90 FROM $n x WHERE n < 146000");
        $db->execute("INSERT INTO endpoint_agent_check_history (device_id, check_key, status, detail, reported_at)
            SELECT 1 + (n % 400), CONCAT('chk', n % 5), 'ok', '', UTC_TIMESTAMP() - INTERVAL (n DIV 400) * 20 MINUTE FROM $n x WHERE n < 200000");
        $db->execute("INSERT INTO endpoint_agent_change_records (device_id, client_id, occurred_at, received_at, kind, watch, change_type, dedupe)
            SELECT 1 + (n % 400), 1 + (n % 10), UTC_TIMESTAMP() - INTERVAL (n DIV 400) * 60 MINUTE, UTC_TIMESTAMP(), 'registry', 'win.run_keys', 1 + (n % 3), UNHEX(MD5(n)) FROM $n x WHERE n < 50000");
        $db->execute("INSERT INTO rmm_alert_meta (alert_id, device_id, client_id, check_key, episode, state, opened_at, resolved_at)
            SELECT n + 1, 1 + (n % 400), 1 + (n % 10), CONCAT('chk', n % 5), n DIV 400 + 1, 'resolved', UTC_TIMESTAMP() - INTERVAL n MINUTE, UTC_TIMESTAMP() - INTERVAL n MINUTE + INTERVAL 30 MINUTE FROM $n x WHERE n < 20000");
        foreach (['rmm_metric_hourly', 'rmm_metric_daily', 'endpoint_agent_check_history', 'endpoint_agent_change_records', 'rmm_alert_meta', 'endpoint_agent_devices'] as $t) {
            $db->execute("ANALYZE TABLE `$t`");
        }
    }

    /** @return iterable<string,array{string,string,list<string>}> view query, then the aliases of the big tables whose access must use an index */
    public static function queries(): iterable
    {
        $day = "UTC_TIMESTAMP() - INTERVAL 1 DAY";
        yield 'hourly: one asset, last day' => ["SELECT * FROM rpt_device_health_hourly WHERE asset_id = 1100 AND hour_start >= $day", 'm'];
        yield 'hourly: one device, last day' => ["SELECT * FROM rpt_device_health_hourly WHERE device_id = 100 AND hour_start >= $day", 'm'];
        yield 'hourly: one client, last day' => ["SELECT * FROM rpt_device_health_hourly WHERE client_id = 3 AND hour_start >= $day", 'm'];
        yield 'hourly: everyone, last 2 hours' => ['SELECT * FROM rpt_device_health_hourly WHERE hour_start >= UTC_TIMESTAMP() - INTERVAL 2 HOUR', 'm'];
        yield 'daily: one asset, last 90 days' => ['SELECT * FROM rpt_device_health_daily WHERE asset_id = 1100 AND day_start >= UTC_TIMESTAMP() - INTERVAL 90 DAY', 'm'];
        yield 'daily: one client, last 30 days' => ['SELECT * FROM rpt_device_health_daily WHERE client_id = 3 AND day_start >= UTC_TIMESTAMP() - INTERVAL 30 DAY', 'm'];
        yield 'check results: one device, last day' => ["SELECT * FROM rpt_check_results WHERE device_id = 100 AND reported_at >= $day", 'h'];
        yield 'check results: everyone, last hour' => ['SELECT * FROM rpt_check_results WHERE reported_at >= UTC_TIMESTAMP() - INTERVAL 1 HOUR', 'h'];
        yield 'check results: one client, last hour' => ['SELECT * FROM rpt_check_results WHERE client_id = 3 AND reported_at >= UTC_TIMESTAMP() - INTERVAL 1 HOUR', 'h'];
        yield 'change events: one device, last week' => ['SELECT * FROM rpt_change_events WHERE device_id = 100 AND occurred_at >= UTC_TIMESTAMP() - INTERVAL 7 DAY', 'e'];
        yield 'change events: everyone, last 2 hours' => ['SELECT * FROM rpt_change_events WHERE occurred_at >= UTC_TIMESTAMP() - INTERVAL 2 HOUR', 'e'];
        yield 'alerts: one device' => ['SELECT * FROM rpt_alerts WHERE device_id = 100', 'a'];
        yield 'alerts: last day' => ["SELECT * FROM rpt_alerts WHERE opened_at >= $day", 'a'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('queries')]
    public function testFilteredViewsUseAnIndexOfTheBigTable(string $sql, string $alias): void
    {
        $plan = $this->db->fetchAll('EXPLAIN ' . $sql);
        $access = null;
        foreach ($plan as $row) {
            if (($row['table'] ?? null) === $alias) {
                $access = $row;
            }
        }
        $this->assertNotNull($access, 'the big table (alias ' . $alias . ') is in the plan: ' . json_encode($plan));
        $this->assertNotNull($access['key'], 'an index is used: ' . json_encode($access));
        $this->assertContains(strtolower((string) $access['type']), ['ref', 'range', 'eq_ref', 'const', 'ref_or_null', 'index_merge'], 'not a scan: ' . json_encode($access));

        $t = microtime(true);
        $rows = $this->db->fetchAll($sql);
        $ms = (microtime(true) - $t) * 1000;
        self::$report[] = sprintf('  %6.1f ms  %6d rows  key=%-22s %s', $ms, count($rows), (string) $access['key'], preg_replace('/\s+/', ' ', substr($sql, 0, 110)));
        $this->assertLessThan(5000, $ms, 'a filtered report query must not take seconds');
    }

    public function testTheDatasetIsAsLargeAsClaimed(): void
    {
        $n = 0;
        foreach (['rmm_metric_hourly', 'rmm_metric_daily', 'endpoint_agent_check_history', 'endpoint_agent_change_records', 'rmm_alert_meta'] as $t) {
            $n += (int) $this->db->fetchOne("SELECT COUNT(*) c FROM `$t`")['c'];
        }
        $this->assertSame(201600 + 146000 + 200000 + 50000 + 20000, $n);
        // and the views read all of it when nothing filters (the reason the guide says: always filter by date)
        $this->assertSame(201600, (int) $this->db->fetchOne('SELECT COUNT(*) c FROM rpt_device_health_hourly')['c']);
        self::$report[] = sprintf('  dataset: %d rows in the five big tables', $n);
    }
}
