<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Technician\ActionResult;
use RivetCore\Tests\Support\AllowUsersPolicy;
use RivetCore\Tests\Support\RmmHarness;
use RivetCore\Tests\Support\RmmTestCase;

/**
 * Red team 2026-11-21 NEW-2 (Chain C step 3): the `rmm.binary.publish` operations of RmmAdmin change what every device of every client runs or is installed from,
 * so they carry the TEN-3 fleet-wide guard. A release manager limited to one client (Globex) is refused each of them and nothing changes; the same grant with
 * no client limit is the control.
 */
final class ReleaseFleetScopeTest extends RmmTestCase
{
    private const MSI = __DIR__ . '/../../Fixtures/rmm/msi/template-x64.msi';
    private const SCOPED = 7;
    private const FLEET = 8;
    private string $tmp = '';

    protected function makeHarness(): RmmHarness
    {
        return new RmmHarness(options: ['allow_linux' => true], policy: new AllowUsersPolicy([1 => true, self::SCOPED => [RmmAbility::BINARY_PUBLISH], self::FLEET => [RmmAbility::BINARY_PUBLISH]]));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/rmm_rel_scope_' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0700);
        $this->h->enable();
        $this->h->module->settings()->set(['service_url' => 'https://rmm.example.com']);
        $this->h->module->settings()->update(['features_json' => ['monitoring' => true, 'updates' => true]]);
        $this->h->tenancy->restrictUser(self::SCOPED, [$this->h->clientB]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->tmp);
        parent::tearDown();
    }

    private function put(string $name, string $bytes): string
    {
        file_put_contents($this->tmp . '/' . $name, $bytes);

        return $this->tmp . '/' . $name;
    }

    /** @return array<string,\Closure(RmmPrincipal):ActionResult> every fleet-wide release operation, on fixtures the administrator created */
    private function operations(): array
    {
        $adm = $this->h->module->admin();
        $admin = $this->h->principal();
        $bin = $adm->uploadBinary($admin, $this->put('a.elf', RmmHarness::fakeElf(62, 4096, '1.0.1')), '1.0.1', 'amd64', ['os' => 'linux']);
        $this->assertTrue($bin->ok, $bin->message);
        $binId = (int) $bin->data['binary']['binary_id'];
        $this->assertTrue($adm->binaryAction($admin, $binId, 'offer_update', 'pilot', 0)->ok);
        $relId = (int) $this->h->one("SELECT release_id FROM endpoint_agent_releases WHERE binary_id = $binId");
        $tpl = $adm->uploadMsiTemplate($admin, $this->put('t.msi', (string) file_get_contents(self::MSI)), '1.0.0', 'amd64', ['activate' => false]);
        $this->assertTrue($tpl->ok, $tpl->message);
        $tplId = (int) $tpl->data['template']['template_id'];
        $msi = (string) file_get_contents(self::MSI);
        $msi[9100] = chr(ord($msi[9100]) ^ 1);

        return [
            'uploadBinary' => fn (RmmPrincipal $p) => $adm->uploadBinary($p, $this->put('b.elf', RmmHarness::fakeElf(62, 4096, '1.0.2')), '1.0.2', 'amd64', ['os' => 'linux', 'activate' => true, 'release_ring' => 'stable', 'rollout_pct' => 100]),
            'binaryAction make_current' => fn (RmmPrincipal $p) => $adm->binaryAction($p, $binId, 'make_current'),
            'binaryAction offer_update' => fn (RmmPrincipal $p) => $adm->binaryAction($p, $binId, 'offer_update', 'stable', 100),
            'binaryAction deactivate' => fn (RmmPrincipal $p) => $adm->binaryAction($p, $binId, 'deactivate'),
            'addExternalRelease' => fn (RmmPrincipal $p) => $adm->addExternalRelease($p, '1.0.3', 'https://rmm.example.com/x.bin', str_repeat('a', 64), '', 'stable', 100, 'n', 'linux'),
            'updateRelease' => fn (RmmPrincipal $p) => $adm->updateRelease($p, $relId, 100, true),
            'uploadMsiTemplate' => fn (RmmPrincipal $p) => $adm->uploadMsiTemplate($p, $this->put('u.msi', $msi), '1.0.1', 'amd64', ['activate' => true]),
            'msiTemplateAction' => fn (RmmPrincipal $p) => $adm->msiTemplateAction($p, $tplId, 'make_current'),
            'setReleaseSignature' => fn (RmmPrincipal $p) => $adm->setReleaseSignature($p, $binId, base64_encode(str_repeat("\x11", 64))),
            'setReleaseRollover' => fn (RmmPrincipal $p) => $adm->setReleaseRollover($p, ''),
        ];
    }

    /** @return array<string,mixed> every table a release operation writes, plus the binary directory */
    private function snapshot(): array
    {
        $out = [];
        foreach (['endpoint_agent_binaries', 'endpoint_agent_releases', 'endpoint_agent_msi_templates', 'rmm_agent_release_sigs', 'rmm_hardening_settings'] as $t) {
            $out[$t] = $this->h->rows("SELECT * FROM `$t`");
        }
        $files = glob($this->h->binaryDir . '/*') ?: [];
        sort($files);
        $out['files'] = $files;

        return $out;
    }

    public function testACallerLimitedToSomeClientsIsRefusedEveryReleaseOperationAndNothingChanges(): void
    {
        $scoped = new RmmPrincipal(self::SCOPED, 'Release manager (Globex only)');
        $this->assertNull($this->h->module->authorizer()->check(self::SCOPED, RmmAbility::BINARY_PUBLISH, 0), 'the role-level ability alone is granted');
        $devId = $this->enrollAcmeDevice();
        foreach ($this->operations() as $name => $op) {
            $before = $this->snapshot();
            $r = $op($scoped);
            $this->assertFalse($r->ok, "$name must be refused for a caller limited to some clients");
            $this->assertSame(403, $r->http, "$name: {$r->message}");
            $this->assertStringContainsString('every client', $r->message, $name);
            $this->assertSame($before, $this->snapshot(), "$name: a refused release operation changes nothing");
        }
        $dev = $this->h->rows("SELECT * FROM endpoint_agent_devices WHERE device_id = $devId")[0];
        $this->assertNull($this->h->module->updates()->offeredRelease($dev), 'nothing the scoped caller tried is offered to the other client\'s device');
    }

    public function testTheSameGrantWithoutAClientLimitIsTheControl(): void
    {
        $fleet = new RmmPrincipal(self::FLEET, 'Release manager');
        $this->enrollAcmeDevice();
        foreach ($this->operations() as $name => $op) {
            $r = $op($fleet);
            $this->assertTrue($r->ok, "$name: {$r->message}");
        }
    }

    public function testTheAbilityIsStillCheckedFirst(): void
    {
        $nobody = new RmmPrincipal(30, 'No grant');
        $this->h->tenancy->restrictUser(30, [$this->h->clientB]);
        $r = $this->h->module->admin()->setReleaseRollover($nobody, '');
        $this->assertSame(403, $r->http);
        $this->assertStringNotContainsString('every client', $r->message, 'a caller without the ability gets the ability refusal, not the scope one');
    }

    private function enrollAcmeDevice(): int
    {
        [$c, , $j] = $this->h->enroll($this->h->token($this->h->clientA), RmmHarness::device(['os' => 'linux', 'agent_version' => '1.0.0']));
        $this->assertSame(201, $c);

        return (int) $j['device_id'];
    }
}
