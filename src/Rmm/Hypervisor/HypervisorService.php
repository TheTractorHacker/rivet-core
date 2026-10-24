<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Hypervisor;

use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Support\Sql;

/**
 * Hypervisor visibility, ingest side (wire protocol 3.2.10): validates the optional `hypervisor` block of a check-in field by field and stores hosts, VMs, storage and the
 * small per-VM state history. Nothing here ever fails a check-in: a bad field is dropped on its own, a bad block is ignored, and the caller catches whatever is left.
 *
 * Rules: a FULL report is the whole inventory of one host; a VM that is missing from a full report that carried no error and was not cut by a cap is marked missing (and purged
 * after `hypervisor_retention_days`). A SUMMARY report only changes the state of VMs the server already knows. A report with an `error` never marks anything missing. A value the
 * agent left out keeps its last known value only for snapshots, backups, backup coverage, the guest agent flag and replication (those are looked up in rotation), and is cleared for
 * everything else (a stopped VM has no CPU reading).
 *
 * @api
 */
final class HypervisorService
{
    private const TIME_RE = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,9})?(Z|[+-]\d{2}:\d{2})$/';
    private const VM_KEY_RE = '/^(?:(qemu|lxc)\/[1-9][0-9]{0,8}|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/D';

    private readonly \RivetCore\Rmm\Store\HypervisorHistoryStore $vmHistory;

    public function __construct(
        private readonly Sql $sql,
        private readonly RmmSettings $settings,
        private readonly ?HypervisorAlerts $alerts = null,
        ?\RivetCore\Rmm\Store\HypervisorHistoryStore $history = null,
    ) {
        $this->vmHistory = $history ?? new \RivetCore\Rmm\Store\MariaDb\MariaDbHypervisorHistoryStore($sql);
    }

    // ------------------------------------------------------------------ validation (pure)

    /**
     * @return array<string,mixed>|null null when the block is not a usable report at all
     */
    public static function cleanReport(mixed $raw, int $now): ?array
    {
        if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            return null;
        }
        $mode = $raw['mode'] ?? null;
        $platform = $raw['platform'] ?? null;
        if (!is_string($mode) || !in_array($mode, HypervisorProtocol::MODES, true) || !is_string($platform) || !in_array($platform, HypervisorProtocol::PLATFORMS, true)) {
            return null;
        }
        $out = [
            'mode' => $mode,
            'platform' => $platform,
            'version' => HypervisorProtocol::text($raw['version'] ?? null, HypervisorProtocol::VERSION_MAX),
            'collected_at' => self::time($raw['collected_at'] ?? null, $now, true) ?? gmdate('Y-m-d H:i:s', $now),
            'error' => HypervisorProtocol::text($raw['error'] ?? null, HypervisorProtocol::ERROR_MAX),
            'truncated' => !empty($raw['truncated']),
            'cfg_version' => preg_match('/^[0-9a-f]{16}$/D', (string) ($raw['cfg_version'] ?? '')) === 1 ? (string) $raw['cfg_version'] : '',
            'cluster' => self::cleanCluster($raw['cluster'] ?? null),
            'vms' => [],
            'storage' => [],
            'changes' => [],
            'vm_count' => self::int($raw['vm_count'] ?? null, 0, 1000000) ?? 0,
            'cut' => false,
        ];
        $vms = $raw['vms'] ?? [];
        if (is_array($vms) && array_is_list($vms)) {
            if (count($vms) > HypervisorProtocol::MAX_VMS) {
                $out['cut'] = true;
                $vms = array_slice($vms, 0, HypervisorProtocol::MAX_VMS);
            }
            foreach ($vms as $v) {
                $c = self::cleanVm($v, $platform, $now);
                if ($c !== null) {
                    $out['vms'][$c['key']] = $c;
                }
            }
            $out['vms'] = array_values($out['vms']);
        }
        $st = $raw['storage'] ?? [];
        if (is_array($st) && array_is_list($st)) {
            if (count($st) > HypervisorProtocol::MAX_STORAGE) {
                $out['cut'] = true;
                $st = array_slice($st, 0, HypervisorProtocol::MAX_STORAGE);
            }
            foreach ($st as $s) {
                $c = self::cleanStorage($s);
                if ($c !== null) {
                    $out['storage'][$c['name']] = $c;
                }
            }
            $out['storage'] = array_values($out['storage']);
        }
        $ch = $raw['changes'] ?? [];
        if ($mode === 'summary' && is_array($ch) && array_is_list($ch)) {
            foreach (array_slice($ch, 0, HypervisorProtocol::MAX_CHANGES) as $c) {
                if (is_array($c) && is_string($c['id'] ?? null) && preg_match(self::VM_KEY_RE, $c['id']) === 1) {
                    $out['changes'][$c['id']] = ['key' => $c['id'], 'state' => self::state($c['state'] ?? null)];
                }
            }
            $out['changes'] = array_values($out['changes']);
        }

        return $out;
    }

    /** @return array<string,mixed>|null */
    private static function cleanCluster(mixed $c): ?array
    {
        if (!is_array($c) || array_is_list($c) && $c !== []) {
            return null;
        }
        $nodes = [];
        if (is_array($c['nodes'] ?? null) && array_is_list($c['nodes'])) {
            foreach (array_slice($c['nodes'], 0, HypervisorProtocol::MAX_NODES) as $n) {
                $name = is_array($n) ? HypervisorProtocol::text($n['name'] ?? null, HypervisorProtocol::NODE_MAX) : '';
                if ($name !== '') {
                    $nodes[$name] = ['name' => $name, 'online' => !empty($n['online'])];
                }
            }
        }
        $q = $c['quorate'] ?? null;

        return ['name' => HypervisorProtocol::text($c['name'] ?? null, HypervisorProtocol::NAME_MAX), 'quorate' => is_bool($q) ? $q : null, 'nodes' => array_values($nodes),
            'failed_groups' => self::int($c['failed_groups'] ?? null, 0, 100000) ?? 0];
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function cleanVm(mixed $v, string $platform, int $now): ?array
    {
        if (!is_array($v) || !is_string($v['id'] ?? null) || preg_match(self::VM_KEY_RE, $v['id']) !== 1) {
            return null;
        }
        $type = $v['type'] ?? null;
        if (!is_string($type) || !in_array($type, HypervisorProtocol::TYPES, true)) {
            return null;
        }
        if (($platform === 'proxmox') !== ($type !== 'hyperv') || ($type !== 'hyperv' && !str_starts_with($v['id'], $type . '/')) || ($type === 'hyperv' && str_contains($v['id'], '/'))) {
            return null;   // the id, the type and the platform must agree
        }
        $name = HypervisorProtocol::text($v['name'] ?? null, HypervisorProtocol::NAME_MAX);
        $snap = is_array($v['snapshots'] ?? null) ? $v['snapshots'] : null;
        $snapCount = $snap === null ? null : self::int($snap['count'] ?? null, 0, 10000);
        $bk = is_array($v['last_backup'] ?? null) ? $v['last_backup'] : null;
        $bkAt = $bk === null ? null : self::time($bk['at'] ?? null, $now, false);
        $bkRes = $bk !== null && is_string($bk['result'] ?? null) && in_array($bk['result'], HypervisorProtocol::BACKUP_RESULTS, true) ? $bk['result'] : null;
        $tags = [];
        if (is_array($v['tags'] ?? null) && array_is_list($v['tags'])) {
            foreach (array_slice($v['tags'], 0, 40) as $t) {
                $t = HypervisorProtocol::text($t, HypervisorProtocol::MAX_TAG);
                if ($t !== '' && !in_array($t, $tags, true)) {
                    $tags[] = $t;
                    if (count($tags) >= HypervisorProtocol::MAX_TAGS) {
                        break;
                    }
                }
            }
        }
        $repl = $v['replication'] ?? null;

        return [
            'key' => $v['id'],
            'name' => $name === '' ? $v['id'] : $name,
            'type' => $type,
            'state' => self::state($v['state'] ?? null),
            'node' => HypervisorProtocol::text($v['node'] ?? null, HypervisorProtocol::NODE_MAX),
            'cpus' => self::int($v['cpus'] ?? null, 0, 4096) ?? 0,
            'cpu_pct' => self::float($v['cpu_pct'] ?? null, 0.0, 100.0),
            'mem_bytes' => self::int($v['mem_bytes'] ?? null, 0, HypervisorProtocol::MAX_BYTES_VALUE),
            'mem_max_bytes' => self::int($v['mem_max_bytes'] ?? null, 0, HypervisorProtocol::MAX_BYTES_VALUE),
            'disk_bytes' => self::int($v['disk_bytes'] ?? null, 0, HypervisorProtocol::MAX_BYTES_VALUE),
            'disk_used_bytes' => self::int($v['disk_used_bytes'] ?? null, 0, HypervisorProtocol::MAX_BYTES_VALUE),
            'uptime_s' => self::int($v['uptime_s'] ?? null, 0, HypervisorProtocol::MAX_UPTIME_S),
            'template' => !empty($v['template']),
            'tags' => implode(',', $tags),
            'snapshot_count' => $snapCount,
            'oldest_snapshot_at' => $snapCount === null || $snapCount === 0 ? null : self::time($snap['oldest_at'] ?? null, $now, false),
            'last_backup_at' => $bkAt !== null && $bkRes !== null ? $bkAt : null,
            'last_backup_result' => $bkAt !== null && $bkRes !== null ? $bkRes : null,
            'backup_covered' => is_bool($v['backup_covered'] ?? null) ? ($v['backup_covered'] ? 1 : 0) : null,
            'guest_agent' => is_bool($v['guest_agent'] ?? null) ? ($v['guest_agent'] ? 1 : 0) : null,
            'replication' => is_string($repl) && in_array($repl, HypervisorProtocol::REPLICATION, true) ? $repl : null,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function cleanStorage(mixed $s): ?array
    {
        if (!is_array($s)) {
            return null;
        }
        $name = HypervisorProtocol::text($s['name'] ?? null, 48);
        if ($name === '') {
            return null;
        }
        $total = self::int($s['total_bytes'] ?? null, 0, HypervisorProtocol::MAX_BYTES_VALUE) ?? 0;
        $used = self::int($s['used_bytes'] ?? null, 0, HypervisorProtocol::MAX_BYTES_VALUE) ?? 0;
        $h = $s['health'] ?? null;

        return ['name' => $name, 'type' => HypervisorProtocol::text($s['type'] ?? null, 24), 'total_bytes' => $total, 'used_bytes' => min($used, $total),
            'health' => is_string($h) && in_array($h, HypervisorProtocol::HEALTHS, true) ? $h : 'unknown', 'shared' => !empty($s['shared']) ? 1 : 0];
    }

    private static function state(mixed $s): string
    {
        return is_string($s) && in_array($s, HypervisorProtocol::STATES, true) ? $s : 'unknown';
    }

    private static function int(mixed $v, int $min, int $max): ?int
    {
        if (is_float($v) && $v == floor($v) && abs($v) < 9.0e15) {
            $v = (int) $v;
        }

        return is_int($v) && $v >= $min && $v <= $max ? $v : null;
    }

    private static function float(mixed $v, float $min, float $max): ?float
    {
        return (is_int($v) || is_float($v)) && is_finite((float) $v) && $v >= $min && $v <= $max ? round((float) $v, 1) : null;
    }

    /** RFC 3339 to a UTC SQL datetime; a time before 2000 or (unless $allowSkew) in the future is refused. */
    private static function time(mixed $v, int $now, bool $allowSkew): ?string
    {
        if (!is_string($v) || preg_match(self::TIME_RE, $v) !== 1) {
            return null;
        }
        try {
            $t = (new \DateTimeImmutable($v))->getTimestamp();
        } catch (\Exception) {
            return null;
        }
        if ($t < 946684800 || $t > $now + ($allowSkew ? 900 : 86400)) {
            return null;
        }

        return gmdate('Y-m-d H:i:s', $t);
    }

    // ------------------------------------------------------------------ ingest

    /**
     * Apply a cleaned report for a device. Idempotent: the same report twice leaves the same rows.
     *
     * @param array<string,mixed> $dev
     * @param array<string,mixed> $r {@see cleanReport()}
     */
    public function apply(array $dev, array $r): void
    {
        $deviceId = (int) $dev['device_id'];
        $limits = $this->settings->limits();
        $now = $this->sql->utcNow();
        $this->sql->transaction(function () use ($deviceId, $r, $limits, $now, $dev): void {
            $host = $this->sql->one('SELECT * FROM rmm_hypervisor_hosts WHERE device_id = ? FOR UPDATE', [$deviceId]);
            if ($host === null) {
                $this->sql->run('INSERT IGNORE INTO rmm_hypervisor_hosts (device_id, platform, updated_at) VALUES (?, ?, ?)', [$deviceId, $r['platform'], $now]);
                $host = $this->sql->one('SELECT * FROM rmm_hypervisor_hosts WHERE device_id = ? FOR UPDATE', [$deviceId]) ?? ['ignored' => 0];
            }
            if ((int) ($host['ignored'] ?? 0) === 1) {
                return;   // an administrator switched this host off: reports are acknowledged and not stored
            }
            $touched = null;
            if ($r['mode'] === 'summary') {
                $touched = $this->applySummary($deviceId, $r, $limits, $now);
                $this->sql->run('UPDATE rmm_hypervisor_hosts SET last_report_at = ?, cfg_version = ?, updated_at = ? WHERE device_id = ?', [$now, $r['cfg_version'], $now, $deviceId]);
            } else {
                $touched = $this->applyFull($deviceId, $r, $limits, $now);
            }
            if ($this->alerts !== null && $touched !== null) {
                $this->alerts->evaluate($dev, $r['mode'] === 'full', $touched);
            }
        });
    }

    /**
     * @param array<string,mixed> $r
     * @param array<string,int> $limits
     * @return array{transitions:array<string,array{from:string,to:string}>,vm_ids:list<int>}|null
     */
    private function applyFull(int $deviceId, array $r, array $limits, string $now): ?array
    {
        $complete = $r['error'] === '' && !$r['truncated'] && !$r['cut'];
        $cl = $r['cluster'];
        $isErrorOnly = $r['error'] !== '' && $r['vms'] === [] && $r['storage'] === [];
        if ($isErrorOnly) {
            // the host could not be read: say so, and touch nothing else (the last good inventory stays, visibly stale)
            $this->sql->run('UPDATE rmm_hypervisor_hosts SET platform = ?, error = ?, last_collected_at = ?, last_report_at = ?, cfg_version = ?, updated_at = ? WHERE device_id = ?',
                [$r['platform'], $r['error'], $r['collected_at'], $now, $r['cfg_version'], $now, $deviceId]);

            return null;
        }
        $this->sql->run('UPDATE rmm_hypervisor_hosts SET platform = ?, version = ?, truncated = ?, cfg_version = ?, last_collected_at = ?, last_full_at = ?, last_report_at = ?, error = ?, updated_at = ? WHERE device_id = ?',
            [$r['platform'], $r['version'], $r['truncated'] || $r['cut'] ? 1 : 0, $r['cfg_version'], $r['collected_at'], $now, $now, $r['error'], $now, $deviceId]);
        if ($cl !== null || $r['error'] === '') {
            // no cluster in a clean report means a stand-alone host; a report with an error may just have failed to read it, so the last known cluster stays
            $this->sql->run('UPDATE rmm_hypervisor_hosts SET cluster_name = ?, quorate = ?, cluster_nodes_json = ?, failed_groups = ? WHERE device_id = ?',
                [$cl['name'] ?? '', isset($cl['quorate']) ? ($cl['quorate'] ? 1 : 0) : null, $cl === null ? null : (string) json_encode($cl['nodes']), (int) ($cl['failed_groups'] ?? 0), $deviceId]);
        }

        $cap = min($limits['hypervisor_max_vms'], HypervisorProtocol::MAX_VMS);
        $vms = $r['vms'];
        $overCap = count($vms) > $cap;
        if ($overCap) {
            $vms = array_slice($vms, 0, $cap);
        }
        $existing = [];
        foreach ($this->sql->all('SELECT vm_id, vm_key, state FROM rmm_hypervisor_vms WHERE device_id = ?', [$deviceId]) as $row) {
            $existing[(string) $row['vm_key']] = $row;
        }
        $transitions = [];
        $newCount = 0;
        $rows = [];
        foreach ($vms as $v) {
            $old = $existing[$v['key']] ?? null;
            if ($old === null) {
                if (count($existing) + $newCount >= $cap + 200) {
                    continue;   // a hard stop against a host that invents guests faster than they are purged (missing rows count until purged)
                }
                ++$newCount;
            } elseif ((string) $old['state'] !== $v['state']) {
                $transitions[$v['key']] = ['from' => (string) $old['state'], 'to' => $v['state']];
            }
            $rows[] = $v;
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            $this->upsertVms($deviceId, $chunk, $now);
        }
        $ids = [];
        foreach ($this->sql->all('SELECT vm_id, vm_key FROM rmm_hypervisor_vms WHERE device_id = ?', [$deviceId]) as $row) {
            $ids[(string) $row['vm_key']] = (int) $row['vm_id'];
        }
        $this->recordTransitions($deviceId, $transitions, $ids, $limits['hypervisor_history_per_vm'], $now);
        if ($complete && !$overCap) {
            $keys = array_map(static fn (array $v): string => $v['key'], $rows);
            if ($keys === []) {
                $this->sql->run('UPDATE rmm_hypervisor_vms SET missing_since = COALESCE(missing_since, ?) WHERE device_id = ?', [$now, $deviceId]);
            } else {
                $this->sql->run('UPDATE rmm_hypervisor_vms SET missing_since = COALESCE(missing_since, ?) WHERE device_id = ? AND vm_key NOT IN (' . implode(',', array_fill(0, count($keys), '?')) . ')', [$now, $deviceId, ...$keys]);
            }
        }
        $this->sql->run('DELETE FROM rmm_hypervisor_vms WHERE device_id = ? AND missing_since IS NOT NULL AND missing_since < ?', [$deviceId, $this->sql->utcAt(-$limits['hypervisor_retention_days'] * 86400)]);

        $this->upsertStorage($deviceId, $r['storage'], $complete, $limits, $now);
        $count = (int) $this->sql->val('SELECT COUNT(*) FROM rmm_hypervisor_vms WHERE device_id = ? AND missing_since IS NULL AND template = 0', [$deviceId]);
        $this->sql->run('UPDATE rmm_hypervisor_hosts SET vm_count = ? WHERE device_id = ?', [$count, $deviceId]);

        return ['transitions' => $transitions, 'vm_ids' => array_values($ids)];
    }

    /**
     * @param list<array<string,mixed>> $vms
     */
    private function upsertVms(int $deviceId, array $vms, string $now): void
    {
        $values = [];
        $params = [];
        foreach ($vms as $v) {
            $values[] = '(' . implode(', ', array_fill(0, 25, '?')) . ', NULL)';
            array_push($params, $deviceId, $v['key'], $v['name'], $v['type'], $v['state'], $now, $v['node'], $v['cpus'], $v['cpu_pct'], $v['mem_bytes'], $v['mem_max_bytes'], $v['disk_bytes'], $v['disk_used_bytes'],
                $v['uptime_s'], $v['template'] ? 1 : 0, $v['tags'], $v['snapshot_count'], $v['oldest_snapshot_at'], $v['last_backup_at'], $v['last_backup_result'], $v['backup_covered'], $v['guest_agent'],
                $v['replication'] ?? '', $now, $now);
        }
        // state_changed_at is assigned BEFORE state, so the comparison still sees the old state (assignments run left to right).
        $this->sql->run('INSERT INTO rmm_hypervisor_vms (device_id, vm_key, name, type, state, state_changed_at, node, cpus, cpu_pct, mem_bytes, mem_max_bytes, disk_bytes, disk_used_bytes, uptime_s, template, tags,
            snapshot_count, oldest_snapshot_at, last_backup_at, last_backup_result, backup_covered, guest_agent, replication, first_seen_at, last_seen_at, missing_since)
            VALUES ' . implode(', ', $values) . ' ON DUPLICATE KEY UPDATE
            state_changed_at = IF(state <> VALUES(state), VALUES(state_changed_at), state_changed_at), state = VALUES(state),
            name = VALUES(name), type = VALUES(type), node = VALUES(node), cpus = VALUES(cpus), cpu_pct = VALUES(cpu_pct), mem_bytes = VALUES(mem_bytes), mem_max_bytes = VALUES(mem_max_bytes),
            disk_bytes = VALUES(disk_bytes), disk_used_bytes = VALUES(disk_used_bytes), uptime_s = VALUES(uptime_s), template = VALUES(template), tags = VALUES(tags),
            oldest_snapshot_at = IF(VALUES(snapshot_count) IS NULL, oldest_snapshot_at, VALUES(oldest_snapshot_at)), snapshot_count = COALESCE(VALUES(snapshot_count), snapshot_count),
            last_backup_result = IF(VALUES(last_backup_at) IS NULL, last_backup_result, VALUES(last_backup_result)), last_backup_at = IF(VALUES(last_backup_at) IS NULL, last_backup_at, VALUES(last_backup_at)),
            backup_covered = COALESCE(VALUES(backup_covered), backup_covered), guest_agent = COALESCE(VALUES(guest_agent), guest_agent),
            replication = IF(VALUES(replication) = \'\', replication, VALUES(replication)), last_seen_at = VALUES(last_seen_at), missing_since = NULL', $params);
    }

    /**
     * @param array<string,mixed> $r
     * @param array<string,int> $limits
     * @return array{transitions:array<string,array{from:string,to:string}>,vm_ids:list<int>}
     */
    private function applySummary(int $deviceId, array $r, array $limits, string $now): array
    {
        $transitions = [];
        $ids = [];
        foreach (array_chunk($r['changes'], 200) as $chunk) {
            $keys = array_map(static fn (array $c): string => $c['key'], $chunk);
            $have = [];
            foreach ($this->sql->all('SELECT vm_id, vm_key, state FROM rmm_hypervisor_vms WHERE device_id = ? AND missing_since IS NULL AND vm_key IN (' . implode(',', array_fill(0, count($keys), '?')) . ')', [$deviceId, ...$keys]) as $row) {
                $have[(string) $row['vm_key']] = $row;
            }
            foreach ($chunk as $c) {
                $row = $have[$c['key']] ?? null;
                if ($row === null || (string) $row['state'] === $c['state']) {
                    continue;   // unknown to us (the next full report adds it) or no real change
                }
                $this->sql->run('UPDATE rmm_hypervisor_vms SET state = ?, state_changed_at = ?, uptime_s = IF(? = \'running\', uptime_s, NULL), cpu_pct = IF(? = \'running\', cpu_pct, NULL),
                    mem_bytes = IF(? = \'running\', mem_bytes, NULL), last_seen_at = ? WHERE vm_id = ?', [$c['state'], $now, $c['state'], $c['state'], $c['state'], $now, $row['vm_id']]);
                $transitions[$c['key']] = ['from' => (string) $row['state'], 'to' => $c['state']];
                $ids[(string) $row['vm_key']] = (int) $row['vm_id'];
            }
        }
        $this->recordTransitions($deviceId, $transitions, $ids, $limits['hypervisor_history_per_vm'], $now);

        return ['transitions' => $transitions, 'vm_ids' => array_values($ids)];
    }

    /**
     * @param array<string,array{from:string,to:string}> $transitions
     * @param array<string,int> $ids vm_key => vm_id
     */
    private function recordTransitions(int $deviceId, array $transitions, array $ids, int $ring, string $now): void
    {
        foreach ($transitions as $key => $t) {
            $vmId = $ids[$key] ?? 0;
            if ($vmId === 0) {
                continue;
            }
            $this->vmHistory->recordTransition($vmId, $deviceId, $now, $t['from'], $t['to'], $ring);
        }
    }

    /**
     * @param list<array<string,mixed>> $storage
     * @param array<string,int> $limits
     */
    private function upsertStorage(int $deviceId, array $storage, bool $complete, array $limits, string $now): void
    {
        foreach (array_chunk($storage, 100) as $chunk) {
            $values = [];
            $params = [];
            foreach ($chunk as $s) {
                $values[] = '(?, ?, ?, ?, ?, ?, ?, ?, NULL)';
                array_push($params, $deviceId, $s['name'], $s['type'], $s['total_bytes'], $s['used_bytes'], $s['health'], $s['shared'], $now);
            }
            $this->sql->run('INSERT INTO rmm_hypervisor_storage (device_id, name, type, total_bytes, used_bytes, health, shared, last_seen_at, missing_since) VALUES ' . implode(', ', $values)
                . ' ON DUPLICATE KEY UPDATE type = VALUES(type), total_bytes = VALUES(total_bytes), used_bytes = VALUES(used_bytes), health = VALUES(health), shared = VALUES(shared), last_seen_at = VALUES(last_seen_at), missing_since = NULL', $params);
        }
        if ($complete) {
            $names = array_map(static fn (array $s): string => $s['name'], $storage);
            if ($names === []) {
                $this->sql->run('UPDATE rmm_hypervisor_storage SET missing_since = COALESCE(missing_since, ?) WHERE device_id = ?', [$now, $deviceId]);
            } else {
                $this->sql->run('UPDATE rmm_hypervisor_storage SET missing_since = COALESCE(missing_since, ?) WHERE device_id = ? AND name NOT IN (' . implode(',', array_fill(0, count($names), '?')) . ')', [$now, $deviceId, ...$names]);
            }
        }
        $this->sql->run('DELETE FROM rmm_hypervisor_storage WHERE device_id = ? AND missing_since IS NOT NULL AND missing_since < ?', [$deviceId, $this->sql->utcAt(-$limits['hypervisor_retention_days'] * 86400)]);
    }

    // ------------------------------------------------------------------ housekeeping

    /**
     * Purge what is past retention or belongs to a device that no longer exists. Bounded.
     *
     * @return array<string,int>
     */
    public function prune(): array
    {
        $limits = $this->settings->limits();
        $cut = $this->sql->utcAt(-$limits['hypervisor_retention_days'] * 86400);
        $out = [];
        $out['vms'] = $this->sql->run('DELETE FROM rmm_hypervisor_vms WHERE missing_since IS NOT NULL AND missing_since < ? LIMIT 5000', [$cut]);
        $out['storage'] = $this->sql->run('DELETE FROM rmm_hypervisor_storage WHERE missing_since IS NOT NULL AND missing_since < ? LIMIT 5000', [$cut]);
        $out['orphans'] = $this->sql->run('DELETE v FROM rmm_hypervisor_vms v LEFT JOIN endpoint_agent_devices d ON d.device_id = v.device_id WHERE d.device_id IS NULL');
        $this->sql->run('DELETE s FROM rmm_hypervisor_storage s LEFT JOIN endpoint_agent_devices d ON d.device_id = s.device_id WHERE d.device_id IS NULL');
        $this->sql->run('DELETE h FROM rmm_hypervisor_hosts h LEFT JOIN endpoint_agent_devices d ON d.device_id = h.device_id WHERE d.device_id IS NULL');
        $out['history'] = $this->vmHistory->deleteOrphans();

        return $out;
    }
}
