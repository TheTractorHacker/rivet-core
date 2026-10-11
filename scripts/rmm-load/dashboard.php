<?php

declare(strict_types=1);

/**
 * Read-path and storage measurements at fleet size (scaling items S5 and S8), on a scratch database: seeds N devices (a mix of online, offline, stale and never-seen) and
 * an hourly metric history, then times the fleet counters, the capacity panel, a device list page and 7/30/90 day graphs, cold and cached, before and after the daily
 * rollup, and the retention of one day of rows by batched DELETE against DROP PARTITION. Prints one JSON document.
 *
 *   php scripts/rmm-load/dashboard.php [--devices=10000] [--metric-devices=2000] [--metric-days=14] [--repeat=5] [--prune=delete|partition]  (run it twice, once of each: the second needs the rows the first deleted)
 *
 * The scratch database is wiped. RIVETCORE_TEST_DB_NAME must contain "scratch". Numbers are for the host and the database it ran against (see docs/rmm/CAPACITY.md).
 */

use RivetCore\Rmm\Storage\MetricRollup;
use RivetCore\Rmm\Support\DatabaseMetricSink;
use RivetCore\Rmm\Support\Sql;
use RivetCore\Tests\Support\LoadEdition;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
date_default_timezone_set('UTC');
$o = getopt('', ['devices:', 'metric-devices:', 'metric-days:', 'repeat:', 'prune:']);
$devices = (int) ($o['devices'] ?? 10000);
$metricDevices = (int) ($o['metric-devices'] ?? 2000);
$metricDays = (int) ($o['metric-days'] ?? 14);
$repeat = max(1, (int) ($o['repeat'] ?? 5));
$prune = (string) ($o['prune'] ?? 'delete');
if (!str_contains((string) getenv('RIVETCORE_TEST_DB_NAME'), 'scratch')) {
    fwrite(STDERR, "Refusing: RIVETCORE_TEST_DB_NAME must name a scratch database\n");
    exit(2);
}

$e = new LoadEdition();
$db = $e->db;
$m = $e->mysqli;
LoadEdition::migrate($db);
LoadEdition::wipe($db);
foreach (['rmm_metric_hourly', 'rmm_metric_daily', 'endpoint_agent_check_history'] as $t) {   // back to the plain tables of the migrations
    $db->execute("DROP TABLE IF EXISTS `$t`");
}
$db->execute(\RivetCore\Rmm\Migration\RmmSchema::phase1Tables()['rmm_metric_hourly']);
$db->execute(\RivetCore\Rmm\Migration\RmmSchema::phase1Tables()['endpoint_agent_check_history']);
$db->execute(\RivetCore\Rmm\Migration\ScaleSchema::tables()['rmm_metric_daily']);
$db->execute('DELETE FROM rmm_storage_state');
$e->module->settings()->enable();
$sql = new Sql($db, $e->clock);
$now = time();
$t0 = microtime(true);

// ---- seed devices: 70 % online, 20 % offline, 5 % stale, 5 % never, 3 KB of inventory each
$inv = json_encode(['hostname' => 'x', 'cpu' => ['model' => str_repeat('Intel Core ', 6)], 'disks' => array_fill(0, 3, ['mount' => 'C:', 'total_bytes' => 512000000000, 'free_bytes' => 200000000000, 'fs' => 'NTFS']),
    'network' => array_fill(0, 4, ['name' => 'Ethernet adapter', 'mac' => 'AA-BB-CC-DD-EE-01', 'ips' => ['10.0.0.5', 'fe80::1']]), 'pad' => str_repeat('p', 1500)]);
$cfg = $e->module->settings()->get(true);
$offline = (int) $cfg['offline_after_s'];
$stale = (int) $cfg['stale_after_s'];
for ($from = 1; $from <= $devices; $from += 2000) {
    $to = min($devices, $from + 1999);
    $m->query("INSERT INTO endpoint_agent_devices (install_id, machine_guid, hostname, os, os_version, arch, serial, manufacturer, model, agent_version, asset_id, client_id, link_state, token_hash, enroll_count, last_checkin_at, last_seq, inventory_json)
        SELECT UUID(), CONCAT('g', n), CONCAT('HOST-', n), 'windows', 'Windows 11 23H2', 'amd64', CONCAT('SN', n), 'Dell', 'Latitude', '1.0.0', 100000 + n, 1 + (n % 40), 'linked', SHA2(CONCAT('t', n), 256), 1,
        CASE WHEN n % 20 = 0 THEN NULL WHEN n % 20 = 1 THEN UTC_TIMESTAMP() - INTERVAL " . ($stale + 3600) . " SECOND WHEN n % 10 < 2 THEN UTC_TIMESTAMP() - INTERVAL " . ($offline + 600) . " SECOND
        ELSE UTC_TIMESTAMP() - INTERVAL (n % 200) SECOND END, 1, '" . $m->real_escape_string($inv) . "'
        FROM (WITH RECURSIVE s(n) AS (SELECT $from UNION ALL SELECT n + 1 FROM s WHERE n < $to) SELECT n FROM s) q");
}
$seedDevices = round(microtime(true) - $t0, 1);

// ---- seed the hourly metric history: $metricDevices x 6 keys x hours
$t1 = microtime(true);
$hours = $metricDays * 24;
$start = intdiv($now, 3600) * 3600 - $hours * 3600;
foreach (['cpu.utilization', 'memory.utilization', 'disk.utilization', 'disk.free_bytes', 'network.rx_bytes_per_s', 'network.tx_bytes_per_s'] as $k) {
    for ($from = 1; $from <= $metricDevices; $from += 200) {
        $to = min($metricDevices, $from + 199);
        $m->query("INSERT INTO rmm_metric_hourly (asset_id, metric_key, instance, hour_start, samples, sum_value, min_value, max_value)
            SELECT 100000 + a.n, '$k', IF('$k' = 'disk.utilization', 'C:', ''), FROM_UNIXTIME($start + h.n * 3600), 12, 12 * (20 + (a.n + h.n) % 50), 10 + (a.n % 10), 40 + (a.n + h.n) % 55
            FROM (WITH RECURSIVE s(n) AS (SELECT $from UNION ALL SELECT n + 1 FROM s WHERE n < $to) SELECT n FROM s) a
            JOIN (WITH RECURSIVE s(n) AS (SELECT 0 UNION ALL SELECT n + 1 FROM s WHERE n < " . ($hours - 1) . ") SELECT n FROM s) h");
    }
}
$hourlyRows = (int) $db->fetchOne('SELECT COUNT(*) c FROM rmm_metric_hourly')['c'];
$seedMetrics = round(microtime(true) - $t1, 1);
$db->execute('ANALYZE TABLE endpoint_agent_devices');
$db->execute('ANALYZE TABLE rmm_metric_hourly');

$time = static function (\Closure $f, int $n) use (&$e): array {
    $ms = [];
    for ($i = 0; $i < $n; ++$i) {
        $t = hrtime(true);
        $f($i);
        $ms[] = (hrtime(true) - $t) / 1e6;
    }
    sort($ms);

    return ['median_ms' => round($ms[intdiv(count($ms), 2)], 1), 'max_ms' => round(end($ms), 1), 'n' => count($ms)];
};
$rm = $e->module->readModel();
$cap = $e->module->capacity();
$cache = $e->module->cache();
$asset = 100000 + intdiv($metricDevices, 2);
$sink = new DatabaseMetricSink($db, $e->clock);
$since = static fn (int $days): \DateTimeImmutable => new \DateTimeImmutable('@' . (time() - $days * 86400));
$until = new \DateTimeImmutable('@' . time());

$out = ['devices' => $devices, 'hourly_rows' => $hourlyRows, 'seed_s' => ['devices' => $seedDevices, 'metrics' => $seedMetrics], 'server' => (string) $db->fetchOne('SELECT VERSION() v')['v']];

// ---- fleet counters and the capacity panel: no cache, cold cache, warm cache, stale-while-revalidate
$e->module->settings()->update(['limits_json' => ['cache_fresh_s' => 0]]);
$out['fleet_counts']['no_cache'] = $time(static fn () => $rm->fleetCounts(), $repeat);
$out['capacity_panel']['no_cache'] = $time(static fn () => $cap->build(), $repeat);
$e->module->settings()->update(['limits_json' => ['cache_fresh_s' => 15, 'cache_stale_s' => 300]]);
$out['fleet_counts']['cold'] = $time(static function () use ($rm, $cache) {
    $cache->invalidate('fleet', true);
    $rm->fleetCounts();
}, $repeat);
$out['fleet_counts']['warm'] = $time(static fn () => $rm->fleetCounts(), $repeat * 4);
$out['capacity_panel']['cold'] = $time(static function () use ($cap, $cache) {
    $cache->invalidate('fleet', true);
    $cap->build();
}, $repeat);
$out['capacity_panel']['warm'] = $time(static fn () => $cap->build(), $repeat * 4);
$out['device_list'] = [
    'page_1' => $time(static fn () => $rm->listDevices([], null, 50, 0), $repeat),
    'page_100' => $time(static fn () => $rm->listDevices([], null, 50, 4950), $repeat),
    'online_filter' => $time(static fn () => $rm->listDevices(['status' => 'online'], null, 50, 0), $repeat),
];

// ---- graphs of one device: hourly tier only, then after the daily rollup
$days = min(90, $metricDays);
$out['graphs'] = ['window_days' => $days, 'hourly_only' => [
    'series_7d' => $time(static fn () => $sink->series($asset, 'cpu.utilization', null, $since(7), $until), $repeat * 3),
    'series_window' => $time(static fn () => $sink->series($asset, 'cpu.utilization', null, $since($days), $until), $repeat * 3),
    'peak_window' => $time(static fn () => $sink->peak($asset, 'cpu.utilization', null, $since($days)), $repeat * 3),
]];
$r = hrtime(true);
$e->clock->now();
$roll = (new MetricRollup($sql))->run(3600);
$out['rollup'] = ['seconds' => round((hrtime(true) - $r) / 1e9, 1)] + $roll;
$out['daily_rows'] = (int) $db->fetchOne('SELECT COUNT(*) c FROM rmm_metric_daily')['c'];
$out['graphs']['with_daily_tier'] = [
    'series_window' => $time(static fn () => $sink->series($asset, 'cpu.utilization', null, $since($days), $until), $repeat * 3),
    'peak_window' => $time(static fn () => $sink->peak($asset, 'cpu.utilization', null, $since($days)), $repeat * 3),
    'points_window' => count($sink->series($asset, 'cpu.utilization', null, $since($days), $until)),
];

// ---- retention of one day of the hourly history: batched DELETE against DROP PARTITION
$pruneRows = (int) $db->fetchOne('SELECT COUNT(*) c FROM rmm_metric_hourly WHERE hour_start < ?', [gmdate('Y-m-d H:i:s', intdiv($now, 86400) * 86400 - ($metricDays - 1) * 86400)])['c'];
if ($prune === 'delete' || $prune === 'both') {
    $t = hrtime(true);
    $deleted = $sink->prune(100000000, null, $metricDays - 1, 400);
    $out['retention_delete'] = ['rows_deleted' => $deleted, 'seconds' => round((hrtime(true) - $t) / 1e9, 2), 'statements_of_5000_rows' => (int) ceil($deleted / 5000)];
}
if ($prune === 'partition' || $prune === 'both') {
    $pm = new \RivetCore\Rmm\Storage\PartitionMaintenance($sql);
    $t = hrtime(true);
    $conv = $pm->convert('rmm_metric_hourly', 1, 7);
    $out['partition_convert'] = ['ok' => $conv['ok'], 'message' => $conv['message'], 'seconds' => round((hrtime(true) - $t) / 1e9, 2), 'partitions' => count($pm->partitions('rmm_metric_hourly'))];
    $t = hrtime(true);
    $mt = $pm->maintain(['rmm_metric_hourly' => $metricDays - 1, 'rmm_metric_daily' => 400, 'endpoint_agent_check_history' => 7], 1, 7);
    $out['retention_partition'] = ['dropped_partitions' => $mt['tables']['rmm_metric_hourly']['dropped'], 'created' => $mt['tables']['rmm_metric_hourly']['created'], 'rows_in_dropped_range' => $pruneRows,
        'seconds' => round((hrtime(true) - $t) / 1e9, 3), 'status' => $mt['tables']['rmm_metric_hourly']['status']];
}
$out['total_s'] = round(microtime(true) - $t0, 1);
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
