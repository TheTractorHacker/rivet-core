<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Hypervisor;

use RivetCore\Rmm\Checks\CheckEvaluator;
use RivetCore\Rmm\RmmEvent;
use RivetCore\Rmm\Support\RmmEventPublisher;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Support\Sql;

/**
 * Turns what a hypervisor host reported into alerts through the ordinary check pipeline (so debounce, flap detection, maintenance windows, escalation and the bridge to the
 * edition's tickets all apply) and into webhook events. Check keys are `hv.vmdown.*`, `hv.backup.*`, `hv.snapshot.*`, `hv.storage.*`, `hv.pool.*` and `hv.quorum`.
 *
 * An alert resolves by itself: the next report that no longer shows the condition (or shows a VM that is ignored, a template, missing or gone) sends `ok` for the open key. A VM that stays
 * stopped for `hypervisor_vm_down_resolve_h` hours is taken as switched off on purpose and its alert resolves. A VM first seen stopped never alerts (it was never seen running).
 * Every text that reaches an alert or an event passes {@see HypervisorProtocol::alertText()}, so a VM named like markup injects nothing.
 *
 * @api
 */
final class HypervisorAlerts
{
    public function __construct(
        private readonly Sql $sql,
        private readonly RmmSettings $settings,
        private readonly CheckEvaluator $checks,
        private readonly ?RmmEventPublisher $events = null,
    ) {
    }

    /**
     * @param array<string,mixed> $dev
     * @param array{transitions:array<string,array{from:string,to:string}>,vm_ids:list<int>} $touched
     */
    public function evaluate(array $dev, bool $full, array $touched): void
    {
        $deviceId = (int) $dev['device_id'];
        $limits = $this->settings->limits();
        $cfg = $this->settings->get();
        $failN = max(1, (int) ($cfg['failure_debounce'] ?? 1));
        $okN = max(1, (int) ($cfg['recovery_debounce'] ?? 1));
        $now = $this->sql->time();
        $open = [];
        foreach ($this->sql->all("SELECT check_key, status FROM endpoint_agent_checks WHERE device_id = ? AND check_key LIKE 'hv!.%' ESCAPE '!'", [$deviceId]) as $row) {
            $open[(string) $row['check_key']] = in_array((string) $row['status'], ['warn', 'fail'], true);
        }
        /** @var array<string,array{status:string,detail:string,copies:int,event:?array{0:string,1:array<string,mixed>}}> $want */
        $want = [];
        $host = $this->sql->one('SELECT * FROM rmm_hypervisor_hosts WHERE device_id = ?', [$deviceId]) ?? [];
        $vmWhere = 'device_id = ? AND missing_since IS NULL';
        $params = [$deviceId];
        if (!$full) {
            if ($touched['vm_ids'] === []) {
                return;
            }
            $vmWhere .= ' AND vm_id IN (' . implode(',', array_fill(0, count($touched['vm_ids']), '?')) . ')';
            array_push($params, ...$touched['vm_ids']);
        }
        foreach ($this->sql->all('SELECT * FROM rmm_hypervisor_vms WHERE ' . $vmWhere . ' LIMIT 1100', $params) as $vm) {
            if ((int) $vm['ignored'] === 1 || (int) $vm['template'] === 1) {
                continue;   // desired state: nothing; an open alert of this VM resolves below
            }
            $this->vm($vm, $touched['transitions'][(string) $vm['vm_key']] ?? null, $open, $limits, $now, $failN, $okN, $want, $dev, $full);
        }
        if ($full) {
            $this->storage($deviceId, $open, $limits, $want, $failN);
            $q = $host['quorate'] ?? null;
            if ($q !== null && $limits['hypervisor_alert_quorum'] === 1) {
                $nodes = json_decode((string) ($host['cluster_nodes_json'] ?? '[]'), true);
                $nodes = is_array($nodes) ? $nodes : [];
                $on = count(array_filter($nodes, static fn ($n): bool => is_array($n) && !empty($n['online'])));
                $name = HypervisorProtocol::alertText((string) ($host['cluster_name'] ?? ''));
                $want['hv.quorum'] = (int) $q === 0
                    ? ['status' => 'fail', 'detail' => 'Cluster ' . ($name === '' ? '' : $name . ' ') . 'lost quorum (' . $on . ' of ' . count($nodes) . ' nodes online as seen from this host).', 'copies' => $failN,
                        'event' => [RmmEvent::HYPERVISOR_CLUSTER_QUORUM_LOST, ['cluster' => $name, 'platform' => (string) ($host['platform'] ?? ''), 'nodes_online' => $on, 'nodes_total' => count($nodes), 'alert_check_key' => 'hv.quorum']]]
                    : ['status' => 'ok', 'detail' => 'Cluster has quorum.', 'copies' => 1, 'event' => null];
            }
        }
        $at = $this->sql->utcNow();
        $results = [];
        foreach ($want as $key => $w) {
            $isOpen = $open[$key] ?? false;
            if ($w['status'] === 'ok') {
                if ($isOpen) {
                    for ($i = 0; $i < $w['copies']; ++$i) {
                        $results[] = ['key' => $key, 'status' => 'ok', 'detail' => $w['detail'], 'at' => $at];
                    }
                }
                continue;
            }
            for ($i = 0; $i < $w['copies']; ++$i) {
                $results[] = ['key' => $key, 'status' => $w['status'], 'detail' => $w['detail'], 'at' => $at];
            }
            if (!$isOpen && $w['event'] !== null) {
                $this->events?->emit($w['event'][0], $dev, $w['event'][1]);
            }
        }
        if ($full) {
            // an open alert whose condition was not evaluated at all any more (the VM is gone, ignored, a template, or the switch for that class was turned off) resolves
            foreach ($open as $key => $isOpen) {
                if ($isOpen && !isset($want[$key])) {
                    for ($i = 0; $i < $okN; ++$i) {
                        $results[] = ['key' => $key, 'status' => 'ok', 'detail' => 'No longer applicable.', 'at' => $at];   // gone, ignored or switched off: resolved at once
                    }
                }
            }
        }
        if ($results !== []) {
            $this->checks->apply($dev, $results);
        }
    }

    /**
     * @param array<string,mixed> $vm
     * @param array{from:string,to:string}|null $trans
     * @param array<string,bool> $open
     * @param array<string,int> $limits
     * @param array<string,array<string,mixed>> $want
     * @param array<string,mixed> $dev
     */
    private function vm(array $vm, ?array $trans, array $open, array $limits, int $now, int $failN, int $okN, array &$want, array $dev, bool $full): void
    {
        $key = (string) $vm['vm_key'];
        $name = HypervisorProtocol::alertText((string) $vm['name']);
        $label = ($name === '' ? $key : $name) . ' (' . HypervisorProtocol::alertText($key, 40) . ')';
        $base = ['vm_key' => $key, 'vm_name' => $name, 'host_device_id' => (int) $dev['device_id']];

        // ---- stopped
        $kd = HypervisorProtocol::vmCheckKey('vmdown', $key);
        $down = in_array((string) $vm['state'], ['stopped', 'error'], true);
        $started = $trans !== null && in_array($trans['to'], ['stopped', 'error'], true) && in_array($trans['from'], ['running', 'paused', 'transitioning', 'suspended'], true);
        $sinceH = ($now - Sql::ts((string) ($vm['state_changed_at'] ?? ''))) / 3600;
        $ev = null;
        if ($started) {
            $ev = [RmmEvent::HYPERVISOR_VM_DOWN, $base + ['vm_type' => (string) $vm['type'], 'from_state' => $trans['from'], 'to_state' => $trans['to'], 'node' => (string) $vm['node'], 'alert_check_key' => $kd]];
            if ($limits['hypervisor_alert_vm_down'] !== 1) {
                $this->events?->emit($ev[0], $dev, $ev[1]);
                $ev = null;
            }
        }
        if ($limits['hypervisor_alert_vm_down'] === 1) {
            if ($down && (($open[$kd] ?? false) || $started) && $sinceH < $limits['hypervisor_vm_down_resolve_h']) {
                $want[$kd] = ['status' => 'warn', 'detail' => 'VM ' . $label . ' is ' . $vm['state'] . ' on ' . HypervisorProtocol::alertText((string) $vm['node'], 64) . '.', 'copies' => $failN, 'event' => $started ? $ev : null];
            } else {
                $want[$kd] = ['status' => 'ok', 'detail' => $down ? 'Stopped for a long time; taken as switched off on purpose.' : 'VM is ' . $vm['state'] . '.', 'copies' => $okN, 'event' => null];
            }
        }

        // ---- backup (Proxmox reports it; Hyper-V has no backup data and is skipped)
        $hasBackup = $vm['last_backup_at'] !== null || (int) ($vm['backup_covered'] ?? 0) === 1;
        if ($full && $hasBackup && $limits['hypervisor_alert_backup'] === 1) {
            $kb = HypervisorProtocol::vmCheckKey('backup', $key);
            $days = $limits['hypervisor_backup_days'];
            $age = $vm['last_backup_at'] === null ? null : (int) floor(($now - Sql::ts((string) $vm['last_backup_at'])) / 86400);
            $firstOld = ($now - Sql::ts((string) $vm['first_seen_at'])) > $days * 86400;
            $reason = null;
            $status = 'ok';
            if ((string) $vm['last_backup_result'] === 'failed') {
                [$reason, $status] = ['failed', 'fail'];
            } elseif ($age !== null && $age >= $days) {
                [$reason, $status] = ['stale', 'warn'];
            } elseif ($age === null && (int) ($vm['backup_covered'] ?? 0) === 1 && $firstOld) {
                [$reason, $status] = ['stale', 'warn'];
            } elseif ((string) $vm['last_backup_result'] === 'warning') {
                [$reason, $status] = ['warning', 'warn'];
            }
            if ($status === 'ok') {
                $want[$kb] = ['status' => 'ok', 'detail' => 'Backup is current.', 'copies' => 1, 'event' => null];
            } else {
                $detail = match ($reason) {
                    'failed' => 'The last backup of VM ' . $label . ' failed.',
                    'warning' => 'The last backup of VM ' . $label . ' finished with warnings.',
                    default => $age === null ? 'VM ' . $label . ' is in a backup job but no backup was found.' : 'The last backup of VM ' . $label . ' is ' . $age . ' days old (limit ' . $days . ').',
                };
                $want[$kb] = ['status' => $status, 'detail' => $detail, 'copies' => $reason === 'failed' ? $failN : 1, 'event' => [RmmEvent::HYPERVISOR_BACKUP_FAILED, $base + ['reason' => $reason, 'last_backup_at' => $vm['last_backup_at'] === null ? null : Sql::iso((string) $vm['last_backup_at']),
                    'last_backup_result' => $vm['last_backup_result'], 'age_days' => $age, 'threshold_days' => $days, 'alert_check_key' => $kb]]];
            }
        }

        // ---- old snapshot
        if ($full && $vm['snapshot_count'] !== null && $limits['hypervisor_alert_snapshot'] === 1) {
            $ks = HypervisorProtocol::vmCheckKey('snapshot', $key);
            $days = $limits['hypervisor_snapshot_days'];
            $age = (int) $vm['snapshot_count'] > 0 && $vm['oldest_snapshot_at'] !== null ? (int) floor(($now - Sql::ts((string) $vm['oldest_snapshot_at'])) / 86400) : null;
            if ($age !== null && $age >= $days) {
                $want[$ks] = ['status' => 'warn', 'detail' => 'VM ' . $label . ' has a snapshot that is ' . $age . ' days old (limit ' . $days . ').', 'copies' => 1,
                    'event' => [RmmEvent::HYPERVISOR_SNAPSHOT_OLD, $base + ['snapshot_count' => (int) $vm['snapshot_count'], 'oldest_snapshot_at' => Sql::iso((string) $vm['oldest_snapshot_at']), 'age_days' => $age, 'threshold_days' => $days, 'alert_check_key' => $ks]]];
            } else {
                $want[$ks] = ['status' => 'ok', 'detail' => 'No old snapshot.', 'copies' => 1, 'event' => null];
            }
        }
    }

    /**
     * @param array<string,bool> $open
     * @param array<string,int> $limits
     * @param array<string,array<string,mixed>> $want
     */
    private function storage(int $deviceId, array $open, array $limits, array &$want, int $failN): void
    {
        foreach ($this->sql->all('SELECT * FROM rmm_hypervisor_storage WHERE device_id = ? AND missing_since IS NULL LIMIT 250', [$deviceId]) as $s) {
            $name = HypervisorProtocol::alertText((string) $s['name'], 48);
            $type = HypervisorProtocol::alertText((string) $s['type'], 24);
            if ($limits['hypervisor_alert_storage'] === 1 && (int) $s['total_bytes'] > 0 && (string) $s['type'] !== 'ceph') {
                $pct = (int) floor((int) $s['used_bytes'] * 100 / (int) $s['total_bytes']);
                $k = HypervisorProtocol::CHECK_PREFIX . 'storage.' . HypervisorProtocol::keyPart((string) $s['name']);
                $level = $pct >= $limits['hypervisor_storage_crit_pct'] ? 'fail' : ($pct >= $limits['hypervisor_storage_warn_pct'] ? 'warn' : 'ok');
                $want[$k] = $level === 'ok'
                    ? ['status' => 'ok', 'detail' => 'Storage ' . $name . ' is at ' . $pct . '%.', 'copies' => 1, 'event' => null]
                    : ['status' => $level, 'detail' => 'Storage ' . $name . ' is ' . $pct . '% full.', 'copies' => 1, 'event' => [RmmEvent::HYPERVISOR_STORAGE_LOW,
                        ['storage' => $name, 'type' => $type, 'used_pct' => $pct, 'level' => $level, 'total_bytes' => (int) $s['total_bytes'], 'used_bytes' => (int) $s['used_bytes'], 'alert_check_key' => $k]]];
            }
            if ($limits['hypervisor_alert_pool'] === 1) {
                $k = HypervisorProtocol::CHECK_PREFIX . 'pool.' . HypervisorProtocol::keyPart((string) $s['name']);
                $h = (string) $s['health'];
                if ($h === 'warn' || $h === 'error') {
                    $want[$k] = ['status' => $h === 'error' ? 'fail' : 'warn', 'detail' => ($type === 'zfs_pool' ? 'ZFS pool ' : ($type === 'ceph' ? 'Ceph ' : 'Storage ')) . $name . ' is ' . ($h === 'error' ? 'faulted or unavailable' : 'degraded') . '.', 'copies' => $failN,
                        'event' => [RmmEvent::HYPERVISOR_POOL_DEGRADED, ['storage' => $name, 'type' => $type, 'health' => $h, 'alert_check_key' => $k]]];
                } elseif ($h === 'ok') {
                    $want[$k] = ['status' => 'ok', 'detail' => 'Healthy.', 'copies' => 1, 'event' => null];
                }
            }
        }
    }
}
