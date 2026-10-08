<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Http\DeviceApi;
use RivetCore\Rmm\RmmStateFile;
use RivetCore\Testing\InMemoryRmmModuleState;
use RivetCore\Tests\Support\AllowUsersPolicy;
use RivetCore\Tests\Support\RmmHarness;
use RivetCore\Tests\Support\RmmTestCase;
use RivetCore\Tests\Support\TempDir;

/**
 * The choices an edition makes when it adopts the module: what a switched-off module answers to devices (uniform 503 or RivetIT's
 * compat 403), the lazily re-created state file, terminology (department), the device list extras and the shared Mesh TTL bounds.
 */
final class EditionOptionsTest extends RmmTestCase
{
    private string $dir = '';

    protected function makeHarness(): RmmHarness
    {
        $this->dir = TempDir::make();

        return new RmmHarness(null, new InMemoryRmmModuleState(true, $this->dir), ['client_label' => 'department', 'denial_reasons' => ['rmm.job.run_script' => 'Ask a Level 3 technician.']], false, null, new AllowUsersPolicy([1 => true, 2 => ['rmm.device.view']]));
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        TempDir::remove($this->dir);
    }

    private function api(?string $mode, bool $legacyArg = true): DeviceApi
    {
        return $this->h->module->deviceApi(static fn (): bool => true, $legacyArg, null, null, $mode);
    }

    private function answer(DeviceApi $api, string $endpoint, ?string $token = null): array
    {
        $r = $api->handle($this->h->request('POST', $endpoint, '{}', $token));

        return [$r->status, (string) (json_decode((string) $r->body, true)['code'] ?? ''), $r->headers['Retry-After'] ?? null];
    }

    public function testUniformIsTheDefaultAnd503EverywhereWhenSwitchedOff(): void
    {
        $this->h->module->settings()->enable();
        $this->h->module->settings()->disable();
        foreach (['agent_enroll', 'agent_checkin', 'agent_jobs', 'agent_update', 'agent_installer'] as $ep) {
            $this->assertSame([503, 'module_disabled', '3600'], $this->answer($this->api(null), $ep), $ep);
            $this->assertSame([503, 'module_disabled', '3600'], $this->answer($this->api(DeviceApi::DISABLED_UNIFORM), $ep), $ep);
        }
    }

    public function testCompatKeepsRivetItsLegacyForbiddenAndTheWithModuleStateFalseArgumentStillMeansCompat(): void
    {
        $this->h->module->settings()->enable();
        $this->h->module->settings()->disable();
        foreach ([$this->api(DeviceApi::DISABLED_COMPAT), $this->api(null, false), $this->h->api] as $api) {
            $this->assertSame([403, 'forbidden', null], $this->answer($api, 'agent_enroll'));
            $this->assertSame([403, 'forbidden', null], $this->answer($api, 'agent_installer'));
            // authentication still comes first for a device endpoint: no credential is a 401, never a hint about the switch
            $this->assertSame(401, $this->answer($api, 'agent_checkin')[0]);
        }
        // an explicit mode wins over the legacy argument
        $this->assertSame(503, $this->answer($this->api(DeviceApi::DISABLED_UNIFORM, false), 'agent_enroll')[0]);
        $this->expectException(\InvalidArgumentException::class);
        $this->api('loud');
    }

    public function testCompatWithAValidCredentialIsForbiddenWhileOffAndWorksAgainWhenOn(): void
    {
        $tok = $this->h->token(null, 24, 5);
        [, , $j] = $this->h->enroll($tok, $this->h::device());
        $T = $j['device_token'];
        $compat = $this->api(DeviceApi::DISABLED_COMPAT);
        $this->assertSame(200, $this->h->checkin($T)[0]);
        $this->h->module->settings()->disable();
        $r = $compat->handle($this->h->request('POST', 'agent_checkin', '{}', $T));
        $this->assertSame(403, $r->status, 'the legacy answer for a valid credential');
        $this->assertSame(503, $this->answer($this->api(DeviceApi::DISABLED_UNIFORM), 'agent_checkin', $T)[0]);
    }

    public function testCompatRecreatesAMissingStateFileOnTheNextRequest(): void
    {
        $this->h->module->settings()->enable();
        $path = RmmStateFile::path($this->dir);
        $this->assertTrue(is_file($path));
        foreach (['compat', 'uniform'] as $mode) {
            unlink($path);
            $this->assertNull(RmmStateFile::read($this->dir));
            $this->api($mode)->handle($this->h->request('POST', 'agent_checkin', '{}'));   // even an unauthenticated call repairs it
            $s = RmmStateFile::read($this->dir);
            $this->assertNotNull($s, "$mode: the file is back");
            $this->assertTrue($s['enabled']);
            file_put_contents($path, '{garbled');
            $this->api($mode)->handle($this->h->request('POST', 'agent_checkin', '{}'));
            $this->assertNotNull(RmmStateFile::read($this->dir), "$mode: a damaged file is rewritten too");
        }
        // a valid file is left alone (same bytes)
        $before = file_get_contents($path);
        $this->api('compat')->handle($this->h->request('POST', 'agent_checkin', '{}'));
        $this->assertSame($before, file_get_contents($path));
    }

    public function testTheKillSwitchIsForbiddenInCompatAndUnavailableInUniform(): void
    {
        $off = new RmmHarness(state: new InMemoryRmmModuleState(false), policy: new AllowUsersPolicy([1 => true]));
        $off->module->settings()->enable();
        $this->assertSame([403, 'forbidden'], array_slice($this->statusOf($off, DeviceApi::DISABLED_COMPAT), 0, 2));
        $this->assertSame([503, 'module_disabled'], array_slice($this->statusOf($off, DeviceApi::DISABLED_UNIFORM), 0, 2));
    }

    private function statusOf(RmmHarness $h, string $mode): array
    {
        $r = $h->module->deviceApi(static fn (): bool => true, true, null, null, $mode)->handle($h->request('POST', 'agent_enroll', '{}'));

        return [$r->status, (string) (json_decode((string) $r->body, true)['code'] ?? '')];
    }

    // ------------------------------------------------------------------ terminology

    public function testTheClientLabelReachesTheMessagesAUserReads(): void
    {
        $this->h->enable();
        try {
            $this->h->module->enrollment()->createToken(9999, 0, 'stable', 1, 1, 'x', 1);
            $this->fail('expected an exception');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Choose the department the devices belong to.', $e->getMessage());
        }
        $this->h->module->settings()->update(['service_url' => 'https://rmm.example.com']);
        $this->h->publishBinary('1.0.0', 'amd64', 8192, true);
        $r = $this->h->module->installerService()->issue(9999, 0, 'stable', 1, 1, 'x', 'amd64', 1, 'Admin');
        $this->assertFalse($r['ok']);
        $this->assertSame('Choose the department the devices belong to.', $r['error'] ?? null);
        $this->assertSame('Department 9999', $this->h->module->installerDownload()->departmentName(9999));

        $tok = $this->h->token();
        [, , $j] = $this->h->enroll($tok, $this->h::device());
        $who = $this->h->principal();
        $t = $this->h->module->technician()->transfer($who, (int) $j['device_id'], 9999);
        $this->assertSame('Choose an existing department.', $t->message);
        $this->h->module->readModel();
        $this->assertSame('The matching asset belongs to a different department than the enrollment token.', $this->h->module->readModel()->matchReasonText('scope_mismatch'));
        $this->assertSame('Ask a Level 3 technician.', $this->h->module->authorizer()->check(2, 'rmm.job.run_script', 0));
        $this->assertSame('You do not have access to this device\'s department.', $this->h->module->authorizer()->noClientAccess());
    }

    public function testTheDefaultLabelIsClientAndAnInvalidLabelFallsBack(): void
    {
        $this->assertSame('client', (new RmmHarness())->module->clientLabel());
        $this->assertSame('client', (new RmmHarness(options: ['client_label' => 'Bad<Label>']))->module->clientLabel());
        $c = new RmmHarness();
        $c->enable();
        try {
            $c->module->enrollment()->createToken(9999, 0, 'stable', 1, 1, 'x', 1);
            $this->fail('expected an exception');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Choose the client the devices belong to.', $e->getMessage());
        }
        $this->assertSame('Client 9999', $c->module->installerDownload()->departmentName(9999));
    }

    // ------------------------------------------------------------------ device list extras

    public function testListDevicesCarriesAssetNameAndUpdateState(): void
    {
        $this->h->enable();
        $asset = $this->h->asset(['name' => 'FRONT-DESK-01', 'serial' => 'SN-LIST-1']);
        $tok = $this->h->token();
        [, , $a] = $this->h->enroll($tok, $this->h::device(['hostname' => 'host-a', 'serial' => 'SN-LIST-1']));
        [, , $b] = $this->h->enroll($tok, $this->h::device(['hostname' => 'host-b', 'serial' => 'SN-NOMATCH-9']));
        $this->assertSame($asset, $this->h->module->devices()->find((int) $a['device_id'])['asset_id'] ?? null, 'the first device linked to the asset');
        $this->h->q('UPDATE endpoint_agent_devices SET update_state_json = \'{"failed_versions":["1.2.3","1.2.4"],"last":{"version":"1.2.4","state":"failed"}}\' WHERE device_id = ' . (int) $a['device_id']);

        $list = $this->h->module->readModel()->listDevices();
        $by = array_column($list['items'], null, 'hostname');
        $this->assertSame('FRONT-DESK-01', $by['host-a']['asset_name']);
        $this->assertSame(['1.2.3', '1.2.4'], $by['host-a']['update_state']['failed_versions']);
        $this->assertNull($by['host-b']['asset_name'], 'no asset, no name');
        $this->assertNull($by['host-b']['update_state']);
        $this->assertArrayHasKey('status', $by['host-a'], 'the base summary keys are still there');

        // summary() and the technician REST list are unchanged (their JSON is frozen)
        $this->assertArrayNotHasKey('asset_name', $this->h->module->readModel()->summary($this->h->module->devices()->find((int) $a['device_id']) ?? []));
        [$c, $body] = $this->h->tech('GET', [], $this->h->principal());
        $this->assertSame(200, $c);
        $this->assertArrayNotHasKey('asset_name', $body['data'][0]);
        $this->assertArrayNotHasKey('update_state', $body['data'][0]);
        $plain = $this->h->module->readModel()->listDevices([], null, 50, 0, false);
        $this->assertArrayNotHasKey('asset_name', $plain['items'][0]);
        $this->assertSame($list['total'], $plain['total']);
    }

    // ------------------------------------------------------------------ settings bounds

    public function testMeshTokenTtlHasOneRangeInUpdateAndSaveMesh(): void
    {
        $s = $this->h->module->settings();
        foreach ([5 => 60, 60 => 60, 61 => 61, 99999 => 3600] as $in => $want) {
            $this->assertSame([], $s->update(['mesh_token_ttl_s' => $in]));
            $this->assertSame($want, (int) $s->get(true)['mesh_token_ttl_s'], "update($in)");
            $r = $this->h->module->admin()->saveMesh($this->h->principal(), ['mesh_enabled' => 0, 'mesh_url' => '', 'mesh_token_ttl_s' => $in === 61 ? 0 : $in]);
            if ($in !== 61) {
                $this->assertTrue($r->ok, 'saveMesh with an empty URL while off: ' . $r->message);
                $this->assertSame($want, (int) $s->get(true)['mesh_token_ttl_s'], "saveMesh($in)");
            }
        }
        $this->assertSame([60, 3600, 300], [\RivetCore\Rmm\Settings\RmmSettings::MESH_TOKEN_TTL_MIN_S, \RivetCore\Rmm\Settings\RmmSettings::MESH_TOKEN_TTL_MAX_S, \RivetCore\Rmm\Settings\RmmSettings::MESH_TOKEN_TTL_DEFAULT_S]);
    }
}
