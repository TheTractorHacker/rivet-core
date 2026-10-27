<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm\Postgres;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Store\Postgres\PostgresConnectionInterface;
use RivetCore\Tests\Support\PostgresTestDatabase;

/**
 * EXPLAIN-level sanity of the PostgreSQL `rpt_` views on a populated synthetic dataset (about 600,000 rows, inserted in time order like real history): a view
 * filtered by date and device or asset must not read a whole hot table. Timings go to STDERR.
 */
final class ReportingPostgresPerformanceTest extends TestCase
{
    private static bool $loaded = false;
    private PostgresConnectionInterface $db;
    /** @var list<string> */
    private static array $report = [];

    protected function setUp(): void
    {
        if (($why = PostgresTestDatabase::unavailable()) !== null) {
            $this->markTestSkipped($why);
        }
        $this->db = PostgresTestDatabase::connect();
        if (!self::$loaded) {
            self::$loaded = true;
            PostgresTestDatabase::prepare($this->db);
            $this->load();
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$loaded && PostgresTestDatabase::unavailable() === null) {
            PostgresTestDatabase::prepare(PostgresTestDatabase::connect());   // leave the empty schema the other tests expect
        }
        if (self::$report !== []) {
            fwrite(STDERR, "\nreporting views on PostgreSQL, plan and timing on the synthetic dataset:\n" . implode("\n", self::$report) . "\n");
        }
    }

    private function load(): void
    {
        $db = $this->db;
        $hour = "date_trunc('hour', now() AT TIME ZONE 'UTC') AT TIME ZONE 'UTC'";
        $db->execute("INSERT INTO rmm_metric_hourly (asset_id, metric_key, instance, hour_start, samples, sum_value, min_value, max_value)
            SELECT 1001 + (n % 400), (ARRAY['cpu.utilization', 'memory.utilization', 'disk.free_bytes'])[1 + (n / 400) % 3], '', $hour - (n / 1200) * INTERVAL '1 hour', 12, 600, 10, 90
            FROM generate_series(0, 201599) AS n ORDER BY n / 1200 DESC");
        $db->execute("INSERT INTO rmm_metric_daily (asset_id, metric_key, instance, day_start, samples, sum_value, min_value, max_value)
            SELECT 1001 + (n % 400), 'disk.free_bytes', 'C:', date_trunc('day', now() AT TIME ZONE 'UTC') AT TIME ZONE 'UTC' - (n / 400) * INTERVAL '1 day', 288, 28800, 10, 90 FROM generate_series(0, 145999) AS n ORDER BY n / 400 DESC");
        $db->execute("INSERT INTO endpoint_agent_check_history (device_id, check_key, status, detail, reported_at)
            SELECT 1 + (n % 400), 'chk' || (n % 5), 'ok', '', now() - (n / 400) * INTERVAL '20 minutes' FROM generate_series(0, 199999) AS n ORDER BY n / 400 DESC");
        $db->execute("INSERT INTO endpoint_agent_change_records (device_id, client_id, occurred_at, received_at, kind, watch, change_type, dedupe)
            SELECT 1 + (n % 400), 1 + (n % 10), now() - (n / 400) * INTERVAL '60 minutes', now(), 'registry', 'win.run_keys', 1 + (n % 3), decode(md5(n::text), 'hex') FROM generate_series(0, 49999) AS n ORDER BY n / 400 DESC");
        $db->execute("INSERT INTO rmm_patch_history (device_id, client_id, update_id, action, result, \"at\") SELECT 1 + (n % 400), 1 + (n % 10), 'u' || (n % 50), 'install', 'success', now() - (n / 400) * INTERVAL '30 minutes' FROM generate_series(0, 39999) AS n ORDER BY n / 400 DESC");
        foreach (['rmm_metric_hourly', 'rmm_metric_daily', 'endpoint_agent_check_history', 'endpoint_agent_change_records', 'rmm_patch_history'] as $t) {
            $db->execute("ANALYZE $t");
        }
    }

    /** @return iterable<string,array{string}> */
    public static function queries(): iterable
    {
        yield 'hourly: one asset, last day' => ["SELECT * FROM rpt_device_health_hourly WHERE asset_id = 1100 AND hour_start >= now() - INTERVAL '1 day'"];
        yield 'hourly: one asset and metric, last week' => ["SELECT * FROM rpt_device_health_hourly WHERE asset_id = 1100 AND metric_name = 'cpu.utilization' AND hour_start >= now() - INTERVAL '7 days'"];
        yield 'hourly: everyone, last 2 hours' => ["SELECT * FROM rpt_device_health_hourly WHERE hour_start >= now() - INTERVAL '2 hours'"];
        yield 'daily: one asset, last 90 days' => ["SELECT * FROM rpt_device_health_daily WHERE asset_id = 1100 AND day_start >= now() - INTERVAL '90 days'"];
        yield 'check results: one device, last day' => ["SELECT * FROM rpt_check_results WHERE device_id = 100 AND reported_at >= now() - INTERVAL '1 day'"];
        yield 'check results: everyone, last hour' => ["SELECT * FROM rpt_check_results WHERE reported_at >= now() - INTERVAL '1 hour'"];
        yield 'change events: one device, last week' => ["SELECT * FROM rpt_change_events WHERE device_id = 100 AND occurred_at >= now() - INTERVAL '7 days'"];
        yield 'change events: one client, last 2 hours' => ["SELECT * FROM rpt_change_events WHERE client_id = 3 AND occurred_at >= now() - INTERVAL '2 hours'"];
        yield 'patch history: one client, last day' => ["SELECT * FROM rpt_patch_history WHERE client_id = 3 AND occurred_at >= now() - INTERVAL '1 day'"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('queries')]
    public function testFilteredViewsDoNotReadAWholeHotTable(string $sql): void
    {
        $json = (string) $this->db->value('EXPLAIN (FORMAT JSON) ' . $sql);
        $plan = json_decode($json, true, 512, JSON_THROW_ON_ERROR)[0]['Plan'];
        $scans = [];
        $this->walk($plan, $scans);
        $this->assertNotSame([], $scans, 'the plan reads the hot table: ' . $json);
        foreach ($scans as [$node, $rel]) {
            if (str_starts_with($rel, '_hyper_')) {
                // TimescaleDB prunes by chunk (chunk exclusion); a recent chunk can be small enough, or lie entirely inside the requested window, that the planner
                // reads it sequentially. What matters on a hypertable is that old chunks are not touched at all, which the plan shows by not listing them.
                continue;
            }
            $this->assertStringNotContainsString('Seq Scan', $node, "$rel is read completely: " . $json);
        }
        $t = microtime(true);
        $rows = $this->db->query($sql);
        $ms = (microtime(true) - $t) * 1000;
        self::$report[] = sprintf('  %6.1f ms  %6d rows  %-40s %s', $ms, count($rows), implode('+', array_unique(array_map(static fn (array $s): string => $s[0], $scans))), substr($sql, 0, 100));
        $this->assertLessThan(5000, $ms);
    }

    /**
     * @param array<string,mixed> $node
     * @param list<array{0:string,1:string}> $scans
     */
    private function walk(array $node, array &$scans): void
    {
        $rel = (string) ($node['Relation Name'] ?? '');
        if ($rel !== '' && preg_match('/^(rmm_metric_hourly|rmm_metric_daily|endpoint_agent_check_history|endpoint_agent_change_records|rmm_patch_history|_hyper_)/', $rel) === 1) {
            $scans[] = [(string) $node['Node Type'] . (isset($node['Index Name']) ? ' (' . $node['Index Name'] . ')' : ''), $rel];
        }
        foreach ($node['Plans'] ?? [] as $child) {
            $this->walk($child, $scans);
        }
    }

    public function testTheDatasetIsAsLargeAsClaimed(): void
    {
        $n = 0;
        foreach (['rmm_metric_hourly', 'rmm_metric_daily', 'endpoint_agent_check_history', 'endpoint_agent_change_records', 'rmm_patch_history'] as $t) {
            $n += (int) $this->db->value("SELECT COUNT(*) FROM $t");
        }
        $this->assertSame(201600 + 146000 + 200000 + 50000 + 40000, $n);
        self::$report[] = sprintf('  dataset: %d rows in the five big tables', $n);
    }
}
