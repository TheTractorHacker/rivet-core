<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Mesh;

use RivetCore\Rmm\Contracts\RmmAuditInterface;
use RivetCore\Rmm\Contracts\SecretBoxInterface;
use RivetCore\Rmm\Device\DeviceRepository;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Support\DailyBudget;
use RivetCore\Rmm\Support\Sql;

/**
 * Verified Mesh node mapping (1.0.0-rc.67). A device's own report never creates a mapping. With `mesh_verify_nodes` on, the housekeeping job asks MeshCentral ITSELF
 * (one control connection per run, see {@see MeshControlClient}) for its nodes and maps the node a device reported ONLY when every test holds:
 *  1. the node it reported exists on the Mesh server;
 *  2. the node's name or host equals the device's host name (case-insensitive) and exactly one node of that Mesh group does;
 *  3. the node's Mesh group is the group configured for the device's client ({@see MeshVerifySettings}); a client without an entry is never mapped, and a group
 *     configured for two clients is refused (MeshCentral does not separate clients);
 *  4. nobody else holds or claims the node ({@see MeshAgentService::claimDecision()} must answer `needs_approval`).
 * Anything else stays `needs_approval` and the device keeps the evidence (`endpoint_agent_mesh_agents.verify_json`) for the administrator. A Mesh server that cannot be
 * reached, answers wrongly or signs in a different account maps nothing. At most {@see MAX_DEVICES_PER_RUN} devices are looked at per run, none within
 * {@see RECHECK_S} of their last look, and never inside a launch (this runs from the housekeeping job only).
 *
 * Verified only against tests/Support/MockMeshControl.php, never against a real MeshCentral.
 *
 * @api
 */
final class MeshVerifyService
{
    public const MAX_DEVICES_PER_RUN = 50;
    public const RECHECK_S = 600;
    /** source stored on a node mapped by this service */
    public const SOURCE = 'verified';
    private const BUDGET = 'mesh_verify_failure';

    public function __construct(
        private readonly Sql $sql,
        private readonly RmmSettings $settings,
        private readonly MeshVerifySettings $verify,
        private readonly MeshControlClient $client,
        private readonly MeshAgentService $agents,
        private readonly DeviceRepository $devices,
        private readonly RmmAuditInterface $audit,
        private readonly SecretBoxInterface $box,
        private readonly bool $allowInsecureHttp = false,
    ) {
    }

    /**
     * @return array{ran:bool,reason?:string,checked:int,mapped:int,left:int}
     */
    public function run(int $maxDevices = self::MAX_DEVICES_PER_RUN): array
    {
        $out = ['ran' => false, 'checked' => 0, 'mapped' => 0, 'left' => 0];
        $cfg = $this->settings->get();
        $v = $this->verify->get();
        if (!$this->verify->available() || !$v['enabled'] || (int) $cfg['mesh_enabled'] !== 1) {
            return $out + ['reason' => 'off'];
        }
        $maxDevices = max(1, min(self::MAX_DEVICES_PER_RUN, $maxDevices));
        $lock = "SELECT GET_LOCK(CONCAT('rivetcore_mesh_verify:', LEFT(MD5(IFNULL(DATABASE(), '')), 10)), 0)";
        if ((int) $this->sql->val($lock) !== 1) {
            return $out + ['reason' => 'busy'];
        }
        try {
            return $this->locked($cfg, $v, $maxDevices, $out);
        } finally {
            $this->sql->val("SELECT RELEASE_LOCK(CONCAT('rivetcore_mesh_verify:', LEFT(MD5(IFNULL(DATABASE(), '')), 10)))");
        }
    }

    /**
     * @param array<string,mixed> $cfg
     * @param array{enabled:bool,service_account:string,group_map:array<int,string>} $v
     * @param array{ran:bool,checked:int,mapped:int,left:int} $out
     * @return array<string,mixed>
     */
    private function locked(array $cfg, array $v, int $max, array $out): array
    {
        $rows = $this->sql->all('SELECT a.device_id, a.node_id FROM endpoint_agent_mesh_agents a JOIN endpoint_agent_devices d ON d.device_id = a.device_id
            WHERE a.installed = 1 AND a.node_id <> \'\' AND a.map_state = ? AND d.revoked_at IS NULL AND d.retired_at IS NULL
              AND NOT EXISTS (SELECT 1 FROM endpoint_agent_mesh_nodes n WHERE n.device_id = a.device_id)
              AND (a.verify_json IS NULL OR COALESCE(JSON_VALUE(a.verify_json, \'$.at\'), \'\') < ?)
            ORDER BY COALESCE(JSON_VALUE(a.verify_json, \'$.at\'), \'\'), a.device_id LIMIT ' . ($max + 1), [MeshAgentService::MAP_NEEDS_APPROVAL, $this->sql->utcAt(-self::RECHECK_S)]);
        if ($rows === []) {
            return $out + ['reason' => 'nothing_to_do'];
        }
        $out['left'] = max(0, count($rows) - $max);
        $rows = array_slice($rows, 0, $max);
        $domain = (string) $cfg['mesh_domain'];
        $base = MeshService::normalizeUrlWith((string) $cfg['mesh_url'], $this->allowInsecureHttp);
        $keyHex = empty($cfg['mesh_login_key_enc']) ? '' : $this->box->decrypt((string) $cfg['mesh_login_key_enc']);
        // a fresh cookie for this one connection; never stored, never logged, never retried
        $cookie = $base === null || $keyHex === '' ? null : MeshCookie::login($keyHex, $domain, $v['service_account'], $this->sql->time());
        unset($keyHex);
        if ($cookie === null) {
            return $this->failed($out, 'The MeshCentral address, login key or service account is not usable.');
        }
        $r = $this->client->fetch($base, $domain, $cookie, 'user/' . $domain . '/' . $v['service_account']);
        unset($cookie);
        if (!$r['ok']) {
            return $this->failed($out, (string) ($r['error'] ?? 'MeshCentral did not answer.'));
        }
        $out['ran'] = true;
        $nodes = [];
        $byGroup = [];
        foreach ($r['nodes'] ?? [] as $n) {
            $nodes[$n['id']] = $n;
            $byGroup[$n['mesh']][] = $n;
        }
        $shared = array_keys(array_filter(array_count_values($v['group_map']), static fn (int $c): bool => $c > 1));
        foreach ($rows as $row) {
            $dev = $this->devices->find((int) $row['device_id']);
            $node = MeshNodeId::canonical((string) $row['node_id']);
            if ($dev === null || $node === null) {
                continue;
            }
            ++$out['checked'];
            if ($this->one($dev, $node, $v['group_map'][(int) $dev['client_id']] ?? null, $shared, $nodes, $byGroup)) {
                ++$out['mapped'];
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $out
     * @return array<string,mixed>
     */
    private function failed(array $out, string $why): array
    {
        $budget = new DailyBudget($this->sql);
        if ($budget->charge(self::BUDGET, 0, 1, 0, 1, PHP_INT_MAX)) {
            $this->audit->record('Mesh Verification Failed', 'The MeshCentral node verification could not run, so nothing was mapped: ' . mb_substr($why, 0, 200), 0, 0);
        }

        return $out + ['reason' => 'mesh_unavailable'];
    }

    /**
     * Test one device and map it only when every test holds.
     *
     * @param array<string,mixed> $dev
     * @param list<string> $sharedGroups
     * @param array<string,array<string,string>> $nodes node id => node
     * @param array<string,list<array<string,string>>> $byGroup group hex => nodes
     */
    private function one(array $dev, string $node, ?string $group, array $sharedGroups, array $nodes, array $byGroup): bool
    {
        $deviceId = (int) $dev['device_id'];
        $client = (int) $dev['client_id'];
        $asset = (int) ($dev['asset_id'] ?? 0);
        $host = strtolower(trim((string) ($dev['hostname'] ?? '')));
        $c = ['node_exists' => null, 'hostname_matches' => null, 'group_matches' => null, 'hostname_unique' => null, 'unclaimed' => null];
        $code = 'verified';
        $text = '';
        $n = $nodes[$node] ?? null;
        if ($group === null) {
            $code = 'no_group_configured';
            $text = 'No Mesh device group is configured for this client, so the node is never mapped automatically.';
        } elseif (in_array($group, $sharedGroups, true)) {
            $code = 'group_shared';
            $text = 'The Mesh device group of this client is also configured for another client. MeshCentral does not separate clients, so nothing is mapped automatically.';
        } elseif ($n === null) {
            $c['node_exists'] = false;
            $code = 'node_missing';
            $text = 'MeshCentral does not list the node this device reported.';
        } else {
            $c['node_exists'] = true;
            $c['group_matches'] = $n['mesh'] === $group;
            $c['hostname_matches'] = $host !== '' && ($host === strtolower($n['name']) || $host === strtolower($n['host']));
            if (!$c['group_matches']) {
                $code = 'wrong_group';
                $text = "The node exists but is not in this client's Mesh device group.";
            } elseif (!$c['hostname_matches']) {
                $code = 'hostname_differs';
                $text = 'The node is in the right group but its name or host does not match the device host name.';
            } else {
                $same = array_filter($byGroup[$group] ?? [], static fn (array $x): bool => $host === strtolower($x['name']) || $host === strtolower($x['host']));
                $c['hostname_unique'] = count($same) === 1;
                if (!$c['hostname_unique']) {
                    $code = 'hostname_not_unique';
                    $text = 'More than one node of the Mesh group has this host name, so the node cannot be told apart.';
                } else {
                    $d = $this->agents->claimDecision($dev, $node);
                    $c['unclaimed'] = $d['action'] === 'needs_approval';
                    if (!$c['unclaimed']) {
                        $code = 'claimed';
                        $text = 'Another device holds or reports this node, or this device is already mapped (' . $d['action'] . ').';
                    }
                }
            }
        }
        $ok = $code === 'verified';
        if ($ok) {
            $text = 'The node exists on the Mesh server, its name or host matches the device host name (unique in the group), it is in the Mesh group configured for this client and no other device claims it.';
        }
        $evidence = (string) json_encode(['at' => $this->sql->utcNow(), 'result' => $ok ? 'mapped' : 'needs_approval', 'code' => $code, 'checks' => $c, 'text' => $text], JSON_THROW_ON_ERROR);
        $this->sql->run('UPDATE endpoint_agent_mesh_agents SET verify_json = ? WHERE device_id = ?', [$evidence, $deviceId]);
        if (!$ok) {
            return false;
        }
        $this->devices->setMeshNode($deviceId, $node, 0, self::SOURCE);
        $this->agents->markMapped($deviceId);
        $this->audit->record('Mesh Node Verified And Mapped', "MeshCentral node $node mapped to device $deviceId after it was verified against MeshCentral: $text", $client, $asset);

        return true;
    }
}
