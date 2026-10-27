<?php

declare(strict_types=1);

/**
 * Replays (or re-records) the golden transcripts through RivetCore\Rmm\Http\DeviceApi on a scratch database.
 *
 *   RIVETCORE_TEST_DB_NAME=..._scratch_... RIVETCORE_TEST_DB_USER=... RIVETCORE_TEST_DB_PASS=... RIVETCORE_TEST_REDIS_PORT=6362 \
 *     php scripts/rmm-golden/replay-core.php [replay|record] [--dir=<golden dir>] [--ignore-key-order]
 *
 * It starts two `php -S` servers on free loopback ports (the main one tolerates plain http like RivetIT's EA_ALLOW_INSECURE_HTTP test
 * install; the second one does not, for the 426 transcripts), runs scripts/rmm-golden/golden.php against them with adapter-core.php, and
 * stops them. Exit code and output are golden.php's (0 = "replay identical (10 files)"). Needs a throwaway Redis for the per-device 429s.
 */

$mode = ($argv[1] ?? 'replay') === 'record' ? 'record' : 'replay';
$extra = array_values(array_filter(array_slice($argv, 1), static fn (string $a): bool => str_starts_with($a, '--')));
if (!str_contains((string) getenv('RIVETCORE_TEST_DB_NAME'), 'scratch')) {
    fwrite(STDERR, "Refusing: RIVETCORE_TEST_DB_NAME must name a scratch database\n");
    exit(2);
}
$here = __DIR__;
$state = sys_get_temp_dir() . '/rmm_golden_core_' . bin2hex(random_bytes(4));
mkdir($state, 0700);

// Four-digit ports on purpose: the stamped installer payload carries the server URL, so its length (a recorded fact of the
// transcripts, which were taken on :8680/:8681) depends on the number of digits of the port.
$free = static function () use (&$taken): int {
    for ($i = 0; $i < 200; ++$i) {
        $port = random_int(8200, 9899);
        if (in_array($port, $taken ?? [], true)) {
            continue;
        }
        $sock = @stream_socket_server("tcp://127.0.0.1:$port", $en, $es);
        if ($sock !== false) {
            fclose($sock);
            $taken[] = $port;

            return $port;
        }
    }
    fwrite(STDERR, "no free four-digit port\n");
    exit(2);
};
$taken = [];
$procs = [];
$start = static function (int $port, bool $insecure) use ($here, $state, &$procs): void {
    $log = $state . "/server_$port.log";
    $env = array_merge(getenv(), ['RMM_GOLDEN_STATE' => $state, 'RMM_GOLDEN_INSECURE' => $insecure ? '1' : '0']);
    $p = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', "error_log=$log", '-S', "127.0.0.1:$port", "$here/core-router.php"],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes, $here, $env);
    $procs[] = $p;
    for ($i = 0; $i < 80; ++$i) {
        if (@fsockopen('127.0.0.1', $port)) {
            return;
        }
        usleep(100000);
    }
    fwrite(STDERR, "server on $port did not start\n");
    exit(2);
};
$main = $free();
$tls = $free();
$start($main, true);
$start($tls, false);

$cmd = array_merge([PHP_BINARY, "$here/golden.php", $mode, "--base=http://127.0.0.1:$main", "--tls-base=http://127.0.0.1:$tls", "--install=$state", "--adapter=$here/adapter-core.php"], $extra);
$p = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR], $pipes, $here, array_merge(getenv(), ['RMM_GOLDEN_STATE' => $state]));
$code = proc_close($p);
foreach ($procs as $proc) {
    proc_terminate($proc);
    proc_close($proc);
}
$logs = '';
foreach (glob("$state/server_*.log") ?: [] as $f) {
    $logs .= (string) file_get_contents($f);
}
if (trim($logs) !== '') {
    fwrite(STDERR, "server log:\n" . $logs);
}
foreach (glob("$state/bin/*") ?: [] as $f) {
    @unlink($f);
}
foreach (glob("$state/*") ?: [] as $f) {
    is_file($f) && @unlink($f);
}
@rmdir("$state/bin");
@rmdir($state);
exit($code);
