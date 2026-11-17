<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Mesh;

use RivetCore\Rmm\Support\Sql;

/**
 * The read model of "why is this device not mapped to a MeshCentral node": for every linked device the exact state and a reason a person can read (1.0.0-rc.67).
 *
 *  - `not_installed`: the device has not reported a MeshCentral agent (and has no node mapped);
 *  - `installed_no_node_reported`: the agent is installed but the device has not said which node it is;
 *  - `reported_needs_approval`: the device reported a node that nobody approved, so nothing was mapped (an administrator maps it, or the verification does);
 *  - `conflict`: the reported node is held by, or also reported by, another device;
 *  - `kept_manual`: the device reported a different node than the one an administrator mapped by hand, which was kept;
 *  - `mapped`: a node is mapped (by an administrator, by the old approval of a retired device, or verified against MeshCentral).
 *
 * Read only. The caller decides who may see which device (see {@see \RivetCore\Rmm\Technician\TechnicianActions::meshMappings()}).
 *
 * @api
 */
final class MeshMapping
{
    public const NOT_INSTALLED = 'not_installed';
    public const NO_NODE = 'installed_no_node_reported';
    public const NEEDS_APPROVAL = 'reported_needs_approval';
    public const CONFLICT = 'conflict';
    public const KEPT_MANUAL = 'kept_manual';
    public const MAPPED = 'mapped';
    public const STATES = [self::NOT_INSTALLED, self::NO_NODE, self::NEEDS_APPROVAL, self::CONFLICT, self::KEPT_MANUAL, self::MAPPED];

    /** The text of `reported_needs_approval` is the message the edition device pages already show. */
    public const TEXT_NEEDS_APPROVAL = 'This device reported a Mesh node that has not been approved yet, so nothing was mapped. Check this node in MeshCentral, then map it.';

    public function __construct(private readonly Sql $sql)
    {
    }

    /**
     * @return array<string,mixed>|null null when there is no such device
     */
    public function forDevice(int $deviceId): ?array
    {
        $rows = $this->rows('d.device_id = ?', [$deviceId], 1, 0);

        return $rows === [] ? null : $rows[0];
    }

    /**
     * Linked, live devices of the clients in `$clients` (null = all), newest first by device id.
     *
     * @param list<int>|null $clients
     * @param array{state?:string,client_id?:int,q?:string} $filters
     * @return array{items:list<array<string,mixed>>,total:int,counts:array<string,int>}
     */
    public function fleet(?array $clients, array $filters, int $limit, int $offset): array
    {
        if ($clients === []) {
            return ['items' => [], 'total' => 0, 'counts' => array_fill_keys(self::STATES, 0)];
        }
        [$where, $params] = $this->scope($clients, $filters['client_id'] ?? 0, (string) ($filters['q'] ?? ''));
        // The state is computed from several tables: filter and count in PHP over the scoped ids (bounded by the device count of the caller's clients).
        $all = $this->rows($where, $params, 100000, 0);
        $counts = array_fill_keys(self::STATES, 0);
        foreach ($all as $r) {
            ++$counts[$r['state']];
        }
        $want = $filters['state'] ?? '';
        if ($want !== '') {
            $all = array_values(array_filter($all, static fn (array $r): bool => $r['state'] === $want));
        }

        return ['items' => array_slice($all, $offset, $limit), 'total' => count($all), 'counts' => $counts];
    }

    /**
     * @param list<int>|null $clients
     * @return array{0:string,1:list<mixed>}
     */
    private function scope(?array $clients, int $client, string $q): array
    {
        $w = ["d.link_state = 'linked'", 'd.revoked_at IS NULL', 'd.retired_at IS NULL'];
        $p = [];
        if ($clients !== null) {
            $w[] = 'd.client_id IN (' . implode(',', array_fill(0, count($clients), '?')) . ')';
            array_push($p, ...$clients);
        }
        if ($client > 0) {
            $w[] = 'd.client_id = ?';
            $p[] = $client;
        }
        if ($q !== '') {
            $w[] = 'd.hostname LIKE ?';
            $p[] = '%' . addcslashes(mb_substr($q, 0, 100), '%_\\') . '%';
        }

        return [implode(' AND ', $w), $p];
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string,mixed>>
     */
    private function rows(string $where, array $params, int $limit, int $offset): array
    {
        $hasVerify = $this->hasVerifyColumn();
        $sql = 'SELECT d.device_id, d.hostname, d.client_id, d.asset_id, d.revoked_at, d.retired_at, a.installed, a.version, a.node_id, a.map_state, a.conflict_device_id, a.reported_at,'
            . ($hasVerify ? ' a.verify_json,' : ' NULL AS verify_json,') . ' n.mesh_node_id AS mapped_node, n.source AS mapped_source
            FROM endpoint_agent_devices d LEFT JOIN endpoint_agent_mesh_agents a ON a.device_id = d.device_id LEFT JOIN endpoint_agent_mesh_nodes n ON n.device_id = d.device_id
            WHERE ' . $where . ' ORDER BY d.device_id DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset);
        $out = [];
        foreach ($this->sql->all($sql, $params) as $r) {
            $out[] = $this->describe($r);
        }

        return $out;
    }

    private ?bool $verifyColumn = null;

    private function hasVerifyColumn(): bool
    {
        if ($this->verifyColumn === null) {
            $this->verifyColumn = (int) $this->sql->val("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'endpoint_agent_mesh_agents' AND COLUMN_NAME = 'verify_json'") > 0;
        }

        return $this->verifyColumn;
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private function describe(array $r): array
    {
        $installed = $r['installed'] !== null && (int) $r['installed'] === 1;
        $reported = (string) ($r['node_id'] ?? '') === '' ? null : (MeshNodeId::canonical((string) $r['node_id']) ?? (string) $r['node_id']);
        $mapped = $r['mapped_node'] === null ? null : (MeshNodeId::canonical((string) $r['mapped_node']) ?? (string) $r['mapped_node']);
        $ms = (string) ($r['map_state'] ?? '');
        if ($mapped !== null && (!$installed || $reported === null || $reported === $mapped)) {
            $state = self::MAPPED;
        } elseif (!$installed) {
            $state = self::NOT_INSTALLED;
        } elseif ($reported === null) {
            $state = $mapped !== null ? self::MAPPED : self::NO_NODE;
        } elseif ($ms === MeshAgentService::MAP_CONFLICT) {
            $state = self::CONFLICT;
        } elseif ($mapped !== null && $ms === MeshAgentService::MAP_KEPT_MANUAL) {
            $state = self::KEPT_MANUAL;
        } elseif ($mapped !== null && $ms === MeshAgentService::MAP_MAPPED) {
            $state = self::MAPPED;
        } else {
            $state = self::NEEDS_APPROVAL;   // nothing mapped (or a different node than the reported one) and nobody approved the reported node
        }
        $verify = is_string($r['verify_json'] ?? null) ? json_decode((string) $r['verify_json'], true) : null;
        $conflict = null;
        if ($state === self::CONFLICT && $r['conflict_device_id'] !== null) {
            $theirs = $this->sql->val('SELECT client_id FROM endpoint_agent_devices WHERE device_id = ?', [$r['conflict_device_id']]);
            $conflict = $theirs !== null && (int) $theirs === (int) $r['client_id'] ? (int) $r['conflict_device_id'] : null;   // another client's device is never named
        }
        $text = [
            self::NOT_INSTALLED => 'The MeshCentral agent has not been reported on this device. Install it from the device page or the installer.',
            self::NO_NODE => 'The MeshCentral agent is installed but the device has not reported which Mesh node it is. Wait for the next check-in, or map the node by hand.',
            self::NEEDS_APPROVAL => self::TEXT_NEEDS_APPROVAL,
            self::CONFLICT => 'This device reported a Mesh node that is held by, or also reported by, another device. Nothing was mapped: check which machine really owns the node.',
            self::KEPT_MANUAL => 'This device reported a different Mesh node than the one an administrator mapped by hand. The manual mapping was kept.',
            self::MAPPED => 'This device is mapped to a MeshCentral node' . (($r['mapped_source'] ?? null) === MeshVerifyService::SOURCE ? ' (verified against MeshCentral).' : '.'),
        ][$state];

        return ['device_id' => (int) $r['device_id'], 'hostname' => (string) $r['hostname'], 'client_id' => (int) $r['client_id'], 'state' => $state, 'reason' => $text,
            'reported_node_id' => $reported, 'mapped_node_id' => $mapped, 'mapped_source' => $mapped === null ? null : (string) $r['mapped_source'],
            'conflict_device_id' => $conflict, 'agent_version' => ($r['version'] ?? '') === '' ? null : (string) $r['version'], 'reported_at' => Sql::iso($r['reported_at'] === null ? null : (string) $r['reported_at']),
            'can_approve' => $state === self::NEEDS_APPROVAL && $ms === MeshAgentService::MAP_NEEDS_APPROVAL,
            'verification' => is_array($verify) ? $verify : null];
    }
}
