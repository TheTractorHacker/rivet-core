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

    public function testTheTickBoxCarriesTheMeshSourceInTheInteractiveAndTheTokenGatedInstaller(): void
    {
        $pin = hash('sha384', 'server cert');
        $this->meshOn(['mesh_server_cert_pin' => strtoupper($pin), 'mesh_agent_ids' => ['windows/arm64' => 40], 'mesh_install_args' => ['linux' => ['-install', '--installPath=/opt/mesh']]]);
        $r = $this->h->module->installerService()->issue($this->h->clientA, 0, 'stable', 24, 5, 'mesh', 'amd64', 1, 'Admin', true);
        $this->assertTrue($r['ok'], json_encode($r));
        $payloads = [json_decode((string) $r['payload'], true), $this->download($r['token_plain'])];
        foreach ($payloads as $d) {
            $this->assertSame(1, $d['version'], 'the payload version stays 1');
            $this->assertTrue($d['install_mesh_agent']);
            $m = $d['mesh'];
            $this->assertSame('https://mesh.example.com', $m['url']);
            $this->assertSame(self::GROUP, $m['meshid']);
            $this->assertSame($pin, $m['pin'], 'stored normalised');
            $this->assertSame(40, $m['agent_ids']['windows/arm64']);
            $this->assertSame(4, $m['agent_ids']['windows/amd64'], 'the built-in ids fill the rest');
            $this->assertSame(['-install', '--installPath=/opt/mesh'], $m['install_args']['linux']);
            $this->assertSame(['-fullinstall'], $m['install_args']['windows']);
            $this->assertArrayNotHasKey('mesh_login_key', $m);
            $this->assertLessThan(InstallerStamp::MAX_PAYLOAD, strlen(json_encode($d)));
        }
        $this->assertStringContainsString('also installs the MeshCentral agent', json_encode($this->h->audit->records()));
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
        $this->h->module->settings()->set(['mesh_enabled' => 0]);
        $d = $this->download($r['token_plain']);
        $this->assertArrayNotHasKey('install_mesh_agent', $d, 'a download never fails over the optional feature');
        $this->assertSame('Dept A', $d['department']);
        $this->assertSame(1, $this->audit('Installer Mesh Skipped'));
    }

    public function testTheDeploymentCommandsCarryTheLinuxMeshFlagsAndThePowerShellStaysTheSame(): void
    {
        $this->meshOn(['mesh_server_cert_pin' => hash('sha384', 'c')]);
        $a = $this->h->module->admin();
        $with = $a->deploymentCommands($this->admin, $this->h->clientA, 0, 'stable', 24, 5, 'w', 'amd64', true);
        $without = $a->deploymentCommands($this->admin, $this->h->clientA, 0, 'stable', 24, 5, 'w', 'amd64');
        $this->assertTrue($with->ok, $with->message);
        $lx = $with->data['commands']['linux'];
        $this->assertStringContainsString("--install-mesh-agent --mesh-url 'https://mesh.example.com' --mesh-group-id '" . self::GROUP . "' --mesh-cert-pin '" . hash('sha384', 'c') . "'", $lx);
        $this->assertStringNotContainsString('mesh', $without->data['commands']['linux']);
        $this->assertStringNotContainsString('mesh', strtolower($with->data['commands']['powershell']), 'the stamped installer carries it; the snippet is unchanged');
        $this->assertStringNotContainsString('mesh', strtolower($without->data['commands']['powershell']));
    }

    public function testTheAdminDownloadIsAStampedInstallerThatCarriesIt(): void
    {
        $this->meshOn();
        $r = $this->h->module->admin()->downloadInstaller($this->admin, $this->h->clientA, 0, 'stable', 24, 5, 'd', 'amd64', true);
        $this->assertTrue($r->ok, $r->message);
        $file = $r->data['download']->file;
        $p = InstallerStamp::read((string) file_get_contents($file->path) . (string) $file->trailer);
        $this->assertNotNull($p);
        $this->assertTrue($p['data']['install_mesh_agent']);
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
        $this->assertSame(self::NODE_A, $this->nodeOf($dev));
        $this->assertSame('agent', $this->h->one("SELECT source FROM endpoint_agent_mesh_nodes WHERE device_id=$dev"));
        $this->assertSame(1, $this->audit('Mesh Node Mapped'));
        $this->assertSame(1, $this->audit('Mesh Agent Installed'));
        $ev = $this->bus->of(RmmEvent::MESH_AGENT_INSTALLED);
        $this->assertCount(1, $ev);
        $this->assertSame([$dev, '1.1.10', self::NODE_A], [$ev[0]['device_id'], $ev[0]['version'], $ev[0]['node_id']]);
        $this->assertSame(self::NODE_A, $this->h->module->mesh()->nodeFor($dev), 'the launch path sees the mapping');
        // repeated, identical reports change nothing and say nothing
        $this->meshReport($token, ['installed' => true, 'version' => '1.1.10', 'node_id' => self::NODE_A]);
        $this->assertSame([1, 1, 1], [$this->audit('Mesh Node Mapped'), $this->audit('Mesh Agent Installed'), count($this->bus->of(RmmEvent::MESH_AGENT_INSTALLED))]);
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
        // switching Mesh on maps it at the next report (the daily one, or the next change)
        $this->h->module->settings()->set(['mesh_enabled' => 1]);
        $this->meshReport($token, ['installed' => true, 'node_id' => self::NODE_A]);
        $this->assertSame(self::NODE_A, $this->nodeOf($dev));
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
        // overwrite on: the report replaces it, audited
        $this->assertSame([], $this->h->module->meshAgentSettings()->update(['mesh_auto_map_overwrite' => true], 1));
        $this->meshReport($token, ['installed' => true, 'node_id' => self::NODE_A]);
        $this->assertSame([self::NODE_A, 'agent'], [$this->nodeOf($dev), $this->h->one("SELECT source FROM endpoint_agent_mesh_nodes WHERE device_id=$dev")]);
        $this->assertStringContainsString('overwrite is on', json_encode($this->h->audit->records()));
    }

    public function testANodeTheAgentSetEarlierIsReplacedWhenTheMachineGetsANewIdentity(): void
    {
        $this->meshOn();
        [$dev, $token] = $this->linked('M4');
        $this->meshReport($token, ['installed' => true, 'node_id' => self::NODE_A]);
        $this->meshReport($token, ['installed' => true, 'node_id' => self::NODE_B]);
        $this->assertSame(self::NODE_B, $this->nodeOf($dev));
        $this->assertSame(2, $this->audit('Mesh Node Mapped'));
    }

    public function testTwoDevicesClaimingOneNodeIsAConflictNothingMovesAndItIsFlagged(): void
    {
        $this->meshOn();
        [$d1, $t1] = $this->linked('C1');
        [$d2, $t2] = $this->linked('C2');
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
        $this->meshReport($t2, ['installed' => true, 'node_id' => self::NODE_A]);
        $this->assertSame(self::NODE_A, $this->nodeOf($d2));
        $this->assertSame(['mapped', null], [$this->h->module->meshAgents()->stateFor($d2)['map_state'], $this->h->module->meshAgents()->stateFor($d2)['conflict_device_id']]);
        $this->assertNotSame('', $t3 . $t4);
    }

    public function testAConflictAcrossClientsIsStillRefusedButDoesNotNameTheOtherDevice(): void
    {
        $this->meshOn();
        [$a, $ta] = $this->linked('XA', $this->h->clientA);
        [$b, $tb] = $this->linked('XB', $this->h->clientB);
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

    public function testANodeOfARetiredDeviceMovesToTheReEnrolledMachine(): void
    {
        $this->meshOn();
        [$old, $to] = $this->linked('R1');
        $this->meshReport($to, ['installed' => true, 'node_id' => self::NODE_A]);
        $this->assertTrue($this->h->module->technician()->retire($this->admin, $old)->ok);
        [$new, $tn] = $this->linked('R1b');
        $this->meshReport($tn, ['installed' => true, 'node_id' => self::NODE_A]);
        $this->assertSame(self::NODE_A, $this->nodeOf($new));
        $this->assertNull($this->nodeOf($old), 'released from the retired device');
        $this->assertSame(1, $this->audit('Mesh Node Moved'));
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
        $this->assertSame(self::NODE_A, $this->nodeOf($dev));
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
