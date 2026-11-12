<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Rmm\Mesh\MeshAgentService;
use RivetCore\Rmm\Mesh\MeshControlClient;
use RivetCore\Rmm\Mesh\MeshCookie;
use RivetCore\Rmm\Mesh\MeshMapping;
use RivetCore\Rmm\Mesh\MeshVerifyService;
use RivetCore\Tests\Support\MockMeshControl;
use RivetCore\Tests\Support\RemoteTestCase;
use RivetCore\Tests\Support\RmmHarness;

/**
 * Mesh node mapping (1.0.0-rc.67): the reason read model, the fleet list and its client scope, the bulk approval, the settings and the verified auto-map against the
 * fake control channel tests/Support/MockMeshControl.php (there is no real MeshCentral behind any of this).
 */
final class MeshMappingTest extends RemoteTestCase
{
    private const GROUP_A = '4a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f9';
    private const GROUP_B = '5b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90a';
    private const NODE_A = 'node//AbCdEfGhIjKlMnOpQrStUvWxYz0123456789@$AbCdEfGhIjKlMnOpQrStUvWxYz01';
    private const NODE_B = 'node//ZyXwVuTsRqPoNmLkJiHgFeDcBa9876543210@$ZyXwVuTsRqPoNmLkJiHgFeDcBa98';
    private const NODE_D = 'node//LlKkJjHhGgFfDdSsAaPpOoIiUuYyTtRrEeWwQq9876543210@$MmNnBbVvCcXxZz';
    private const NODE_C = 'node//QqWwEeRrTtYyUuIiOoPpAaSsDdFfGgHhJjKkLlZzXxCcVvBbNnMm0123456789@$a';
    private string $keyHex = '';
    private ?MockMeshControl $ctl = null;

    protected function makeHarness(): RmmHarness
    {
        $this->transferDir = sys_get_temp_dir() . '/rmm_xfer_' . bin2hex(random_bytes(4));
        $tech = [RmmAbility::DEVICE_VIEW, RmmAbility::REMOTE_LAUNCH];

        return new RmmHarness(null, null, ['allow_linux' => true, 'transfer_dir' => $this->transferDir, 'allow_insecure_http' => true], false, null,
            new \RivetCore\Tests\Support\AllowUsersPolicy([1 => true, 10 => $tech, 12 => [RmmAbility::DEVICE_VIEW, RmmAbility::DEVICE_MANAGE], 16 => true]),
            new \RivetCore\Webhooks\UrlPolicy(true));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->h->module->settings()->set(['service_url' => 'https://rmm.example.test']);
        $this->h->tenancy->restrictUser(12, [$this->h->clientA]);
        $this->h->tenancy->restrictUser(10, [$this->h->clientA]);
    }

    protected function tearDown(): void
    {
        $this->ctl?->stop();
        parent::tearDown();
    }

    private function meshOn(): void
    {
        $this->h->module->settings()->set(['mesh_enabled' => 1, 'mesh_url' => 'https://mesh.example.com']);
    }

    private function meshReport(string $token, ?string $node, bool $installed = true): void
    {
        $m = ['installed' => $installed] + ($node === null ? [] : ['node_id' => $node]);
        [$c] = $this->h->checkin($token, ['mesh_agent' => $m]);
        $this->assertSame(200, $c);
    }

    private function audits(string $action): int
    {
        return count(array_filter($this->h->audit->records(), static fn (array $r): bool => $r['action'] === $action));
    }

    private function state(int $dev): string
    {
        return (string) $this->h->module->meshMapping()->forDevice($dev)['state'];
    }

    // ------------------------------------------------------------------ the read model

    public function testEveryStateHasItsReasonAndTheOldMessageIsKept(): void
    {
        $this->meshOn();
        [$none] = $this->linked('S1');
        [$noNode, $t2] = $this->linked('S2');
        [$needs, $t3] = $this->linked('S3');
        [$holder, $t4] = $this->linked('S4');
        [$rival, $t5] = $this->linked('S5');
        [$manual, $t6] = $this->linked('S6');
        $this->meshReport($t2, null);
        $this->meshReport($t3, self::NODE_A);
        $this->meshReport($t4, self::NODE_B);
        $this->assertTrue($this->h->module->devices()->setMeshNode($holder, self::NODE_B, 1));
        $this->meshReport($t4, self::NODE_B);
        $this->meshReport($t5, self::NODE_B);
        $this->assertTrue($this->h->module->devices()->setMeshNode($manual, self::NODE_C, 1));
        $this->meshReport($t6, self::NODE_D);   // S6 has a different node (C) mapped by hand
        $m = $this->h->module->meshMapping();
        $this->assertSame(MeshMapping::NOT_INSTALLED, $m->forDevice($none)['state']);
        $this->assertSame(MeshMapping::NO_NODE, $m->forDevice($noNode)['state']);
        $r = $m->forDevice($needs);
        $this->assertSame(MeshMapping::NEEDS_APPROVAL, $r['state']);
        $this->assertSame('This device reported a Mesh node that has not been approved yet, so nothing was mapped. Check this node in MeshCentral, then map it.', $r['reason']);
        $this->assertTrue($r['can_approve']);
        $this->assertSame(MeshMapping::MAPPED, $m->forDevice($holder)['state']);
        $this->assertSame(MeshMapping::CONFLICT, $m->forDevice($rival)['state']);
        $this->assertSame($holder, $m->forDevice($rival)['conflict_device_id']);
        $this->assertFalse($m->forDevice($rival)['can_approve']);
        $this->assertSame(MeshMapping::KEPT_MANUAL, $m->forDevice($manual)['state']);
        foreach ([$none, $noNode, $needs, $holder, $rival, $manual] as $id) {
            $this->assertNotSame('', $m->forDevice($id)['reason']);
        }
        // one device is an opt-in route; the default detail is byte for byte what it was (the golden transcripts)
        $this->assertArrayNotHasKey('mesh_mapping', $this->h->module->readModel()->deviceView($needs, false));
        [$c, $j] = $this->h->tech('GET', [(string) $needs, 'mesh', 'mapping'], $this->h->principal(1));
        $this->assertSame(200, $c);
        $this->assertSame('reported_needs_approval', $j['state']);
        $this->assertTrue($j['can_approve']);
        [$c, $j] = $this->h->tech('GET', [(string) $needs, 'mesh', 'mapping'], $this->h->principal(10));
        $this->assertSame(200, $c);
        $this->assertFalse($j['can_approve'], 'no rmm.device.manage');
        $this->h->tenancy->restrictUser(16, [$this->h->clientB]);
        [$c] = $this->h->tech('GET', [(string) $needs, 'mesh', 'mapping'], $this->h->principal(16));
        $this->assertSame(404, $c, 'another client answers like a missing device');
        [$c2] = $this->h->tech('GET', ['99999', 'mesh', 'mapping'], $this->h->principal(16));
        $this->assertSame(404, $c2);
    }

    public function testTheFleetListIsClientScopedAndAnotherClientsDeviceIsAbsent(): void
    {
        $this->meshOn();
        [$a, $ta] = $this->linked('F1');
        [$b, $tb] = $this->linked('F2', $this->h->clientB);
        $this->meshReport($ta, self::NODE_A);
        $this->meshReport($tb, self::NODE_B);
        [$c, $j] = $this->h->tech('GET', ['mesh', 'mapping'], $this->h->principal(1));
        $this->assertSame(200, $c);
        $this->assertEqualsCanonicalizing([$a, $b], array_column($j['items'], 'device_id'));
        // user 12 is restricted to client A: client B's device answers exactly like a missing one (it is not in the list), also with an explicit filter
        [$c, $j] = $this->h->tech('GET', ['mesh', 'mapping'], $this->h->principal(12));
        $this->assertSame([$a], array_column($j['items'], 'device_id'));
        $this->assertSame(1, $j['total']);
        [$c, $j2] = $this->h->tech('GET', ['mesh', 'mapping'], $this->h->principal(12), null, ['client_id' => (string) $this->h->clientB]);
        $this->assertSame([], $j2['items']);
        $this->assertSame(0, $j2['total']);
        [$c, $j3] = $this->h->tech('GET', ['mesh', 'mapping'], $this->h->principal(1), null, ['state' => 'reported_needs_approval', 'q' => 'F2']);
        $this->assertSame([$b], array_column($j3['items'], 'device_id'));
        [$c] = $this->h->tech('GET', ['mesh', 'mapping'], $this->h->principal(1), null, ['state' => 'bogus']);
        $this->assertSame(422, $c);
        [$c] = $this->h->tech('GET', ['mesh', 'mapping'], $this->h->principal(99));
        $this->assertSame(403, $c);
    }

    // ------------------------------------------------------------------ the bulk approval

    public function testBulkApproveMapsOnlyTheNodeTheDeviceReportedWithPerDeviceResults(): void
    {
        $this->meshOn();
        [$ok, $t1] = $this->linked('B1');
        [$none] = $this->linked('B2');
        [$holder, $t3] = $this->linked('B3');
        [$rival, $t4] = $this->linked('B4');
        [$other, $t5] = $this->linked('B5', $this->h->clientB);
        $this->meshReport($t1, self::NODE_A);
        $this->meshReport($t3, self::NODE_B);
        $this->h->module->devices()->setMeshNode($holder, self::NODE_B, 1);
        $this->meshReport($t4, self::NODE_B);
        $this->meshReport($t5, self::NODE_C);
        // user 12 may manage devices of client A only
        [$c, $j] = $this->h->tech('POST', ['mesh', 'approve'], $this->h->principal(12), ['device_ids' => [$ok, $none, $rival, $other, 99999, $ok]]);
        $this->assertSame(200, $c, json_encode($j));
        $by = array_column($j['results'], null, 'device_id');
        $this->assertSame('changed', $by[$ok]['result']);
        $this->assertSame('skipped', $by[$none]['result']);
        $this->assertSame('skipped', $by[$rival]['result'], 'a conflicting device is not waiting for approval');
        $this->assertSame('refused', $by[$other]['result']);
        $this->assertSame('refused', $by[99999]['result']);
        $this->assertSame($by[$other]['code'], $by[99999]['code'], 'another client answers exactly like a missing device');
        $this->assertSame($by[$other]['message'], $by[99999]['message']);
        $this->assertSame(5, $j['requested']);
        $this->assertSame(1, $j['changed']);
        $this->assertSame(self::NODE_A, $this->h->one("SELECT mesh_node_id FROM endpoint_agent_mesh_nodes WHERE device_id=$ok"));
        $this->assertSame(self::NODE_B, $this->h->one("SELECT mesh_node_id FROM endpoint_agent_mesh_nodes WHERE device_id=$holder"), 'the holder keeps its node');
        $this->assertNull($this->h->one("SELECT mesh_node_id FROM endpoint_agent_mesh_nodes WHERE device_id=$rival"));
        $this->assertNull($this->h->one("SELECT mesh_node_id FROM endpoint_agent_mesh_nodes WHERE device_id=$other"));
        $this->assertSame(MeshMapping::MAPPED, $this->state($ok));
        $this->assertSame(1, $this->audits('Mesh Node Approved'));
        $this->assertSame(1, $this->audits('Mesh Nodes Bulk Approved'));
        // a second call changes nothing
        [, $j] = $this->h->tech('POST', ['mesh', 'approve'], $this->h->principal(12), ['device_ids' => [$ok]]);
        $this->assertSame('skipped', $j['results'][0]['result']);
    }

    public function testBulkApproveRefusesAConflictThatAppearedAfterThePageWasDrawn(): void
    {
        $this->meshOn();
        [$a, $ta] = $this->linked('C1');
        [$b] = $this->linked('C2');
        $this->meshReport($ta, self::NODE_A);
        $this->assertTrue($this->h->module->meshMapping()->forDevice($a)['can_approve']);
        $this->h->module->devices()->setMeshNode($b, self::NODE_A, 1);   // someone maps the node to another device meanwhile
        [, $j] = $this->h->tech('POST', ['mesh', 'approve'], $this->h->principal(1), ['device_ids' => [$a]]);
        $this->assertSame('refused', $j['results'][0]['result']);
        $this->assertSame('conflict', $j['results'][0]['code']);
        $this->assertNull($this->h->one("SELECT mesh_node_id FROM endpoint_agent_mesh_nodes WHERE device_id=$a"));
        $this->assertSame(1, $this->audits('Mesh Node Approval Refused'));
    }

    public function testBulkApproveLimitsAndRefusesATypedNodeAndAPlainRole(): void
    {
        [$a] = $this->linked('L1');
        [$c] = $this->h->tech('POST', ['mesh', 'approve'], $this->h->principal(1), ['device_ids' => range(1, 201)]);
        $this->assertSame(422, $c);
        [$c] = $this->h->tech('POST', ['mesh', 'approve'], $this->h->principal(1), ['device_ids' => []]);
        $this->assertSame(422, $c);
        [$c] = $this->h->tech('POST', ['mesh', 'approve'], $this->h->principal(1), ['device_ids' => [$a], 'node_id' => self::NODE_A]);
        $this->assertSame(422, $c, 'a typed node id is not accepted');
        [$c] = $this->h->tech('POST', ['mesh', 'approve'], $this->h->principal(1), ['device_ids' => ['1']]);
        $this->assertSame(422, $c);
        [$c] = $this->h->tech('POST', ['mesh', 'approve'], $this->h->principal(10), ['device_ids' => [$a]]);
        $this->assertSame(403, $c, 'a technician without rmm.device.manage');
        [$c, $j] = $this->h->tech('POST', ['mesh', 'approve'], $this->h->principal(1), ['device_ids' => range(1, 200)]);
        $this->assertSame(200, $c, 'exactly 200 is allowed');
        $this->assertSame(200, $j['requested']);
    }

    // ------------------------------------------------------------------ the settings

    public function testVerifySettingsAreAdminOnlyAuditedAndValidated(): void
    {
        [$c, $j] = $this->h->tech('GET', ['mesh', 'verify'], $this->h->principal(1));
        $this->assertSame(200, $c);
        $this->assertFalse($j['mesh_verify_nodes'], 'off by default');
        $this->assertStringContainsString('does not separate clients', $j['warning']);
        [$c] = $this->h->tech('GET', ['mesh', 'verify'], $this->h->principal(10));
        $this->assertSame(403, $c);
        [$c] = $this->h->tech('PUT', ['mesh', 'verify'], $this->h->principal(10), ['mesh_verify_nodes' => true]);
        $this->assertSame(403, $c);
        [$c] = $this->h->tech('PUT', ['mesh', 'verify'], $this->h->principal(1), ['mesh_verify_nodes' => true]);
        $this->assertSame(409, $c, 'Mesh must be on with a login key first');
        $this->meshOn();
        $this->h->module->settings()->set(['mesh_login_key_enc' => $this->h->box->encrypt(MeshCookie::newLoginKey())]);
        [$c] = $this->h->tech('PUT', ['mesh', 'verify'], $this->h->principal(1), ['mesh_verify_nodes' => true]);
        $this->assertSame(422, $c, 'a service account is needed');
        foreach ([['mesh_service_account' => 'bad name!'], ['mesh_service_account' => str_repeat('a', 65)], ['mesh_group_map' => ['x' => self::GROUP_A]], ['mesh_group_map' => ['5' => 'nonsense']], ['mesh_verify_nodes' => 'yes'], ['other' => 1]] as $bad) {
            [$c] = $this->h->tech('PUT', ['mesh', 'verify'], $this->h->principal(1), $bad);
            $this->assertSame(422, $c, json_encode($bad));
        }
        [$c, $j] = $this->h->tech('PUT', ['mesh', 'verify'], $this->h->principal(1), ['mesh_service_account' => 'rivetit-verify', 'mesh_group_map' => [(string) $this->h->clientA => 'mesh//' . $this->urlForm(self::GROUP_A)], 'mesh_verify_nodes' => true]);
        $this->assertSame(200, $c, json_encode($j));
        $this->assertTrue($j['mesh_verify_nodes']);
        $this->assertSame(self::GROUP_A, ((array) $j['mesh_group_map'])[(string) $this->h->clientA]);
        $this->assertGreaterThanOrEqual(1, $this->audits('Settings'));
        $this->assertStringContainsString('automatic mapping is ON', json_encode($this->h->audit->records()));
    }

    private function urlForm(string $hex): string
    {
        return (string) \RivetCore\Rmm\Mesh\MeshAgentCatalog::hexToUrl($hex);
    }

    // ------------------------------------------------------------------ the verified auto-map

    /**
     * @param array<string,mixed> $scenario
     * @param array<int,string> $map client id => group hex
     */
    private function verifyOn(array $scenario = [], ?array $map = null): MockMeshControl
    {
        $this->keyHex = MeshCookie::newLoginKey();
        $this->ctl = new MockMeshControl($scenario + ['key_hex' => $this->keyHex, 'domain' => '', 'account' => 'rivetit-verify', 'mode' => 'ok', 'meshes' => [], 'nodes' => new \stdClass()]);
        $this->h->module->settings()->set(['mesh_enabled' => 1, 'mesh_url' => $this->ctl->url(), 'mesh_login_key_enc' => $this->h->box->encrypt($this->keyHex)]);
        $json = [];
        foreach ($map ?? [$this->h->clientA => self::GROUP_A] as $client => $hex) {
            $json[(string) $client] = $hex;
        }
        $this->assertSame([], $this->h->module->meshVerifySettings()->update(['mesh_service_account' => 'rivetit-verify', 'mesh_group_map' => $json, 'mesh_verify_nodes' => true], 1));

        return $this->ctl;
    }

    /** @return array<string,list<array<string,string>>> */
    private function nodes(array $perGroup): array
    {
        $out = [];
        foreach ($perGroup as $hex => $list) {
            $out['mesh//' . $this->urlForm($hex)] = $list;
        }

        return $out;
    }

    private function node(string $id, string $name, string $host = '10.0.0.5'): array
    {
        return ['_id' => $id, 'name' => $name, 'host' => $host, 'osdesc' => 'Windows', 'rname' => $name];
    }

    private function evidence(int $dev): array
    {
        return json_decode((string) $this->h->one("SELECT verify_json FROM endpoint_agent_mesh_agents WHERE device_id=$dev"), true);
    }

    public function testAVerifiedNodeIsMappedAndAudited(): void
    {
        [$dev, $t] = $this->linked('HOST1');
        $ctl = $this->verifyOn(['nodes' => $this->nodes([self::GROUP_A => [$this->node(self::NODE_A, 'host1', '10.0.0.5'), $this->node(self::NODE_B, 'OTHER')]])]);
        $this->meshReport($t, self::NODE_A);
        $this->assertSame(MeshMapping::NEEDS_APPROVAL, $this->state($dev), 'the report alone maps nothing');
        $r = $this->h->module->meshVerifyService()->run();
        $this->assertSame(['ran' => true, 'checked' => 1, 'mapped' => 1, 'left' => 0], $r);
        $this->assertSame(self::NODE_A, $this->h->one("SELECT mesh_node_id FROM endpoint_agent_mesh_nodes WHERE device_id=$dev"));
        $this->assertSame('verified', $this->h->one("SELECT source FROM endpoint_agent_mesh_nodes WHERE device_id=$dev"));
        $this->assertSame(MeshMapping::MAPPED, $this->state($dev));
        $this->assertSame('mapped', $this->h->module->meshAgents()->stateFor($dev)['map_state']);
        $ev = $this->evidence($dev);
        $this->assertSame('mapped', $ev['result']);
        $this->assertSame(['node_exists' => true, 'hostname_matches' => true, 'group_matches' => true, 'hostname_unique' => true, 'unclaimed' => true], $ev['checks']);
        $this->assertSame(1, $this->audits('Mesh Node Verified And Mapped'));
        $log = $ctl->log();
        $first = array_values(array_filter($log, static fn (array $e): bool => $e['event'] === 'first_frame'))[0];
        $this->assertSame('urlargs', $first['action']);
        $this->assertTrue($first['cookie_ok'], 'the cookie is the service account\'s');
        $this->assertFalse($first['cookie_in_path'], 'never in the URL');
        $this->assertSame('/control.ashx?moreargs=1', array_values(array_filter($log, static fn (array $e): bool => $e['event'] === 'handshake'))[0]['path']);
        $this->assertSame(['meshes', 'nodes'], array_column(array_filter($log, static fn (array $e): bool => $e['event'] === 'request'), 'action'));
        $this->assertNotEmpty(array_filter($log, static fn (array $e): bool => $e['event'] === 'pong'), 'the ping was answered');
        $this->assertStringNotContainsString($first['cookie'], json_encode($this->h->audit->records()));
        $this->assertStringNotContainsString($this->keyHex, json_encode($this->h->audit->records()));
        // the verified mapping shows in the detail
        [, $j] = $this->h->tech('GET', [(string) $dev, 'mesh', 'mapping'], $this->h->principal(1));
        $this->assertSame('mapped', $j['state']);
    }

    public function testNothingHappensWhileTheSettingIsOff(): void
    {
        [$dev, $t] = $this->linked('HOST1');
        $ctl = $this->verifyOn(['nodes' => $this->nodes([self::GROUP_A => [$this->node(self::NODE_A, 'HOST1')]])]);
        $this->assertSame([], $this->h->module->meshVerifySettings()->update(['mesh_verify_nodes' => false], 1));
        $this->meshReport($t, self::NODE_A);
        $this->assertSame(['ran' => false, 'checked' => 0, 'mapped' => 0, 'left' => 0, 'reason' => 'off'], $this->h->module->meshVerifyService()->run());
        $this->assertSame([], $ctl->log(), 'no connection at all');
        $this->assertSame(MeshMapping::NEEDS_APPROVAL, $this->state($dev));
        $this->assertFalse($this->h->module->meshVerifySettings()->get()['enabled']);
    }

    /** @return array{0:int,1:string} */
    private function deviceWith(string $name, string $node, ?int $client = null): array
    {
        $this->meshOn();
        [$dev, $t] = $this->linked($name, $client);
        $this->meshReport($t, $node);

        return [$dev, $t];
    }

    public function testWrongGroupSameNameGroupsAndAnotherClientsNodeAreNeverMapped(): void
    {
        [$a] = $this->deviceWith('HOST1', self::NODE_A);
        [$b] = $this->deviceWith('HOSTB', self::NODE_B, $this->h->clientB);
        // client A's host name is in group B too (two groups named "Lab" with different ids); client B's device reports a node that lives in client A's group
        $ctl = $this->verifyOn(['meshes' => [['_id' => 'mesh//' . $this->urlForm(self::GROUP_A), 'name' => 'Lab'], ['_id' => 'mesh//' . $this->urlForm(self::GROUP_B), 'name' => 'Lab']],
            'nodes' => $this->nodes([self::GROUP_A => [$this->node(self::NODE_B, 'HOSTB')], self::GROUP_B => [$this->node(self::NODE_A, 'HOST1')]])],
            [$this->h->clientA => self::GROUP_A, $this->h->clientB => self::GROUP_B]);
        $r = $this->h->module->meshVerifyService()->run();
        $this->assertSame(2, $r['checked']);
        $this->assertSame(0, $r['mapped']);
        $this->assertSame('wrong_group', $this->evidence($a)['code'], 'right name, same-named but different group');
        $this->assertSame('wrong_group', $this->evidence($b)['code'], 'another client\'s node can never be mapped');
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_mesh_nodes'));
        $this->assertSame(0, $this->audits('Mesh Node Verified And Mapped'));
        $this->assertSame(MeshMapping::NEEDS_APPROVAL, $this->state($a));
        $this->assertFalse($this->evidence($a)['checks']['group_matches']);
    }

    public function testHostnameCollisionDifferingNameAndMissingNodeStayNeedsApproval(): void
    {
        [$collide] = $this->deviceWith('HOST1', self::NODE_A);
        [$differs] = $this->deviceWith('HOST2', self::NODE_B);
        [$missing] = $this->deviceWith('HOST3', self::NODE_C);
        $this->verifyOn(['nodes' => $this->nodes([self::GROUP_A => [$this->node(self::NODE_A, 'host1'), $this->node('node//' . str_repeat('Z', 64), 'HOST1', '192.168.1.9'), $this->node(self::NODE_B, 'SOMETHING', '10.9.9.9')]])]);
        $r = $this->h->module->meshVerifyService()->run();
        $this->assertSame(['checked' => 3, 'mapped' => 0], ['checked' => $r['checked'], 'mapped' => $r['mapped']]);
        $this->assertSame('hostname_not_unique', $this->evidence($collide)['code']);
        $this->assertSame('hostname_differs', $this->evidence($differs)['code']);
        $this->assertSame('node_missing', $this->evidence($missing)['code']);
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_mesh_nodes'));
        foreach ([$collide, $differs, $missing] as $d) {
            $this->assertSame(MeshMapping::NEEDS_APPROVAL, $this->state($d));
            $this->assertNotSame('', $this->evidence($d)['text']);
        }
    }

    public function testAClientWithoutAGroupOrWithASharedGroupIsNeverMapped(): void
    {
        [$a] = $this->deviceWith('HOST1', self::NODE_A);
        [$b] = $this->deviceWith('HOSTB', self::NODE_B, $this->h->clientB);
        $this->verifyOn(['nodes' => $this->nodes([self::GROUP_A => [$this->node(self::NODE_A, 'HOST1'), $this->node(self::NODE_B, 'HOSTB')]])]);   // only client A has a group
        $this->h->module->meshVerifyService()->run();
        $this->assertSame('verified', $this->evidence($a)['code']);
        $this->assertSame('no_group_configured', $this->evidence($b)['code']);
        $this->assertNull($this->h->one("SELECT mesh_node_id FROM endpoint_agent_mesh_nodes WHERE device_id=$b"));
        // both clients configured with the SAME group: refused for the one still waiting
        $this->assertSame([], $this->h->module->meshVerifySettings()->update(['mesh_group_map' => [(string) $this->h->clientA => self::GROUP_A, (string) $this->h->clientB => self::GROUP_A]], 1));
        $this->h->q("UPDATE endpoint_agent_mesh_agents SET verify_json = NULL WHERE device_id=$b");
        $this->h->module->meshVerifyService()->run();
        $this->assertSame('group_shared', $this->evidence($b)['code']);
        $this->assertNull($this->h->one("SELECT mesh_node_id FROM endpoint_agent_mesh_nodes WHERE device_id=$b"));
    }

    public function testANodeAnotherDeviceHoldsIsNeverTaken(): void
    {
        [$a] = $this->deviceWith('HOST1', self::NODE_A);
        [$holder] = $this->linked('OTHERBOX');
        $this->verifyOn(['nodes' => $this->nodes([self::GROUP_A => [$this->node(self::NODE_A, 'HOST1')]])]);
        $this->h->module->devices()->setMeshNode($holder, self::NODE_A, 1);   // an administrator maps it to another device after the report
        $r = $this->h->module->meshVerifyService()->run();
        $this->assertSame(0, $r['mapped']);
        $this->assertSame('claimed', $this->evidence($a)['code']);
        $this->assertNull($this->h->one("SELECT mesh_node_id FROM endpoint_agent_mesh_nodes WHERE device_id=$a"));
        $this->assertSame(self::NODE_A, $this->h->one("SELECT mesh_node_id FROM endpoint_agent_mesh_nodes WHERE device_id=$holder"));
    }

    /** @return array<string,array{0:string}> */
    public static function failingServers(): array
    {
        return ['noauth' => ['noauth'], 'mismatch' => ['userinfo_mismatch'], 'redirect' => ['no_handshake'], 'bad accept' => ['bad_accept'], 'no serverinfo' => ['no_serverinfo'], 'huge' => ['huge']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failingServers')]
    public function testAServerThatFailsMapsNothingAndLeaksNothing(string $mode): void
    {
        [$dev, $t] = $this->deviceWith('HOST1', self::NODE_A);
        $ctl = $this->verifyOn(['mode' => $mode, 'nodes' => $this->nodes([self::GROUP_A => [$this->node(self::NODE_A, 'HOST1')]])]);
        $log = tempnam(sys_get_temp_dir(), 'errlog');
        $old = ini_set('error_log', (string) $log);
        try {
            $svc = new MeshVerifyService($this->h->module->sql(), $this->h->module->settings(), $this->h->module->meshVerifySettings(), new MeshControlClient(new \RivetCore\Webhooks\UrlPolicy(true), true, 3), $this->h->module->meshAgents(), $this->h->module->devices(), $this->h->audit, $this->h->box, true);
            $r = $svc->run();
            $r2 = $svc->run();
        } finally {
            ini_set('error_log', (string) $old);
        }
        $this->assertFalse($r['ran'] ?? false);
        $this->assertSame('mesh_unavailable', $r['reason']);
        $this->assertSame('mesh_unavailable', $r2['reason']);
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_mesh_nodes'), 'fail closed');
        $this->assertSame(MeshMapping::NEEDS_APPROVAL, $this->state($dev));
        $this->assertSame(1, $this->audits('Mesh Verification Failed'), 'audited once a day');
        $cookies = array_column(array_filter($ctl->log(), static fn (array $e): bool => $e['event'] === 'first_frame'), 'cookie');
        $all = json_encode($this->h->audit->records()) . (string) file_get_contents((string) $log);
        $this->assertStringNotContainsString($this->keyHex, $all);
        $cookies = array_filter($cookies, static fn (string $c): bool => $c !== '');   // a server that refused the handshake never saw one
        foreach ($cookies as $c) {
            $this->assertStringNotContainsString($c, $all, 'the cookie is never logged or audited');
        }
        @unlink((string) $log);
    }

    public function testATimeoutFailsClosed(): void
    {
        [$dev] = $this->deviceWith('HOST1', self::NODE_A);
        $this->verifyOn(['mode' => 'silent']);
        $svc = new MeshVerifyService($this->h->module->sql(), $this->h->module->settings(), $this->h->module->meshVerifySettings(), new MeshControlClient(new \RivetCore\Webhooks\UrlPolicy(true), true, 2), $this->h->module->meshAgents(), $this->h->module->devices(), $this->h->audit, $this->h->box, true);
        $t0 = microtime(true);
        $r = $svc->run();
        $this->assertLessThan(6.0, microtime(true) - $t0);
        $this->assertSame('mesh_unavailable', $r['reason']);
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_mesh_nodes'));
    }

    public function testARunIsCappedAndAdevicesLookIsNotRepeatedImmediately(): void
    {
        $ids = [];
        foreach ([['HOST1', self::NODE_A], ['HOST2', self::NODE_B], ['HOST3', self::NODE_C]] as [$n, $node]) {
            [$ids[]] = $this->deviceWith($n, $node);
        }
        $this->verifyOn(['nodes' => $this->nodes([self::GROUP_A => [$this->node(self::NODE_A, 'HOST1'), $this->node(self::NODE_B, 'HOST2'), $this->node(self::NODE_C, 'HOST3')]])]);
        $r = $this->h->module->meshVerifyService()->run(2);
        $this->assertSame(['checked' => 2, 'mapped' => 2, 'left' => 1], ['checked' => $r['checked'], 'mapped' => $r['mapped'], 'left' => $r['left']]);
        $this->assertSame(1, count(array_filter($this->ctl->log(), static fn (array $e): bool => $e['event'] === 'handshake')), 'one connection per run');
        $r = $this->h->module->meshVerifyService()->run(2);
        $this->assertSame(1, $r['mapped']);
        $this->assertSame(3, (int) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_mesh_nodes'));
        $this->assertSame('nothing_to_do', $this->h->module->meshVerifyService()->run()['reason']);
    }

    public function testTheHousekeepingJobRunsTheVerification(): void
    {
        [$dev, $t] = $this->deviceWith('HOST1', self::NODE_A);
        $this->verifyOn(['nodes' => $this->nodes([self::GROUP_A => [$this->node(self::NODE_A, 'HOST1')]])]);
        $this->h->enable();
        $out = $this->h->module->housekeeping()->run();
        $this->assertSame(1, $out['mesh_verified']);
        $this->assertSame(MeshMapping::MAPPED, $this->state($dev));
    }
}
