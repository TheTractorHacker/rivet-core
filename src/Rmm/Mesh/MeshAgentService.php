<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Mesh;

use RivetCore\Rmm\Contracts\RmmAuditInterface;
use RivetCore\Rmm\Device\DeviceRepository;
use RivetCore\Rmm\Enrollment\DeviceValidator;
use RivetCore\Rmm\RmmEvent;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Support\RmmEventPublisher;
use RivetCore\Rmm\Support\Sql;

/**
 * The server side of the optional MeshCentral agent: the Mesh block an installer carries, what a device reports about its Mesh agent (the
 * `mesh_agent` member of a check-in) and the automatic mapping of the reported node id to the device.
 *
 * Mapping rules (checked in this order, only while Mesh is enabled and the node id passes {@see MeshService::validNodeId()}):
 *  1. The node id is already mapped to ANOTHER live device: refused. The device is flagged `conflict` (naming the other device), an audit row is
 *     written once and `rmm.mesh.node_conflict` is published. Nothing is moved. (A node mapped to a retired or revoked device is not a conflict:
 *     the machine was re-enrolled, so the mapping moves to the live device, audited.)
 *  2. The device has no mapping: it is mapped (source "agent").
 *  3. The device is mapped to the same node: nothing changes.
 *  4. The device is mapped to a different node by the agent earlier: it is replaced (the machine got a new Mesh identity).
 *  5. The device is mapped to a different node BY HAND: kept, flagged `kept_manual`, unless the `mesh_auto_map_overwrite` setting is on.
 *
 * @api
 */
final class MeshAgentService
{
    public const MAP_MAPPED = 'mapped';
    public const MAP_CONFLICT = 'conflict';
    public const MAP_KEPT_MANUAL = 'kept_manual';
    public const MAX_VERSION = 60;
    public const MAX_ERROR = 300;

    public function __construct(
        private readonly Sql $sql,
        private readonly RmmSettings $settings,
        private readonly MeshAgentSettings $meshSettings,
        private readonly DeviceRepository $devices,
        private readonly RmmAuditInterface $audit,
        private readonly ?RmmEventPublisher $events = null,
    ) {
    }

    // ---------------------------------------------------------------- the installer side

    /**
     * Why an installer cannot ask for the Mesh agent right now, or null when it can: Mesh must be enabled, the address an https:// one and the device
     * group id set.
     */
    public function installRefusal(): ?string
    {
        $cfg = $this->settings->get();
        if ((int) $cfg['mesh_enabled'] !== 1) {
            return 'MeshCentral is not switched on. Turn it on in the MeshCentral settings first.';
        }
        $url = MeshService::normalizeUrlWith((string) $cfg['mesh_url'], false);
        if ($url === null) {
            return 'The MeshCentral address must be set and start with https:// before the agent can be installed from it.';
        }
        if ($this->meshSettings->get()['group_id'] === '') {
            return 'Set the MeshCentral device group id (the group the devices join) in the MeshCentral settings first.';
        }

        return null;
    }

    /**
     * The `mesh` member of the installer payload: the source the agent installs from. Null when {@see installRefusal()} says no.
     *
     * @return array<string,mixed>|null
     */
    public function installBlock(): ?array
    {
        if ($this->installRefusal() !== null) {
            return null;
        }
        $cfg = $this->settings->get();
        $m = $this->meshSettings->get();
        $t = $this->meshSettings->effectiveTables();
        $block = ['url' => (string) MeshService::normalizeUrlWith((string) $cfg['mesh_url'], false), 'meshid' => $m['group_id_url']];
        if ($m['server_cert_pin'] !== '') {
            $block['pin'] = $m['server_cert_pin'];
        }
        $block['agent_ids'] = $t['agent_ids'];
        $block['install_args'] = $t['install_args'];

        return $block;
    }

    /**
     * The parameters of a pushed `mesh_install` job: the SAME source the stamped installer's Mesh block carries (address, device group id in MeshCentral's URL form, optional
     * pin, the agent-id and install-argument tables), taken from the stored settings at the moment the job is created and from nowhere else, so a caller can add nothing.
     * The two tables are JSON text because a job parameter is a scalar. Null when {@see installRefusal()} says no.
     *
     * @return array{url:string,meshid:string,pin?:string,agent_ids:string,install_args:string,force:bool}|null
     */
    public function jobParams(bool $force = false): ?array
    {
        $b = $this->installBlock();
        if ($b === null) {
            return null;
        }
        $out = ['url' => (string) $b['url'], 'meshid' => (string) $b['meshid']];
        if (isset($b['pin'])) {
            $out['pin'] = (string) $b['pin'];
        }
        $out['agent_ids'] = json_encode($b['agent_ids'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $out['install_args'] = json_encode($b['install_args'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $out['force'] = $force;

        return $out;
    }

    // ---------------------------------------------------------------- the device side

    /**
     * What the device last reported, or null.
     *
     * @return array{installed:bool,version:?string,node_id:?string,error:?string,map_state:?string,conflict_device_id:?int,first_installed_at:?string,reported_at:?string}|null
     */
    public function stateFor(int $deviceId): ?array
    {
        $r = $this->sql->one('SELECT * FROM endpoint_agent_mesh_agents WHERE device_id = ?', [$deviceId]);
        if ($r === null) {
            return null;
        }
        // The other device is named only when it belongs to the same client: a conflict across clients is flagged ("conflict") without naming a device the
        // viewer may not be allowed to see (the audit log, an administrator-only record, has the id).
        $conflict = null;
        if ($r['conflict_device_id'] !== null) {
            $theirs = $this->sql->val('SELECT client_id FROM endpoint_agent_devices WHERE device_id = ?', [$r['conflict_device_id']]);
            $mine = $this->sql->val('SELECT client_id FROM endpoint_agent_devices WHERE device_id = ?', [$deviceId]);
            $conflict = $theirs !== null && $mine !== null && (int) $theirs === (int) $mine ? (int) $r['conflict_device_id'] : null;
        }

        return ['installed' => (int) $r['installed'] === 1, 'version' => (string) $r['version'] === '' ? null : (string) $r['version'], 'node_id' => (string) $r['node_id'] === '' ? null : (string) $r['node_id'],
            'error' => (string) $r['error'] === '' ? null : (string) $r['error'], 'map_state' => (string) $r['map_state'] === '' ? null : (string) $r['map_state'], 'conflict_device_id' => $conflict,
            'first_installed_at' => Sql::iso($r['first_installed_at'] === null ? null : (string) $r['first_installed_at']), 'reported_at' => Sql::iso((string) $r['reported_at'])];
    }

    /**
     * Take one `mesh_agent` report. NEVER throws for bad input (an announcement can never fail a check-in): anything unusable is ignored.
     *
     * @param array<string,mixed> $dev the device row
     */
    public function report(array $dev, mixed $raw): void
    {
        if (!is_array($raw) || ($raw !== [] && array_is_list($raw)) || !array_key_exists('installed', $raw)) {
            return;
        }
        $installed = $raw['installed'];
        if (!is_bool($installed) && $installed !== 0 && $installed !== 1) {
            return;
        }
        $installed = (bool) $installed;
        $deviceId = (int) $dev['device_id'];
        $version = DeviceValidator::cleanText($raw['version'] ?? null, self::MAX_VERSION) ?? '';
        $error = $installed ? '' : (DeviceValidator::cleanText($raw['error'] ?? null, self::MAX_ERROR) ?? '');
        $node = is_string($raw['node_id'] ?? null) && MeshService::validNodeId($raw['node_id']) ? $raw['node_id'] : '';
        if (!$installed) {
            $node = '';
        }
        $now = $this->sql->utcNow();
        $old = $this->sql->one('SELECT * FROM endpoint_agent_mesh_agents WHERE device_id = ?', [$deviceId]);
        $wasInstalled = $old !== null && (int) $old['installed'] === 1;
        $same = $old !== null && $wasInstalled === $installed && (string) $old['version'] === $version && (string) $old['node_id'] === $node && (string) $old['error'] === $error;
        if ($old === null) {
            $this->sql->run('INSERT INTO endpoint_agent_mesh_agents (device_id, installed, version, node_id, error, map_state, first_installed_at, reported_at, changed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$deviceId, (int) $installed, $version, $node, $error, '', $installed ? $now : null, $now, $now]);
        } elseif ($same) {
            $this->sql->run('UPDATE endpoint_agent_mesh_agents SET reported_at = ? WHERE device_id = ?', [$now, $deviceId]);
        } else {
            $this->sql->run('UPDATE endpoint_agent_mesh_agents SET installed = ?, version = ?, node_id = ?, error = ?, first_installed_at = COALESCE(first_installed_at, ?), reported_at = ?, changed_at = ? WHERE device_id = ?',
                [(int) $installed, $version, $node, $error, $installed ? $now : null, $now, $now, $deviceId]);
        }
        if ($installed && !$wasInstalled) {
            $this->audit->record('Mesh Agent Installed', "The MeshCentral agent is installed on device $deviceId" . ($version !== '' ? " (version $version)" : ''), (int) $dev['client_id'], (int) ($dev['asset_id'] ?? 0));
            $this->events?->emit(RmmEvent::MESH_AGENT_INSTALLED, $dev, ['version' => $version === '' ? null : $version, 'node_id' => $node === '' ? null : $node]);
        } elseif (!$installed && $error !== '' && ($old === null || (string) $old['error'] !== $error)) {
            $this->audit->record('Mesh Agent Install Failed', "The MeshCentral agent could not be installed on device $deviceId: $error", (int) $dev['client_id'], (int) ($dev['asset_id'] ?? 0));
        }
        if ($node !== '' && (int) $this->settings->get()['mesh_enabled'] === 1) {
            $this->mapNode($dev, $node, $old);
        } elseif ($old !== null && (string) $old['map_state'] !== '' && $node === '') {
            $this->sql->run("UPDATE endpoint_agent_mesh_agents SET map_state = '', conflict_device_id = NULL WHERE device_id = ?", [$deviceId]);
        }
    }

    /**
     * @param array<string,mixed> $dev
     * @param array<string,mixed>|null $old the Mesh agent row before this report
     */
    private function mapNode(array $dev, string $node, ?array $old): void
    {
        $deviceId = (int) $dev['device_id'];
        $client = (int) $dev['client_id'];
        $asset = (int) ($dev['asset_id'] ?? 0);
        $prevState = (string) ($old['map_state'] ?? '');
        $prevNode = (string) ($old['node_id'] ?? '');
        $prevConflict = isset($old['conflict_device_id']) ? (int) $old['conflict_device_id'] : null;
        $state = '';
        $conflictWith = null;

        $other = $this->sql->one('SELECT m.device_id, d.revoked_at, d.retired_at FROM endpoint_agent_mesh_nodes m JOIN endpoint_agent_devices d ON d.device_id = m.device_id
            WHERE m.mesh_node_id = ? AND m.device_id <> ? ORDER BY (d.revoked_at IS NULL AND d.retired_at IS NULL) DESC, m.device_id LIMIT 1', [$node, $deviceId]);
        $otherLive = $other !== null && $other['revoked_at'] === null && $other['retired_at'] === null;
        $mine = $this->sql->one('SELECT mesh_node_id, source FROM endpoint_agent_mesh_nodes WHERE device_id = ?', [$deviceId]);

        if ($otherLive) {
            $state = self::MAP_CONFLICT;
            $conflictWith = (int) $other['device_id'];
            if ($prevState !== self::MAP_CONFLICT || $prevNode !== $node || $prevConflict !== $conflictWith) {
                $this->audit->record('Mesh Node Conflict', "Device $deviceId reported MeshCentral node $node, which is already mapped to device $conflictWith. Not mapped: check which machine really owns the node.", $client, $asset);
                $this->events?->emit(RmmEvent::MESH_NODE_CONFLICT, $dev, ['node_id' => $node, 'other_device_id' => $conflictWith]);
            }
        } elseif ($mine !== null && (string) $mine['mesh_node_id'] === $node) {
            $state = self::MAP_MAPPED;
        } elseif ($mine !== null && (string) $mine['source'] !== 'agent' && !$this->meshSettings->get()['auto_map_overwrite']) {
            $state = self::MAP_KEPT_MANUAL;
            if ($prevState !== self::MAP_KEPT_MANUAL || $prevNode !== $node) {
                $this->audit->record('Mesh Node Differs', "Device $deviceId reported MeshCentral node $node but is mapped by hand to {$mine['mesh_node_id']}. The manual mapping was kept (turn on the automatic overwrite to replace it).", $client, $asset);
            }
        } else {
            if ($other !== null) {   // the old owner is retired or revoked: the machine was re-enrolled, the node moves
                $this->devices->setMeshNode((int) $other['device_id'], null, 0);
                $this->audit->record('Mesh Node Moved', "MeshCentral node $node moved from retired device {$other['device_id']} to device $deviceId", $client, $asset);
            }
            $this->devices->setMeshNode($deviceId, $node, 0, 'agent');
            $state = self::MAP_MAPPED;
            $what = $mine === null ? 'mapped' : "re-mapped (was {$mine['mesh_node_id']}, " . ((string) $mine['source'] === 'agent' ? 'set automatically' : 'set by hand; overwrite is on') . ')';
            $this->audit->record('Mesh Node Mapped', "MeshCentral node $node $what to device $deviceId automatically from the agent's report", $client, $asset);
        }
        if ($state !== $prevState || $prevConflict !== $conflictWith) {
            $this->sql->run('UPDATE endpoint_agent_mesh_agents SET map_state = ?, conflict_device_id = ? WHERE device_id = ?', [$state, $conflictWith, $deviceId]);
        }
    }
}
