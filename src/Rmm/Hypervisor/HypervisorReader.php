<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Hypervisor;

use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Support\Sql;

/**
 * The read model of hypervisor visibility: the host list with VM counts, one host with its VMs and storage, the VM search, and one VM with the ring of its state changes.
 * Pure reads. The caller (HypervisorActions) has already checked the grants and passes the clients the caller may see (null = all).
 *
 * @api
 */
final class HypervisorReader
{
    public const MAX_LIMIT = 500;

    public function __construct(private readonly Sql $sql, private readonly RmmSettings $settings)
    {
    }

    /**
     * @param list<int>|null $visible
     * @param array<string,mixed> $f q (host name), platform, client_id, problems (1 = only hosts with an error, stale data or an unhealthy storage)
     * @return array{data:list<array<string,mixed>>,total:int}
     */
    public function hosts(?array $visible, array $f, int $limit, int $offset): array
    {
        [$where, $params] = $this->hostWhere($visible, $f);
        $total = (int) $this->sql->val('SELECT COUNT(*) FROM rmm_hypervisor_hosts h JOIN endpoint_agent_devices d ON d.device_id = h.device_id WHERE ' . $where, $params);
        $rows = $this->sql->all('SELECT h.*, d.hostname, d.client_id, d.asset_id, d.os, d.last_checkin_at,
            (SELECT COUNT(*) FROM rmm_hypervisor_vms v WHERE v.device_id = h.device_id AND v.missing_since IS NULL AND v.template = 0) AS vms,
            (SELECT COUNT(*) FROM rmm_hypervisor_vms v WHERE v.device_id = h.device_id AND v.missing_since IS NULL AND v.template = 0 AND v.state = \'running\') AS vms_running,
            (SELECT COUNT(*) FROM rmm_hypervisor_vms v WHERE v.device_id = h.device_id AND v.missing_since IS NULL AND v.template = 1) AS templates,
            (SELECT COUNT(*) FROM rmm_hypervisor_vms v WHERE v.device_id = h.device_id AND v.missing_since IS NOT NULL) AS vms_missing,
            (SELECT COUNT(*) FROM rmm_hypervisor_storage s WHERE s.device_id = h.device_id AND s.missing_since IS NULL) AS storages,
            (SELECT COUNT(*) FROM rmm_hypervisor_storage s WHERE s.device_id = h.device_id AND s.missing_since IS NULL AND s.health IN (\'warn\', \'error\')) AS storages_unhealthy
            FROM rmm_hypervisor_hosts h JOIN endpoint_agent_devices d ON d.device_id = h.device_id WHERE ' . $where . ' ORDER BY d.hostname, h.device_id LIMIT ' . $this->limit($limit) . ' OFFSET ' . max(0, $offset), $params);

        return ['data' => array_map(fn (array $r): array => $this->hostRow($r), $rows), 'total' => $total];
    }

    /**
     * One host with its storage and a page of its VMs.
     *
     * @param list<int>|null $visible
     * @param array<string,mixed> $f q, state, type, template (0/1), ignored (0/1), missing (0/1)
     * @return array<string,mixed>|null null when the device reports nothing (or is outside the caller's clients)
     */
    public function host(int $deviceId, ?array $visible, array $f, int $limit, int $offset): ?array
    {
        $list = $this->hosts($visible, ['device_id' => $deviceId], 1, 0);
        if ($list['data'] === []) {
            return null;
        }
        $host = $list['data'][0];
        $storage = [];
        foreach ($this->sql->all('SELECT * FROM rmm_hypervisor_storage WHERE device_id = ? AND missing_since IS NULL ORDER BY name LIMIT 250', [$deviceId]) as $s) {
            $pct = (int) $s['total_bytes'] > 0 ? (int) floor((int) $s['used_bytes'] * 100 / (int) $s['total_bytes']) : null;
            $storage[] = ['name' => (string) $s['name'], 'type' => (string) $s['type'], 'total_bytes' => (int) $s['total_bytes'], 'used_bytes' => (int) $s['used_bytes'], 'used_pct' => $pct,
                'health' => (string) $s['health'], 'shared' => (int) $s['shared'] === 1, 'last_seen_at' => Sql::iso((string) $s['last_seen_at'])];
        }
        $vms = $this->vms($visible, $f + ['device_id' => $deviceId], $limit, $offset);
        $byState = [];
        foreach ($this->sql->all('SELECT state, COUNT(*) AS n FROM rmm_hypervisor_vms WHERE device_id = ? AND missing_since IS NULL AND template = 0 GROUP BY state', [$deviceId]) as $r) {
            $byState[(string) $r['state']] = (int) $r['n'];
        }

        return $host + ['storage' => $storage, 'vm_states' => $byState, 'vms' => $vms['data'], 'vms_total' => $vms['total']];
    }

    /**
     * @param list<int>|null $visible
     * @param array<string,mixed> $f device_id, client_id, q (VM name, key or tag), state, type, template, ignored, missing, snapshots (1 = has snapshots), backup (stale|failed|none)
     * @return array{data:list<array<string,mixed>>,total:int}
     */
    public function vms(?array $visible, array $f, int $limit, int $offset): array
    {
        $where = ['1 = 1'];
        $params = [];
        if ($visible !== null) {
            if ($visible === []) {
                return ['data' => [], 'total' => 0];
            }
            $where[] = 'd.client_id IN (' . implode(',', array_fill(0, count($visible), '?')) . ')';
            array_push($params, ...$visible);
        }
        if (isset($f['device_id']) && (int) $f['device_id'] > 0) {
            $where[] = 'v.device_id = ?';
            $params[] = (int) $f['device_id'];
        }
        if (isset($f['client_id']) && (int) $f['client_id'] > 0) {
            $where[] = 'd.client_id = ?';
            $params[] = (int) $f['client_id'];
        }
        if (isset($f['q']) && is_string($f['q']) && trim($f['q']) !== '') {
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_substr(trim($f['q']), 0, 100)) . '%';
            $where[] = "(v.name LIKE ? ESCAPE '!' OR v.vm_key LIKE ? ESCAPE '!' OR v.tags LIKE ? ESCAPE '!')";
            array_push($params, $like, $like, $like);
        }
        if (isset($f['state']) && is_string($f['state']) && in_array($f['state'], HypervisorProtocol::STATES, true)) {
            $where[] = 'v.state = ?';
            $params[] = $f['state'];
        }
        if (isset($f['type']) && is_string($f['type']) && in_array($f['type'], HypervisorProtocol::TYPES, true)) {
            $where[] = 'v.type = ?';
            $params[] = $f['type'];
        }
        foreach (['template' => 'v.template', 'ignored' => 'v.ignored'] as $k => $col) {
            if (isset($f[$k]) && ($f[$k] === '0' || $f[$k] === '1' || is_int($f[$k]))) {
                $where[] = "$col = ?";
                $params[] = (int) $f[$k];
            }
        }
        $missing = isset($f['missing']) && ($f['missing'] === '1' || $f['missing'] === 1);
        $where[] = $missing ? 'v.missing_since IS NOT NULL' : 'v.missing_since IS NULL';
        if (isset($f['snapshots']) && ($f['snapshots'] === '1' || $f['snapshots'] === 1)) {
            $where[] = 'v.snapshot_count > 0';
        }
        $days = $this->settings->limits()['hypervisor_backup_days'];
        $backup = $f['backup'] ?? null;
        if ($backup === 'failed') {
            $where[] = "v.last_backup_result = 'failed'";
        } elseif ($backup === 'stale') {
            $where[] = 'v.last_backup_at < ?';
            $params[] = $this->sql->utcAt(-$days * 86400);
        } elseif ($backup === 'none') {
            $where[] = 'v.last_backup_at IS NULL AND v.template = 0';
        }
        $w = implode(' AND ', $where);
        $from = 'FROM rmm_hypervisor_vms v JOIN endpoint_agent_devices d ON d.device_id = v.device_id WHERE ' . $w;
        $total = (int) $this->sql->val('SELECT COUNT(*) ' . $from, $params);
        $rows = $this->sql->all('SELECT v.*, d.hostname AS host_name, d.client_id ' . $from . ' ORDER BY v.name, v.vm_id LIMIT ' . $this->limit($limit) . ' OFFSET ' . max(0, $offset), $params);

        return ['data' => array_map(fn (array $r): array => $this->vmRow($r), $rows), 'total' => $total];
    }

    /**
     * One VM with its state-change ring and its open alerts.
     *
     * @param list<int>|null $visible
     * @return array<string,mixed>|null
     */
    public function vm(int $vmId, ?array $visible): ?array
    {
        $row = $this->sql->one('SELECT v.*, d.hostname AS host_name, d.client_id FROM rmm_hypervisor_vms v JOIN endpoint_agent_devices d ON d.device_id = v.device_id WHERE v.vm_id = ?', [$vmId]);
        if ($row === null || ($visible !== null && !in_array((int) $row['client_id'], $visible, true))) {
            return null;
        }
        $out = $this->vmRow($row);
        $out['history'] = array_map(static fn (array $h): array => ['at' => Sql::iso((string) $h['at']), 'from' => (string) $h['from_state'], 'to' => (string) $h['to_state']],
            $this->sql->all('SELECT at, from_state, to_state FROM rmm_hypervisor_vm_history WHERE vm_id = ? ORDER BY history_id DESC LIMIT 100', [$vmId]));
        $keys = [];
        foreach (['vmdown', 'backup', 'snapshot'] as $kind) {
            $keys[] = HypervisorProtocol::vmCheckKey($kind, (string) $row['vm_key']);
        }
        $alerts = [];
        foreach ($this->sql->all('SELECT check_key, status, detail, alert_id, last_changed_at FROM endpoint_agent_checks WHERE device_id = ? AND check_key IN (?, ?, ?) AND status IN (\'warn\', \'fail\')', [(int) $row['device_id'], ...$keys]) as $c) {
            $alerts[] = ['check_key' => (string) $c['check_key'], 'status' => (string) $c['status'], 'detail' => (string) $c['detail'], 'alert_id' => $c['alert_id'] === null ? null : (int) $c['alert_id'], 'since' => Sql::iso((string) $c['last_changed_at'])];
        }
        $out['alerts'] = $alerts;

        return $out;
    }

    /** @return array<string,mixed> */
    public function summary(?array $visible): array
    {
        $where = '1 = 1';
        $params = [];
        if ($visible !== null) {
            if ($visible === []) {
                return ['hosts' => 0, 'vms' => 0, 'running' => 0, 'ignored' => 0, 'missing' => 0];
            }
            $where = 'd.client_id IN (' . implode(',', array_fill(0, count($visible), '?')) . ')';
            $params = $visible;
        }
        $hosts = (int) $this->sql->val('SELECT COUNT(*) FROM rmm_hypervisor_hosts h JOIN endpoint_agent_devices d ON d.device_id = h.device_id WHERE ' . $where, $params);
        $r = $this->sql->one('SELECT COUNT(*) AS vms, COALESCE(SUM(v.state = \'running\'), 0) AS running, COALESCE(SUM(v.ignored), 0) AS ignored FROM rmm_hypervisor_vms v JOIN endpoint_agent_devices d ON d.device_id = v.device_id
            WHERE v.missing_since IS NULL AND v.template = 0 AND ' . $where, $params) ?? [];
        $missing = (int) $this->sql->val('SELECT COUNT(*) FROM rmm_hypervisor_vms v JOIN endpoint_agent_devices d ON d.device_id = v.device_id WHERE v.missing_since IS NOT NULL AND ' . $where, $params);

        return ['hosts' => $hosts, 'vms' => (int) ($r['vms'] ?? 0), 'running' => (int) ($r['running'] ?? 0), 'ignored' => (int) ($r['ignored'] ?? 0), 'missing' => $missing];
    }

    /**
     * @param list<int>|null $visible
     * @param array<string,mixed> $f
     * @return array{0:string,1:list<mixed>}
     */
    private function hostWhere(?array $visible, array $f): array
    {
        $where = ['1 = 1'];
        $params = [];
        if ($visible !== null) {
            if ($visible === []) {
                return ['1 = 0', []];
            }
            $where[] = 'd.client_id IN (' . implode(',', array_fill(0, count($visible), '?')) . ')';
            array_push($params, ...$visible);
        }
        if (isset($f['device_id']) && (int) $f['device_id'] > 0) {
            $where[] = 'h.device_id = ?';
            $params[] = (int) $f['device_id'];
        }
        if (isset($f['client_id']) && (int) $f['client_id'] > 0) {
            $where[] = 'd.client_id = ?';
            $params[] = (int) $f['client_id'];
        }
        if (isset($f['platform']) && is_string($f['platform']) && in_array($f['platform'], HypervisorProtocol::PLATFORMS, true)) {
            $where[] = 'h.platform = ?';
            $params[] = $f['platform'];
        }
        if (isset($f['q']) && is_string($f['q']) && trim($f['q']) !== '') {
            $where[] = "d.hostname LIKE ? ESCAPE '!'";
            $params[] = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_substr(trim($f['q']), 0, 100)) . '%';
        }
        if (isset($f['problems']) && ($f['problems'] === '1' || $f['problems'] === 1)) {
            $where[] = "(h.error <> '' OR h.quorate = 0 OR EXISTS (SELECT 1 FROM rmm_hypervisor_storage s WHERE s.device_id = h.device_id AND s.missing_since IS NULL AND s.health IN ('warn', 'error')))";
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private function hostRow(array $r): array
    {
        $nodes = json_decode((string) ($r['cluster_nodes_json'] ?? ''), true);
        $full = $this->settings->limits()['hypervisor_full_interval_s'];
        $collected = $r['last_collected_at'] === null ? null : Sql::ts((string) $r['last_collected_at']);
        $stale = $collected === null || $this->sql->time() - $collected > max(1800, 3 * $full);

        return [
            'device_id' => (int) $r['device_id'], 'hostname' => (string) ($r['hostname'] ?? ''), 'client_id' => (int) ($r['client_id'] ?? 0), 'asset_id' => ($r['asset_id'] ?? null) === null ? null : (int) $r['asset_id'],
            'platform' => (string) $r['platform'], 'version' => (string) $r['version'],
            'cluster' => (string) $r['cluster_name'] === '' && $r['quorate'] === null ? null : ['name' => (string) $r['cluster_name'], 'quorate' => $r['quorate'] === null ? null : (int) $r['quorate'] === 1,
                'nodes' => is_array($nodes) ? $nodes : [], 'failed_groups' => (int) $r['failed_groups']],
            'vms' => (int) ($r['vms'] ?? 0), 'vms_running' => (int) ($r['vms_running'] ?? 0), 'templates' => (int) ($r['templates'] ?? 0), 'vms_missing' => (int) ($r['vms_missing'] ?? 0),
            'storages' => (int) ($r['storages'] ?? 0), 'storages_unhealthy' => (int) ($r['storages_unhealthy'] ?? 0),
            'truncated' => (int) $r['truncated'] === 1, 'ignored' => (int) $r['ignored'] === 1, 'error' => (string) $r['error'], 'stale' => $stale,
            'last_collected_at' => Sql::iso($r['last_collected_at'] === null ? null : (string) $r['last_collected_at']), 'last_full_at' => Sql::iso($r['last_full_at'] === null ? null : (string) $r['last_full_at']),
            'last_report_at' => Sql::iso($r['last_report_at'] === null ? null : (string) $r['last_report_at']),
        ];
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private function vmRow(array $r): array
    {
        $n = static fn (mixed $v): ?int => $v === null ? null : (int) $v;
        $t = static fn (mixed $v): ?string => $v === null ? null : Sql::iso((string) $v);

        return [
            'vm_id' => (int) $r['vm_id'], 'device_id' => (int) $r['device_id'], 'host' => (string) ($r['host_name'] ?? ''), 'client_id' => (int) ($r['client_id'] ?? 0),
            'key' => (string) $r['vm_key'], 'name' => (string) $r['name'], 'type' => (string) $r['type'], 'state' => (string) $r['state'], 'state_changed_at' => $t($r['state_changed_at']), 'node' => (string) $r['node'],
            'cpus' => (int) $r['cpus'], 'cpu_pct' => $r['cpu_pct'] === null ? null : (float) $r['cpu_pct'], 'mem_bytes' => $n($r['mem_bytes']), 'mem_max_bytes' => $n($r['mem_max_bytes']),
            'disk_bytes' => $n($r['disk_bytes']), 'disk_used_bytes' => $n($r['disk_used_bytes']), 'uptime_s' => $n($r['uptime_s']), 'template' => (int) $r['template'] === 1,
            'tags' => (string) $r['tags'] === '' ? [] : explode(',', (string) $r['tags']),
            'snapshots' => $r['snapshot_count'] === null ? null : ['count' => (int) $r['snapshot_count'], 'oldest_at' => $t($r['oldest_snapshot_at'])],
            'last_backup' => $r['last_backup_at'] === null ? null : ['at' => $t($r['last_backup_at']), 'result' => (string) $r['last_backup_result']],
            'backup_covered' => $r['backup_covered'] === null ? null : (int) $r['backup_covered'] === 1, 'guest_agent' => $r['guest_agent'] === null ? null : (int) $r['guest_agent'] === 1,
            'replication' => (string) $r['replication'] === '' ? null : (string) $r['replication'],
            'ignored' => (int) $r['ignored'] === 1, 'ignored_reason' => (string) $r['ignored_reason'], 'ignored_by' => (int) $r['ignored_by'], 'ignored_at' => $t($r['ignored_at']),
            'first_seen_at' => $t($r['first_seen_at']), 'last_seen_at' => $t($r['last_seen_at']), 'missing_since' => $t($r['missing_since']),
        ];
    }

    private function limit(int $limit): int
    {
        return max(1, min(self::MAX_LIMIT, $limit));
    }
}
