<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\Migration;

/**
 * The hot tables of the RMM data store in copy order (docs/design/rmm-data-store.md section 4, plus the rollup watermark that sits next to the metric tiers).
 *
 * @internal
 */
final class HotTables
{
    /** @var array<string,HotTable>|null */
    private static ?array $tables = null;

    /** @return array<string,HotTable> name => table, in copy order (events before the ledgers that count them) */
    public static function all(): array
    {
        return self::$tables ??= self::build();
    }

    public static function get(string $name): HotTable
    {
        return self::all()[$name] ?? throw new \InvalidArgumentException('Unknown hot table ' . $name);
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::all());
    }

    /** @return array<string,HotTable> */
    private static function build(): array
    {
        $t = [];
        $add = static function (HotTable $x) use (&$t): void {
            $t[$x->name] = $x;
        };
        $add(new HotTable('rmm_metric_latest', HotTable::LATEST, ['asset_id' => 'i', 'metric_key' => 's', 'instance' => 's', 'value' => 'f', 'label' => 's', 'sampled_at' => 't'],
            ['asset_id', 'metric_key', 'instance'], 'sampled_at', 'asset_id'));
        foreach (['rmm_metric_hourly' => 'hour_start', 'rmm_metric_daily' => 'day_start'] as $name => $col) {
            $add(new HotTable($name, HotTable::TIER, ['asset_id' => 'i', 'metric_key' => 's', 'instance' => 's', $col => 't', 'samples' => 'i', 'sum_value' => 'f', 'min_value' => 'f', 'max_value' => 'f'],
                ['asset_id', 'metric_key', 'instance', $col], $col, 'asset_id'));
        }
        $add(new HotTable('rmm_metric_rollup_state', HotTable::STATE, ['name' => 's', 'watermark' => 't', 'detail' => 'j', 'updated_at' => 't'], ['name'], null));
        $add(new HotTable('endpoint_agent_check_history', HotTable::ID, ['device_id' => 'i', 'check_key' => 's', 'status' => 's', 'detail' => 's', 'reported_at' => 't'],
            ['hist_id'], 'reported_at', 'device_id'));
        $add(new HotTable('endpoint_agent_log_events', HotTable::ID, ['device_id' => 'i', 'client_id' => 'i', 'occurred_at' => 't', 'received_at' => 't', 'level' => 'i', 'source' => 's',
            'code' => 'i', 'message' => 's', 'dedupe' => 'x'], ['event_id'], 'received_at', 'device_id', true));
        $add(new HotTable('endpoint_agent_log_state', HotTable::LEDGER, ['device_id' => 'i', 'row_count' => 'i', 'dropped_total' => 'i', 'last_event_at' => 't', 'last_batch_at' => 't',
            'window_start' => 't', 'window_count' => 'i'], ['device_id'], null, 'device_id', false, 'endpoint_agent_log_events'));
        $add(new HotTable('endpoint_agent_change_records', HotTable::ID, ['device_id' => 'i', 'client_id' => 'i', 'occurred_at' => 't', 'received_at' => 't', 'kind' => 's', 'watch' => 's',
            'change_type' => 'i', 'target' => 's', 'item' => 's', 'old_value' => 's', 'new_value' => 's', 'summary' => 's', 'old_hash' => 's', 'new_hash' => 's', 'actor' => 's', 'process' => 's',
            'flags' => 'i', 'dedupe' => 'x'], ['change_id'], 'received_at', 'device_id', true));
        $add(new HotTable('endpoint_agent_change_state', HotTable::LEDGER, ['device_id' => 'i', 'client_id' => 'i', 'row_count' => 'i', 'dropped_total' => 'i', 'last_change_at' => 't',
            'last_batch_at' => 't', 'window_start' => 't', 'window_count' => 'i', 'config_version' => 's', 'monitor_json' => 's', 'monitor_at' => 't'], ['device_id'], null, 'device_id', false,
            'endpoint_agent_change_records'));
        $add(new HotTable('rmm_software_history', HotTable::ID, ['device_id' => 'i', 'software_key' => 's', 'name' => 's', 'source' => 's', 'change_type' => 's', 'old_version' => 's',
            'new_version' => 's', 'publisher' => 's', 'occurred_at' => 't'], ['history_id'], 'occurred_at', 'device_id', true));
        $add(new HotTable('rmm_patch_history', HotTable::ID, ['device_id' => 'i', 'client_id' => 'i', 'update_id' => 's', 'title' => 's', 'action' => 's', 'result' => 's', 'code' => 's',
            'detail' => 's', 'at' => 't', 'job_id' => 's', 'user_id' => 'i'], ['id'], 'at', 'device_id'));
        $add(new HotTable('rmm_package_history', HotTable::ID, ['device_id' => 'i', 'client_id' => 'i', 'package_id' => 'i', 'version_id' => 'i', 'action' => 's', 'mode' => 's', 'result' => 's',
            'code' => 's', 'detail' => 's', 'version' => 's', 'at' => 't', 'job_id' => 's', 'user_id' => 'i'], ['id'], 'at', 'device_id'));
        $add(new HotTable('rmm_hypervisor_vm_history', HotTable::ID, ['vm_id' => 'i', 'device_id' => 'i', 'at' => 't', 'from_state' => 's', 'to_state' => 's'], ['history_id'], 'at', 'device_id'));

        return $t;
    }
}
