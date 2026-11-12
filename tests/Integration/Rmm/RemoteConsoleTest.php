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

    private function console(bool $on, bool $withUrl = true): void
    {
        $in = ['features' => ['remote_console' => $on]];
        if ($on && $withUrl) {
            $in['console_public_url'] = 'https://rmm.example.com';
        }
        $this->assertTrue($this->h->module->remoteActions()->updateSettings($this->admin, $in)->ok);
    }

    /** @return list<array<string,mixed>> */
    private function codeRows(): array
    {
        return $this->h->module->sql()->all('SELECT * FROM rmm_console_codes ORDER BY created_at, code_hash');
    }

    private function codeOf(string $link): string
    {
        parse_str((string) parse_url($link, PHP_URL_QUERY), $q);

        return (string) $q['code'];
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

    public function testOffNothingIsMintedAndNoCodeTableRowExists(): void
    {
        $t = $this->h->module->technician();
        $t->launchRemote($this->tech, $this->dev, false, null, null, 'desktop', 'standard', true);
        $this->assertSame([], $this->codeRows());
    }

    public function testOnReturnsTheV1DeepLinkWithACodeAndTheStockUrlAsFallback(): void
    {
        $this->console(true);
        $r = $this->h->module->technician()->launchRemote($this->tech, $this->dev, false, '203.0.113.9', null, 'desktop', 'standard', true);
        $this->assertTrue($r->ok, $r->message);
        $this->assertSame(['url', 'session_id', 'console'], array_keys($r->data));
        $c = $r->data['console'];
        $this->assertSame(['url', 'fallback_url'], array_keys($c), 'kind and node are not in the descriptor');
        $this->assertSame($r->data['url'], $c['fallback_url']);
        $this->assertStringStartsWith($this->mesh->url() . '/?login=', $c['fallback_url']);
        $this->assertMatchesRegularExpression('/^rivetconsole:\/\/connect\?v=1&origin=https%3A%2F%2Frmm\.example\.com&code=[A-Za-z0-9_-]{43}$/D', $c['url']);
        $code = $this->codeOf($c['url']);
        $login = (string) preg_replace('/^.*[?&]login=([^&]+).*$/', '$1', $r->data['url']);
        $this->assertStringNotContainsString($login, $c['url']);
        $this->assertStringNotContainsString('login', $c['url']);
        $rows = $this->codeRows();
        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame(hash('sha256', $code), $row['code_hash'], 'only the hash is stored');
        $this->assertSame([18, $this->dev, 'desktop', 'standard', $r->data['session_id'], 'node//' . str_repeat('A', 24) . $this->dev], [(int) $row['user_id'], (int) $row['device_id'], $row['kind'], $row['mode'], $row['session_id'], $row['node_id']]);
        $this->assertSame(60, strtotime((string) $row['expires_at'] . ' UTC') - strtotime((string) $row['created_at'] . ' UTC'));
        $this->assertNull($row['used_at']);
        $this->assertSame(hash('sha256', 'rivet-console-ip|203.0.113.9'), $row['ip_hash']);
        $this->assertStringNotContainsString('203.0.113.9', json_encode($row));
        $this->assertStringContainsString('(Rivet Console link, desktop)', implode("\n", $this->sessionRows()));
        $this->assertStringNotContainsString($code, json_encode($this->h->audit->records()));
        $this->assertStringNotContainsString($login, json_encode($this->h->audit->records()));
    }

    public function testEveryLaunchMintsItsOwnCode(): void
    {
        $this->console(true);
        $t = $this->h->module->technician();
        $a = $t->launchRemote($this->tech, $this->dev, false, null, null, 'desktop', 'standard', true)->data['console']['url'];
        $b = $t->launchRemote($this->tech, $this->dev, false, null, null, 'desktop', 'standard', true)->data['console']['url'];
        $this->assertNotSame($this->codeOf($a), $this->codeOf($b));
        $this->assertCount(2, $this->codeRows());
    }

    public function testWithoutAPublicUrlTheLaunchIsRefusedAuditedAndMintsNothing(): void
    {
        $this->console(true, false);
        $r = $this->h->module->technician()->launchRemote($this->tech, $this->dev, false, null, null, 'desktop', 'standard', true);
        $this->assertSame([false, 409, 'not_configured'], [$r->ok, $r->http, $r->code]);
        $this->assertSame([], $this->codeRows());
        $this->assertSame([], $this->sessionRows(), 'no Mesh session was opened');
        $actions = array_column($this->h->audit->records(), 'action');
        $this->assertContains('Remote Failed', $actions);
        [$c, $j] = $this->h->tech('POST', [(string) $this->dev, 'remote'], $this->tech, ['console' => true]);
        $this->assertSame([409, 'not_configured'], [$c, $j['code']]);
        // the stock launch (no console flag) still works with the console switch on and no URL
        $this->assertTrue($this->h->module->technician()->launchRemote($this->tech, $this->dev)->ok);
    }

    public function testADeniedLaunchMintsNothing(): void
    {
        $this->console(true);
        $nobody = new RmmPrincipal(15, 'Nobody');
        $this->assertSame(403, $this->h->module->technician()->launchRemote($nobody, $this->dev, false, null, null, 'desktop', 'standard', true)->http);
        $viewerLevel = new RmmPrincipal(12, 'Viewer');
        $this->assertFalse($this->h->module->technician()->launchRemote($viewerLevel, $this->dev, false, null, null, 'desktop', 'standard', true)->ok);
        $this->assertSame([], $this->codeRows());
    }

    public function testAnUnmappedOrOfflineDeviceMintsNothing(): void
    {
        $this->console(true);
        $this->assertTrue($this->h->module->devices()->setMeshNode($this->dev, '', 1));
        $r = $this->h->module->technician()->launchRemote($this->tech, $this->dev, false, null, null, 'desktop', 'standard', true);
        $this->assertFalse($r->ok);
        $this->assertSame([], $this->codeRows());
    }

    public function testKindsAndTheViewRules(): void
    {
        $this->console(true);
        $t = $this->h->module->technician();
        $kinds = [];
        $r = $t->launchRemote($this->tech, $this->dev, false, null, null, 'terminal', 'standard', true, 'terminal-ps');
        $this->assertTrue($r->ok, $r->message);
        $kinds[] = 'terminal-ps';
        $this->assertTrue($t->launchRemote($this->tech, $this->dev, false, null, null, 'files', 'standard', true)->ok);
        $kinds[] = 'files';
        $this->assertTrue($t->launchRemote($this->tech, $this->dev, false, null, null, 'terminal', 'standard', true)->ok);
        $kinds[] = 'terminal';
        $got = array_column($this->codeRows(), 'kind');
        sort($got);
        sort($kinds);
        $this->assertSame($kinds, $got);
        $before = count($this->codeRows());
        $bad = $t->launchRemote($this->tech, $this->dev, false, null, null, 'desktop', 'standard', true, 'terminal-ps');
        $this->assertSame([false, 422], [$bad->ok, $bad->http]);
        $this->assertSame(422, $t->launchRemote($this->tech, $this->dev, false, null, null, 'desktop', 'standard', true, 'vnc')->http);
        $this->assertSame(422, $t->launchRemote($this->tech, $this->dev, false, null, null, 'desktop', 'standard', true, 'background')->http);
        $this->assertSame($before, count($this->codeRows()));
    }

    public function testBackgroundAndChatKindsAreNeverIssued(): void
    {
        $this->console(true);
        $t = $this->h->module->technician();
        foreach (['background', 'chat', 'console'] as $view) {
            $r = $t->launchRemote($this->admin, $this->dev, false, null, null, $view, 'standard', true);
            $this->assertFalse($r->ok, $view);
        }
        $this->assertFalse($t->launchRemote($this->admin, $this->dev, false, null, null, 'console', 'background', true)->ok, 'a Background launch (switch off) is denied');
        $this->assertSame([], $this->codeRows());
        $this->assertSame([], array_values(array_filter(array_column($this->codeRows(), 'mode'), static fn ($m) => $m !== 'standard')));
    }

    public function testASwitchedOffConsoleLaunchIsByteIdenticalToTheStockOne(): void
    {
        // an install that never touched the console: same keys, same audit text, no row anywhere
        $t = $this->h->module->technician();
        $r = $t->launchRemote($this->tech, $this->dev, false, '203.0.113.9', 'UA', 'desktop', 'standard', true, 'terminal-ps');
        $this->assertSame(['url', 'session_id'], array_keys($r->data));
        $this->assertSame([], $this->codeRows());
        $all = $this->h->audit->records();
        $last = end($all);
        $this->assertSame('Remote Session', $last['action']);
        $this->assertSame("Tess opened MeshCentral session {$r->data['session_id']} on device {$this->dev}", $last['description']);
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
