<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Rmm\Crypto\Signer;
use RivetCore\Rmm\Job\JobExtras;
use RivetCore\Rmm\RmmEvent;
use RivetCore\Testing\InMemoryRmmEvents;
use RivetCore\Tests\Support\RemoteTestCase;
use RivetCore\Tests\Support\RmmHarness;

/**
 * 1.0.0-rc.16: the pushed MeshCentral agent install (`mesh_install`) and removal (`mesh_uninstall`): offered only when Mesh is enabled and configured and the agent
 * announced the capability, parameters taken only from the stored settings, the group id kept out of the job row, idempotency, the bulk variant with its caps and
 * approval rule, the events, and client scoping of every new route.
 */
final class MeshPushTest extends RemoteTestCase
{
    private const GROUP = '4a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f9';
    private const GROUP_URL = 'ShssPU5fYHGCk6S1xtfo@QobLD1OX2BxgpOktcbX6PkKGyw9Tl9gcYKTpLXG1@j5';
    private const NODE = 'node//AbCdEfGhIjKlMnOpQrStUvWxYz0123456789@$AbCdEfGhIjKlMnOpQrStUvWxYz01';
    private InMemoryRmmEvents $bus;
    private string $pub = '';

    protected function makeHarness(): RmmHarness
    {
        $this->transferDir = sys_get_temp_dir() . '/rmm_xfer_' . bin2hex(random_bytes(4));
        $this->bus = new InMemoryRmmEvents();
        $tech = [RmmAbility::DEVICE_VIEW, RmmAbility::JOB_RUN_SAVED, RmmAbility::REMOTE_LAUNCH, RmmAbility::REMOTE_MANAGE];

        return new RmmHarness(null, null, ['allow_linux' => true, 'transfer_dir' => $this->transferDir], false, null,
            new \RivetCore\Tests\Support\AllowUsersPolicy([1 => true, 10 => $tech, 11 => [RmmAbility::DEVICE_VIEW, RmmAbility::JOB_RUN_SAVED, RmmAbility::REMOTE_LAUNCH], 12 => [RmmAbility::DEVICE_VIEW], 16 => true]),
            new \RivetCore\Webhooks\UrlPolicy(true), $this->bus);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->h->module->settings()->set(['service_url' => 'https://rmm.example.test']);
        $this->switchOn(['remote_tools']);
        $this->pub = $this->h->module->settings()->signingKey()[1] ?? '';
    }

    private function meshOn(array $extra = []): void
    {
        $this->h->module->settings()->set(['mesh_enabled' => 1, 'mesh_url' => 'https://mesh.example.com']);
        $this->assertSame([], $this->h->module->meshAgentSettings()->update(['mesh_group_id' => self::GROUP] + $extra, 1));
    }

    /** @return list<string> */
    private static function caps(string $os = 'windows', bool $mesh = true): array
    {
        $caps = ['job:collect', 'job:reboot', $os === 'windows' ? 'job:powershell' : 'job:shell', 'mesh:nodeid'];
        if ($mesh) {
            array_push($caps, 'job:mesh_install', 'job:mesh_uninstall');
        }
        sort($caps);

        return $caps;
    }

    /** @return array{0:int,1:string} */
    private function device(string $name, ?int $client = null, string $os = 'windows', bool $mesh = true): array
    {
        return $this->linked($name, $client, $os, self::caps($os, $mesh));
    }

    private function jobRow(string $id): array
    {
        return $this->h->rows("SELECT * FROM endpoint_agent_jobs WHERE job_id='$id'")[0];
    }

    private function audits(string $action): int
    {
        return count(array_filter($this->h->audit->records(), static fn (array $r): bool => $r['action'] === $action));
    }

    // ------------------------------------------------------------------ registry and frozen values

    public function testTheRegistryAndTheFrozenListsAreAsDocumented(): void
    {
        $this->assertSame(['svc_list', 'svc_control', 'proc_list', 'proc_kill', 'file_get', 'file_put'], \RivetCore\Rmm\Remote\RemoteProtocol::JOB_TYPES, 'the frozen list did not move');
        $reg = $this->h->module->jobs()->registry();
        $i = $reg->get('mesh_install');
        $u = $reg->get('mesh_uninstall');
        $this->assertNotNull($i);
        $this->assertNotNull($u);
        $this->assertSame([RmmAbility::REMOTE_MANAGE, false, ['windows', 'linux'], 'job:mesh_install'], [$i->ability, $i->destructive, $i->platforms, $i->capability]);
        $this->assertSame([RmmAbility::REMOTE_MANAGE, true, 'job:mesh_uninstall'], [$u->ability, $u->destructive, $u->capability], 'the removal is destructive: offered at most once');
        $this->assertTrue(\RivetCore\Rmm\Remote\RemoteProtocol::isToolJob('mesh_install'));
        $this->assertFalse(\RivetCore\Rmm\Remote\RemoteProtocol::isToolJob('powershell'));
    }

    // ------------------------------------------------------------------ the single install

    public function testOrderOfDecisions(): void
    {
        [$d] = $this->device('M1');
        $route = [(string) $d, 'mesh', 'install'];
        $this->assertSame(401, $this->h->tech('POST', $route, null)[0]);
        $this->assertSame(403, $this->api('POST', $route, $this->viewer)[0]);
        $this->assertSame(403, $this->api('POST', $route, $this->runner)[0], 'saved-script rights are not remote.manage');
        $this->assertSame(404, $this->api('POST', $route, $this->scoped)[0]);
        $this->assertSame(404, $this->api('POST', ['99999', 'mesh', 'install'], $this->tech)[0]);
        [$c, $j] = $this->api('POST', $route, $this->tech);
        $this->assertSame([409, 'not_configured'], [$c, $j['code']], 'Mesh is not switched on');
        $this->assertStringContainsString('not switched on', $j['error']);
        $this->h->module->settings()->set(['mesh_enabled' => 1, 'mesh_url' => 'https://mesh.example.com']);
        $this->assertStringContainsString('device group id', $this->api('POST', $route, $this->tech)[1]['error']);
        $this->h->module->settings()->set(['mesh_url' => 'http://mesh.example.com']);
        $this->assertStringContainsString('https://', $this->api('POST', $route, $this->tech)[1]['error'], 'plain http is never an install source');
        $this->meshOn();
        $this->assertSame([], $this->h->module->settings()->update(['features_json' => ['monitoring' => true, 'jobs' => true]]));
        [$c, $j] = $this->api('POST', $route, $this->tech);
        $this->assertSame([409, 'feature_disabled'], [$c, $j['code']]);
        $this->assertSame(0, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE type='mesh_install'"), 'nothing was queued by any refusal');
    }

    public function testAnAgentWithoutTheCapabilityIsRefusedWithAnUpdateHint(): void
    {
        $this->meshOn();
        [$d] = $this->device('OLD', null, 'windows', false);
        [$c, $j] = $this->api('POST', [(string) $d, 'mesh', 'install'], $this->tech);
        $this->assertSame([409, 'unsupported'], [$c, $j['code']]);
        $this->assertStringContainsString('Update the agent', $j['error']);
        // an agent that announced nothing at all is not assumed to have it either
        [$d2] = $this->linked('SILENT', null, 'windows', []);
        $this->assertSame(409, $this->api('POST', [(string) $d2, 'mesh', 'install'], $this->tech)[0]);
    }

    public function testTheJobCarriesOnlyTheStoredSettingsAndTheGroupIdStaysOutOfTheRow(): void
    {
        $pin = hash('sha384', 'cert');
        $this->meshOn(['mesh_server_cert_pin' => $pin, 'mesh_agent_ids' => ['windows/arm64' => 40]]);
        [$d, $tok] = $this->device('M2');
        [$c, $j] = $this->api('POST', [(string) $d, 'mesh', 'install'], $this->tech);
        $this->assertSame(202, $c, json_encode($j));
        $row = $this->jobRow($j['job_id']);
        $this->assertSame(['mesh_install', 0, 10, 900], [$row['type'], (int) $row['destructive'], (int) $row['created_by'], (int) $row['timeout_s']]);
        $stored = json_decode((string) $row['params_json'], true);
        $this->assertSame(['url', 'meshid', 'pin', 'agent_ids', 'install_args', 'force'], array_keys($stored));
        $this->assertSame([JobExtras::SECRET_MARK, false, $pin], [$stored['meshid'], $stored['force'], $stored['pin']]);
        $this->assertStringNotContainsString(self::GROUP_URL, (string) $row['params_json'], 'the group id is not in the job row');
        $this->assertStringNotContainsString(self::GROUP, (string) $row['params_json']);
        // what the device is offered: the real group id, signed
        $jobs = $this->offered($tok);
        $this->assertCount(1, $jobs);
        $p = $jobs[0]['params'];
        $this->assertSame('mesh_install', $jobs[0]['type']);
        $this->assertSame(['https://mesh.example.com', self::GROUP_URL, $pin], [$p['url'], $p['meshid'], $p['pin']]);
        $this->assertSame(40, json_decode($p['agent_ids'], true)['windows/arm64']);
        $this->assertSame(['-fullinstall'], json_decode($p['install_args'], true)['windows']);
        $this->assertTrue(Signer::verify(Signer::jobMessage($jobs[0]), $jobs[0]['signature'], $this->pub), 'the offer is signed over the real parameters');
        // the device echoes the group id in its output: it never reaches storage
        $this->report($tok, $jobs[0], 'succeeded', json_encode(['v' => 1, 'ok' => true, 'detail' => 'installed', 'note' => 'joined ' . self::GROUP_URL, 'node_id' => self::NODE]));
        $after = $this->jobRow($j['job_id']);
        $this->assertStringNotContainsString(self::GROUP_URL, (string) $after['output']);
        $this->assertNull($this->h->one("SELECT secret_params_enc FROM rmm_job_extra WHERE job_id='{$j['job_id']}'"), 'the sealed value is wiped once the job has a result');
        $this->assertStringContainsString('install the MeshCentral agent', json_encode($this->h->audit->records()));
    }

    public function testNothingTheCallerSendsReachesTheJob(): void
    {
        $this->meshOn();
        [$d] = $this->device('M3');
        $route = [(string) $d, 'mesh', 'install'];
        foreach ([['url' => 'https://evil.example'], ['meshid' => 'x'], ['args' => ['-x']], ['command' => 'calc'], ['force' => 'yes'], ['force' => 1]] as $body) {
            [$c] = $this->api('POST', $route, $this->tech, $body);
            $this->assertSame(422, $c, json_encode($body));
        }
        $this->assertSame(0, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE type='mesh_install'"));
        // and the generic job endpoint refuses the type altogether
        [$c, $j] = $this->api('POST', [(string) $d, 'jobs'], $this->tech, ['type' => 'mesh_install', 'params' => ['url' => 'https://evil.example']]);
        $this->assertSame(422, $c);
        $this->assertStringContainsString('remote-tools endpoints', $j['error']);
        // the job validator itself refuses a hostile parameter set
        $reg = $this->h->module->jobs()->registry()->get('mesh_install');
        foreach ([['url' => 'http://m.example', 'meshid' => self::GROUP_URL], ['url' => 'https://m.example/?x=1', 'meshid' => self::GROUP_URL], ['url' => 'https://u:p@m.example', 'meshid' => self::GROUP_URL],
            ['url' => 'https://m.example', 'meshid' => 'nope'], ['url' => 'https://m.example', 'meshid' => self::GROUP_URL, 'pin' => 'zz'], ['url' => 'https://m.example', 'meshid' => self::GROUP_URL, 'extra' => 1],
            ['url' => 'https://m.example', 'meshid' => self::GROUP_URL, 'install_args' => '{"linux":["-install; reboot"]}'], ['url' => 'https://m.example', 'meshid' => self::GROUP_URL, 'agent_ids' => '{"linux/amd64":999}']] as $bad) {
            $this->assertNotNull(($reg->paramValidator)($bad)[1], json_encode($bad));
        }
        $this->assertNull(($reg->paramValidator)(['url' => 'https://m.example', 'meshid' => self::GROUP_URL])[1]);
    }

    public function testInstallIsIdempotentAndReportsFlowIntoTheDeviceState(): void
    {
        $this->meshOn();
        [$d, $tok] = $this->device('M4', null, 'linux');
        $route = [(string) $d, 'mesh', 'install'];
        [$c, $j] = $this->api('POST', $route, $this->tech);
        $this->assertSame(202, $c);
        $first = $j['job_id'];
        // a second click while the job is open answers with the same job
        [$c, $j] = $this->api('POST', $route, $this->tech);
        $this->assertSame([200, 'already_queued', $first], [$c, $j['skipped'], $j['job_id']]);
        $this->assertSame(1, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE type='mesh_install'"));
        $jobs = $this->offered($tok);
        $this->report($tok, $jobs[0], 'succeeded', '{"v":1,"ok":true,"detail":"installed"}');
        // the existing check-in member carries the result; the node id it names waits for approval (rc.31 review F1) and is mapped when an administrator confirms it
        [$c, , $ck] = $this->h->checkin($tok, ['mesh_agent' => ['installed' => true, 'version' => '1.2.3', 'node_id' => self::NODE]]);
        $this->assertSame(200, $c, json_encode($ck));
        $this->assertNull($this->h->one("SELECT mesh_node_id FROM endpoint_agent_mesh_nodes WHERE device_id=$d"));
        $this->assertSame('needs_approval', $this->h->module->meshAgents()->stateFor($d)['map_state']);
        $this->assertTrue($this->h->module->devices()->setMeshNode($d, self::NODE, 1));
        $this->assertCount(1, $this->bus->of(RmmEvent::MESH_AGENT_INSTALLED), 'the existing rmm.mesh.agent_installed event fires once the check-in reports it');
        $detail = $this->api('GET', [(string) $d], $this->tech)[1];
        $this->assertTrue($detail['mesh_agent']['installed']);
        $this->assertSame(['available' => true, 'agent_supports' => true, 'installed' => true], $detail['mesh_install']);
        // already installed: skipped, nothing queued; force queues again
        [$c, $j] = $this->api('POST', $route, $this->tech);
        $this->assertSame([200, 'already_installed', self::NODE], [$c, $j['skipped'], $j['node_id']]);
        $this->assertSame(1, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE type='mesh_install'"));
        [$c, $j] = $this->api('POST', $route, $this->tech, ['force' => true]);
        $this->assertSame(202, $c);
        $this->assertTrue(json_decode((string) $this->jobRow($j['job_id'])['params_json'], true)['force']);
    }

    public function testAFailedInstallIsStoredAndShownWithoutHidingTheDeviceState(): void
    {
        $this->meshOn();
        [$d, $tok] = $this->device('M5');
        $j = $this->api('POST', [(string) $d, 'mesh', 'install'], $this->tech)[1];
        $jobs = $this->offered($tok);
        $this->report($tok, $jobs[0], 'failed', '{"v":1,"ok":false,"detail":"install_failed","installed":false,"error":"the Mesh server certificate does not match the pinned hash"}', 1);
        $this->h->checkin($tok, ['mesh_agent' => ['installed' => false, 'error' => 'the Mesh server certificate does not match the pinned hash']]);
        $res = $this->api('GET', [(string) $d, 'remote', 'jobs', $j['job_id']], $this->tech)[1];
        $this->assertSame(['failed', 'install_failed'], [$res['state'], $res['result']['detail']]);
        $state = $this->api('GET', [(string) $d], $this->tech)[1]['mesh_agent'];
        $this->assertFalse($state['installed']);
        $this->assertStringContainsString('pinned hash', $state['error']);
        $this->assertSame(1, $this->audits('Mesh Agent Install Failed'));
        // a viewer holding only the saved-script grant cannot read a Mesh job's result or output
        $this->assertSame(403, $this->api('GET', [(string) $d, 'remote', 'jobs', $j['job_id']], $this->runner)[0]);
        $row = $this->api('GET', [(string) $d, 'jobs'], $this->runner)[1]['data'][0];
        $this->assertArrayNotHasKey('output', $row);
    }

    public function testUninstallNeedsConfirmationIsDestructiveAndOfferedOnce(): void
    {
        [$d, $tok] = $this->device('M6');
        $route = [(string) $d, 'mesh', 'uninstall'];
        [$c, $j] = $this->api('POST', $route, $this->tech);
        $this->assertSame([422, 'confirmation_required'], [$c, $j['code']]);
        $this->assertSame(422, $this->api('POST', $route, $this->tech, ['confirm' => true, 'force' => true])[0]);
        $this->assertSame(403, $this->api('POST', $route, $this->runner, ['confirm' => true])[0]);
        [$c, $j] = $this->api('POST', $route, $this->tech, ['confirm' => true]);
        $this->assertSame(202, $c, 'removal does not depend on the Mesh settings');
        $row = $this->jobRow($j['job_id']);
        $this->assertSame(['mesh_uninstall', 1, '{}'], [$row['type'], (int) $row['destructive'], (string) $row['params_json']]);
        $this->assertCount(1, $this->offered($tok));
        $this->assertCount(0, $this->offered($tok), 'a destructive job is never offered twice');
        [$old] = $this->device('OLD2', null, 'windows', false);
        $this->assertSame(409, $this->api('POST', [(string) $old, 'mesh', 'uninstall'], $this->tech, ['confirm' => true])[0]);
    }

    // ------------------------------------------------------------------ the bulk variant

    public function testBulkInstallRules(): void
    {
        $this->meshOn();
        $this->device('B1');
        $this->device('B2');
        $this->device('B3-old', null, 'windows', false);
        [, $t4] = $this->device('B4');
        $this->h->checkin($t4, ['mesh_agent' => ['installed' => true, 'node_id' => self::NODE]]);
        [$b1] = $this->device('OTHER', $this->h->clientB);
        $route = ['mesh', 'install'];
        $target = ['type' => 'client', 'id' => $this->h->clientA];
        $this->assertSame(403, $this->api('POST', $route, $this->viewer, ['target' => $target, 'confirm' => true])[0]);
        $this->assertSame(403, $this->api('POST', $route, $this->runner, ['target' => $target, 'confirm' => true])[0]);
        $this->assertSame(401, $this->h->tech('POST', $route, null, ['target' => $target])[0]);
        $this->assertSame(422, $this->api('POST', $route, $this->tech, ['target' => $target, 'confirm' => true, 'meshid' => 'x'])[0], 'only target, force, confirm');
        $this->assertSame(422, $this->api('POST', $route, $this->tech, ['target' => ['type' => 'bogus'], 'confirm' => true])[0]);
        [$c, $j] = $this->api('POST', $route, $this->tech, ['target' => $target]);
        $this->assertSame([422, 'confirmation_required', 4], [$c, $j['code'], $j['device_count']], 'a bulk install is confirmed explicitly');
        $this->assertSame(0, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE type='mesh_install'"));
        [$c, $j] = $this->api('POST', $route, $this->tech, ['target' => $target, 'confirm' => true]);
        $this->assertSame(201, $c, json_encode($j));
        $this->assertSame([4, 2, 1, 1, 0], [$j['device_count'], $j['created'], $j['skipped_installed'], $j['skipped_unsupported'], $j['skipped_denied']]);
        $this->assertSame(2, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE type='mesh_install'"));
        $this->assertSame(0, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE type='mesh_install' AND device_id=$b1"), 'the other client was not part of the target');
        // running it again changes nothing: the queued devices are skipped
        $j = $this->api('POST', $route, $this->tech, ['target' => $target, 'confirm' => true])[1];
        $this->assertSame([0, 2], [$j['created'], $j['skipped_queued']]);
        $this->assertSame(2, $this->audits('Mesh Agent Bulk Install'), 'each bulk request is audited once');
    }

    public function testBulkCapsAndTheApprovalThresholdFailClosed(): void
    {
        $this->meshOn();
        foreach (['C1', 'C2', 'C3'] as $n) {
            $this->device($n);
        }
        $route = ['mesh', 'install'];
        $body = ['target' => ['type' => 'client', 'id' => $this->h->clientA], 'confirm' => true];
        $this->h->module->settings()->update(['limits_json' => '{"bulk_run_max":2}']);
        [$c, $j] = $this->api('POST', $route, $this->tech, $body);
        $this->assertSame([422, 'too_many'], [$c, $j['code']]);
        $this->h->module->settings()->update(['limits_json' => '{"approval_bulk_threshold":2}']);
        [$c, $j] = $this->api('POST', $route, $this->tech, $body);
        $this->assertSame([409, 'approval_required'], [$c, $j['code']], 'above the approval threshold nothing is queued');
        $this->assertSame(0, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE type='mesh_install'"));
        $this->h->module->settings()->update(['limits_json' => '{"approval_bulk_threshold":3}']);
        $this->assertSame(201, $this->api('POST', $route, $this->tech, $body)[0]);
        // a Mesh that is off, or remote tools that are off, refuse the bulk request too
        $this->h->module->settings()->set(['mesh_enabled' => 0]);
        $this->assertSame(409, $this->api('POST', $route, $this->tech, $body)[0]);
    }

    public function testBulkNeverReachesAnotherClientsDevices(): void
    {
        $this->meshOn();
        [$a] = $this->device('SCOPE-A');
        [$b] = $this->device('SCOPE-B', $this->h->clientB);
        // the scoped technician (every ability, client B only) asks for client A, a device of A, and for everything
        [$c] = $this->api('POST', ['mesh', 'install'], $this->scoped, ['target' => ['type' => 'client', 'id' => $this->h->clientA], 'confirm' => true]);
        $this->assertSame(422, $c, 'no device of client A is inside this caller\'s clients');
        [$c] = $this->api('POST', ['mesh', 'install'], $this->scoped, ['target' => ['type' => 'device', 'id' => $a], 'confirm' => true]);
        $this->assertSame(422, $c);
        $this->assertSame(0, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE type='mesh_install'"));
        [$c, $j] = $this->api('POST', ['mesh', 'install'], $this->scoped, ['target' => ['type' => 'all'], 'confirm' => true]);
        $this->assertSame(201, $c);
        $this->assertSame(1, $j['created'], 'only client B\'s device');
        $this->assertSame(1, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE type='mesh_install' AND device_id=$b"));
        $this->assertSame(0, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE type='mesh_install' AND device_id=$a"));
    }

    // ------------------------------------------------------------------ client scoping of every new device route

    /** @return iterable<string,array{string,list<string>,mixed}> */
    public static function deviceRoutes(): iterable
    {
        yield 'mesh install' => ['POST', ['mesh', 'install'], []];
        yield 'mesh install forced' => ['POST', ['mesh', 'install'], ['force' => true]];
        yield 'mesh uninstall' => ['POST', ['mesh', 'uninstall'], ['confirm' => true]];
        yield 'mesh uninstall unconfirmed' => ['POST', ['mesh', 'uninstall'], []];
    }

    /** @param list<string> $tail */
    #[\PHPUnit\Framework\Attributes\DataProvider('deviceRoutes')]
    public function testEveryNewDeviceRouteIsTheSame404ForAnotherClientsDevice(string $method, array $tail, mixed $body): void
    {
        $this->meshOn();
        [$d] = $this->device('SCOPED-A');
        [$c, $j] = $this->api($method, [(string) $d, ...$tail], $this->scoped, $body);
        $this->assertSame([404, 'not_found'], [$c, $j['code'] ?? null], 'a technician limited to client B sees client A\'s device as not found');
        [$c2, $j2] = $this->api($method, ['987654', ...$tail], $this->scoped, $body);
        $this->assertSame([404, 'not_found'], [$c2, $j2['code'] ?? null]);
        $this->assertSame($j['error'], $j2['error'], 'no existence oracle in the text either');
        $this->assertSame(0, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE type IN ('mesh_install','mesh_uninstall')"));
    }

    public function testUnknownMeshRoutesAndMethodsAre404And405(): void
    {
        [$d] = $this->device('R1');
        $this->assertSame(404, $this->api('POST', [(string) $d, 'mesh', 'bogus'], $this->tech)[0]);
        $this->assertSame(404, $this->api('POST', [(string) $d, 'mesh'], $this->tech)[0]);
        $this->assertSame(405, $this->api('GET', [(string) $d, 'mesh', 'install'], $this->tech)[0]);
        $this->assertSame(404, $this->api('POST', ['mesh', 'bogus'], $this->tech)[0]);
        $this->assertSame(405, $this->api('GET', ['mesh', 'install'], $this->tech)[0]);
    }

    public function testTheDetailOfADeviceIsUnchangedWhileMeshIsNotConfigured(): void
    {
        [$d] = $this->device('D1');
        $detail = $this->api('GET', [(string) $d], $this->tech)[1];
        $this->assertArrayNotHasKey('mesh_install', $detail);
        $this->assertArrayNotHasKey('mesh_agent', $detail);
        $this->meshOn();
        $this->assertSame(['available' => true, 'agent_supports' => true, 'installed' => false], $this->api('GET', [(string) $d], $this->tech)[1]['mesh_install']);
    }
}
