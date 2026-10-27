<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\Postgres;

use RivetCore\Rmm\Store\InventoryAwareHypervisorHistoryStore;

/**
 * {@see HypervisorHistoryStore} on PostgreSQL (`rmm_hypervisor_vm_history`). The VM inventory is in the control database, so the orphan sweep asks the caller which
 * of the VM ids still exist.
 *
 * @internal
 */
final class PostgresHypervisorHistoryStore implements InventoryAwareHypervisorHistoryStore
{
    private const SWEEP_CHUNK = 500;

    public function __construct(private readonly PostgresConnectionInterface $db)
    {
    }

    public function recordTransition(int $vmId, int $deviceId, string $atUtc, string $from, string $to, int $ring): void
    {
        $this->db->transaction(function () use ($vmId, $deviceId, $atUtc, $from, $to, $ring): void {
            $this->db->execute('INSERT INTO rmm_hypervisor_vm_history (vm_id, device_id, "at", from_state, to_state) VALUES (?, ?, ?, ?, ?)', [$vmId, $deviceId, $atUtc, $from, $to]);
            $cut = $this->db->value('SELECT history_id FROM rmm_hypervisor_vm_history WHERE vm_id = ? ORDER BY history_id DESC LIMIT 1 OFFSET ' . max(1, $ring), [$vmId]);
            if ($cut !== null) {
                $this->db->execute('DELETE FROM rmm_hypervisor_vm_history WHERE vm_id = ? AND history_id <= ?', [$vmId, (int) $cut]);
            }
        });
    }

    public function recent(int $vmId, int $limit = 100): array
    {
        $out = [];
        foreach ($this->db->query('SELECT ' . PostgresSql::ts('"at"', '"at"') . ', from_state, to_state FROM rmm_hypervisor_vm_history WHERE vm_id = ? ORDER BY history_id DESC LIMIT ' . max(1, $limit), [$vmId]) as $h) {
            $out[] = ['at' => (string) $h['at'], 'from_state' => (string) $h['from_state'], 'to_state' => (string) $h['to_state']];
        }

        return $out;
    }

    public function deleteOrphans(?\Closure $existingVmIds = null): int
    {
        if ($existingVmIds === null) {
            return 0;   // without the inventory nothing can be called an orphan: keep every row
        }
        $deleted = 0;
        $ids = array_map(static fn (array $r): int => (int) $r['vm_id'], $this->db->query('SELECT DISTINCT vm_id FROM rmm_hypervisor_vm_history'));
        foreach (array_chunk($ids, self::SWEEP_CHUNK) as $chunk) {
            $alive = array_flip(array_map('intval', $existingVmIds($chunk)));
            $gone = array_values(array_filter($chunk, static fn (int $id): bool => !isset($alive[$id])));
            if ($gone !== []) {
                $deleted += $this->db->execute('DELETE FROM rmm_hypervisor_vm_history WHERE vm_id IN (' . PostgresSql::in(count($gone)) . ')', $gone);
            }
        }

        return $deleted;
    }
}
