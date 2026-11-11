<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use PHPUnit\Framework\Attributes\DataProvider;
use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Mesh\MeshCookie;
use RivetCore\Rmm\Migration\Migration0049BackgroundMode;
use RivetCore\Rmm\Remote\DbBackgroundModeConfig;
use RivetCore\Tests\Support\MockMeshServer;
use RivetCore\Tests\Support\RmmHarness;
use RivetCore\Tests\Support\RmmTestCase;
use RivetCore\Tests\Support\RoleMatrixPolicy;
use RivetCore\Tests\Support\StubBackgroundConfig;

/**
 * Background Mode end to end through TechnicianApi on the scratch database: the switch and its risk acknowledgement, the role matrix, exactly one audit row per outcome,
 * the Mesh launch against MockMeshServer, the session check (single use, revoked user, switch off) and the session end. Needs RIVETCORE_TEST_DB_NAME like every
 * test of this folder (skipped otherwise).
 */
final class BackgroundModeTest extends RmmTestCase
{
    /** @var array<string,RmmPrincipal> */
    private array $who = [];
    private int $D1 = 0;
    private int $D3 = 0;
    private ?MockMeshServer $mesh = null;

    protected function makeHarness(): RmmHarness
    {
        $p = new RoleMatrixPolicy();
        $p->addUser(1, 3, 3, 1, admin: true);
        $p->addUser(17, 3, 3, 2, background: true);     // technician, remote.manage, background grant, member of the group "Ops"
        $p->addUser(18, 3, 3, 2);                       // technician, remote.manage, no background grant
        $p->addUser(12, 1, 0, 0);                       // viewer
        $p->addUser(15, 0, 0, 0);                       // no RMM access
        $this->who = ['admin' => new RmmPrincipal(1, 'Admin'), 'bgtech' => new RmmPrincipal(17, 'BgTech'), 'nobg' => new RmmPrincipal(18, 'NoBg'),
            'viewer' => new RmmPrincipal(12, 'Viewer'), 'normo' => new RmmPrincipal(15, 'NoRmm')];

        return new RmmHarness(policy: $p, options: ['background_groups' => new StubBackgroundConfig('', '', [], ['Ops' => [17, 18]])]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->mesh = new MockMeshServer();
        $this->h->enable();
        $r = $this->h->module->admin()->saveMesh($this->who['admin'], ['mesh_enabled' => 1, 'mesh_url' => $this->mesh->url(), 'mesh_login_key' => MeshCookie::newLoginKey(), 'mesh_account_template' => 'rivetit-support']);
        $this->assertTrue($r->ok, $r->message);
        $tok = $this->h->token($this->h->clientA, 24, 50);
        $this->D1 = $this->mkdev($tok, 'BG-PC', true);
        $this->D3 = $this->mkdev($tok, 'BG-UNMAPPED', false);
        $cfg = new DbBackgroundModeConfig($this->h->module->sql());
        $this->assertTrue($cfg->setPolicyMode('internal', 1));
        $this->assertTrue($cfg->setInternalGroup('Ops', 1));
    }

    protected function tearDown(): void
    {
        $this->mesh?->stop();
        parent::tearDown();
    }

    private function mkdev(string $token, string $name, bool $withNode): int
    {
        $this->h->asset(['name' => $name, 'serial' => "SER-$name", 'client_id' => $this->h->clientA]);
        [$c, , $j] = $this->h->enroll($token, $this->h::device(['serial' => "SER-$name", 'hostname' => $name]));
        $this->assertSame(201, $c);
        $id = (int) $j['device_id'];
        if ($withNode) {
            $this->assertTrue($this->h->module->devices()->setMeshNode($id, 'node//' . str_repeat('A', 24) . $id, 1));
        }
        $this->h->checkin($j['device_token']);

        return $id;
    }

    /** Turn the switch on the supported way, with the acknowledgement. */
    private function switchOn(bool $remoteTools = true): void
    {
        $r = $this->h->module->remoteActions()->updateSettings($this->who['admin'], ['features' => ['remote_tools' => $remoteTools, 'background_mode' => true], 'accept_background_risk' => true]);
        $this->assertTrue($r->ok, $r->message);
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

    /** @return list<string> the Background Session audit titles written so far */
    private function bgRows(): array
    {
        return array_values(array_filter(array_column($this->h->audit->records(), 'action'), static fn (string $a): bool => str_starts_with($a, 'Background Session')));
    }

    /** @return array{0:int,1:mixed} */
    private function launchBg(string $who, ?int $device = null): array
    {
        return $this->api($who, 'POST', [(string) ($device ?? $this->D1), 'remote'], ['mode' => 'background', 'view' => 'console']);
    }

    public function testTheSwitchIsOffByDefaultAndEveryoneIsDenied(): void
    {
        $this->assertFalse($this->h->module->settings()->featureOn('background_mode'));
        [$c, $j] = $this->launchBg('admin');
        $this->assertSame([403, 'feature_disabled'], [$c, $j['code']]);
        $this->assertSame(['Background Session Denied'], $this->bgRows());
    }

    public function testTurningItOnNeedsTheRiskAcknowledgementAndAnAdministrator(): void
    {
        $ra = $this->h->module->remoteActions();
        $r = $ra->updateSettings($this->who['admin'], ['features' => ['background_mode' => true]]);
        $this->assertFalse($r->ok);
        $this->assertSame('confirmation_required', $r->code);
        $this->assertStringContainsString('without asking', $r->message);
        $this->assertFalse($this->h->module->settings()->featureOn('background_mode'));
        $this->assertSame(403, $ra->updateSettings($this->who['bgtech'], ['features' => ['background_mode' => true], 'accept_background_risk' => true])->http, 'not an administrator');
        $this->assertFalse($this->h->module->settings()->featureOn('background_mode'));
        $this->assertTrue($ra->updateSettings($this->who['admin'], ['features' => ['background_mode' => true], 'accept_background_risk' => true])->ok);
        $this->assertTrue($this->h->module->settings()->featureOn('background_mode'));
        $this->assertTrue($ra->updateSettings($this->who['admin'], ['features' => ['background_mode' => false]])->ok, 'turning it off needs no acknowledgement');
        $this->assertFalse($this->h->module->settings()->featureOn('background_mode'));
    }

    /** @return array<string,array{0:string,1:int,2:string}> */
    public static function roles(): array
    {
        return [
            'site admin' => ['admin', 200, 'Background Session Started'],
            'technician with the grant, in the group' => ['bgtech', 200, 'Background Session Started'],
            'technician without the grant' => ['nobg', 403, 'Background Session Denied'],
            'viewer' => ['viewer', 403, 'Background Session Denied'],
            'no RMM role' => ['normo', 403, 'Background Session Denied'],
        ];
    }

    #[DataProvider('roles')]
    public function testRoleMatrixWritesExactlyOneAuditRow(string $who, int $status, string $row): void
    {
        $this->switchOn();
        [$c, $j] = $this->launchBg($who);
        $this->assertSame($status, $c, json_encode($j));
        $this->assertSame([$row], $this->bgRows());
        $this->assertNotContains('Remote Denied', array_column($this->h->audit->records(), 'action'), 'a background denial is one Background row, not a second Remote row');
        $texts = implode("\n", array_column($this->h->audit->records(), 'description'));
        $this->assertStringContainsString('user ' . $this->who[$who]->userId . ' (' . $this->who[$who]->userName . ')', $texts, 'the technician is recorded');
        if ($status === 200) {
            $this->assertStringContainsString('login=', (string) $j['url']);
            $this->assertStringNotContainsString('login=', $texts, 'no Mesh token in the audit text');
            $this->assertStringNotContainsString((string) $j['check_token'], $texts);
            $this->assertSame('background', $j['mode']);
        }
    }

    public function testUnmappedDeviceIsAFailedRowAndNoSession(): void
    {
        $this->switchOn();
        [$c, $j] = $this->launchBg('admin', $this->D3);
        $this->assertSame([404, 'unmapped'], [$c, $j['code']]);
        $this->assertSame(['Background Session Failed'], $this->bgRows());
        $this->assertSame([], $this->h->bridge->sessions() ?? []);
    }

    public function testModeAndViewMustGoTogether(): void
    {
        $this->switchOn();
        foreach ([['mode' => 'background', 'view' => 'desktop'], ['mode' => 'background'], ['mode' => 'standard', 'view' => 'console'], ['view' => 'console'], ['mode' => 'stealth', 'view' => 'console']] as $body) {
            [$c, $j] = $this->api('admin', 'POST', [(string) $this->D1, 'remote'], $body);
            $this->assertSame([422, 'invalid'], [$c, $j['code']], json_encode($body));
        }
        $this->assertSame([], $this->bgRows());
    }

    public function testStandardLaunchIsUnchanged(): void
    {
        $this->switchOn();
        [$c, $j] = $this->api('admin', 'POST', [(string) $this->D1, 'remote'], []);
        $this->assertSame(200, $c);
        $this->assertArrayNotHasKey('check_token', $j);
        $this->assertContains('Remote Session', array_column($this->h->audit->records(), 'action'));
        $this->assertSame([], $this->bgRows());
    }

    public function testSessionCheckSingleUseThenReplayRefused(): void
    {
        $this->switchOn();
        [, $j] = $this->launchBg('bgtech');
        $check = fn (string $who, string $token): array => $this->api($who, 'POST', ['background', 'session-check'], ['token' => $token]);
        [$c, $r] = $check('bgtech', $j['check_token']);
        $this->assertSame([200, true], [$c, $r['allow']]);
        $this->assertSame($j['session_id'], $r['session_id']);
        [$c, $r] = $check('bgtech', $j['check_token']);
        $this->assertSame([403, false], [$c, $r['allow']], 'replay');
        $this->assertSame(['Background Session Started', 'Background Session Verified', 'Background Session Denied'], $this->bgRows());
        [$c] = $check('bgtech', str_repeat('0', 64));
        $this->assertSame(403, $c);
        [$c] = $this->h->tech('POST', ['background', 'session-check'], null, ['token' => $j['check_token']]);
        $this->assertSame(401, $c, 'the endpoint needs an authenticated caller');
        [$c] = $this->api('bgtech', 'GET', ['background', 'session-check']);
        $this->assertSame(405, $c);
    }

    public function testSessionCheckRefusesARevokedUserAndASwitchTurnedOff(): void
    {
        $this->switchOn();
        [, $a] = $this->launchBg('bgtech');
        [, $b] = $this->launchBg('bgtech');
        $this->h->policy instanceof RoleMatrixPolicy && $this->h->policy->deactivate(17);
        $this->h->module->authorizer()->forget();
        [$c, $r] = $this->api('admin', 'POST', ['background', 'session-check'], ['token' => $a['check_token']]);
        $this->assertSame([403, false], [$c, $r['allow']], 'revoked user');
        $this->h->policy instanceof RoleMatrixPolicy && $this->h->policy->activate(17);
        $this->h->module->authorizer()->forget();
        $this->assertTrue($this->h->module->remoteActions()->updateSettings($this->who['admin'], ['features' => ['background_mode' => false]])->ok);
        [$c, $r] = $this->api('bgtech', 'POST', ['background', 'session-check'], ['token' => $b['check_token']]);
        $this->assertSame([403, 'feature_disabled'], [$c, $r['code']]);
    }

    public function testSessionEndIsRecordedOnce(): void
    {
        $this->switchOn();
        [, $j] = $this->launchBg('bgtech');
        [$c] = $this->api('viewer', 'POST', ['background', 'session-end'], ['session_id' => $j['session_id']]);
        $this->assertSame(404, $c);
        [$c] = $this->api('bgtech', 'POST', ['background', 'session-end'], ['session_id' => $j['session_id'], 'reason' => 'user_closed']);
        $this->assertSame(200, $c);
        [$c] = $this->api('bgtech', 'POST', ['background', 'session-end'], ['session_id' => $j['session_id']]);
        $this->assertSame(409, $c);
        $this->assertSame(['Background Session Started', 'Background Session Ended'], $this->bgRows());
    }

    public function testMigration0049RunsAgainWithoutError(): void
    {
        $db = $this->h->module->sql()->database();
        (new Migration0049BackgroundMode())->up($db);
        (new Migration0049BackgroundMode())->up($db);
        $this->assertSame('internal', (new DbBackgroundModeConfig($this->h->module->sql()))->policyMode(), 'a second run keeps the stored settings');
    }

    public function testPerClientPolicyThroughTheDatabaseTables(): void
    {
        $this->switchOn();
        $cfg = new DbBackgroundModeConfig($this->h->module->sql());
        $this->assertTrue($cfg->setPolicyMode('per_client', 1));
        [$c, $j] = $this->launchBg('bgtech');
        $this->assertSame(403, $c, 'no policy stored for the client');
        $this->assertTrue($cfg->setClientPolicy($this->h->clientA, 'notify', 1));
        [$c, $j] = $this->launchBg('bgtech');
        $this->assertSame([200, 'notify'], [$c, $j['notice']]);
        $this->assertTrue($cfg->setClientPolicy($this->h->clientA, 'approve', 1));
        [$c, $j] = $this->launchBg('bgtech');
        $this->assertSame([403, 'approval_required'], [$c, $j['code']]);
    }
}
