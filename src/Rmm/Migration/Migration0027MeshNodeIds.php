<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Migration;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Migration\MigrationInterface;
use RivetCore\Rmm\Mesh\MeshNodeId;

/**
 * Rewrites the stored MeshCentral node ids to the canonical `node/<domain>/<64-character id>` form ({@see MeshNodeId}): what an agent reported as 96 hex
 * characters is the same node as the base64 form MeshCentral uses in its URLs, and the launch link only finds the device with the latter.
 *
 * Touches `endpoint_agent_mesh_nodes.mesh_node_id` and `endpoint_agent_mesh_agents.node_id`. A value that cannot be parsed is left alone (it still launches, through
 * the legacy rule). Two devices that now resolve to the same node: the manual mapping is kept; an automatic mapping that loses is removed and its device is flagged
 * (`map_state` = conflict, `conflict_device_id` = the keeper) so the next report shows it. Two manual mappings are both kept (an administrator chose them).
 * Idempotent: a second run finds every row canonical and changes nothing. Runtime lookups also match the old spellings, so the system works before and after.
 *
 * @internal
 */
final class Migration0027MeshNodeIds implements MigrationInterface
{
    public function id(): string
    {
        return '0027_rmm_mesh_node_ids';
    }

    public function up(DatabaseInterface $database): void
    {
        if ($this->tableExists($database, 'endpoint_agent_mesh_nodes')) {
            $this->nodes($database);
        }
        if ($this->tableExists($database, 'endpoint_agent_mesh_agents')) {
            foreach ($database->fetchAll('SELECT device_id, node_id FROM endpoint_agent_mesh_agents WHERE node_id <> ?', ['']) as $r) {
                $c = MeshNodeId::normalize((string) $r['node_id']);
                if ($c !== null && $c !== (string) $r['node_id']) {
                    $database->execute('UPDATE endpoint_agent_mesh_agents SET node_id = ? WHERE device_id = ?', [$c, (int) $r['device_id']]);
                }
            }
        }
    }

    private function nodes(DatabaseInterface $database): void
    {
        /** @var array<string,list<array{device_id:int,source:string,canonical:bool}>> $groups */
        $groups = [];
        foreach ($database->fetchAll('SELECT device_id, mesh_node_id, source FROM endpoint_agent_mesh_nodes ORDER BY device_id') as $r) {
            $c = MeshNodeId::normalize((string) $r['mesh_node_id']);
            if ($c !== null) {
                $groups[$c][] = ['device_id' => (int) $r['device_id'], 'source' => (string) $r['source'], 'canonical' => $c === (string) $r['mesh_node_id']];
            }
        }
        foreach ($groups as $canonical => $rows) {
            $keeper = $rows[0];
            foreach ($rows as $r) {   // manual first, then one already canonical, then the lowest device id (the rows are ordered by it)
                $rank = static fn (array $x): int => ($x['source'] !== 'agent' ? 2 : 0) + ($x['canonical'] ? 1 : 0);
                if ($rank($r) > $rank($keeper)) {
                    $keeper = $r;
                }
            }
            foreach ($rows as $r) {
                if ($r['device_id'] !== $keeper['device_id'] && $r['source'] === 'agent') {
                    $database->execute('DELETE FROM endpoint_agent_mesh_nodes WHERE device_id = ?', [$r['device_id']]);
                    if ($this->tableExists($database, 'endpoint_agent_mesh_agents')) {
                        $database->execute("UPDATE endpoint_agent_mesh_agents SET map_state = 'conflict', conflict_device_id = ? WHERE device_id = ?", [$keeper['device_id'], $r['device_id']]);
                    }
                } elseif (!$r['canonical']) {
                    $database->execute('UPDATE endpoint_agent_mesh_nodes SET mesh_node_id = ? WHERE device_id = ?', [$canonical, $r['device_id']]);
                }
            }
        }
    }

    private function tableExists(DatabaseInterface $database, string $table): bool
    {
        return $database->fetchOne('SELECT 1 AS x FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]) !== null;
    }
}
