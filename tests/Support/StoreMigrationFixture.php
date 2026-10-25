<?php

declare(strict_types=1);

namespace RivetCore\Tests\Support;

use RivetCore\Rmm\Store\Migration\HotTable;
use RivetCore\Rmm\Store\Migration\HotTables;
use RivetCore\Rmm\Store\Migration\StoreEndpoint;

/** Synthetic hot-table content for the migration tests: deterministic, every table and every column type, ledgers consistent with their events. */
final class StoreMigrationFixture
{
    public const DEVICES = 40;

    /**
     * @param int $scale 1 = about 5,000 rows; 10 = about 50,000
     * @return array<string,int> rows per table
     */
    public static function fill(StoreEndpoint $ep, int $now, int $scale = 1): array
    {
        $ts = static fn (int $secondsAgo): string => gmdate('Y-m-d H:i:s', $now - $secondsAgo);
        $hour = $now - $now % 3600;
        $out = [];
        $put = function (string $table, array $rows) use ($ep, &$out): void {
            $t = HotTables::get($table);
            foreach (array_chunk($rows, 2000) as $chunk) {
                $ep->insert($t, $chunk, 'ignore', true);
            }
            $out[$table] = ($out[$table] ?? 0) + count($rows);
        };

        $rows = [];
        for ($i = 1; $i <= 1500 * $scale; ++$i) {
            $rows[] = ['hist_id' => $i, 'device_id' => $i % self::DEVICES + 1, 'check_key' => 'chk' . ($i % 7), 'status' => ['ok', 'warn', 'fail'][$i % 3], 'detail' => "detail $i ’", 'reported_at' => $ts(30 + ($i * 7) % 86000)];
        }
        $put('endpoint_agent_check_history', $rows);

        foreach (['log' => ['endpoint_agent_log_events', 'event_id', 'endpoint_agent_log_state', 1500], 'change' => ['endpoint_agent_change_records', 'change_id', 'endpoint_agent_change_state', 800]] as $kind => [$events, $idCol, $ledger, $n]) {
            $rows = [];
            $per = [];
            for ($i = 1; $i <= $n * $scale; ++$i) {
                $dev = $i % self::DEVICES + 1;
                $per[$dev] = ($per[$dev] ?? 0) + 1;
                $base = ['device_id' => $dev, 'client_id' => $i % 11 === 0 ? null : $dev % 3 + 1, 'occurred_at' => $ts(100 + $i % 80000), 'received_at' => $ts(60 + $i % 80000), 'dedupe' => md5($kind . $i)];
                $rows[] = $kind === 'log'
                    ? [$idCol => $i] + $base + ['level' => $i % 5, 'source' => 'src' . ($i % 4), 'code' => $i % 9 === 0 ? null : $i % 1000, 'message' => "message $i\nline2 \u{00fc}"]
                    : [$idCol => $i] + $base + ['kind' => 'file', 'watch' => 'w' . ($i % 3), 'change_type' => $i % 4, 'target' => "/t/$i", 'item' => "item$i", 'old_value' => "o$i", 'new_value' => "n$i",
                        'summary' => "s$i", 'old_hash' => md5("o$i"), 'new_hash' => md5("n$i"), 'actor' => 'actor' . ($i % 5), 'process' => 'proc', 'flags' => $i % 3];
            }
            $put($events, $rows);
            $state = [];
            foreach ($per as $dev => $count) {
                $state[] = $kind === 'log'
                    ? ['device_id' => $dev, 'row_count' => $count, 'dropped_total' => $dev, 'last_event_at' => $ts(100), 'last_batch_at' => $ts(60), 'window_start' => $ts(30), 'window_count' => 3]
                    : ['device_id' => $dev, 'client_id' => $dev % 3 + 1, 'row_count' => $count, 'dropped_total' => $dev, 'last_change_at' => $ts(100), 'last_batch_at' => $ts(60), 'window_start' => $ts(30), 'window_count' => 3,
                        'config_version' => 'abcdef0123456789', 'monitor_json' => '{"a": [1, 2],  "b":"x"}', 'monitor_at' => $ts(50)];
            }
            $put($ledger, $state);
        }

        $rows = [];
        for ($i = 1; $i <= 400 * $scale; ++$i) {
            $rows[] = ['history_id' => $i, 'device_id' => $i % self::DEVICES + 1, 'software_key' => md5("sw$i"), 'name' => "App $i", 'source' => 'msi', 'change_type' => ['install', 'update', 'remove'][$i % 3],
                'old_version' => $i % 3 === 0 ? null : '1.' . $i, 'new_version' => $i % 3 === 2 ? null : '2.' . $i, 'publisher' => 'Pub', 'occurred_at' => $ts(500 + $i * 11 % 90000)];
        }
        $put('rmm_software_history', $rows);

        $rows = [];
        $rows2 = [];
        for ($i = 1; $i <= 300 * $scale; ++$i) {
            $rows[] = ['id' => $i, 'device_id' => $i % self::DEVICES + 1, 'client_id' => $i % 3 + 1, 'update_id' => "KB$i", 'title' => "Update $i", 'action' => 'install', 'result' => 'ok', 'code' => '', 'detail' => 'd',
                'at' => $ts(1000 + $i * 13 % 90000), 'job_id' => $i % 2 === 0 ? null : '11111111-2222-3333-4444-' . str_pad((string) $i, 12, '0', STR_PAD_LEFT), 'user_id' => $i % 5 === 0 ? null : 7];
            $rows2[] = ['id' => $i, 'device_id' => $i % self::DEVICES + 1, 'client_id' => $i % 3 + 1, 'package_id' => $i % 20 + 1, 'version_id' => $i % 4 === 0 ? null : 3, 'action' => 'install', 'mode' => 'live', 'result' => 'ok',
                'code' => '', 'detail' => 'd', 'version' => '1.0', 'at' => $ts(1000 + $i * 17 % 90000), 'job_id' => null, 'user_id' => null];
        }
        $put('rmm_patch_history', $rows);
        $put('rmm_package_history', $rows2);

        $rows = [];
        for ($i = 1; $i <= 100 * $scale; ++$i) {
            $rows[] = ['history_id' => $i, 'vm_id' => $i % 15 + 1, 'device_id' => $i % self::DEVICES + 1, 'at' => $ts(100 + $i * 19 % 80000), 'from_state' => 'running', 'to_state' => 'stopped'];
        }
        $put('rmm_hypervisor_vm_history', $rows);

        $latest = [];
        $hourly = [];
        $daily = [];
        for ($a = 1; $a <= 10; ++$a) {
            foreach (['cpu', 'mem', 'disk'] as $k => $key) {
                $latest[] = ['asset_id' => $a, 'metric_key' => $key, 'instance' => $key === 'disk' ? 'C:' : '', 'value' => $a * 1.1 + $k / 3, 'label' => $a % 2 === 0 ? null : 'lbl', 'sampled_at' => $ts(20 + $a)];
                for ($h = 3; $h < 3 + 40 * $scale; ++$h) {
                    $hourly[] = ['asset_id' => $a, 'metric_key' => $key, 'instance' => $key === 'disk' ? 'C:' : '', 'hour_start' => gmdate('Y-m-d H:i:s', $hour - $h * 3600), 'samples' => 12 + $h, 'sum_value' => ($a + $h) * 0.1 + 0.2,
                        'min_value' => $a / 7, 'max_value' => $a * 3.3 + $h];
                }
                for ($d = 1; $d <= 20 * $scale; ++$d) {
                    $day = $now - $now % 86400 - $d * 86400;
                    $daily[] = ['asset_id' => $a, 'metric_key' => $key, 'instance' => $key === 'disk' ? 'C:' : '', 'day_start' => gmdate('Y-m-d H:i:s', $day), 'samples' => 288, 'sum_value' => $d + $a * 0.5, 'min_value' => 0.5, 'max_value' => 99.5];
                }
            }
        }
        $put('rmm_metric_latest', $latest);
        $put('rmm_metric_hourly', $hourly);
        $put('rmm_metric_daily', $daily);
        $put('rmm_metric_rollup_state', [['name' => 'metric_daily_rollup', 'watermark' => gmdate('Y-m-d H:i:s', $now - $now % 86400), 'detail' => '{"days":3}', 'updated_at' => $ts(5)]]);

        return $out;
    }

    /** @return array<string,int> */
    public static function counts(StoreEndpoint $ep): array
    {
        $o = [];
        foreach (HotTables::all() as $t) {
            $o[$t->name] = $ep->count($t);
        }

        return $o;
    }

    public static function truncateAll(StoreEndpoint $ep): void
    {
        foreach (HotTables::all() as $t) {
            $ep->truncate($t);
        }
    }

    /** A digest of a whole table without its surrogate ids, for "the source was not touched". */
    public static function digest(StoreEndpoint $ep, HotTable $t): string
    {
        $all = [];
        $after = null;
        while (($rows = $ep->readBatch($t, $after, 5000)) !== []) {
            array_push($all, ...$rows);
            $after = array_map(static fn (string $k) => end($rows)[$k], $t->key);
        }

        return \RivetCore\Rmm\Store\Migration\Fingerprint::digest($t, $all);
    }
}
