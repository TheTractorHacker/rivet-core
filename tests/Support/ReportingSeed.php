<?php

declare(strict_types=1);

namespace RivetCore\Tests\Support;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Rmm\Store\Postgres\PostgresConnectionInterface;

/**
 * Two clients (7 and 9) with devices, alerts, patches, software, VMs, history and audit rows in the control tables and in the hot tables, for the reporting view
 * tests (#91 phase 2). Every column the views must NOT expose carries the marker {@see SECRET}, so a test can search every row of every view for it.
 *
 *   device 1  client 7  asset 101  RETIRED (the old install of asset 101)
 *   device 2  client 7  asset 101  the current device of asset 101
 *   device 3  client 7  asset 102  hypervisor host
 *   device 4  client 9  asset 201
 *   device 5  client 9  asset 202  hypervisor host
 *   device 6  client 9  no asset
 *   asset 999 has metrics but no device at all
 */
final class ReportingSeed
{
    public const SECRET = 'SECRET-MARKER';

    /** Rows the client-scoped views must return per client in the control database: view => [client 7, client 9]. */
    public const EXPECTED_MARIADB = [
        'rpt_devices' => [3, 3], 'rpt_device_groups' => [1, 1], 'rpt_device_tags' => [2, 1], 'rpt_device_health_hourly' => [12, 12], 'rpt_device_health_daily' => [4, 4],
        'rpt_alerts' => [2, 2], 'rpt_check_status' => [3, 1], 'rpt_check_results' => [6, 6], 'rpt_patch_compliance' => [2, 2], 'rpt_patch_updates' => [3, 1],
        'rpt_patch_history' => [2, 2], 'rpt_software_inventory' => [2, 1], 'rpt_software_changes' => [2, 1], 'rpt_hypervisor_hosts' => [1, 1], 'rpt_hypervisor_vms' => [2, 1],
        'rpt_change_events' => [3, 3],
    ];

    public static function ts(int $secondsAgo): string
    {
        return gmdate('Y-m-d H:i:s', time() - $secondsAgo);
    }

    /** Control tables and (in the same database) the hot tables. */
    public static function maria(DatabaseInterface $db): void
    {
        $s = self::SECRET;
        $db->execute('INSERT INTO endpoint_agent_settings (id) VALUES (1) ON DUPLICATE KEY UPDATE id = id');
        $dev = [[1, 7, 101, 'old-pc'], [2, 7, 101, 'pc-a'], [3, 7, 102, 'host-a'], [4, 9, 201, 'pc-b'], [5, 9, 202, 'host-b'], [6, 9, null, 'pc-c']];
        foreach ($dev as [$id, $client, $asset, $host]) {
            $retired = $id === 1 ? self::ts(86400) : null;
            $db->execute('INSERT INTO endpoint_agent_devices (device_id, install_id, hostname, os, os_version, client_id, location_id, asset_id, link_state, token_hash, serial, last_ip, logged_in_user, mac_addresses,
                inventory_json, last_metrics_json, machine_guid, last_checkin_at, first_seen_at, retired_at, agent_version, pending_reboot, uptime_s)
                VALUES (?, ?, ?, \'windows\', \'Windows 11\', ?, ?, ?, \'linked\', ?, ?, \'203.0.113.9\', ?, ?, ?, ?, ?, ?, ?, ?, \'1.2.3\', 1, 3600)',
                [$id, sprintf('00000000-0000-4000-8000-%012d', $id), $host, $client, $client * 10, $asset, str_repeat('a', 40) . $s, 'SERIAL-' . $s, 'user-' . $s, 'mac-' . $s, '{"x":"' . $s . '"}', '{"y":"' . $s . '"}', 'guid-' . $s,
                    $id === 6 ? null : self::ts($id === 4 ? 3000 : 60), self::ts(86400 * 30), $retired]);
            $db->execute('INSERT INTO rmm_device_state (device_id, presence, presence_at, software_hash) VALUES (?, ?, ?, ?)', [$id, 'online', self::ts(60), str_repeat('b', 64)]);
        }
        // groups and tags
        $db->execute('INSERT INTO rmm_groups (group_id, name) VALUES (1, \'g1\')');
        $db->execute('INSERT INTO rmm_group_devices (group_id, device_id) VALUES (1, 2), (1, 4)');
        $db->execute('INSERT INTO rmm_tags (tag_id, name) VALUES (1, \'prod\')');
        $db->execute('INSERT INTO rmm_device_tags (device_id, tag_id, source) VALUES (2, 1, \'manual\'), (3, 1, \'auto\'), (5, 1, \'manual\')');
        // alerts
        $db->execute('INSERT INTO rmm_alert_meta (alert_id, device_id, client_id, check_key, episode, severity, state, message, opened_at, acked_at, resolved_at, resolve_reason, acked_by, resolved_by, group_key) VALUES
            (501, 2, 7, \'cpu.utilization\', 1, \'warn\', \'resolved\', ?, \'2026-10-01 10:00:00\', \'2026-10-01 10:10:00\', \'2026-10-01 11:30:00\', \'recovered\', 3, 3, ?),
            (502, 2, 7, \'disk.c\', 1, \'fail\', \'open\', ?, ?, NULL, NULL, NULL, NULL, NULL, ?),
            (503, 4, 9, \'disk.c\', 1, \'fail\', \'open\', ?, ?, NULL, NULL, NULL, NULL, NULL, ?),
            (504, 5, 9, \'patch.compliance\', 1, \'warn\', \'acknowledged\', ?, ?, ?, NULL, NULL, 3, NULL, ?)',
            ['MSG-' . $s, 'G-' . $s, 'MSG-' . $s, self::ts(7200), 'G-' . $s, 'MSG-' . $s, self::ts(3600), 'G-' . $s, 'MSG-' . $s, self::ts(3600), self::ts(1800), 'G-' . $s]);
        // checks
        $db->execute('INSERT INTO endpoint_agent_checks (device_id, check_key, status, detail, consecutive_failures, last_reported_at) VALUES (2, \'cpu.utilization\', \'ok\', ?, 0, ?), (2, \'disk.c\', \'fail\', ?, 3, ?), (4, \'disk.c\', \'warn\', ?, 1, ?)',
            ['DETAIL-' . $s, self::ts(60), 'DETAIL-' . $s, self::ts(60), 'DETAIL-' . $s, self::ts(60)]);
        $db->execute('INSERT INTO rmm_check_eval (device_id, check_key, tier, flapping, updated_at) VALUES (2, \'disk.c\', \'fail\', 1, ?)', [self::ts(60)]);
        // patching
        $db->execute('INSERT INTO rmm_patch_scans (device_id, client_id, scanned_at, scan_state, manager, total_count, critical_count, security_count) VALUES (2, 7, ?, \'ok\', \'wua\', 3, 1, 1), (3, 7, ?, \'ok\', \'apt\', 0, 0, 0), (4, 9, ?, \'ok\', \'wua\', 1, 0, 0), (5, 9, ?, \'failed\', \'apt\', 0, 0, 0)',
            [self::ts(600), self::ts(600), self::ts(600), self::ts(600)]);
        $db->execute('INSERT INTO rmm_patch_available (device_id, client_id, update_id, kb, title, kind, status, first_seen_at, last_seen_at, decline_reason, last_job_id) VALUES
            (2, 7, \'u1\', \'KB1\', \'Critical fix\', \'critical\', \'available\', ?, ?, ?, NULL), (2, 7, \'u2\', \'KB2\', \'Security fix\', \'security\', \'failed\', ?, ?, ?, NULL), (2, 7, \'u3\', \'KB3\', \'Optional\', \'optional\', \'installed\', ?, ?, ?, NULL),
            (4, 9, \'u4\', \'KB4\', \'Driver\', \'driver\', \'available\', ?, ?, ?, NULL)',
            [self::ts(86400 * 20), self::ts(600), 'DECLINE-' . $s, self::ts(86400 * 3), self::ts(600), 'DECLINE-' . $s, self::ts(86400 * 3), self::ts(600), 'DECLINE-' . $s, self::ts(86400), self::ts(600), 'DECLINE-' . $s]);
        $db->execute('INSERT INTO endpoint_agent_checks (device_id, check_key, status, detail) VALUES (2, \'patch.compliance\', \'warn\', ?)', ['DETAIL-' . $s]);
        // software
        $db->execute('INSERT INTO rmm_device_software (device_id, software_key, name, source, version, publisher, first_seen_at, last_seen_at, removed_at) VALUES
            (2, ?, \'7-Zip\', \'msi\', \'23.1\', \'Igor\', ?, ?, NULL), (2, ?, \'Gone App\', \'msi\', \'1\', \'X\', ?, ?, ?), (3, ?, \'curl\', \'apt\', \'8\', \'Debian\', ?, ?, NULL), (4, ?, \'Chrome\', \'msi\', \'120\', \'Google\', ?, ?, NULL)',
            [str_repeat('1', 40), self::ts(9000), self::ts(60), str_repeat('2', 40), self::ts(9000), self::ts(900), self::ts(900), str_repeat('3', 40), self::ts(9000), self::ts(60), str_repeat('4', 40), self::ts(9000), self::ts(60)]);
        // hypervisors
        $db->execute('INSERT INTO rmm_hypervisor_hosts (device_id, platform, version, cluster_name, vm_count, error, cluster_nodes_json, updated_at) VALUES (3, \'proxmox\', \'8.2\', \'c1\', 2, ?, ?, ?), (5, \'hyperv\', \'10\', \'\', 1, ?, NULL, ?)',
            ['ERR-' . $s, '["n1"]', self::ts(60), 'ERR-' . $s, self::ts(60)]);
        $db->execute('INSERT INTO rmm_hypervisor_vms (device_id, vm_key, name, type, state, tags, ignored_reason, first_seen_at, last_seen_at) VALUES (3, \'100\', \'web\', \'qemu\', \'running\', ?, ?, ?, ?), (3, \'101\', \'db\', \'qemu\', \'stopped\', \'\', \'\', ?, ?), (5, \'a\', \'vm-b\', \'hyperv\', \'running\', \'\', \'\', ?, ?)',
            ['TAGS-' . $s, 'IGN-' . $s, self::ts(9000), self::ts(60), self::ts(9000), self::ts(60), self::ts(9000), self::ts(60)]);
        // audit
        $db->execute('INSERT INTO audit_events (event_type, actor_user_id, entity_type, entity_id, action, summary, metadata_json, ip_address, user_agent, request_id) VALUES
            (\'user.login\', 3, \'user\', ?, \'login\', ?, ?, \'198.51.100.7\', ?, \'r1\'), (\'asset.update\', 3, \'asset\', \'55\', \'update\', ?, ?, \'198.51.100.7\', ?, \'r2\'), (\'client.delete\', 4, \'client\', \'7\', \'delete\', ?, ?, \'198.51.100.8\', ?, \'r3\')',
            ['ENT-' . $s, 'SUM-' . $s, '{"m":"' . $s . '"}', 'UA-' . $s, 'SUM-' . $s, '{"m":"' . $s . '"}', 'UA-' . $s, 'SUM-' . $s, '{"m":"' . $s . '"}', 'UA-' . $s]);

        self::hotMaria($db);
    }

    private static function hotMaria(DatabaseInterface $db): void
    {
        $s = self::SECRET;
        // metrics: 3 hours x (cpu, disk.free C:) for four assets plus the orphan asset 999; daily: 2 days of disk.free_bytes
        foreach ([101, 102, 201, 202, 999] as $asset) {
            foreach ([3, 2, 1] as $h) {
                $hour = gmdate('Y-m-d H:00:00', time() - $h * 3600);
                $db->execute('INSERT INTO rmm_metric_hourly (asset_id, metric_key, instance, hour_start, samples, sum_value, min_value, max_value) VALUES (?, \'cpu.utilization\', \'\', ?, 4, 80, 10, 40), (?, \'disk.free_bytes\', \'C:\', ?, 4, 400, 90, 110)', [$asset, $hour, $asset, $hour]);
            }
            foreach ([2, 1] as $d) {
                $db->execute('INSERT INTO rmm_metric_daily (asset_id, metric_key, instance, day_start, samples, sum_value, min_value, max_value) VALUES (?, \'disk.free_bytes\', \'C:\', ?, 96, 9600, 90, 110)', [$asset, gmdate('Y-m-d 00:00:00', time() - $d * 86400)]);
            }
        }
        // check history: two recent rows for each device, one 40-day-old row for device 2 (outside the 31-day bound of the view)
        foreach ([1, 2, 3, 4, 5, 6] as $dev) {
            $db->execute('INSERT INTO endpoint_agent_check_history (device_id, check_key, status, detail, reported_at) VALUES (?, \'cpu.utilization\', \'ok\', ?, ?), (?, \'disk.c\', \'warn\', ?, ?)', [$dev, 'DETAIL-' . $s, self::ts(600), $dev, 'DETAIL-' . $s, self::ts(300)]);
        }
        $db->execute('INSERT INTO endpoint_agent_check_history (device_id, check_key, status, detail, reported_at) VALUES (2, \'cpu.utilization\', \'ok\', \'old\', ?)', [self::ts(86400 * 40)]);
        // change records: device 1 and 6 carry no client_id (the view takes the device's client), the others carry it
        $clients = [1 => null, 2 => 7, 3 => 7, 4 => 9, 5 => 9, 6 => null];
        foreach ($clients as $dev => $client) {
            $db->execute('INSERT INTO endpoint_agent_change_records (device_id, client_id, occurred_at, received_at, kind, watch, change_type, target, item, old_value, new_value, summary, old_hash, new_hash, actor, process, flags, dedupe)
                VALUES (?, ?, ?, ?, \'registry\', \'win.run_keys\', 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, UNHEX(MD5(?)))',
                [$dev, $client, self::ts(500), self::ts(490), 'HKLM\\Run\\' . $s, 'ITEM-' . $s, 'OLD-' . $s, 'NEW-' . $s, 'SUM-' . $s, md5('o' . $s), md5('n' . $s), 'ACTOR-' . $s, 'C:\\evil-' . $s . '.exe', 'd' . $dev]);
        }
        // patch history
        $db->execute('INSERT INTO rmm_patch_history (device_id, client_id, update_id, title, action, result, code, detail, at, job_id, user_id) VALUES
            (2, 7, \'u1\', \'Critical fix\', \'install\', \'success\', \'0\', ?, ?, ?, 3), (3, 7, \'u9\', \'Other\', \'install\', \'failed\', \'0x80\', ?, ?, NULL, 3), (4, 9, \'u4\', \'Driver\', \'install\', \'success\', \'0\', ?, ?, NULL, 3), (5, 9, \'u8\', \'X\', \'install\', \'success\', \'0\', ?, ?, NULL, 3)',
            ['PD-' . $s, self::ts(900), 'JOB-' . $s, 'PD-' . $s, self::ts(900), 'PD-' . $s, self::ts(900), 'PD-' . $s, self::ts(900)]);
        // software history
        $db->execute('INSERT INTO rmm_software_history (device_id, software_key, name, source, change_type, old_version, new_version, publisher, occurred_at) VALUES
            (2, ?, \'7-Zip\', \'msi\', \'updated\', \'22\', \'23.1\', \'Igor\', ?), (3, ?, \'curl\', \'apt\', \'added\', NULL, \'8\', \'Debian\', ?), (4, ?, \'Chrome\', \'msi\', \'added\', NULL, \'120\', \'Google\', ?)',
            [str_repeat('1', 40), self::ts(900), str_repeat('3', 40), self::ts(900), str_repeat('4', 40), self::ts(900)]);
    }

    /** The hot tables in the PostgreSQL store; the same ids, with the denormalised client where the table has one. */
    public static function postgres(PostgresConnectionInterface $db): void
    {
        $s = self::SECRET;
        foreach ([101, 102, 201, 202, 999] as $asset) {
            foreach ([3, 2, 1] as $h) {
                $hour = gmdate('Y-m-d H:00:00', time() - $h * 3600);
                $db->execute("INSERT INTO rmm_metric_hourly (asset_id, metric_key, instance, hour_start, samples, sum_value, min_value, max_value) VALUES (?, 'cpu.utilization', '', ?, 4, 80, 10, 40), (?, 'disk.free_bytes', 'C:', ?, 4, 400, 90, 110)", [$asset, $hour . '+00', $asset, $hour . '+00']);
            }
            foreach ([2, 1] as $d) {
                $db->execute("INSERT INTO rmm_metric_daily (asset_id, metric_key, instance, day_start, samples, sum_value, min_value, max_value) VALUES (?, 'disk.free_bytes', 'C:', ?, 96, 9600, 90, 110)", [$asset, gmdate('Y-m-d 00:00:00', time() - $d * 86400) . '+00']);
            }
        }
        foreach ([1, 2, 3, 4, 5, 6] as $dev) {
            $db->execute("INSERT INTO endpoint_agent_check_history (device_id, check_key, status, detail, reported_at) VALUES (?, 'cpu.utilization', 'ok', ?, ?), (?, 'disk.c', 'warn', ?, ?)", [$dev, 'DETAIL-' . $s, self::ts(600) . '+00', $dev, 'DETAIL-' . $s, self::ts(300) . '+00']);
        }
        $db->execute("INSERT INTO endpoint_agent_check_history (device_id, check_key, status, detail, reported_at) VALUES (2, 'cpu.utilization', 'ok', 'old', ?)", [self::ts(86400 * 40) . '+00']);
        // device 1 has a record with NO client (a row written before the denormalisation): the PostgreSQL view must keep it out of every client's rows
        $clients = [1 => null, 2 => 7, 3 => 7, 4 => 9, 5 => 9, 6 => 9];
        foreach ($clients as $dev => $client) {
            $db->execute("INSERT INTO endpoint_agent_change_records (device_id, client_id, occurred_at, received_at, kind, watch, change_type, target, item, old_value, new_value, summary, old_hash, new_hash, actor, process, flags, dedupe)
                VALUES (?, ?, ?, ?, 'registry', 'win.run_keys', 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, decode(md5(?), 'hex'))",
                [$dev, $client, self::ts(500) . '+00', self::ts(490) . '+00', 'HKLM\\Run\\' . $s, 'ITEM-' . $s, 'OLD-' . $s, 'NEW-' . $s, 'SUM-' . $s, md5('o' . $s), md5('n' . $s), 'ACTOR-' . $s, 'C:\\evil-' . $s . '.exe', 'd' . $dev]);
        }
        $db->execute("INSERT INTO rmm_patch_history (device_id, client_id, update_id, title, action, result, code, detail, \"at\", job_id, user_id) VALUES
            (2, 7, 'u1', 'Critical fix', 'install', 'success', '0', ?, ?, ?, 3), (3, 7, 'u9', 'Other', 'install', 'failed', '0x80', ?, ?, NULL, 3), (4, 9, 'u4', 'Driver', 'install', 'success', '0', ?, ?, NULL, 3), (5, 9, 'u8', 'X', 'install', 'success', '0', ?, ?, NULL, 3)",
            ['PD-' . $s, self::ts(900) . '+00', 'JOB-' . $s, 'PD-' . $s, self::ts(900) . '+00', 'PD-' . $s, self::ts(900) . '+00', 'PD-' . $s, self::ts(900) . '+00']);
        $db->execute("INSERT INTO rmm_software_history (device_id, software_key, name, source, change_type, old_version, new_version, publisher, occurred_at) VALUES
            (2, ?, '7-Zip', 'msi', 'updated', '22', '23.1', 'Igor', ?), (3, ?, 'curl', 'apt', 'added', NULL, '8', 'Debian', ?), (4, ?, 'Chrome', 'msi', 'added', NULL, '120', 'Google', ?)",
            [str_repeat('1', 40), self::ts(900) . '+00', str_repeat('3', 40), self::ts(900) . '+00', str_repeat('4', 40), self::ts(900) . '+00']);
    }
}
