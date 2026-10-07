<?php

declare(strict_types=1);

/**
 * One load measurement (S1): scratch database + `php -S` (N workers) + the rmm-sim simulator, with the database server's own counters
 * read before and after. Prints one JSON document. Scratch only: RIVETCORE_TEST_DB_NAME must contain "scratch", and the simulator only
 * talks to loopback.
 *
 *   php scripts/rmm-load/run.php --mode=sync|queued --devices=200 --interval=6s --duration=120s [--workers=8] [--concurrency=32]
 *       [--batches=4] [--settings='{"collect_interval_s":60}'] [--sim=endpoint-agent/dist/rmm-sim] [--herd] [--state-dir=<dir>] [--port=8700]
 *       [--drain-seconds=120] [--no-teardown] [--poll-shed]
 *
 * The statements per check-in are the server's Questions delta divided by the check-ins that were accepted (the device rows' last_seq
 * sums), minus the counter queries of the harness itself, which are negligible against thousands of requests.
 */

$o = getopt('', ['mode:', 'devices:', 'interval:', 'duration:', 'workers:', 'concurrency:', 'batches:', 'settings:', 'sim:', 'herd', 'state-dir:', 'port:', 'drain-seconds:', 'no-teardown', 'retry-scale:', 'poll-shed']);
$mode = $o['mode'] ?? 'sync';
$devices = (int) ($o['devices'] ?? 200);
$interval = $o['interval'] ?? '6s';
$duration = $o['duration'] ?? '120s';
$workers = (int) ($o['workers'] ?? 8);
$conc = (int) ($o['concurrency'] ?? 32);
$port = (int) ($o['port'] ?? random_int(8700, 8799));
$root = dirname(__DIR__, 2);
$sim = $o['sim'] ?? "$root/endpoint-agent/dist/rmm-sim";
if (!str_contains((string) getenv('RIVETCORE_TEST_DB_NAME'), 'scratch')) {
    fwrite(STDERR, "Refusing: RIVETCORE_TEST_DB_NAME must name a scratch database\n");
    exit(2);
}
$php = static fn (array $args): string => (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1');
$load = "$root/scripts/rmm-load/load.php";
$token = trim($php([$load, 'setup', (string) $devices, $mode, $o['settings'] ?? '{}']));
if (!str_starts_with($token, 'rvte1.')) {
    fwrite(STDERR, "setup failed: $token\n");
    exit(1);
}
$stateDir = $o['state-dir'] ?? '';
$env = array_merge(getenv(), ['PHP_CLI_SERVER_WORKERS' => (string) $workers, 'RMM_LOAD_TRUST_SIM_IP' => '1', 'RMM_LOAD_STATE_DIR' => $stateDir]);
$srv = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'opcache.enable_cli=1', '-d', 'opcache.jit=off', '-S', "127.0.0.1:$port", "$root/scripts/rmm-load/router.php"],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', "/tmp/claude-1000/t9/server_$port.log", 'a']], $p1, $root, $env);
for ($i = 0; $i < 100 && !@fsockopen('127.0.0.1', $port); ++$i) {
    usleep(100000);
}
$worker = null;
if ($mode === 'queued') {
    $worker = proc_open([PHP_BINARY, $load, 'worker', (string) ((int) $duration + 600)], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $p2, $root, $env);
}
/** CPU seconds (user + system) of a process and, optionally, its direct children (the `php -S` workers). */
$cpu = static function (int $pid, bool $children = false): float {
    $ticks = 0;
    $read = static function (string $f): int {
        $raw = @file_get_contents($f);
        if (!is_string($raw)) {
            return 0;
        }
        $f = explode(' ', substr($raw, (int) strrpos($raw, ')') + 2));

        return (int) ($f[11] ?? 0) + (int) ($f[12] ?? 0);   // utime + stime (fields 14 and 15 of /proc/<pid>/stat)
    };
    $ticks += $read("/proc/$pid/stat");
    if ($children) {
        foreach (glob('/proc/[0-9]*/stat') ?: [] as $f) {
            $raw = @file_get_contents($f);
            if (is_string($raw) && (int) (explode(' ', substr($raw, (int) strrpos($raw, ')') + 2))[1] ?? 0) === $pid) {
                $ticks += $read($f);
            }
        }
    }

    return $ticks / 100;
};
$dbPid = (int) trim((string) @file_get_contents((string) (getenv('RMM_LOAD_DB_PIDFILE') ?: '')));
$srvPid = (int) (proc_get_status($srv)['pid'] ?? 0);
$counters = static fn (): array => json_decode(trim($php([$load, 'counters'])), true) ?: [];
$simArgs = [$sim, '-url', "http://127.0.0.1:$port/api/v1/", '-token', $token, '-devices', (string) $devices, '-interval', $interval, '-duration', $duration, '-concurrency', (string) $conc,
    '-insecure', '-json', '-batches', (string) ($o['batches'] ?? 4), '-client-ip-header', 'X-Sim-Client-Ip', '-report', isset($o['poll-shed']) ? '5s' : '30s', '-retry-scale', (string) ($o['retry-scale'] ?? '0.02')];
if (isset($o['herd'])) {
    $simArgs[] = '-herd';
}
$proc = proc_open(array_merge($simArgs), [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $root);
$c0 = $c1 = [];
$timeline = [];
$cpu0 = $cpu1 = [0.0, 0.0];
$load0 = $load1 = 0.0;
$t0 = $t1 = 0.0;
$last = '';
while (($line = fgets($pipes[1])) !== false) {
    $line = rtrim($line);
    if ($line === 'rmm-sim: measurement start') {
        $c0 = $counters();
        $cpu0 = [$dbPid > 0 ? $cpu($dbPid) : 0.0, $cpu($srvPid, true)];
        $load0 = sys_getloadavg()[0];
        $t0 = microtime(true);
        $stats0 = json_decode(trim($php([$load, 'stats'])), true);
    } elseif ($line === 'rmm-sim: measurement end') {
        $c1 = $counters();
        $t1 = microtime(true);
        $cpu1 = [$dbPid > 0 ? $cpu($dbPid) : 0.0, $cpu($srvPid, true)];
        $load1 = sys_getloadavg()[0];
        $stats1 = json_decode(trim($php([$load, 'stats'])), true);
    } elseif (isset($o['poll-shed']) && str_starts_with($line, 't=')) {
        $sh = json_decode(trim($php([$load, 'shed'])), true) ?: [];
        $timeline[] = ['level' => $sh['level'] ?? null, 'backlog' => $sh['backlog'] ?? null, 'line' => preg_replace('/\s+/', ' ', $line)];
        fwrite(STDERR, $line . "  shed=" . ($sh['level'] ?? '?') . " backlog=" . ($sh['backlog'] ?? '?') . "\n");
    } elseif (str_starts_with($line, '{')) {
        $last = $line;
    } else {
        fwrite(STDERR, $line . "\n");
    }
}
proc_close($proc);
$simResult = json_decode($last, true) ?: [];
// queued mode: let the worker finish the backlog, and time it
$drain = null;
if ($mode === 'queued') {
    $d0 = microtime(true);
    $deadline = $d0 + (int) ($o['drain-seconds'] ?? 120);
    do {
        $st = json_decode(trim($php([$load, 'stats'])), true);
        $pending = (int) ($st['capacity']['queue']['pending'] ?? 0) + (int) ($st['capacity']['queue']['running'] ?? 0);
        if ($pending === 0) {
            break;
        }
        usleep(500000);
    } while (microtime(true) < $deadline);
    $drain = ['seconds_to_drain_after_run' => round(microtime(true) - $d0, 1), 'left' => $pending];
}
$final = json_decode(trim($php([$load, 'stats'])), true);
$secs = max(0.001, $t1 - $t0);
$delta = static fn (string $k): int => (int) ($c1[$k] ?? 0) - (int) ($c0[$k] ?? 0);
$accepted = max(1, (int) ($stats1['checkin_rows'] ?? 0) - (int) ($stats0['checkin_rows'] ?? 0));
$out = [
    'mode' => $mode, 'devices' => $devices, 'interval' => $interval, 'duration_s' => round($secs, 1), 'workers' => $workers, 'concurrency' => $conc,
    'sim' => $simResult,
    'checkins_accepted' => $accepted,
    'cpu_cores_used' => ['database' => round(($cpu1[0] - $cpu0[0]) / $secs, 3), 'php_workers' => round(($cpu1[1] - $cpu0[1]) / $secs, 3)],
    'host_load_avg_1m' => ['start' => round($load0, 2), 'end' => round($load1, 2)],
    'server_per_s' => [
        'statements' => round($delta('Questions') / $secs, 1), 'selects' => round($delta('Com_select') / $secs, 1), 'inserts' => round($delta('Com_insert') / $secs, 1),
        'updates' => round($delta('Com_update') / $secs, 1), 'deletes' => round($delta('Com_delete') / $secs, 1), 'commits' => round($delta('Com_commit') / $secs, 1),
        'rows_inserted' => round((($stats1['checkin_rows'] + $stats1['metrics_rows']) - ($stats0['checkin_rows'] + $stats0['metrics_rows'])) / $secs, 1),
        'handler_updates' => round($delta('Handler_update') / $secs, 1),
    ],
    'per_checkin' => [
        'statements' => round($delta('Questions') / $accepted, 1), 'selects' => round($delta('Com_select') / $accepted, 1), 'inserts' => round($delta('Com_insert') / $accepted, 1),
        'updates' => round($delta('Com_update') / $accepted, 1), 'metric_rows' => round((($stats1['metrics_rows']) - ($stats0['metrics_rows'])) / $accepted, 1),
        'rows_inserted' => round((($stats1['checkin_rows'] + $stats1['metrics_rows']) - ($stats0['checkin_rows'] + $stats0['metrics_rows'])) / $accepted, 1),
    ],
    'drain' => $drain,
    'integrity' => ['duplicate_seq' => $final['duplicate_seq'], 'device_seq_mismatch' => $final['device_seq_mismatch'], 'metrics_rows' => $final['metrics_rows'], 'checkin_rows' => $final['checkin_rows']],
    'queue_after' => $final['capacity']['queue'] ?? null,
    'shed_level_after' => $final['capacity']['shed']['level'] ?? null,
    'shed_timeline' => $timeline === [] ? null : array_map(static fn (array $r): array => ['level' => $r['level'], 'backlog' => $r['backlog']], $timeline),
];
echo json_encode($out, JSON_PRETTY_PRINT), "\n";
if (!isset($o['no-teardown'])) {
    proc_terminate($srv, 15);
    proc_close($srv);
    if ($worker !== null) {
        proc_terminate($worker, 15);
        proc_close($worker);
    }
}
