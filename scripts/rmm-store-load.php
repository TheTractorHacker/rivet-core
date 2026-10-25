<?php

declare(strict_types=1);

/**
 * Load smoke / measurement of the RMM hot-table STORE (phase 3 prep for the phase 5 load test of #89, docs/rmm/STORE_LOAD_TEST.md): runs the rmm-sim simulator against the
 * existing scripts/rmm-load server with the hot tables on MariaDB or on PostgreSQL and prints ONE JSON document.
 *
 *   RIVETCORE_TEST_DB_* (scratch MariaDB, the control database), RIVETCORE_TEST_PG_* (scratch PostgreSQL, for --store=postgres), [RIVETCORE_TEST_REDIS_PORT]
 *   php scripts/rmm-store-load.php --store=mariadb|postgres [--devices=200] [--duration=60] [--interval=6s] [--workers=8] [--concurrency=32] [--mode=sync|queued]
 *       [--docker-stats-file=FILE] [--out=FILE]
 *
 * Both databases are SCRATCH ones (the names must contain "scratch") and are EMPTIED. The simulator only talks to loopback. The database CPU and disk come from the
 * `docker stats` samples in --docker-stats-file when given (scripts/rmm-load/docker-sampler.sh writes them on the host, because the container that runs PHP has no docker),
 * otherwise only from the host's /proc (whole machine, includes everything else that runs). Every number depends on the machine and what else runs on it: compare the
 * two stores of ONE session, never two sessions, and never read a smoke as a capacity figure.
 */

require __DIR__ . '/../vendor/autoload.php';

use RivetCore\Tests\Support\LoadEdition;

$o = getopt('', ['store:', 'devices:', 'duration:', 'interval:', 'workers:', 'concurrency:', 'mode:', 'docker-stats-file:', 'out:']);
$store = (string) ($o['store'] ?? '');
if (!in_array($store, ['mariadb', 'postgres'], true)) {
    fwrite(STDERR, "Usage: php scripts/rmm-store-load.php --store=mariadb|postgres [--devices=200] [--duration=60] [--interval=6s] [--workers=8] [--concurrency=32] [--mode=sync|queued] [--docker-stats-file=F] [--out=F]\n");
    exit(2);
}
$devices = max(1, (int) ($o['devices'] ?? 200));
$duration = max(10, (int) ($o['duration'] ?? 60));
putenv('RMM_LOAD_STORE=' . $store);
$_ENV['RMM_LOAD_STORE'] = $store;
foreach (['RIVETCORE_TEST_DB_NAME', 'RIVETCORE_TEST_PG_NAME'] as $v) {
    if (($v === 'RIVETCORE_TEST_DB_NAME' || $store === 'postgres') && !str_contains((string) getenv($v), 'scratch')) {
        fwrite(STDERR, "Refusing: $v must name a scratch database\n");
        exit(2);
    }
}

$cpuTimes = static function (): array {
    $l = explode(' ', preg_replace('/\s+/', ' ', trim((string) strtok((string) @file_get_contents('/proc/stat'), "\n"))) ?? '');
    $v = array_map('intval', array_slice($l, 1));

    return ['busy' => array_sum($v) - ($v[3] ?? 0) - ($v[4] ?? 0), 'total' => array_sum($v)];
};
$sectorsWritten = static function (): int {
    $sum = 0;
    foreach (explode("\n", (string) @file_get_contents('/proc/diskstats')) as $line) {
        $f = preg_split('/\s+/', trim($line));
        if (is_array($f) && count($f) >= 10 && preg_match('/^(sd[a-z]+|vd[a-z]+|nvme\d+n\d+|xvd[a-z]+)$/D', $f[2]) === 1) {
            $sum += (int) $f[9];   // sectors written, whole disks only (partitions would count twice)
        }
    }

    return $sum;
};

$cpu0 = $cpuTimes();
$sec0 = $sectorsWritten();
$load0 = sys_getloadavg()[0];
$wall0 = microtime(true);
$cmd = [PHP_BINARY, __DIR__ . '/rmm-load/run.php', '--mode=' . ($o['mode'] ?? 'sync'), '--devices=' . $devices, '--interval=' . ($o['interval'] ?? '6s'), '--duration=' . $duration . 's',
    '--workers=' . (int) ($o['workers'] ?? 8), '--concurrency=' . (int) ($o['concurrency'] ?? 32)];
$proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => STDERR], $pipes, dirname(__DIR__), array_merge(getenv(), ['RMM_LOAD_STORE' => $store]));
$stdout = stream_get_contents($pipes[1]);
$code = proc_close($proc);
$wall = microtime(true) - $wall0;
$cpu1 = $cpuTimes();
$run = json_decode($stdout, true);
if (!is_array($run)) {
    fwrite(STDERR, "The load run produced no result (exit $code):\n$stdout\n");
    exit(1);
}

// versions
$e = new LoadEdition();
$versions = ['mariadb' => (string) ($e->db->fetchOne('SELECT VERSION() AS v')['v'] ?? '?')];
if ($store === 'postgres') {
    $pg = LoadEdition::pgConnection();
    $versions['postgres'] = $pg->serverVersion();
    $versions['timescaledb'] = $pg->value("SELECT extversion FROM pg_extension WHERE extname = 'timescaledb'");
}

// docker stats samples (taken on the host)
$docker = null;
$file = (string) ($o['docker-stats-file'] ?? '');
if ($file !== '' && is_file($file)) {
    $by = [];
    foreach (explode("\n", trim((string) file_get_contents($file))) as $line) {
        $r = json_decode($line, true);
        if (is_array($r) && isset($r['name'], $r['cpu_pct'])) {
            $by[$r['name']]['cpu'][] = (float) $r['cpu_pct'];
            $by[$r['name']]['mem'][] = (float) ($r['mem_mib'] ?? 0);
            $by[$r['name']]['block'][] = (string) ($r['block_io'] ?? '');
        }
    }
    $docker = [];
    foreach ($by as $name => $d) {
        sort($d['cpu']);
        $docker[$name] = ['samples' => count($d['cpu']), 'cpu_cores_mean' => round(array_sum($d['cpu']) / count($d['cpu']) / 100, 3), 'cpu_cores_max' => round(max($d['cpu']) / 100, 3),
            'mem_mib_max' => round(max($d['mem']), 1), 'block_io_first' => $d['block'][0], 'block_io_last' => end($d['block'])];
    }
}

$sim = $run['sim'] ?? [];
$secs = (float) ($run['duration_s'] ?? $duration);
$accepted = (int) ($run['checkins_accepted'] ?? 0);
$report = [
    'tool' => 'rmm-store-load', 'note' => 'a smoke on a shared, noisy host: compare the two stores of one session only; not a capacity figure',
    'store' => $store, 'versions' => $versions, 'php' => PHP_VERSION, 'when_utc' => gmdate('c'),
    'devices' => $devices, 'duration_s' => $secs, 'interval' => $run['interval'] ?? null, 'mode' => $run['mode'] ?? null, 'workers' => $run['workers'] ?? null, 'concurrency' => $run['concurrency'] ?? null,
    'throughput' => ['checkins_accepted' => $accepted, 'checkins_per_s' => round($accepted / max(0.001, $secs), 2), 'requests_per_s' => $sim['req_per_s'] ?? null],
    'latency_ms' => ['scope' => 'all requests of the simulator (check-in, jobs)', 'mean' => $sim['mean_ms'] ?? null, 'p50' => $sim['p50_ms'] ?? null, 'p95' => $sim['p95_ms'] ?? null, 'p99' => $sim['p99_ms'] ?? null, 'max' => $sim['max_ms'] ?? null],
    'errors' => ['error_pct' => $sim['error_pct'] ?? null, 'codes' => $sim['codes'] ?? null, 'enroll_failed' => $sim['enroll_failed'] ?? null, 'requests' => $sim['requests'] ?? null],
    'statements' => ['control_database_per_checkin' => $run['per_checkin'] ?? null, 'control_database_per_s' => $run['server_per_s'] ?? null,
        'postgres_per_checkin' => $run['postgres_per_checkin'] ?? null, 'postgres_per_s' => $run['postgres_per_s'] ?? null, 'postgres_cache_hit_pct' => $run['postgres_cache_hit_pct'] ?? null,
        'note' => 'MariaDB: server Questions (statements) delta. PostgreSQL: transactions and tuples from pg_stat_database (statements are not counted without pg_stat_statements). The control-database figure on a postgres run is only the control part.'],
    'hot_rows_after' => $run['hot_rows_after'] ?? null,
    'cpu_disk' => [
        'source' => $docker !== null ? 'docker stats samples of the database containers (host) plus /proc of this machine' : '/proc of this machine only (everything that runs on it)',
        'host_cpu_busy_pct' => round(100 * ($cpu1['busy'] - $cpu0['busy']) / max(1, $cpu1['total'] - $cpu0['total']), 1),
        'host_disk_mib_written' => round(($sectorsWritten() - $sec0) * 512 / 1048576, 1),
        'php_workers_cores' => $run['cpu_cores_used']['php_workers'] ?? null,
        'database_containers' => $docker,
    ],
    'host_load_avg_1m' => ['start' => round($load0, 2), 'end' => round(sys_getloadavg()[0], 2)],
    'integrity' => $run['integrity'] ?? null,
    'wall_s' => round($wall, 1),
];
$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
if (isset($o['out'])) {
    file_put_contents((string) $o['out'], $json);
}
echo $json;
exit($code === 0 ? 0 : 1);
