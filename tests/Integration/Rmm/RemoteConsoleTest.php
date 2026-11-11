<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Mesh\MeshCookie;
use RivetCore\Tests\Support\MockMeshServer;
use RivetCore\Tests\Support\RmmHarness;
use RivetCore\Tests\Support\RmmTestCase;
use RivetCore\Tests\Support\RoleMatrixPolicy;

/**
 * The optional Rivet Console through launchRemote()/TechnicianApi on the scratch database and MockMeshServer: with the `remote_console` switch off the launch is
 * exactly the stock one; with it on the result adds a credential-free deep link and keeps the stock URL as the fallback. Needs RIVETCORE_TEST_DB_NAME (skipped otherwise).
 */
final class RemoteConsoleTest extends RmmTestCase
{
    private RmmPrincipal $admin;
    private RmmPrincipal $tech;
    private int $dev = 0;
    private ?MockMeshServer $mesh = null;

    protected function makeHarness(): RmmHarness
    {
        $p = new RoleMatrixPolicy();
        $p->addUser(1, 3, 3, 1, admin: true);
        $p->addUser(18, 3, 3, 2);   // technician with remote.launch and remote.manage, no Background grant

        return new RmmHarness(policy: $p);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = new RmmPrincipal(1, 'Admin');
        $this->tech = new RmmPrincipal(18, 'Tess');
        $this->mesh = new MockMeshServer();
        $this->h->enable();
        $r = $this->h->module->admin()->saveMesh($this->admin, ['mesh_enabled' => 1, 'mesh_url' => $this->mesh->url(), 'mesh_login_key' => MeshCookie::newLoginKey(), 'mesh_account_template' => 'rivetit-support']);
        $this->assertTrue($r->ok, $r->message);
        $this->h->asset(['name' => 'CON-PC', 'serial' => 'SER-CON', 'client_id' => $this->h->clientA]);
        [$c, , $j] = $this->h->enroll($this->h->token($this->h->clientA, 24, 50), $this->h::device(['serial' => 'SER-CON', 'hostname' => 'CON-PC']));
        $this->assertSame(201, $c);
        $this->dev = (int) $j['device_id'];
        $this->h->checkin($j['device_token']);
        $this->assertTrue($this->h->module->devices()->setMeshNode($this->dev, 'node//' . str_repeat('A', 24) . $this->dev, 1));
        $this->assertTrue($this->h->module->remoteActions()->updateSettings($this->admin, ['features' => ['remote_tools' => true]])->ok);
    }

    protected function tearDown(): void
    {
        $this->mesh?->stop();
        parent::tearDown();
    }

    private function console(bool $on): void
    {
        $this->assertTrue($this->h->module->remoteActions()->updateSettings($this->admin, ['features' => ['remote_console' => $on]])->ok);
    }

    /** @return list<string> */
    private function sessionRows(): array
    {
        return array_values(array_map(static fn (array $a): string => (string) $a['description'], array_filter($this->h->audit->records(), static fn (array $a): bool => $a['action'] === 'Remote Session')));
    }

    public function testTheSwitchIsOffByDefault(): void
    {
        $this->assertFalse($this->h->module->settings()->featureOn('remote_console'));
        $this->assertFalse($this->h->module->remoteActions()->settings($this->admin)->data['features']['remote_console']);
    }

    public function testOffLaunchIsExactlyTheStockResultEvenWhenTheConsoleIsAskedFor(): void
    {
        $t = $this->h->module->technician();
        $plain = $t->launchRemote($this->tech, $this->dev);
        $asked = $t->launchRemote($this->tech, $this->dev, false, null, null, 'desktop', 'standard', true, 'terminal-ps');
        foreach ([$plain, $asked] as $r) {
            $this->assertTrue($r->ok, $r->message);
            $this->assertSame(['url', 'session_id'], array_keys($r->data), 'no console key at all');
            $this->assertStringStartsWith($this->mesh->url() . '/?login=', $r->data['url'], 'the stock MeshCentral login-token URL');
            $this->assertStringContainsString('&viewmode=11', $r->data['url']);
            $this->assertStringNotContainsString('rivetconsole', json_encode($r->data));
        }
        $this->assertSame('Opening the remote session.', $plain->message);
        foreach ($this->sessionRows() as $row) {
            $this->assertStringNotContainsString('Rivet Console', $row, 'the audit text is the stock one');
        }
        [$c, $j] = $this->h->tech('POST', [(string) $this->dev, 'remote'], $this->tech, ['console' => true]);
        $this->assertSame([200, ['url', 'session_id']], [$c, array_keys($j)]);
    }

    public function testOnWithoutTheFlagIsAlsoUnchanged(): void
    {
        $this->console(true);
        $r = $this->h->module->technician()->launchRemote($this->tech, $this->dev);
        $this->assertSame(['url', 'session_id'], array_keys($r->data));
    }

    public function testOnReturnsACredentialFreeDeepLinkAndTheStockUrlAsFallback(): void
    {
        $this->console(true);
        $r = $this->h->module->technician()->launchRemote($this->tech, $this->dev, false, null, null, 'desktop', 'standard', true);
        $this->assertTrue($r->ok, $r->message);
        $c = $r->data['console'];
        $this->assertSame('desktop', $c['kind']);
        $this->assertSame($r->data['url'], $c['fallback_url']);
        $this->assertStringStartsWith($this->mesh->url() . '/?login=', $c['fallback_url']);
        $node = 'node//' . str_repeat('A', 24) . $this->dev;
        $this->assertSame('rivetconsole://connect?kind=desktop&node=' . rawurlencode($node), $c['url']);
        $login = (string) preg_replace('/^.*[?&]login=([^&]+).*$/', '$1', $r->data['url']);
        $this->assertNotSame('', $login);
        $this->assertStringNotContainsString($login, $c['url']);
        $this->assertStringNotContainsString('login', $c['url']);
        $this->assertStringContainsString('(Rivet Console link, desktop)', implode("\n", $this->sessionRows()));
    }

    public function testKindsAndTheViewRules(): void
    {
        $this->console(true);
        $t = $this->h->module->technician();
        $r = $t->launchRemote($this->tech, $this->dev, false, null, null, 'terminal', 'standard', true, 'terminal-ps');
        $this->assertSame('terminal-ps', $r->data['console']['kind']);
        $this->assertSame('files', $t->launchRemote($this->tech, $this->dev, false, null, null, 'files', 'standard', true)->data['console']['kind']);
        $bad = $t->launchRemote($this->tech, $this->dev, false, null, null, 'desktop', 'standard', true, 'terminal-ps');
        $this->assertSame([false, 422], [$bad->ok, $bad->http]);
        $this->assertSame(422, $t->launchRemote($this->tech, $this->dev, false, null, null, 'desktop', 'standard', true, 'vnc')->http);
    }

    public function testTheConsoleNeverBypassesTheAbilityChecks(): void
    {
        $this->console(true);
        $viewer = new RmmPrincipal(15, 'Nobody');
        $this->assertSame(403, $this->h->module->technician()->launchRemote($viewer, $this->dev, false, null, null, 'desktop', 'standard', true)->http);
    }

    public function testTheConsoleSwitchNeverTurnsBackgroundOnAndBackgroundIsDeniedWhileItIsOff(): void
    {
        $ra = $this->h->module->remoteActions();
        $this->console(true);
        $this->assertFalse($this->h->module->settings()->featureOn('background_mode'));
        $this->assertTrue($ra->updateSettings($this->admin, ['features' => ['background_mode' => true], 'accept_background_risk' => true])->ok);
        $this->console(false);
        $this->assertTrue($this->h->module->settings()->featureOn('background_mode'), 'turning the console off leaves the Background switch alone');
        $r = $this->h->module->technician()->launchRemote($this->admin, $this->dev, false, null, null, 'console', 'background');
        $this->assertSame([403, 'feature_disabled'], [$r->http, $r->code]);
        $rows = array_column($this->h->audit->records(), 'action');
        $this->assertSame(['Background Session Denied'], array_values(array_filter($rows, static fn (string $a): bool => str_starts_with($a, 'Background Session'))));
    }
}
