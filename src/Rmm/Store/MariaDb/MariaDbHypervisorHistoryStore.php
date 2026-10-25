<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\MariaDb;

use RivetCore\Rmm\Store\HypervisorHistoryStore;
use RivetCore\Rmm\Support\Sql;

/**
 * {@see HypervisorHistoryStore} on MariaDB / MySQL: the statements the hypervisor service and reader ran before the store existed, unchanged. The orphan sweep
 * joins the VM inventory table (the same database).
 *
 * @internal
 */
final class MariaDbHypervisorHistoryStore implements HypervisorHistoryStore
{
    public function __construct(private readonly Sql $sql)
    {
    }

    public function recordTransition(int $vmId, int $deviceId, string $atUtc, string $from, string $to, int $ring): void
    {
        $this->sql->run('INSERT INTO rmm_hypervisor_vm_history (vm_id, device_id, at, from_state, to_state) VALUES (?, ?, ?, ?, ?)', [$vmId, $deviceId, $atUtc, $from, $to]);
        $cut = $this->sql->val('SELECT history_id FROM rmm_hypervisor_vm_history WHERE vm_id = ? ORDER BY history_id DESC LIMIT 1 OFFSET ' . max(1, $ring), [$vmId]);
        if ($cut !== null && $cut !== false) {
            $this->sql->run('DELETE FROM rmm_hypervisor_vm_history WHERE vm_id = ? AND history_id <= ?', [$vmId, (int) $cut]);
        }
    }

    public function recent(int $vmId, int $limit = 100): array
    {
        $out = [];
        foreach ($this->sql->all('SELECT at, from_state, to_state FROM rmm_hypervisor_vm_history WHERE vm_id = ? ORDER BY history_id DESC LIMIT ' . max(1, $limit), [$vmId]) as $h) {
            $out[] = ['at' => (string) $h['at'], 'from_state' => (string) $h['from_state'], 'to_state' => (string) $h['to_state']];
        }

        return $out;
    }

    /** The inventory is in this database: $existingVmIds is not needed. */
    public function deleteOrphans(?\Closure $existingVmIds = null): int
    {
        return $this->sql->run('DELETE h FROM rmm_hypervisor_vm_history h LEFT JOIN rmm_hypervisor_vms v ON v.vm_id = h.vm_id WHERE v.vm_id IS NULL');
    }
}
