<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Http\DeviceApi;
use RivetCore\Rmm\RmmModule;
use RivetCore\Testing\InMemoryRmmModuleState;
use RivetCore\Testing\InMemorySecretBox;
use RivetCore\Tests\Support\RmmHarness;
use RivetCore\Tests\Support\RmmTestCase;
use RivetCore\Tests\Support\TempDir;

/**
 * Two adoption gaps: the device API answers the module switch from the state file (the edition is not asked while the file is valid,
 * so an edition whose editionAllows() reads a setting costs nothing per request), and a SecretBox that cannot encrypt turns the
 * key-creating admin actions into a failed result with nothing written.
 */
final class ModuleOffFastPathTest extends RmmTestCase
{
    private string $dir = '';

    protected function makeHarness(): RmmHarness
    {
        $this->dir = TempDir::make();

        return new RmmHarness(null, new InMemoryRmmModuleState(true, $this->dir));
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        TempDir::remove($this->dir);
    }

    /** An edition whose editionAllows() costs one counted statement, like RivetMSP's settings SELECT. */
    private function countingEdition(): object
    {
        $h = $this->h;

        return new class ($this->dir, $h) extends InMemoryRmmModuleState {
            public int $asked = 0;
            public function __construct(?string $dir, private readonly RmmHarness $h)
            {
                parent::__construct(true, $dir);
            }
            public function editionAllows(): bool
            {
                ++$this->asked;
                $this->h->counting->fetchOne('SELECT 1');

                return $this->allows;
            }
        };
    }

    private function moduleWith(object $edition, ?InMemorySecretBox $box = null): RmmModule
    {
        $h = $this->h;

        return new RmmModule($h->counting, $h->clock, $h->tenancy, $h->assets, $h->bridge, $box ?? $h->box, $h->audit, $h->metrics, $edition, ['allow_insecure_http' => true], null, new \RivetCore\Tests\Support\AllowUsersPolicy([1 => true]), new \RivetCore\Webhooks\UrlPolicy(true));
    }

    /** @return array{0:int,1:string} */
    private function call(RmmModule $m, ?string $mode): array
    {
        $api = $m->deviceApi(static fn (): bool => true, true, null, null, $mode);
        $r = $api->handle($this->h->request('POST', 'agent_checkin', '{}'));

        return [$r->status, (string) (json_decode((string) $r->body, true)['code'] ?? '')];
    }

    public function testAValidStateFileAnswersTheSwitchWithoutAskingTheEditionOrTheDatabase(): void
    {
        $this->h->module->settings()->enable();
        $this->h->module->settings()->disable();
        foreach ([null, DeviceApi::DISABLED_COMPAT] as $mode) {
            $edition = $this->countingEdition();
            $m = $this->moduleWith($edition);
            $before = $this->h->counting->statements;
            $r = $this->call($m, $mode);
            if ($mode === null) {
                $this->assertSame([503, 'module_disabled'], $r);
            } else {
                $this->assertNotSame(403, $r[0], 'compat: only the edition kill switch answers 403 here');
            }
            $this->assertSame(0, $edition->asked, 'the edition is not asked while the file is valid');
            if ($mode === null) {
                $this->assertSame($before, $this->h->counting->statements, 'an off module in uniform mode costs zero queries');
            }
        }
    }

    public function testTheEditionIsAskedOnlyWhileTheFileIsUnknownAndTheFileIsRepaired(): void
    {
        $this->h->module->settings()->enable();
        @unlink(\RivetCore\Rmm\RmmStateFile::path($this->dir));
        $edition = $this->countingEdition();
        $this->assertNotSame([503, 'module_disabled'], $this->call($this->moduleWith($edition), null), 'unknown file, enabled install: never off');
        $asked = $edition->asked;
        $this->assertGreaterThan(0, $asked);
        $this->call($this->moduleWith($edition), null);
        $this->assertSame($asked, $edition->asked, 'the second request is answered by the repaired file');
    }

    public function testTheKillSwitchInTheFileAnswersForbiddenInCompatAndUnavailableInUniform(): void
    {
        $this->h->module->settings()->enable();
        $off = new InMemoryRmmModuleState(false, $this->dir);
        $m = $this->moduleWith($off);
        $m->syncState();   // what the edition does after changing its own flag
        $this->assertSame([403, 'forbidden'], $this->call($m, DeviceApi::DISABLED_COMPAT));
        $this->assertSame([503, 'module_disabled'], $this->call($m, null));
    }

    // ------------------------------------------------------------------ SecretBox that cannot encrypt

    private function brokenBox(): InMemorySecretBox
    {
        return new class extends InMemorySecretBox {
            public function encrypt(string $plaintext): string
            {
                throw new \RuntimeException('no encryption key configured');
            }
        };
    }

    public function testEnableWithABoxThatCannotEncryptFailsCleanlyAndWritesNothing(): void
    {
        $m = $this->moduleWith(new InMemoryRmmModuleState(true, $this->dir), $this->brokenBox());
        $res = $m->admin()->enable($this->h->principal());
        $this->assertFalse($res->ok);
        $this->assertSame('secret_box_unavailable', $res->code);
        $this->assertStringContainsString('encryption key', $res->message);
        $this->assertSame(0, (int) $this->h->one('SELECT enabled FROM endpoint_agent_settings WHERE id = 1'));
        $this->assertSame('', (string) $this->h->one('SELECT COALESCE(signing_private_key_enc, "") FROM endpoint_agent_settings WHERE id = 1'));
        $this->assertSame('', (string) $this->h->one('SELECT COALESCE(signing_public_key, "") FROM endpoint_agent_settings WHERE id = 1'));
        $this->assertFalse($m->enabled());
    }

    public function testSavingTheSettingsWithEnabledAndRotatingFailCleanlyToo(): void
    {
        $m = $this->moduleWith(new InMemoryRmmModuleState(true, $this->dir), $this->brokenBox());
        $res = $m->admin()->saveSettings($this->h->principal(), ['enabled' => 1]);
        $this->assertSame([false, 'secret_box_unavailable'], [$res->ok, $res->code]);
        $this->assertSame(0, (int) $this->h->one('SELECT enabled FROM endpoint_agent_settings WHERE id = 1'));

        $res = $m->admin()->rotateSigningKey($this->h->principal());
        $this->assertSame([false, 'secret_box_unavailable'], [$res->ok, $res->code]);
        $this->assertSame('', (string) $this->h->one('SELECT COALESCE(signing_public_key, "") FROM endpoint_agent_settings WHERE id = 1'));
    }

    public function testARotationThatFailsKeepsTheExistingKey(): void
    {
        $this->h->module->settings()->enable();
        $kid = (string) $this->h->one('SELECT signing_key_id FROM endpoint_agent_settings WHERE id = 1');
        $this->assertNotSame('', $kid);
        $m = $this->moduleWith(new InMemoryRmmModuleState(true, $this->dir), $this->brokenBox());
        $this->assertFalse($m->admin()->rotateSigningKey($this->h->principal())->ok);
        $this->assertSame($kid, (string) $this->h->one('SELECT signing_key_id FROM endpoint_agent_settings WHERE id = 1'));
    }

    public function testASaveMeshWithAKeyThatCannotBeSealedChangesNothing(): void
    {
        $m = $this->moduleWith(new InMemoryRmmModuleState(true, $this->dir), $this->brokenBox());
        $res = $m->admin()->saveMesh($this->h->principal(), ['mesh_url' => 'https://mesh.example.com', 'mesh_login_key' => str_repeat('ab', 32)]);
        $this->assertSame([false, 'secret_box_unavailable'], [$res->ok, $res->code]);
        $this->assertSame('', (string) $this->h->one('SELECT COALESCE(mesh_url, "") FROM endpoint_agent_settings WHERE id = 1'));
    }
}
