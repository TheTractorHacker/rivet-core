<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Installer\InstallerStamp;
use RivetCore\Rmm\Mesh\MeshAgentCatalog;
use RivetCore\Rmm\RmmEvent;
use RivetCore\Testing\InMemoryRmmEvents;
use RivetCore\Tests\Support\MockTlsServer;
use RivetCore\Tests\Support\RemoteTestCase;
use RivetCore\Tests\Support\RmmHarness;

/**
 * The optional MeshCentral agent: the installer option and its payload, the Mesh settings, what a device reports at check-in, the automatic node-id
 * mapping (no overwrite of a manual one, conflicts refused, retired devices released), the events, the audit rows and the technician API. Everything here
 * runs against mocks: there is no real MeshCentral server behind it (see docs/rmm/REMOTE_AND_LOGS.md).
 */
final class MeshAgentTest extends RemoteTestCase
{
    private const GROUP = '4a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f9';
    /** The URL form of GROUP: what MeshCentral prints in its download address and what the installer now carries. */
    private const GROUP_URL = 'ShssPU5fYHGCk6S1xtfo@QobLD1OX2BxgpOktcbX6PkKGyw9Tl9gcYKTpLXG1@j5';
    private const NODE_A = 'node//AbCdEfGhIjKlMnOpQrStUvWxYz0123456789@$AbCdEfGhIjKlMnOpQrStUvWxYz01';
    private const NODE_B = 'node//ZyXwVuTsRqPoNmLkJiHgFeDcBa9876543210@$ZyXwVuTsRqPoNmLkJiHgFeDcBa98';
    private InMemoryRmmEvents $bus;

    protected function makeHarness(): RmmHarness
    {
        $this->transferDir = sys_get_temp_dir() . '/rmm_xfer_' . bin2hex(random_bytes(4));
        $this->bus = new InMemoryRmmEvents();
        $tech = [\RivetCore\Rmm\Authz\RmmAbility::DEVICE_VIEW, \RivetCore\Rmm\Authz\RmmAbility::JOB_RUN_SAVED, \RivetCore\Rmm\Authz\RmmAbility::REMOTE_LAUNCH, \RivetCore\Rmm\Authz\RmmAbility::REMOTE_MANAGE];

        return new RmmHarness(null, null, ['allow_linux' => true, 'transfer_dir' => $this->transferDir], false, null,
            new \RivetCore\Tests\Support\AllowUsersPolicy([1 => true, 10 => $tech, 11 => [\RivetCore\Rmm\Authz\RmmAbility::DEVICE_VIEW], 12 => [\RivetCore\Rmm\Authz\RmmAbility::DEVICE_VIEW], 16 => true]),
            new \RivetCore\Webhooks\UrlPolicy(true), $this->bus);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->h->module->settings()->set(['service_url' => 'https://rmm.example.test']);
        $this->h->publishBinary('1.0.0', 'amd64', 8192, true, null);
        $this->h->publishBinary('1.0.0', 'arm64', 8192, true, null);
    }

    private function meshOn(array $extra = []): void
    {
        $this->h->module->settings()->set(['mesh_enabled' => 1, 'mesh_url' => 'https://mesh.example.com']);
        $this->assertSame([], $this->h->module->meshAgentSettings()->update(['mesh_group_id' => self::GROUP] + $extra, 1));
    }

    private function audit(string $action): int
    {
        return count(array_filter($this->h->audit->records(), static fn (array $r): bool => $r['action'] === $action));
    }

    /** @return array<string,mixed> the stamp payload of a token-gated download */
    private function download(string $token, string $arch = 'amd64'): array
    {
        [$c, , $j, $r] = $this->h->call('POST', 'agent_installer', ['token' => $token, 'arch' => $arch]);
        $this->assertSame(200, $c, json_encode($j));
        $parsed = InstallerStamp::read((string) file_get_contents($r->file->path) . (string) $r->file->trailer);
        $this->assertNotNull($parsed);

        return $parsed['data'];
    }

    /** @return array<string,mixed> */
    private function meshReport(string $token, mixed $mesh): array
    {
        [$c, , $j] = $this->h->checkin($token, ['mesh_agent' => $mesh]);
        $this->assertSame(200, $c, json_encode($j));

        return $j;
    }

    /** What an administrator does for a claim that needs approval: map the node on the device page (a manual mapping). */
    private function approve(int $dev, string $node): void
    {
        $this->assertTrue($this->h->module->devices()->setMeshNode($dev, $node, 1));
    }

    private function nodeOf(int $dev): ?string
    {
        $n = $this->h->one("SELECT mesh_node_id FROM endpoint_agent_mesh_nodes WHERE device_id=$dev");

        return $n === null ? null : (string) $n;
    }

    // ------------------------------------------------------------------ the installer option

    public function testTheOptionIsOffByDefaultAndTheOldPayloadIsUnchanged(): void
    {
        $this->meshOn();
        $r = $this->h->module->installerService()->issue($this->h->clientA, 0, 'stable', 24, 5, 'plain', 'amd64', 1, 'Admin');
        $this->assertTrue($r['ok']);
        $d = json_decode((string) $r['payload'], true);
        $this->assertSame(['version', 'installer_id', 'server_url', 'enrollment_token', 'department', 'ca_pem', 'created_at', 'expires_at'], array_keys($d), 'exactly the members of every earlier release');
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_installer_options'));
        $this->assertArrayNotHasKey('install_mesh_agent', $this->download($r['token_plain']));
    }

    public function testTheNewTickBoxCarriesNothingOfTheMeshSettingsInAnyInstaller(): void
    {
        $this->meshOn(['mesh_server_cert_pin' => hash('sha384', 'server cert')]);
        $r = $this->h->module->installerService()->issue($this->h->clientA, 0, 'stable', 24, 5, 'mesh', 'amd64', 1, 'Admin', true);
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertTrue($r['mesh_after_approval']);
        $this->assertSame(2, (int) $this->h->one('SELECT install_mesh_agent FROM endpoint_agent_installer_options'), 'after-approval is option value 2');
        foreach ([json_decode((string) $r['payload'], true), $this->download($r['token_plain'])] as $d) {
            $this->assertArrayNotHasKey('install_mesh_agent', $d);
            $this->assertArrayNotHasKey('mesh', $d);
            $this->assertStringNotContainsString(self::GROUP_URL, json_encode($d));
            $this->assertStringNotContainsString('mesh.example.com', json_encode($d));
        }
        $cmds = $this->h->module->installerService()->deploymentCommands(['token' => $r['token'], 'token_plain' => $r['token_plain'], 'department' => $r['department']], 'amd64', true);
        $this->assertTrue($cmds['mesh_after_approval']);
        foreach ([$cmds['linux'], $cmds['powershell']] as $c) {
            $this->assertStringNotContainsString('--install-mesh-agent', $c);
            $this->assertStringNotContainsString('--mesh-group-id', $c);
            $this->assertStringNotContainsString(self::GROUP_URL, $c);
            $this->assertStringNotContainsString('mesh.example.com', $c);
        }
        $this->assertStringContainsString('installs the MeshCentral agent after the device is approved', json_encode($this->h->audit->records()));
    }

    public function testALegacyTokenKeepsTheInstallTimeMeshSourceInTheInteractiveAndTheTokenGatedInstaller(): void
    {
        $pin = hash('sha384', 'server cert');
        $this->meshOn(['mesh_server_cert_pin' => strtoupper($pin), 'mesh_agent_ids' => ['windows/arm64' => 40], 'mesh_install_args' => ['linux' => ['-install', '--installPath=/opt/mesh']]]);
        $r = $this->h->module->installerService()->issue($this->h->clientA, 0, 'stable', 24, 5, 'mesh', 'amd64', 1, 'Admin', true);
        $this->assertTrue($r['ok'], json_encode($r));
        // A token issued BEFORE rc.27 stored option value 1 ("install at install time"); the new tick-box stores 2. Simulate the old token.
        $this->h->mysqli->query('UPDATE endpoint_agent_installer_options SET install_mesh_agent = 1');
        $r['payload'] = $this->h->module->installerDownload()->payloadFor($this->h->rows('SELECT * FROM endpoint_agent_enrollment_tokens WHERE token_id=' . (int) $r['token']['token_id'])[0], $r['token_plain'], (string) $r['installer_id'])[0];
        $payloads = [json_decode((string) $r['payload'], true), $this->download($r['token_plain'])];
        foreach ($payloads as $d) {
            $this->assertSame(1, $d['version'], 'the payload version stays 1');
            $this->assertTrue($d['install_mesh_agent']);
            $m = $d['mesh'];
            $this->assertSame('https://mesh.example.com', $m['url']);
            $this->assertSame(self::GROUP_URL, $m['meshid'], 'the payload carries the URL form, derived from the stored hex');
            $this->assertSame($pin, $m['pin'], 'stored normalised');
            $this->assertSame(40, $m['agent_ids']['windows/arm64']);
            $this->assertSame(4, $m['agent_ids']['windows/amd64'], 'the built-in ids fill the rest');
            $this->assertSame(['-install', '--installPath=/opt/mesh'], $m['install_args']['linux']);
            $this->assertSame(['-fullinstall'], $m['install_args']['windows']);
            $this->assertArrayNotHasKey('mesh_login_key', $m);
            $this->assertLessThan(InstallerStamp::MAX_PAYLOAD, strlen(json_encode($d)));
        }
        $this->assertStringContainsString('installs the MeshCentral agent after the device is approved', json_encode($this->h->audit->records()));
        // a token issued WITHOUT the box stays without it, whatever the settings say
        $plain = $this->h->module->installerService()->issue($this->h->clientA, 0, 'stable', 24, 5, 'plain', 'amd64', 1, 'Admin');
        $this->assertArrayNotHasKey('mesh', $this->download($plain['token_plain']));
    }

    public function testTheBoxIsRefusedUpFrontWhenMeshCannotSupportItAndNothingIsCreated(): void
    {
        $count = fn (): int => (int) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_enrollment_tokens');
        $svc = $this->h->module->installerService();
        $try = fn (): array => $svc->issue($this->h->clientA, 0, 'stable', 24, 5, 'x', 'amd64', 1, 'Admin', true);
        $r = $try();
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('not switched on', $r['error']);
        $this->h->module->settings()->set(['mesh_enabled' => 1]);
        $this->assertStringContainsString('https://', $try()['error']);
        $this->h->module->settings()->set(['mesh_url' => 'http://mesh.example.com']);
        $this->assertStringContainsString('https://', $try()['error'], 'plain http is never an install source');
        $this->h->module->settings()->set(['mesh_url' => 'https://mesh.example.com']);
        $this->assertStringContainsString('device group id', $try()['error']);
        $this->assertSame(0, $count(), 'no token was created by a refused request');
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_installer_options'));
    }

    public function testASettingThatChangesAfterIssuingServesTheInstallerWithoutMeshAndAuditsIt(): void
    {
        $this->meshOn();
        $r = $this->h->module->installerService()->issue($this->h->clientA, 0, 'stable', 24, 5, 'm', 'amd64', 1, 'Admin', true);
        $this->h->mysqli->query('UPDATE endpoint_agent_installer_options SET install_mesh_agent = 1');   // a token issued before rc.27
        $this->h->module->settings()->set(['mesh_enabled' => 0]);
        $d = $this->download($r['token_plain']);
        $this->assertArrayNotHasKey('install_mesh_agent', $d, 'a download never fails over the optional feature');
        $this->assertSame('Dept A', $d['department']);
        $this->assertSame(1, $this->audit('Installer Mesh Skipped'));
    }

    public function testTheDeploymentCommandsNoLongerCarryTheMeshFlagsButTheManualLinuxHelperStillBuildsThem(): void
    {
        $this->meshOn(['mesh_server_cert_pin' => hash('sha384', 'c')]);
        $a = $this->h->module->admin();
        $with = $a->deploymentCommands($this->admin, $this->h->clientA, 0, 'stable', 24, 5, 'w', 'amd64', true);
        $without = $a->deploymentCommands($this->admin, $this->h->clientA, 0, 'stable', 24, 5, 'w', 'amd64');
        $this->assertTrue($with->ok, $with->message);
        $this->assertTrue($with->data['mesh_after_approval']);
        $this->assertTrue($with->data['commands']['mesh_after_approval']);
        $this->assertFalse($without->data['commands']['mesh_after_approval']);
        $this->assertStringNotContainsString('mesh', strtolower($with->data['commands']['linux']), 'no Mesh address, group id, pin or flag any more');
        $this->assertStringNotContainsString('mesh', strtolower($without->data['commands']['linux']));
        $this->assertStringNotContainsString('mesh', strtolower($with->data['commands']['powershell']));
        // the static builder keeps working for manual use (install-linux.sh flags are unchanged)
        $block = $this->h->module->meshAgents()->installBlock();
        $lx = \RivetCore\Rmm\Installer\InstallerService::linuxSnippet('https://rmm.example.test', 'tok', 'amd64', 'Dept A', null, $block);
        $this->assertStringContainsString("--install-mesh-agent --mesh-url 'https://mesh.example.com' --mesh-group-id '" . self::GROUP_URL . "' --mesh-cert-pin '" . hash('sha384', 'c') . "'", $lx);
    }

    public function testTheAdminDownloadIsAStampedInstallerThatCarriesNothingOfMesh(): void
    {
        $this->meshOn();
        $r = $this->h->module->admin()->downloadInstaller($this->admin, $this->h->clientA, 0, 'stable', 24, 5, 'd', 'amd64', true);
        $this->assertTrue($r->ok, $r->message);
        $file = $r->data['download']->file;
        $p = InstallerStamp::read((string) file_get_contents($file->path) . (string) $file->trailer);
        $this->assertNotNull($p);
        $this->assertArrayNotHasKey('install_mesh_agent', $p['data'], 'the Mesh agent installs after approval, not from the installer');
        $this->assertArrayNotHasKey('mesh', $p['data']);
        $this->assertTrue($r->data['mesh_after_approval']);
        $this->assertFalse($this->h->module->admin()->downloadInstaller($this->tech, $this->h->clientA, 0, 'stable', 24, 5, 'd', 'amd64', true)->ok, 'token management is an admin ability');
    }

    // ------------------------------------------------------------------ settings

    public function testSettingsAreValidatedAndNothingIsWrittenOnAnyError(): void
    {
        $s = $this->h->module->meshAgentSettings();
        $this->assertSame([], $s->update(['mesh_group_id' => strtoupper(self::GROUP), 'mesh_auto_map_overwrite' => true], 1));
        $this->assertSame(self::GROUP, $s->get()['group_id']);
        $this->assertTrue($s->get()['auto_map_overwrite']);
        $bad = [['mesh_group_id' => 'zz'], ['mesh_group_id' => str_repeat('a', 31)], ['mesh_group_id' => ['x']], ['mesh_server_cert_pin' => 'abc'], ['mesh_server_cert_pin' => str_repeat('g', 96)],
            ['mesh_auto_map_overwrite' => 'yes'], ['mesh_auto_map_overwrite' => 1], ['mesh_agent_ids' => ['windows/amd64' => 999]], ['mesh_agent_ids' => 'x'], ['mesh_install_args' => ['linux' => ['-x; id']]], ['bogus' => 1]];
        foreach ($bad as $in) {
            $before = $s->get();
            $this->assertNotSame([], $s->update($in, 1), json_encode($in));
            $this->assertSame($before, $s->get(), 'unchanged after ' . json_encode($in));
        }
        $before = $s->get();
        $this->assertNotSame([], $s->update(['mesh_server_cert_pin' => hash('sha384', 'ok'), 'mesh_agent_ids' => ['nope' => 1]], 1));
        $this->assertSame($before, $s->get(), 'one bad value rejects the whole request');
        $this->assertSame([], $s->update(['mesh_group_id' => ''], 1), 'empty clears');
        $this->assertSame('', $s->get()['group_id']);
        // MeshCentral's own mesh// form is converted
        $raw = implode('', array_map('chr', range(0, 47)));
        $this->assertSame([], $s->update(['mesh_group_id' => 'mesh//' . strtr(base64_encode($raw), ['+' => '@', '/' => '$'])], 1));
        $this->assertSame(bin2hex($raw), $s->get()['group_id']);
    }

    public function testThePastedDownloadAddressAndTheBareURLFormAreSavedAndCarriedVerbatim(): void
    {
        $fresh = 'e10df2d66e829b53c567652d4d91add5c62f457fe0d70c32f9d2012df54eb7aad9e0cab782a7c99b0992317e235d849e';
        $url = '4Q3y1m6Cm1PFZ2UtTZGt1cYvRX$g1wwy@dIBLfVOt6rZ4Mq3gqfJmwmSMX4jXYSe';
        $this->meshOn();
        $s = $this->h->module->meshAgentSettings();
        $addr = "\n  https://mesh.example.com/meshagents?id=4&meshid=$url&installflags=0  \n";
        $this->assertSame([], $s->update(['mesh_group_id' => $addr], 1));
        $this->assertSame([], $s->lastWarnings());
        $g = $s->get();
        $this->assertSame([$fresh, $url], [$g['group_id'], $g['group_id_url']]);
        $this->assertSame($url, $this->h->module->meshAgents()->installBlock()['meshid'] ?? null, 'the block carries what MeshCentral printed');
        $stored = $this->h->module->settings()->get();   // sanity: the Mesh address itself is untouched
        $this->assertSame('https://mesh.example.com', $stored['mesh_url']);
        // the bare form, the mesh// form and the hex give the same canonical id and the same wire form
        foreach ([$url, 'mesh//' . $url, $fresh, strtoupper($fresh)] as $in) {
            $this->assertSame([], $s->update(['mesh_group_id' => ''], 1));
            $this->assertSame([], $s->update(['mesh_group_id' => $in], 1), $in);
            $this->assertSame([$fresh, $url], [$s->get()['group_id'], $s->get()['group_id_url']], $in);
            $this->assertSame($url, $this->h->module->meshAgents()->installBlock()['meshid'] ?? null, $in);
        }
        // an address for another host is saved, with a warning
        $this->assertSame([], $s->update(['mesh_group_id' => "https://desktop.example.org/meshagents?id=4&meshid=$url&installflags=0"], 1));
        $this->assertCount(1, $s->lastWarnings());
        $this->assertStringContainsString('desktop.example.org', $s->lastWarnings()[0]);
        $this->assertSame($fresh, $s->get()['group_id']);
        // hostile values are refused with the help text and change nothing
        foreach (["$url\"", "$url'", "$url x", "$url&installflags=0", "$url#", 'a b' . substr($url, 3), substr($url, 1), "https://mesh.example.com/meshagents?id=4&meshid=" . substr($url, 1)] as $bad) {
            $before = $s->get();
            $err = $s->update(['mesh_group_id' => $bad], 1);
            $this->assertSame([\RivetCore\Rmm\Mesh\MeshAgentSettings::GROUP_HELP_ERROR], $err, json_encode($bad));
            $this->assertSame($before, $s->get());
        }
        // the pin message tells the Let's Encrypt story
        $err = $s->update(['mesh_server_cert_pin' => 'abc'], 1);
        $this->assertStringContainsString('public CA', $err[0]);
        $this->assertStringContainsString("Let's Encrypt", $err[0]);
    }

    public function testASettingsRowFromBeforeTheURLFormColumnStillWorks(): void
    {
        // a row saved by rc.14 (hex only, group_id_url empty) and a database where migration 0024 is not applied yet
        $this->meshOn();
        $this->h->module->meshAgentSettings()->update(['mesh_group_id' => 'x'], 1);
        $this->assertSame([], $this->h->module->meshAgentSettings()->update(['mesh_group_id' => self::GROUP], 1));
        $this->h->db->execute("UPDATE endpoint_agent_mesh_settings SET group_id_url = '' WHERE id = 1");
        $this->h->module->meshAgentSettings()->refresh();
        $this->assertSame(self::GROUP_URL, $this->h->module->meshAgents()->installBlock()['meshid'] ?? null);
        $this->h->db->execute('ALTER TABLE endpoint_agent_mesh_settings DROP COLUMN group_id_url');
        $this->h->module->meshAgentSettings()->refresh();
        $this->assertSame([self::GROUP, self::GROUP_URL], [$this->h->module->meshAgentSettings()->get()['group_id'], $this->h->module->meshAgentSettings()->get()['group_id_url']]);
        $this->assertSame([], $this->h->module->meshAgentSettings()->update(['mesh_group_id' => 'mesh//' . self::GROUP_URL, 'mesh_auto_map_overwrite' => false], 1), 'saving works without the column');
        $this->assertSame(self::GROUP_URL, $this->h->module->meshAgents()->installBlock()['meshid'] ?? null);
    }

    public function testSettingsRoutesAreAdminOnlyAndRoundTrip(): void
    {
        $this->meshOn();
        $this->assertSame(403, $this->api('GET', ['remote', 'mesh'], $this->tech)[0]);
        $this->assertSame(403, $this->api('PUT', ['remote', 'mesh'], $this->tech, ['mesh_group_id' => str_repeat('ab', 40)])[0]);
        $this->assertSame(401, $this->h->tech('GET', ['remote', 'mesh'], null)[0]);
        $this->assertSame(self::GROUP, $this->h->module->meshAgentSettings()->get()['group_id'], 'a refused request changes nothing');
        [$c, $j] = $this->api('GET', ['remote', 'mesh'], $this->admin);
        $this->assertSame(200, $c);
        $this->assertSame([true, 'https://mesh.example.com', self::GROUP, true, null], [$j['mesh_enabled'], $j['mesh_url'], $j['mesh_group_id'], $j['install_ready'], $j['install_refusal']]);
        $this->assertSame(MeshAgentCatalog::DEFAULT_AGENT_IDS, $j['default_agent_ids']);
        $this->assertArrayNotHasKey('mesh_login_key_enc', $j);
        [$c, $j] = $this->api('PUT', ['remote', 'mesh'], $this->admin, ['mesh_auto_map_overwrite' => true, 'mesh_agent_ids' => ['linux/amd64' => 8]]);
        $this->assertSame(200, $c, json_encode($j));
        $this->assertTrue($j['mesh_auto_map_overwrite']);
        $this->assertSame(8, $j['effective_agent_ids']['linux/amd64']);
        [$c, $j] = $this->api('PUT', ['remote', 'mesh'], $this->admin, ['mesh_server_cert_pin' => 'nope']);
        $this->assertSame([422, 'invalid'], [$c, $j['code']]);
        $this->assertSame(422, $this->api('PUT', ['remote', 'mesh'], $this->admin, ['mesh_url' => 'https://other.example.com'])[0], 'the address is edited on the MeshCentral page, not here');
        $this->assertSame(405, $this->api('DELETE', ['remote', 'mesh'], $this->admin)[0]);
        $this->assertSame(1, $this->audit('Remote Settings Denied') >= 1 ? 1 : 0);
    }

    public function testSaveMeshAcceptsTheInstallSettingsTogetherWithTheRest(): void
    {
        $a = $this->h->module->admin();
        $r = $a->saveMesh($this->admin, ['mesh_enabled' => 1, 'mesh_url' => 'https://mesh.example.com', 'mesh_group_id' => self::GROUP, 'mesh_auto_map_overwrite' => true]);
        $this->assertTrue($r->ok, $r->message);
        $this->assertSame(self::GROUP, $this->h->module->meshAgentSettings()->get()['group_id']);
        $bad = $a->saveMesh($this->admin, ['mesh_enabled' => 0, 'mesh_url' => 'https://mesh.example.com', 'mesh_group_id' => 'nothex']);
        $this->assertFalse($bad->ok);
        $this->assertSame(1, (int) $this->h->module->settings()->get()['mesh_enabled'], 'nothing is saved when a Mesh install setting is invalid');
        $this->assertFalse($a->saveMesh($this->tech, ['mesh_group_id' => self::GROUP])->ok);
    }

    public function testThePinCanBeProbedFromTheServerWithoutBeingSaved(): void
    {
        $tls = new MockTlsServer();
        $this->h->module->settings()->set(['mesh_enabled' => 1, 'mesh_url' => $tls->url()]);
        [$c, $j] = $this->api('PUT', ['remote', 'mesh'], $this->admin, ['probe_pin' => true]);
        $this->assertSame(200, $c, json_encode($j));
        $this->assertTrue($j['pin_probe']['ok'], json_encode($j['pin_probe']));
        $this->assertSame($tls->sha384, $j['pin_probe']['pin']);
        $this->assertSame($tls->sha256, $j['pin_probe']['sha256']);
        $this->assertSame('mesh.test.invalid', $j['pin_probe']['subject']);
        $this->assertFalse($j['pin_probe']['publicly_trusted'], 'a self-signed certificate is not trusted by the system roots');
        $this->assertStringContainsString('not trusted', $j['pin_probe']['note']);
        $this->assertSame('', $this->h->module->meshAgentSettings()->get()['server_cert_pin'], 'proposed, never saved by the probe');
        $r = $this->h->module->admin()->saveMesh($this->admin, ['mesh_enabled' => 1, 'mesh_url' => $tls->url(), 'mesh_probe_pin' => true]);
        $this->assertSame($tls->sha384, $r->data['pin_probe']['pin']);
        $tls->stop();
        $this->assertFalse($this->h->module->mesh()->probePin($tls->url())['ok'], 'an unreachable server is an error text, not an exception');
        $this->assertFalse($this->h->module->mesh()->probePin('http://mesh.example.com')['ok'], 'TLS only');
    }

    public function testThePinProbeObeysTheNetworkPolicy(): void
    {
        $strict = new RmmHarness(policy: $this->h->policy, urlPolicy: new \RivetCore\Webhooks\UrlPolicy(false));
        $p = $strict->module->mesh()->probePin('https://127.0.0.1:9');
        $this->assertFalse($p['ok']);
        $this->assertStringContainsString('network policy', $p['error']);
        $this->assertFalse($strict->module->mesh()->probePin('https://169.254.169.254')['ok']);
    }

    // ------------------------------------------------------------------ the device's report

    public function testAReportRecordsTheStateMapsTheNodeAuditsAndRaisesTheEventOnce(): void
    {
        $this->meshOn();
        [$dev, $token] = $this->linked('M1');
        $this->meshReport($token, ['installed' => true, 'version' => '1.1.10', 'node_id' => self::NODE_A]);
        $this->assertNull($this->nodeOf($dev), 'a device saying which node it is does not map it (rc.31 review F1)');
        $this->assertSame(1, $this->audit('Mesh Node Needs Approval'));
        $this->approve($dev, self::NODE_A);
        $this->meshReport($token, ['installed' => true, 'version' => '1.1.10', 'node_id' => self::NODE_A]);
        $this->assertSame(self::NODE_A, $this->nodeOf($dev));
        $this->assertSame('manual', $this->h->one("SELECT source FROM endpoint_agent_mesh_nodes WHERE device_id=$dev"));
        $this->assertSame(1, $this->audit('Mesh Agent Installed'));
        $ev = $this->bus->of(RmmEvent::MESH_AGENT_INSTALLED);
        $this->assertCount(1, $ev);
        $this->assertSame([$dev, '1.1.10', self::NODE_A], [$ev[0]['device_id'], $ev[0]['version'], $ev[0]['node_id']]);
        $this->assertSame(self::NODE_A, $this->h->module->mesh()->nodeFor($dev), 'the launch path sees the mapping');
        // repeated, identical reports change nothing and say nothing
        $this->meshReport($token, ['installed' => true, 'version' => '1.1.10', 'node_id' => self::NODE_A]);
        $this->assertSame([1, 1, 1], [$this->audit('Mesh Node Needs Approval'), $this->audit('Mesh Agent Installed'), count($this->bus->of(RmmEvent::MESH_AGENT_INSTALLED))]);
        // the technician API shows the state, additively
        [$c, $j] = $this->api('GET', [(string) $dev], $this->tech);
        $this->assertSame(200, $c);
        $m = $j['mesh_agent'];
        $this->assertSame([true, '1.1.10', self::NODE_A, null, 'mapped', null], [$m['installed'], $m['version'], $m['node_id'], $m['error'], $m['map_state'], $m['conflict_device_id']]);
        $this->assertNotNull($m['first_installed_at']);
        $this->assertTrue($j['mesh_mapped']);
    }

    public function testADeviceThatNeverReportedHasNoMeshAgentMemberAndCostsNoExtraQuery(): void
    {
        $this->meshOn();
        [$dev, $token] = $this->linked('M0');
        [, $j] = $this->api('GET', [(string) $dev], $this->tech);
        $this->assertArrayNotHasKey('mesh_agent', $j, 'the detail of every other device is byte for byte what it was');
        $before = $this->h->counting->statements;
        $this->h->checkin($token);
        $withoutMember = $this->h->counting->statements - $before;
        $before = $this->h->counting->statements;
        $this->h->checkin($token, ['mesh_agent' => ['installed' => true, 'node_id' => self::NODE_A]]);
        $this->assertGreaterThan($withoutMember, $this->h->counting->statements - $before, 'the report is the only thing that costs queries');
        $this->assertSame(0, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_mesh_agents WHERE device_id <> $dev"));
    }

    public function testNothingIsMappedWhileMeshIsDisabledButTheStateIsStillRecorded(): void
    {
        $this->h->module->settings()->set(['mesh_enabled' => 0]);
        [$dev, $token] = $this->linked('M2');
        $this->meshReport($token, ['installed' => true, 'node_id' => self::NODE_A]);
        $this->assertNull($this->nodeOf($dev));
        $this->assertSame(self::NODE_A, $this->h->module->meshAgents()->stateFor($dev)['node_id']);
        $this->assertNull($this->h->module->meshAgents()->stateFor($dev)['map_state']);
        // switching Mesh on evaluates it at the next report (the daily one, or the next change): it waits for approval, it is not mapped
        $this->h->module->settings()->set(['mesh_enabled' => 1]);
        $this->meshReport($token, ['installed' => true, 'node_id' => self::NODE_A]);
        $this->assertNull($this->nodeOf($dev));
        $this->assertSame('needs_approval', $this->h->module->meshAgents()->stateFor($dev)['map_state']);
        $this->approve($dev, self::NODE_A);
        $this->meshReport($token, ['installed' => true, 'node_id' => self::NODE_A]);
        $this->assertSame('mapped', $this->h->module->meshAgents()->stateFor($dev)['map_state']);
    }

    public function testAManualMappingIsNeverOverwrittenUnlessTheSettingIsOn(): void
    {
        $this->meshOn();
        [$dev, $token] = $this->linked('M3');
        $this->assertTrue($this->h->module->devices()->setMeshNode($dev, self::NODE_B, 1));
        $this->meshReport($token, ['installed' => true, 'node_id' => self::NODE_A]);
        $this->assertSame(self::NODE_B, $this->nodeOf($dev), 'the administrator\'s mapping stays');
        $this->assertSame('kept_manual', $this->h->module->meshAgents()->stateFor($dev)['map_state']);
        $this->assertSame(1, $this->audit('Mesh Node Differs'));
        $this->meshReport($token, ['installed' => true, 'node_id' => self::NODE_A]);
        $this->assertSame(1, $this->audit('Mesh Node Differs'), 'said once, not on every report');
        // the same node as the manual one is simply "mapped", nothing is rewritten
        $this->meshReport($token, ['installed' => true, 'node_id' => self::NODE_B]);
        $this->assertSame('manual', $this->h->one("SELECT source FROM endpoint_agent_mesh_nodes WHERE device_id=$dev"));
        $this->assertSame('mapped', $this->h->module->meshAgents()->stateFor($dev)['map_state']);
        // overwrite on: a device's own claim still does not replace a mapping (rc.31 review F1): it waits for approval, the mapping stays
        $this->assertSame([], $this->h->module->meshAgentSettings()->update(['mesh_auto_map_overwrite' => true], 1));
        $this->meshReport($token, ['installed' => true, 'node_id' => self::NODE_A]);
        $this->assertSame([self::NODE_B, 'manual'], [$this->nodeOf($dev), $this->h->one("SELECT source FROM endpoint_agent_mesh_nodes WHERE device_id=$dev")]);
        $this->assertSame('needs_approval', $this->h->module->meshAgents()->stateFor($dev)['map_state']);
    }

    public function testANodeTheAgentSetEarlierIsReplacedWhenTheMachineGetsANewIdentity(): void
    {
        $this->meshOn();
        [$dev, $token] = $this->linked('M4');
        $this->h->module->devices()->setMeshNode($dev, self::NODE_A, 0, 'agent');   // a mapping an older release made automatically
        $this->meshReport($token, ['installed' => true, 'node_id' => self::NODE_A]);
        $this->assertSame('mapped', $this->h->module->meshAgents()->stateFor($dev)['map_state']);
        $this->meshReport($token, ['installed' => true, 'node_id' => self::NODE_B]);
        $this->assertSame(self::NODE_A, $this->nodeOf($dev), 'a new identity claimed by the device is not taken on its word');
        $this->assertSame('needs_approval', $this->h->module->meshAgents()->stateFor($dev)['map_state']);
        $this->approve($dev, self::NODE_B);
        $this->assertSame(self::NODE_B, $this->nodeOf($dev));
    }

    public function testTwoDevicesClaimingOneNodeIsAConflictNothingMovesAndItIsFlagged(): void
    {
        $this->meshOn();
        [$d1, $t1] = $this->linked('C1');
        [$d2, $t2] = $this->linked('C2');
        $this->approve($d1, self::NODE_A);
        $this->meshReport($t1, ['installed' => true, 'node_id' => self::NODE_A]);
        $this->meshReport($t2, ['installed' => true, 'node_id' => self::NODE_A]);
        $this->assertSame(self::NODE_A, $this->nodeOf($d1));
        $this->assertNull($this->nodeOf($d2), 'the second claim is refused');
        $s = $this->h->module->meshAgents()->stateFor($d2);
        $this->assertSame(['conflict', $d1], [$s['map_state'], $s['conflict_device_id']]);
        $this->assertSame(1, $this->audit('Mesh Node Conflict'));
        $ev = $this->bus->of(RmmEvent::MESH_NODE_CONFLICT);
        $this->assertCount(1, $ev);
        $this->assertSame([$d2, self::NODE_A, $d1], [$ev[0]['device_id'], $ev[0]['node_id'], $ev[0]['other_device_id']]);
        $this->meshReport($t2, ['installed' => true, 'node_id' => self::NODE_A]);
        $this->assertSame([1, 1], [$this->audit('Mesh Node Conflict'), count($this->bus->of(RmmEvent::MESH_NODE_CONFLICT))], 'once per conflict, not per report');
        // even with the overwrite switch on, a node owned by another live device is never taken
        $this->h->module->meshAgentSettings()->update(['mesh_auto_map_overwrite' => true], 1);
        $this->meshReport($t2, ['installed' => true, 'node_id' => self::NODE_A]);
        $this->assertNull($this->nodeOf($d2));
        // a manual mapping of the same node on another live device blocks it too
        [$d3, $t3] = $this->linked('C3');
        $this->assertTrue($this->h->module->devices()->setMeshNode($d3, self::NODE_B, 1));
        [$d4, $t4] = $this->linked('C4');
        $this->meshReport($t4, ['installed' => true, 'node_id' => self::NODE_B]);
        $this->assertNull($this->nodeOf($d4));
        $this->assertSame($d3, $this->h->module->meshAgents()->stateFor($d4)['conflict_device_id']);
        // the conflict clears when the node is no longer claimed
        $this->assertTrue($this->h->module->devices()->setMeshNode($d1, null, 1));
        $this->meshReport($t1, ['installed' => false]);   // and the first device no longer reports it
        $this->meshReport($t2, ['installed' => true, 'node_id' => self::NODE_A]);
        $this->assertNull($this->nodeOf($d2), 'the node is free again, but still not taken on the device\'s word');
        $this->approve($d2, self::NODE_A);
        $this->meshReport($t2, ['installed' => true, 'node_id' => self::NODE_A]);
        $this->assertSame(['mapped', null], [$this->h->module->meshAgents()->stateFor($d2)['map_state'], $this->h->module->meshAgents()->stateFor($d2)['conflict_device_id']]);
        $this->assertNotSame('', $t3 . $t4);
    }

    /** @return array{0:string,1:string,2:string} hex, URL form, canonical */
    private static function freshNode(): array
    {
        $raw = random_bytes(48);
        $url = strtr(rtrim(base64_encode($raw), '='), '+/', '@$');

        return [bin2hex($raw), $url, 'node//' . $url];
    }

    public function testAHexReportIsStoredInTheCanonicalForm(): void
    {
        $this->meshOn();
        [$dev, $token] = $this->linked('H1');
        [$hex, , $canon] = self::freshNode();
        $this->meshReport($token, ['installed' => true, 'node_id' => 'node//' . $hex]);
        $this->approve($dev, $canon);
        $this->meshReport($token, ['installed' => true, 'node_id' => 'node//' . $hex]);
        $this->assertSame($canon, $this->nodeOf($dev));
        $this->assertSame($canon, $this->h->one("SELECT node_id FROM endpoint_agent_mesh_agents WHERE device_id=$dev"));
        $this->assertSame($canon, $this->h->module->meshAgents()->stateFor($dev)['node_id']);
        $this->assertSame('mapped', $this->h->module->meshAgents()->stateFor($dev)['map_state']);
        // the same node reported again in the URL form changes nothing
        $this->meshReport($token, ['installed' => true, 'node_id' => $canon]);
        $this->assertSame([1, $canon], [$this->audit('Mesh Node Needs Approval'), $this->nodeOf($dev)]);
        // a pasted MeshCentral address in the report is understood too
        [, $other] = $this->linked('H2');
        [$hex2, $url2, $canon2] = self::freshNode();
        $this->meshReport($other, ['installed' => true, 'node_id' => "https://mesh.example.com/?viewmode=11&gotonode=$url2&="]);
        $this->assertNotNull($this->h->one("SELECT device_id FROM endpoint_agent_mesh_agents WHERE node_id='$canon2'"), 'the address was understood and recorded as the reported node');
        $this->assertNotSame('', $hex2);
        // the inventory path (mesh_node_id in a check-in inventory) is normalised the same way
        [$d3, $t3] = $this->linked('H3');
        [$hex3, , $canon3] = self::freshNode();
        $inv = ['hostname' => 'H3', 'os' => 'windows', 'os_version' => 'Windows 11', 'serial' => 'SER-H3', 'mesh_node_id' => 'node//' . $hex3];
        [$c] = $this->h->checkin($t3, ['inventory' => $inv]);
        $this->assertSame(200, $c);
        $this->assertNull($this->nodeOf($d3), 'an inventory claim is not taken on the device\'s word either');
        $this->approve($d3, $canon3);
        $this->h->checkin($t3, ['inventory' => $inv]);
        $this->assertSame([$canon3, 'manual'], [$this->nodeOf($d3), $this->h->one("SELECT source FROM endpoint_agent_mesh_nodes WHERE device_id=$d3")]);
    }

    public function testConflictDetectionComparesTheSameNodeAcrossSpellings(): void
    {
        $this->meshOn();
        [$d1] = $this->linked('X1');
        [$d2, $t2] = $this->linked('X2');
        [$hex, , $canon] = self::freshNode();
        // device 1 holds the node in the old hex spelling (a row from before ids were normalised), set by hand
        $this->h->q("INSERT INTO endpoint_agent_mesh_nodes (device_id, mesh_node_id, source, updated_at, updated_by) VALUES ($d1, 'node//" . strtoupper($hex) . "', 'manual', NOW(), 1)");
        $this->meshReport($t2, ['installed' => true, 'node_id' => $canon]);
        $this->assertNull($this->nodeOf($d2), 'the same node in another spelling is the same node');
        $s = $this->h->module->meshAgents()->stateFor($d2);
        $this->assertSame(['conflict', $d1], [$s['map_state'], $s['conflict_device_id']]);
        // and the other direction: a manual mapping typed as hex while the agent holds the canonical form
        [$d3, $t3] = $this->linked('X3');
        [$hex4, , $canon4] = self::freshNode();
        $this->meshReport($t3, ['installed' => true, 'node_id' => $canon4]);
        $this->assertTrue($this->h->module->devices()->setMeshNode($d3, $hex4, 1));
        $this->assertSame($canon4, $this->nodeOf($d3), 'a hand-typed hex id is stored canonical');
        $this->assertSame('manual', $this->h->one("SELECT source FROM endpoint_agent_mesh_nodes WHERE device_id=$d3"));
        $this->meshReport($t3, ['installed' => true, 'node_id' => 'node//' . $hex4]);
        $this->assertSame('mapped', $this->h->module->meshAgents()->stateFor($d3)['map_state'], 'the report is the same node as the manual mapping');
    }

    public function testAConflictAcrossClientsIsStillRefusedButDoesNotNameTheOtherDevice(): void
    {
        $this->meshOn();
        [$a, $ta] = $this->linked('XA', $this->h->clientA);
        [$b, $tb] = $this->linked('XB', $this->h->clientB);
        $this->approve($a, self::NODE_A);
        $this->meshReport($ta, ['installed' => true, 'node_id' => self::NODE_A]);
        $this->meshReport($tb, ['installed' => true, 'node_id' => self::NODE_A]);
        $this->assertNull($this->nodeOf($b));
        [$c, $j] = $this->api('GET', [(string) $b], $this->scoped);
        $this->assertSame(200, $c);
        $this->assertSame('conflict', $j['mesh_agent']['map_state']);
        $this->assertNull($j['mesh_agent']['conflict_device_id'], 'a device of another client is not named');
        $this->assertSame(404, $this->api('GET', [(string) $a], $this->scoped)[0], 'the scoped user cannot see the first device at all');
        $this->assertNull($this->api('GET', [(string) $b], $this->admin)[1]['mesh_agent']['conflict_device_id'], 'not even to an administrator through the device detail: the audit record has the id');
        $this->assertStringContainsString("already mapped to device $a", json_encode($this->h->audit->records()));
    }

    public function testANodeOfARetiredDeviceMovesOnlyToTheReEnrolledMachineOfTheSameAsset(): void
    {
        $this->meshOn();
        [$old, $to] = $this->linked('R1');
        $this->approve($old, self::NODE_A);
        $this->meshReport($to, ['installed' => true, 'node_id' => self::NODE_A]);
        $this->assertTrue($this->h->module->technician()->retire($this->admin, $old)->ok);
        [$new, $tn] = $this->linked('R1b');
        $this->h->q("UPDATE endpoint_agent_devices SET asset_id=" . (int) $this->h->one("SELECT asset_id FROM endpoint_agent_devices WHERE device_id=$old") . " WHERE device_id=$new");
        $this->meshReport($tn, ['installed' => true, 'node_id' => self::NODE_A]);
        $this->assertSame(self::NODE_A, $this->nodeOf($new));
        $this->assertNull($this->nodeOf($old), 'released from the retired device');
        $this->assertSame(1, $this->audit('Mesh Node Moved'));
    }

    /** rc.31 review F1: a node bound to a retired device is never handed to an unrelated device, in the same client or another one. */
    public function testANodeOfARetiredDeviceIsNeverTakenByAStranger(): void
    {
        $this->meshOn();
        [$old, $to] = $this->linked('S1', $this->h->clientA);
        $this->approve($old, self::NODE_A);
        $this->assertTrue($this->h->module->technician()->retire($this->admin, $old)->ok);
        [$sameClient, $t1] = $this->linked('S2', $this->h->clientA);   // another asset of the same client
        [$otherClient, $t2] = $this->linked('S3', $this->h->clientB);
        foreach ([[$sameClient, $t1], [$otherClient, $t2]] as [$d, $t]) {
            $this->meshReport($t, ['installed' => true, 'node_id' => self::NODE_A]);
            $this->assertNull($this->nodeOf($d));
            $this->assertSame('conflict', $this->h->module->meshAgents()->stateFor($d)['map_state']);
            $this->assertNull($this->h->module->mesh()->nodeFor($d), 'nothing for a technician to launch into');
        }
        $this->assertSame(self::NODE_A, $this->nodeOf($old), 'the retired device keeps its mapping untouched');
        $this->assertSame(0, $this->audit('Mesh Node Moved'));
    }

    /** rc.31 review F1: the attacker case. A node nobody has been seen to hold is claimed by a rogue device: not mapped, launch stays unmapped. */
    public function testAnUnseenNodeClaimedByARogueDeviceIsNotMappedAndNothingLaunches(): void
    {
        $this->meshOn();
        [$rogue, $tr] = $this->linked('ROGUE', $this->h->clientB);
        $this->meshReport($tr, ['installed' => true, 'node_id' => self::NODE_B]);
        $this->assertNull($this->nodeOf($rogue));
        $s = $this->h->module->meshAgents()->stateFor($rogue);
        $this->assertSame('needs_approval', $s['map_state']);
        $this->assertSame(1, $this->audit('Mesh Node Needs Approval'));
        $this->assertSame(0, $this->audit('Mesh Node Mapped'));
        $this->assertNull($this->h->module->mesh()->nodeFor($rogue), 'launch() reads this mapping and answers "unmapped"');
        // reporting it again says nothing more
        $this->meshReport($tr, ['installed' => true, 'node_id' => self::NODE_B]);
        $this->assertSame(1, $this->audit('Mesh Node Needs Approval'));
        // two live devices reporting the same unmapped node conflict with each other
        [$other, $to2] = $this->linked('ROGUE2', $this->h->clientA);
        $this->meshReport($to2, ['installed' => true, 'node_id' => self::NODE_B]);
        $this->assertSame(['conflict', $rogue], [$this->h->module->meshAgents()->stateFor($other)['map_state'], (int) $this->h->one("SELECT conflict_device_id FROM endpoint_agent_mesh_agents WHERE device_id=$other")]);
    }

    public function testAFailedInstallIsRecordedWithItsErrorAndMapsNothing(): void
    {
        $this->meshOn();
        [$dev, $token] = $this->linked('F1');
        $this->meshReport($token, ['installed' => false, 'error' => "the Mesh server answered HTTP 404\nsecond line \x07", 'node_id' => self::NODE_A]);
        $s = $this->h->module->meshAgents()->stateFor($dev);
        $this->assertFalse($s['installed']);
        $this->assertSame('the Mesh server answered HTTP 404 second line', $s['error']);
        $this->assertNull($s['node_id'], 'a node id without an installed agent is ignored');
        $this->assertNull($this->nodeOf($dev));
        $this->assertSame(1, $this->audit('Mesh Agent Install Failed'));
        $this->assertSame([], $this->bus->of(RmmEvent::MESH_AGENT_INSTALLED));
        // later it is installed (an administrator fixed the group id): the event fires then
        $this->meshReport($token, ['installed' => true, 'node_id' => self::NODE_A]);
        $this->assertCount(1, $this->bus->of(RmmEvent::MESH_AGENT_INSTALLED));
        $this->assertSame('', (string) $this->h->one("SELECT error FROM endpoint_agent_mesh_agents WHERE device_id=$dev"));
    }

    public function testHostileOrBrokenReportsNeverFailTheCheckInAndAreSanitised(): void
    {
        $this->meshOn();
        [$dev, $token] = $this->linked('H1');
        $bad = ['x', 5, true, [], [1, 2], ['installed' => 'yes'], ['installed' => null], ['node_id' => self::NODE_A], ['installed' => 2], ['installed' => []],
            ['installed' => true, 'node_id' => ['a']], ['installed' => true, 'version' => ['a'], 'error' => ['b']]];
        foreach ($bad as $m) {
            $this->meshReport($token, $m);
        }
        $this->assertNull($this->nodeOf($dev));
        // invalid node ids are ignored, long texts are cut, the report still counts
        foreach (['node//short', "node//" . str_repeat('A', 24) . "\n", 'NODE//' . str_repeat('A', 24), 'node//' . str_repeat('A', 101), 'node//' . str_repeat('!', 24), '../../etc/passwd', str_repeat('x', 5000)] as $node) {
            $this->meshReport($token, ['installed' => true, 'node_id' => $node, 'version' => str_repeat('v', 500)]);
            $this->assertNull($this->nodeOf($dev), json_encode($node));
        }
        $s = $this->h->module->meshAgents()->stateFor($dev);
        $this->assertTrue($s['installed']);
        $this->assertSame(60, strlen($s['version']));
        $this->assertNull($s['node_id']);
        $this->meshReport($token, ['installed' => false, 'error' => str_repeat('e', 5000)]);
        $this->assertSame(300, strlen($this->h->module->meshAgents()->stateFor($dev)['error']));
        // an oversized body of unrelated junk next to a valid report is the existing 422/413 behaviour, not a new one
        $this->assertSame(200, $this->h->checkin($token, ['mesh_agent' => ['installed' => true, 'node_id' => self::NODE_A], 'unknown_future_member' => ['a' => 1]])[0]);
        $this->assertNull($this->nodeOf($dev), 'a well-formed claim still waits for approval');
        $this->assertSame('needs_approval', $this->h->module->meshAgents()->stateFor($dev)['map_state']);
    }

    public function testOneDevicesReportNeverTouchesAnotherDevice(): void
    {
        $this->meshOn();
        [$d1, $t1] = $this->linked('S1');
        [$d2, $t2] = $this->linked('S2');
        $this->meshReport($t1, ['installed' => true, 'node_id' => self::NODE_A, 'version' => '9']);
        $this->assertNull($this->h->module->meshAgents()->stateFor($d2));
        $this->meshReport($t2, ['installed' => false, 'error' => 'nope']);
        $this->assertSame(self::NODE_A, $this->h->module->meshAgents()->stateFor($d1)['node_id']);
        $this->assertSame('9', $this->h->module->meshAgents()->stateFor($d1)['version']);
        // a viewer-only principal sees the state (device.view) but cannot change settings
        $this->assertSame(200, $this->api('GET', [(string) $d1], $this->viewer)[0]);
        $this->assertSame(403, $this->api('PUT', ['remote', 'mesh'], $this->viewer, ['mesh_auto_map_overwrite' => true])[0]);
    }

    public function testAnAgentThatPredatesTheFeatureCheckingInIsByteForByteWhatItWas(): void
    {
        $this->meshOn();
        [$dev, $token] = $this->linked('OLD');
        [$c, , $j] = $this->h->checkin($token);
        $this->assertSame(200, $c);
        $this->assertArrayNotHasKey('mesh_agent', $j, 'the response never grows a Mesh member');
        $this->assertNull($this->h->module->meshAgents()->stateFor($dev));
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_mesh_agents'));
    }
}
