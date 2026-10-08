<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use PHPUnit\Framework\Attributes\DataProvider;
use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Mesh\MeshCookie;
use RivetCore\Tests\Support\MockMeshServer;
use RivetCore\Tests\Support\RmmHarness;
use RivetCore\Tests\Support\RmmTestCase;
use RivetCore\Tests\Support\RoleMatrixPolicy;

/**
 * Port of RivetIT tests/endpoint_agent_authz.php (the 151-check role matrix, REST side and MeshCentral launch) against Core's
 * TechnicianApi + RmmAuthorizer + a stub policy that models RivetIT's rules (RoleMatrixPolicy). What is not here is the edition's own
 * business and stays in the edition's tests: forged web sessions, CSRF, page rendering and escaping, the admin POST handler routing.
 *
 * One deliberate difference: RivetIT's API layer refuses a module-only login's token before the endpoint is reached (403 on every
 * call), while Authz lets such a login VIEW; Core only knows the policy, so a module-only user views (200) and can do nothing else.
 */
final class RoleMatrixTest extends RmmTestCase
{
    /** @var array<string,RmmPrincipal> */
    private array $who = [];
    private int $D1 = 0;
    private int $D2 = 0;
    private int $D3 = 0;
    private string $T1 = '';
    private string $key = '';
    private ?MockMeshServer $mesh = null;
    private int $savedScript = 0;

    protected function makeHarness(): RmmHarness
    {
        $p = new RoleMatrixPolicy();
        $p->addUser(1, 3, 3, 1, admin: true);
        $p->addUser(10, 3, 3, 1);                       // full tech
        $p->addUser(11, 1, 2, 0);                       // reboot-only
        $p->addUser(12, 1, 0, 0);                       // viewer
        $p->addUser(13, 1, 0, 1);                       // remote-only
        $p->addUser(14, 3, 3, 1, limited: true);        // module-only login
        $p->addUser(15, 0, 0, 0);                       // no RMM access at all
        $p->addUser(16, 3, 3, 1);                       // full tech restricted to client B
        $this->who = ['admin' => new RmmPrincipal(1, 'Admin'), 'tech' => new RmmPrincipal(10, 'Tech'), 'rebootonly' => new RmmPrincipal(11, 'RebootOnly'),
            'viewer' => new RmmPrincipal(12, 'Viewer'), 'remoteonly' => new RmmPrincipal(13, 'RemoteOnly'), 'moduleonly' => new RmmPrincipal(14, 'ModuleOnly'),
            'normo' => new RmmPrincipal(15, 'NoRmm'), 'deptb' => new RmmPrincipal(16, 'DeptB')];

        return new RmmHarness(policy: $p);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->h->tenancy->restrictUser(16, [$this->h->clientB]);
        $this->mesh = new MockMeshServer();
        $this->key = MeshCookie::newLoginKey();
        $this->h->enable();
        $r = $this->h->module->admin()->saveMesh($this->who['admin'], ['mesh_enabled' => 1, 'mesh_url' => $this->mesh->url(), 'mesh_login_key' => $this->key, 'mesh_account_template' => 'rivetit-support']);
        $this->assertTrue($r->ok, $r->message);
        $tokA = $this->h->token($this->h->clientA, 24, 50);
        $tokB = $this->h->token($this->h->clientB, 24, 50);
        [$this->D1, $this->T1] = $this->mkdev($tokA, 'DEPT1-PC', $this->h->clientA);
        [$this->D2] = $this->mkdev($tokB, 'DEPT2-PC', $this->h->clientB);
        [$this->D3] = $this->mkdev($tokA, 'UNMAPPED-PC', $this->h->clientA, false);
        $this->savedScript = $this->h->bridge->addScript('Get-Date');
    }

    protected function tearDown(): void
    {
        $this->mesh?->stop();
        parent::tearDown();
    }

    /** @return array{0:int,1:string} */
    private function mkdev(string $token, string $name, int $client, bool $withNode = true): array
    {
        $this->h->asset(['name' => $name, 'serial' => "SER-$name", 'client_id' => $client]);
        [$c, , $j] = $this->h->enroll($token, $this->h::device(['serial' => "SER-$name", 'hostname' => $name]));
        $this->assertSame(201, $c);
        $id = (int) $j['device_id'];
        if ($withNode) {
            $this->assertTrue($this->h->module->devices()->setMeshNode($id, 'node//' . str_repeat('A', 24) . $id, 1));
        }
        $this->h->checkin($j['device_token']);

        return [$id, $j['device_token']];
    }

    /**
     * @param list<string> $seg
     * @return array{0:int,1:mixed}
     */
    private function api(string $who, string $method, array $seg, mixed $body = null): array
    {
        [$c, $b] = $this->h->tech($method, $seg, $this->who[$who], $body);

        return [$c, $b];
    }

    // ------------------------------------------------------------------ the matrix

    /** @return array<string,array{0:string,1:list<int>}> */
    public static function matrix(): array
    {
        //                  view  free-form  saved  reboot  remote  (status codes for device 1, client A)
        return [
            'admin' => ['admin', [200, 201, 201, 201, 200]],
            'tech' => ['tech', [200, 201, 201, 201, 200]],
            'rebootonly' => ['rebootonly', [200, 403, 201, 201, 403]],
            'viewer' => ['viewer', [200, 403, 403, 403, 403]],
            'remoteonly' => ['remoteonly', [200, 403, 403, 403, 200]],
            'moduleonly' => ['moduleonly', [200, 403, 403, 403, 403]],
            'normo' => ['normo', [403, 403, 403, 403, 403]],
            'deptb' => ['deptb', [404, 404, 404, 404, 404]],
        ];
    }

    /**
     * @param list<int> $expect
     */
    #[DataProvider('matrix')]
    public function testRoleMatrix(string $who, array $expect): void
    {
        [$view, $ps, $saved, $reboot, $remote] = $expect;
        [$c] = $this->api($who, 'GET', [(string) $this->D1]);
        $this->assertSame($view, $c, "$who: view device");
        [$c] = $this->api($who, 'POST', [(string) $this->D1, 'jobs'], ['type' => 'powershell', 'script' => 'Get-Date']);
        $this->assertSame($ps, $c, "$who: free-form PowerShell");
        [$c] = $this->api($who, 'POST', [(string) $this->D1, 'jobs'], ['type' => 'powershell', 'script_id' => $this->savedScript]);
        $this->assertSame($saved, $c, "$who: saved script");
        [$c] = $this->api($who, 'POST', [(string) $this->D1, 'jobs'], ['type' => 'reboot', 'confirm' => true]);
        $this->assertSame($reboot, $c, "$who: reboot");
        [$c] = $this->api($who, 'POST', [(string) $this->D1, 'remote'], []);
        $this->assertSame($remote, $c, "$who: remote launch");
    }

    public function testDepartmentRestrictedUserSeesOnlyTheirClient(): void
    {
        [$c, $r] = $this->api('deptb', 'GET', []);
        $this->assertSame(200, $c);
        $this->assertCount(1, $r['data']);
        $this->assertSame($this->D2, $r['data'][0]['device_id']);
        $this->assertSame(1, $r['total']);
        [$c] = $this->api('deptb', 'GET', [(string) $this->D2]);
        $this->assertSame(200, $c);
        [$c] = $this->api('deptb', 'POST', [(string) $this->D2, 'remote'], []);
        $this->assertSame(200, $c);
        [$c] = $this->api('deptb', 'POST', [(string) $this->D2, 'jobs'], ['type' => 'powershell', 'script' => 'x']);
        $this->assertSame(201, $c);
        [$c, $r] = $this->api('admin', 'GET', []);
        $this->assertSame(200, $c);
        $this->assertCount(3, $r['data']);
    }

    public function testMissingAndOutOfScopeDevicesAreTheSame404(): void
    {
        [$c1, $b1] = $this->api('deptb', 'GET', [(string) $this->D1]);        // exists, other client
        [$c2, $b2] = $this->api('deptb', 'GET', ['999999']);                  // does not exist
        $this->assertSame(404, $c1);
        $this->assertSame([$c2, $b2], [$c1, $b1]);
        $this->assertSame('not_found', $b1['code']);
        // a role that cannot view anything learns nothing about any device: 403 for the existing and the missing one alike
        [$c1, $b1] = $this->api('normo', 'GET', [(string) $this->D1]);
        [$c2, $b2] = $this->api('normo', 'GET', ['999999']);
        $this->assertSame(403, $c1);
        $this->assertSame([$c2, $b2], [$c1, $b1]);
        [$c] = $this->api('normo', 'GET', []);
        $this->assertSame(403, $c);
    }

    public function testUnauthenticatedAndDisabledAccounts(): void
    {
        [$c, $b] = $this->h->tech('GET', [(string) $this->D1], null);
        $this->assertSame(401, $c);
        $this->assertSame(['error' => 'Unauthorized'], $b);
        $this->h->policy instanceof RoleMatrixPolicy && $this->h->policy->deactivate(10);
        $this->h->module->authorizer()->forget();   // the authorizer remembers answers for the request
        [$c] = $this->api('tech', 'POST', [(string) $this->D1, 'jobs'], ['type' => 'collect']);
        $this->assertSame(403, $c, 'a deactivated user is refused by the policy');
        [$c] = $this->api('tech', 'GET', [(string) $this->D1]);
        $this->assertSame(403, $c);
        $this->h->policy instanceof RoleMatrixPolicy && $this->h->policy->activate(10);
        $this->h->module->authorizer()->forget();
        [$c] = $this->api('tech', 'GET', [(string) $this->D1]);
        $this->assertSame(200, $c);
    }

    public function testDeniedAttemptsAreAudited(): void
    {
        foreach (['viewer', 'remoteonly', 'rebootonly', 'moduleonly'] as $who) {
            $this->api($who, 'POST', [(string) $this->D1, 'jobs'], ['type' => 'powershell', 'script' => 'x']);
        }
        foreach (['viewer', 'rebootonly'] as $who) {
            $this->api($who, 'POST', [(string) $this->D1, 'remote'], []);
        }
        $actions = array_count_values(array_column($this->h->audit->records(), 'action'));
        $this->assertGreaterThanOrEqual(4, $actions['Job Denied'] ?? 0);
        $this->assertGreaterThanOrEqual(2, $actions['Remote Denied'] ?? 0);
    }

    public function testDisabledModuleAnswersDisabledAndDevicesGet403(): void
    {
        $this->h->module->settings()->disable();
        [$c, $r] = $this->api('tech', 'GET', [(string) $this->D1]);
        $this->assertSame(404, $c);
        $this->assertSame('disabled', $r['code']);
        [$c] = $this->h->checkin($this->T1);
        $this->assertSame(403, $c, 'with the service switched off devices get 403 forbidden');
        $this->h->module->settings()->enable();
    }

    // ------------------------------------------------------------------ MeshCentral launch

    public function testLaunchReturnsALoginTokenUrlForTheLinkedDevicesNodeAndStoresNoToken(): void
    {
        [$c, $r] = $this->api('tech', 'POST', [(string) $this->D1, 'remote'], []);
        $this->assertSame(200, $c);
        $this->assertArrayHasKey('url', $r);
        $this->assertArrayHasKey('session_id', $r);
        $u = parse_url($r['url']);
        parse_str($u['query'], $qs);
        $this->assertStringStartsWith($this->mesh->url() . '/?login=', $r['url']);
        $this->assertSame('node//' . str_repeat('A', 24) . $this->D1, $qs['gotonode']);
        $this->assertSame('11', $qs['viewmode']);
        $cookie = MeshCookie::decode($qs['login'], $this->key);
        $this->assertNotNull($cookie);
        $this->assertSame('user//rivetit-support', $cookie['u']);
        $this->assertSame(3, $cookie['a']);
        $this->assertEqualsWithDelta(time() - 120, $cookie['time'], 30);
        $this->assertStringNotContainsString('+', $qs['login']);
        $this->assertStringNotContainsString('/', $qs['login']);
        [, $r2] = $this->api('tech', 'POST', [(string) $this->D1, 'remote'], []);
        $this->assertNotSame(parse_url($r2['url'], PHP_URL_QUERY), parse_url($r['url'], PHP_URL_QUERY), 'every launch mints a different token');
        $sessions = $this->h->bridge->sessions();
        $this->assertCount(2, $sessions);
        $this->assertSame('meshcentral', $sessions[0]['connection_type']);
        $this->assertSame('meshcentral:session:' . $r['session_id'], $sessions[0]['reference']);
        $this->assertStringNotContainsString('login', $sessions[0]['reference']);
        $this->assertSame(10, $sessions[0]['user_id']);
        $this->assertSame('127.0.0.1', $sessions[0]['ip_address']);
        $log = json_encode($this->h->audit->records());
        $this->assertStringNotContainsString($qs['login'], (string) $log);
        $this->assertStringNotContainsString($this->key, (string) $log);
        $this->assertStringContainsString($r['session_id'], (string) $log);
        $mine = array_filter($this->h->audit->records(), static fn (array $a): bool => $a['action'] === 'Remote Session' && str_contains($a['description'], 'Tech '));
        $this->assertCount(2, $mine, 'the audit names the actor');
    }

    public function testUnmappedOfflineAndForcedLaunch(): void
    {
        [$c, $r] = $this->api('tech', 'POST', [(string) $this->D3, 'remote'], []);
        $this->assertSame(404, $c);
        $this->assertSame('unmapped', $r['code']);
        $this->assertStringContainsString('not mapped', $r['error']);
        $this->assertArrayNotHasKey('url', $r);
        $this->h->q("UPDATE endpoint_agent_devices SET last_checkin_at='" . gmdate('Y-m-d H:i:s', time() - 5000) . "' WHERE device_id={$this->D1}");
        [$c, $r] = $this->api('tech', 'POST', [(string) $this->D1, 'remote'], []);
        $this->assertSame(409, $c);
        $this->assertSame('device_offline', $r['code']);
        $this->assertArrayNotHasKey('url', $r);
        [$c, $r] = $this->api('tech', 'POST', [(string) $this->D1, 'remote'], ['force' => true]);
        $this->assertSame(200, $c);
        $this->assertArrayHasKey('url', $r);
        $this->assertStringContainsString('(forced while offline)', (string) json_encode($this->h->audit->records()));
    }

    public function testMeshOutagesAreA503WithAClearMessage(): void
    {
        $down = new MockMeshServer('error');
        $this->h->module->settings()->set(['mesh_url' => $down->url()]);
        [$c, $r] = $this->api('tech', 'POST', [(string) $this->D1, 'remote'], []);
        $this->assertSame(503, $c);
        $this->assertSame('mesh_unavailable', $r['code']);
        $this->assertArrayNotHasKey('url', $r);
        $down->stop();
        $this->h->module->settings()->set(['mesh_url' => 'http://127.0.0.1:1']);
        [$c, $r] = $this->api('tech', 'POST', [(string) $this->D1, 'remote'], []);
        $this->assertSame(503, $c, 'connection refused');
        $this->assertSame('mesh_unavailable', $r['code']);
    }

    public function testMeshTimeoutIsBoundedByTheProbeTimeout(): void
    {
        $slow = new MockMeshServer('slow');
        $this->h->module->settings()->set(['mesh_url' => $slow->url()]);
        $t0 = microtime(true);
        [$c, $r] = $this->api('tech', 'POST', [(string) $this->D1, 'remote'], []);
        $this->assertSame(503, $c);
        $this->assertSame('mesh_unavailable', $r['code']);
        $this->assertLessThan(15, microtime(true) - $t0);
        $slow->stop();
    }

    public function testStrictNetworkPolicyRefusesPrivateTargets(): void
    {
        $strict = new RmmHarness(policy: $this->h->policy, urlPolicy: new \RivetCore\Webhooks\UrlPolicy(false));   // (it resets the scratch tables: this test needs only its own data)
        $strict->enable();
        foreach (['http://169.254.169.254', 'http://127.0.0.1:' . $this->mesh->port, 'http://10.0.0.5', 'http://[::1]'] as $bad) {
            $msg = $strict->module->mesh()->probe($bad);
            $this->assertNotNull($msg, $bad);
            $this->assertStringContainsString('network policy', (string) $msg, $bad);
        }
        // the same address through a launch is a 503 with the policy text, and no URL
        $strict->tenancy->restrictUser(16, null);
        $r = $strict->module->admin()->saveMesh($this->who['admin'], ['mesh_enabled' => 1, 'mesh_url' => 'http://169.254.169.254', 'mesh_login_key' => $this->key]);
        $this->assertTrue($r->ok, 'the address is a valid URL; the network policy applies when it is used');
        $strict->asset(['name' => 'STRICT', 'serial' => 'SER-STRICT']);
        [, , $j] = $strict->enroll($strict->token(), $strict::device(['serial' => 'SER-STRICT', 'hostname' => 'STRICT']));
        $strict->module->devices()->setMeshNode((int) $j['device_id'], 'node//' . str_repeat('A', 24), 1);
        $strict->checkin($j['device_token']);
        [$c, $body] = $strict->tech('POST', [(string) $j['device_id'], 'remote'], $this->who['admin'], []);
        $this->assertSame(503, $c);
        $this->assertSame('mesh_unavailable', $body['code']);
        $this->assertStringContainsString('network policy', $body['error']);
        $this->assertArrayNotHasKey('url', $body);
        $r = $strict->module->admin()->saveMesh($this->who['admin'], ['mesh_enabled' => 1, 'mesh_url' => 'http://169.254.169.254'], true);
        $this->assertFalse($r->ok);
        $this->assertStringContainsString('network policy', $r->message);
    }

    public function testAllowedInternalNetworkIsReachableButMetadataNeverIs(): void
    {
        $allowed = new RmmHarness(policy: $this->h->policy, urlPolicy: new \RivetCore\Webhooks\UrlPolicy(false, null, ['10.0.0.0/8']));
        $msg = $allowed->module->mesh()->probe('http://169.254.169.254');
        $this->assertStringContainsString('network policy', (string) $msg);
        $msg = $allowed->module->mesh()->probe('http://127.0.0.1:' . $this->mesh->port);
        $this->assertStringContainsString('network policy', (string) $msg, 'loopback is never allowed, even with an internal network listed');
    }

    public function testUrlValidationAndLaunchPreconditions(): void
    {
        $m = $this->h->module->mesh();
        $this->assertNull($m->normalizeUrl('ftp://x'));
        $this->assertNull($m->normalizeUrl('http://user:pw@host'));
        $this->assertNull($m->normalizeUrl('https://host/?a=b'));
        $this->assertNull($m->normalizeUrl('https://host/#f'));
        $this->assertSame('https://mesh.example.com', $m->normalizeUrl('https://mesh.example.com/'));
        $this->assertNull(\RivetCore\Rmm\Mesh\MeshService::normalizeUrlWith('http://mesh.example.com', false), 'http only when the module allows it');
        $this->assertSame('http://mesh.example.com', \RivetCore\Rmm\Mesh\MeshService::normalizeUrlWith('http://mesh.example.com', true));
        $this->h->module->settings()->set(['mesh_enabled' => 0]);
        [$c, $r] = $this->api('tech', 'POST', [(string) $this->D1, 'remote'], []);
        $this->assertSame(409, $c);
        $this->assertSame('not_configured', $r['code']);
        $this->h->module->settings()->set(['mesh_enabled' => 1]);
        $this->assertTrue($this->h->module->technician()->retire($this->who['admin'], $this->D3)->ok);
        $this->assertTrue($this->h->module->technician()->revoke($this->who['admin'], $this->D2)->ok);
        [$c, $r] = $this->api('admin', 'POST', [(string) $this->D2, 'remote'], []);
        $this->assertSame(409, $c);
        $this->assertSame('device_retired', $r['code'], 'a revoked device cannot be launched');
    }

    public function testNodeMappingIsValidatedAndSeparateFromTheAssetName(): void
    {
        $t = $this->h->module->technician();
        $this->assertSame(422, $t->setMeshNode($this->who['admin'], $this->D1, 'node//short')->http);
        $this->assertTrue($t->setMeshNode($this->who['admin'], $this->D1, 'node//' . str_repeat('B', 24))->ok);
        $this->assertSame('node//' . str_repeat('B', 24), $this->h->one("SELECT mesh_node_id FROM endpoint_agent_mesh_nodes WHERE device_id={$this->D1}"));
        $this->assertSame(403, $t->setMeshNode($this->who['tech'], $this->D1, 'node//' . str_repeat('Q', 24))->http, 'only administrators may map a node');
        $this->assertTrue($t->setMeshNode($this->who['admin'], $this->D1, '')->ok);
        $this->assertSame(0, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_mesh_nodes WHERE device_id={$this->D1}"));
        $this->assertStringContainsString('cleared', (string) json_encode($this->h->audit->records()));
    }
}
