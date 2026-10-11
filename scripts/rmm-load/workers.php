<?php

declare(strict_types=1);

/**
 * How fast do `rmm.ingest` workers drain a backlog, and does adding workers help (scaling item S6)? Builds a backlog of queued check-ins by running rmm-sim against a
 * `php -S` server WITHOUT a worker, then drains the same backlog with 1, 2, 4 ... worker processes (`load.php worker`, i.e. IngestQueue::drain() in a loop) and
 * prints jobs per second per configuration, the claiming strategy in use, and whether any job was done twice or lost. Scratch databases only.
 *
 *   php scripts/rmm-load/workers.php [--devices=1000] [--seconds=25] [--workers=1,2,4] [--sim=endpoint-agent/dist/rmm-sim] [--batch=50]
 */

$o = getopt('', ['devices:', 'seconds:', 'workers:', 'sim:', 'batch:', 'port:']);
$devices = (int) ($o['devices'] ?? 1000);
$seconds = (int) ($o['seconds'] ?? 25);
$workers = array_map('intval', explode(',', (string) ($o['workers'] ?? '1,2,4')));
$port = (int) ($o['port'] ?? random_int(8800, 8899));
$root = dirname(__DIR__, 2);
$sim = $o['sim'] ?? "$root/endpoint-agent/dist/rmm-sim";
if (!str_contains((string) getenv('RIVETCORE_TEST_DB_NAME'), 'scratch')) {
    fwrite(STDERR, "Refusing: RIVETCORE_TEST_DB_NAME must name a scratch database\n");
    exit(2);
}
$load = "$root/scripts/rmm-load/load.php";
$php = static fn (array $args): string => (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1');
$settings = json_encode(['limits_json' => ['ingest_batch' => (int) ($o['batch'] ?? 50)]]);
$token = trim($php([$load, 'setup', (string) $devices, 'queued', (string) $settings]));
if (!str_starts_with($token, 'rvte1.')) {
    fwrite(STDERR, "setup failed: $token\n");
    exit(1);
}
@mkdir('/tmp/claude-1000/t9', 0777, true);
$env = array_merge(getenv(), ['PHP_CLI_SERVER_WORKERS' => '8', 'RMM_LOAD_TRUST_SIM_IP' => '1', 'RMM_LOAD_STATE_DIR' => '']);
$srv = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-S', "127.0.0.1:$port", "$root/scripts/rmm-load/router.php"], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', "/tmp/claude-1000/t9/server_$port.log", 'a']], $p, $root, $env);
for ($i = 0; $i < 100 && !@fsockopen('127.0.0.1', $port); ++$i) {
    usleep(100000);
}
$simOut = (string) shell_exec(escapeshellarg($sim) . " -url http://127.0.0.1:$port/api/v1/ -token " . escapeshellarg($token) . " -devices $devices -interval {$seconds}s -duration {$seconds}s -concurrency 32 -insecure -json -herd -batches 4 -client-ip-header X-Sim-Client-Ip -retry-scale 0.02 2>/dev/null");
proc_terminate($srv, 15);
proc_close($srv);
$stats = json_decode(trim($php([$load, 'stats'])), true);
$backlog = (int) ($stats['capacity']['queue']['pending'] ?? 0);
$out = ['devices' => $devices, 'backlog_jobs' => $backlog, 'server' => null, 'runs' => []];
if ($backlog < 100) {
    fwrite(STDERR, "backlog too small ($backlog): is the queue in queued mode?\n");
    exit(1);
}
$mysqli = new mysqli(getenv('RIVETCORE_TEST_DB_HOST') ?: 'localhost', (string) getenv('RIVETCORE_TEST_DB_USER'), (string) getenv('RIVETCORE_TEST_DB_PASS'), (string) getenv('RIVETCORE_TEST_DB_NAME'), (int) (getenv('RIVETCORE_TEST_DB_PORT') ?: 0));
$out['server'] = $mysqli->query('SELECT VERSION() v')->fetch_assoc()['v'];
foreach ($workers as $w) {
    $mysqli->query("UPDATE integration_jobs SET status = 'pending', attempts = 0, started_at = NULL, completed_at = NULL, result = NULL, error = NULL, available_at = NOW() WHERE job_type = 'rmm.ingest'");
    $mysqli->query('DELETE FROM load_metrics');
    $t0 = microtime(true);
    $procs = [];
    for ($i = 0; $i < $w; ++$i) {
        $procs[] = proc_open([PHP_BINARY, $load, 'worker', '600'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pp, $root, getenv());
    }
    $deadline = $t0 + 600;
    do {
        usleep(200000);
        $left = (int) $mysqli->query("SELECT COUNT(*) c FROM integration_jobs WHERE job_type = 'rmm.ingest' AND status IN ('pending','running')")->fetch_assoc()['c'];
    } while ($left > 0 && microtime(true) < $deadline);
    $secs = microtime(true) - $t0;
    foreach ($procs as $pr) {
        proc_terminate($pr, 15);
        proc_close($pr);
    }
    $done = (int) $mysqli->query("SELECT COUNT(*) c FROM integration_jobs WHERE job_type = 'rmm.ingest' AND status = 'completed'")->fetch_assoc()['c'];
    $twice = (int) $mysqli->query("SELECT COUNT(*) c FROM integration_jobs WHERE job_type = 'rmm.ingest' AND attempts <> 1")->fetch_assoc()['c'];
    $out['runs'][] = ['workers' => $w, 'seconds' => round($secs, 2), 'jobs_per_s' => round($done / max(0.001, $secs), 1), 'jobs_per_s_per_worker' => round($done / max(0.001, $secs) / $w, 1),
        'completed' => $done, 'attempts_not_one' => $twice, 'left' => $left];
}
echo json_encode($out, JSON_PRETTY_PRINT), "\n";
