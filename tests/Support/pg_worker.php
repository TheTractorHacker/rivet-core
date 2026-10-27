<?php

declare(strict_types=1);

// A child process of the PostgreSQL concurrency tests: `php pg_worker.php <job> <json arguments>`; prints one JSON line with its result.

use RivetCore\Rmm\Store\Postgres\PostgresLogStore;
use RivetCore\Rmm\Store\Postgres\PostgresMetricStore;
use RivetCore\Tests\Support\MutableClock;
use RivetCore\Tests\Support\PostgresTestDatabase;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$job = (string) ($argv[1] ?? '');
$a = json_decode((string) ($argv[2] ?? '{}'), true);
$db = PostgresTestDatabase::connect();
$out = ['job' => $job, 'errors' => [], 'inserted' => 0, 'deleted' => 0];
try {
    switch ($job) {
        case 'logs':
            $store = new PostgresLogStore($db);
            $caps = ['rate_per_min' => 1_000_000, 'device_max_rows' => 1_000_000, 'fleet_max_rows' => 10_000_000];
            for ($b = 0; $b < $a['batches']; ++$b) {
                $entries = [];
                for ($i = 0; $i < $a['per_batch']; ++$i) {
                    $key = $a['name'] . '-' . $b . '-' . $i;
                    $entries[] = ['ts' => $a['t'] + $b * 100 + $i, 'level' => 4, 'source' => 'S', 'code' => null, 'message' => $key, 'hash' => substr(hash('sha256', $key), 0, 32)];
                }
                foreach ($a['shared'] ?? [] as $s) {
                    if ($b === 0) {
                        $entries[] = ['ts' => $a['t'] - 1000 + $s, 'level' => 4, 'source' => 'S', 'code' => null, 'message' => 'shared-' . $s, 'hash' => substr(hash('sha256', 'shared-' . $s), 0, 32)];
                    }
                }
                $out['inserted'] += $store->admit($a['device'], $entries, $caps, $a['t'] + $b, 0, 0, 7)['inserted'];
            }
            break;
        case 'prune':
            $store = new PostgresLogStore($db);
            for ($i = 0; $i < $a['rounds']; ++$i) {
                $r = $store->enforceLimits(gmdate('Y-m-d H:i:s', $a['cutoff']), $a['device_max'], 10_000_000, 100000, $a['batch']);
                $out['deleted'] += $r['retention'] + $r['device_cap'] + $r['fleet_cap'];
                usleep(10000);
            }
            break;
        case 'metrics':
            $store = new PostgresMetricStore($db, new MutableClock());
            for ($i = 0; $i < $a['times']; ++$i) {
                $store->ingest([['asset_id' => $a['asset'], 'key' => 'cpu.utilization', 'instance' => null, 'value' => $a['value'], 'at' => new DateTimeImmutable('@' . $a['at']), 'label' => $a['name']]], 1);
                ++$out['inserted'];
            }
            break;
        case 'spread':
            // distinct series (one row each) plus one series upserted over and over, for as long as `count` says
            $store = new PostgresMetricStore($db, new MutableClock());
            for ($i = 0; $i < $a['count']; ++$i) {
                $store->ingest([
                    ['asset_id' => $a['base'] + $i, 'key' => 'cpu.utilization', 'instance' => null, 'value' => 10, 'at' => new DateTimeImmutable('@' . $a['at']), 'label' => null],
                    ['asset_id' => $a['base'], 'key' => 'memory.utilization', 'instance' => 'same', 'value' => 20, 'at' => new DateTimeImmutable('@' . $a['at']), 'label' => null],
                ], 1);
                ++$out['inserted'];
                usleep(2000);
            }
            break;
        default:
            $out['errors'][] = 'unknown job';
    }
} catch (Throwable $e) {
    $out['errors'][] = get_class($e) . ': ' . $e->getMessage();
}
echo json_encode($out), "\n";
