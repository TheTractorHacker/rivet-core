<?php

declare(strict_types=1);

/**
 * Throughput of the metric write and rollup paths on the MariaDB store and on the PostgreSQL store, with the SAME input (phase 2 of #89, docs/design/rmm-data-store.md 12.10).
 *
 *   RIVETCORE_TEST_DB_NAME=rivetcore_scratch_x RIVETCORE_TEST_DB_USER=... RIVETCORE_TEST_DB_PASS=... [RIVETCORE_TEST_DB_HOST=..] [RIVETCORE_TEST_DB_PORT=..] \
 *   RIVETCORE_TEST_PG_NAME=rivet_scratch RIVETCORE_TEST_PG_USER=... RIVETCORE_TEST_PG_PASS=... [RIVETCORE_TEST_PG_HOST=..] [RIVETCORE_TEST_PG_PORT=..] \
 *   php scripts/rmm-store-bench.php [--devices=500] [--days=3] [--repeat=3] [--json]
 *
 * What it measures, per store: ingest of check-in sized batches (one device, ~25 samples: the real shape), the daily rollup of the days that ingest produced, point reads (`latest`,
 * `peak`, `series`) and the retention prune. The numbers depend on the machine, the container, the disks and what else runs; compare the two columns of ONE run, not two runs.
 *
 * SAFETY: it EMPTIES the metric tables of both scratch databases; both database names must contain "scratch".
 */

require __DIR__ . '/../vendor/autoload.php';

use RivetCore\Rmm\Store\Postgres\Pdo\PdoPostgresConnection;
use RivetCore\Rmm\Store\Postgres\PostgresConfig;
use RivetCore\Rmm\Store\Postgres\PostgresMetricStore;
use RivetCore\Rmm\Store\Postgres\PostgresMigrator;
use RivetCore\Rmm\Store\MetricStore;
use RivetCore\Rmm\Support\DatabaseMetricSink;
use RivetCore\Tests\Support\MutableClock;
use RivetCore\Tests\Support\MysqliDatabase;
use RivetCore\Tests\Support\ScratchDb;

$opts = ['devices' => 500, 'days' => 3, 'repeat' => 3, 'json' => false];
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--json') {
        $opts['json'] = true;
    } elseif (preg_match('/^--(devices|days|repeat)=(\d+)$/D', $a, $m) === 1) {
        $opts[$m[1]] = max(1, (int) $m[2]);
    } else {
        fwrite(STDERR, "Unknown argument $a\n");
        exit(2);
    }
}
foreach (['RIVETCORE_TEST_DB_NAME', 'RIVETCORE_TEST_PG_NAME'] as $v) {
    if (!str_contains((string) getenv($v), 'scratch')) {
        fwrite(STDERR, "$v must name a scratch database.\n");
        exit(2);
    }
}
$mysqli = ScratchDb::connect();
$maria = new MysqliDatabase($mysqli);
foreach (['rmm_metric_latest', 'rmm_metric_hourly', 'rmm_metric_daily', 'rmm_storage_state', 'rmm_metric_rollup_state'] as $t) {
    if ($mysqli->query("SHOW TABLES LIKE '$t'")->num_rows === 0) {
        fwrite(STDERR, "MariaDB table $t is missing: run the Core migrations on the scratch database first (the test harness does).\n");
        exit(2);
    }
    $mysqli->query("DELETE FROM `$t`");
}
$cfg = PostgresConfig::fromArray(['database' => (string) getenv('RIVETCORE_TEST_PG_NAME'), 'user' => (string) getenv('RIVETCORE_TEST_PG_USER'), 'password' => (string) getenv('RIVETCORE_TEST_PG_PASS'),
    'host' => (string) (getenv('RIVETCORE_TEST_PG_HOST') ?: '127.0.0.1'), 'port' => (int) (getenv('RIVETCORE_TEST_PG_PORT') ?: 5432), 'sslmode' => 'disable', 'statement_timeout_ms' => 0]);
$pg = new PdoPostgresConnection($cfg);
(new PostgresMigrator($pg))->migrate(getenv('RIVETCORE_TEST_PG_MODE') ?: 'auto');
$pg->execute('TRUNCATE rmm_metric_latest, rmm_metric_hourly, rmm_metric_daily, rmm_metric_rollup_state');

$clock = new MutableClock();
$stores = ['mariadb' => new DatabaseMetricSink($maria, $clock), 'postgres' => new PostgresMetricStore($pg, $clock)];
$now = $clock->now()->getTimestamp();
$keys = ['cpu.utilization', 'memory.utilization', 'disk.utilization', 'disk.free_bytes', 'network.rx_bytes_per_s', 'network.tx_bytes_per_s', 'system.uptime_seconds'];

/** One check-in worth of samples for a device at a time (disks and cores add instances): about 25 samples, 10 of them with an hourly rollup. */
$batch = static function (int $asset, int $at) use ($keys): array {
    $s = [];
    foreach ($keys as $k) {
        $s[] = ['asset_id' => $asset, 'key' => $k, 'instance' => null, 'value' => ($asset * 7 + $at) % 100, 'at' => new DateTimeImmutable('@' . $at), 'label' => null];
    }
    foreach (['C:', 'D:', 'E:'] as $d) {
        foreach (['disk.utilization', 'disk.free_bytes'] as $k) {
            $s[] = ['asset_id' => $asset, 'key' => $k, 'instance' => $d, 'value' => ($asset + ord($d[0])) % 100, 'at' => new DateTimeImmutable('@' . $at), 'label' => 'Disk ' . $d];
        }
    }
    for ($c = 0; $c < 12; ++$c) {
        $s[] = ['asset_id' => $asset, 'key' => 'cpu.core.utilization', 'instance' => (string) $c, 'value' => ($asset + $c * 3) % 100, 'at' => new DateTimeImmutable('@' . $at), 'label' => null];
    }

    return $s;
};

$median = static function (array $v): float {
    sort($v);

    return $v[intdiv(count($v), 2)];
};
$results = [];
foreach ($stores as $name => $store) {
    $ingest = $rollup = $reads = $prune = [];
    $rows = 0;
    for ($rep = 0; $rep < $opts['repeat']; ++$rep) {
        $maria->execute('DELETE FROM rmm_metric_latest');
        $maria->execute('DELETE FROM rmm_metric_hourly');
        $maria->execute('DELETE FROM rmm_metric_daily');
        $maria->execute('DELETE FROM rmm_storage_state');
        $maria->execute('DELETE FROM rmm_metric_rollup_state');
        $pg->execute('TRUNCATE rmm_metric_latest, rmm_metric_hourly, rmm_metric_daily, rmm_metric_rollup_state');
        // ingest: every device checks in every 5 minutes for the number of days, newest day last (the order of real life)
        $t0 = hrtime(true);
        $n = 0;
        for ($d = $opts['days']; $d >= 1; --$d) {
            $dayStart = intdiv($now, 86400) * 86400 - $d * 86400;
            for ($slot = 0; $slot < 288; $slot += 12) {   // one check-in an hour per device is enough to fill the hourly tier; five minutes would only repeat the upsert
                for ($dev = 1; $dev <= $opts['devices']; ++$dev) {
                    $store->ingest($batch($dev, $dayStart + $slot * 300 + $dev % 300), 1);
                    ++$n;
                }
            }
        }
        $ingest[] = $n / ((hrtime(true) - $t0) / 1e9);
        $rows = $n;
        $t0 = hrtime(true);
        $r = $store->rollUp(600);
        $rollup[] = (hrtime(true) - $t0) / 1e9;
        $t0 = hrtime(true);
        $reads_n = 0;
        for ($dev = 1; $dev <= min(200, $opts['devices']); ++$dev) {
            $store->latest($dev);
            $store->peak($dev, 'cpu.utilization', null, new DateTimeImmutable('@' . ($now - 2 * 86400)));
            $store->series($dev, 'cpu.utilization', null, new DateTimeImmutable('@' . ($now - $opts['days'] * 86400)), new DateTimeImmutable('@' . $now));
            $reads_n += 3;
        }
        $reads[] = $reads_n / ((hrtime(true) - $t0) / 1e9);
        $t0 = hrtime(true);
        $deleted = $store->prune(10_000_000, null, 1, 1);
        $prune[] = $deleted / max(1e-9, (hrtime(true) - $t0) / 1e9);
    }
    $results[$name] = ['checkins_per_s' => $median($ingest), 'samples_per_s' => $median($ingest) * count($batch(1, 0)), 'rollup_s' => $median($rollup), 'rollup_days' => $r['days'],
        'reads_per_s' => $median($reads), 'prune_rows_per_s' => $median($prune), 'checkins' => $rows];
}
$out = ['devices' => $opts['devices'], 'days' => $opts['days'], 'repeat' => $opts['repeat'], 'php' => PHP_VERSION, 'postgres' => $pg->serverVersion(), 'mariadb' => (string) $maria->fetchOne('SELECT VERSION() v')['v'], 'results' => $results];
if ($opts['json']) {
    echo json_encode($out, JSON_PRETTY_PRINT), "\n";
    exit(0);
}
printf("devices %d, days %d, %d check-ins per run, median of %d runs; PHP %s, MariaDB %s, PostgreSQL %s\n\n", $opts['devices'], $opts['days'], $rows, $opts['repeat'], PHP_VERSION, $out['mariadb'], $out['postgres']);
printf("| %-34s | %12s | %12s |\n|%s|%s|%s|\n", 'path', 'MariaDB', 'PostgreSQL', str_repeat('-', 36), str_repeat('-', 14), str_repeat('-', 14));
foreach ([['check-ins ingested per second', 'checkins_per_s', '%12.0f'], ['samples ingested per second', 'samples_per_s', '%12.0f'], ['daily rollup, seconds', 'rollup_s', '%12.2f'], ['point reads per second', 'reads_per_s', '%12.0f'], ['rows pruned per second', 'prune_rows_per_s', '%12.0f']] as [$label, $k, $f]) {
    printf("| %-34s | $f | $f |\n", $label, $results['mariadb'][$k], $results['postgres'][$k]);
}
