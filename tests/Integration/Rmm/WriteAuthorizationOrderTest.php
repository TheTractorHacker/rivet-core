<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Contracts\AccessPolicyInterface;
use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Tests\Support\RmmHarness;
use RivetCore\Tests\Support\RmmTestCase;

/**
 * Red team 2026-11-21 CORE-API-7: on these write routes the body used to be read and validated before the ability was checked, so a view-only caller got 400
 * or 422 (and learned field names and body validity) instead of 403. Now the ability comes first: a viewer gets the same 403 whatever the body (valid,
 * schema-invalid or not JSON at all), and an authorized caller gets exactly the statuses it got before (the Android client relies on them).
 */
final class WriteAuthorizationOrderTest extends RmmTestCase
{
    private const ADMIN = 1;
    private const VIEWER = 12;
    private int $dev = 0;
    private int $other = 0;

    protected function makeHarness(): RmmHarness
    {
        $policy = new class implements AccessPolicyInterface {
            public function can(?int $userId, string $ability, ?string $subjectType = null, string|int|null $subjectId = null, array $context = []): bool
            {
                return match ($userId) {
                    1 => in_array($ability, RmmAbility::all(), true),
                    12 => str_ends_with($ability, '.view'),
                    default => false,
                };
            }
        };

        return new RmmHarness(policy: $policy);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->h->enable();
        $this->h->module->settings()->update(['features_json' => array_fill_keys(RmmSettings::FEATURES, true)]);
        foreach (['dev' => 'SN-WAO-1', 'other' => 'SN-WAO-2'] as $prop => $serial) {
            $this->h->asset(['name' => $serial, 'serial' => $serial]);
            [$c, , $j] = $this->h->enroll($this->h->token(), RmmHarness::device(['serial' => $serial]));
            $this->assertSame(201, $c);
            $this->$prop = (int) $j['device_id'];
        }
    }

    /** @return list<array{0:string,1:string,2:array<string,mixed>}> method, path ({d} = the device), a body the route accepts as valid */
    private function routes(): array
    {
        return [
            ['PUT', 'changes/settings', ['features' => ['change_monitor' => true]]],
            ['PATCH', 'changes/settings', ['features' => ['change_monitor' => true]]],
            ['POST', 'changes/rules/recommended', ['client_id' => $this->h->clientA]],
            ['PUT', 'software_params/1', ['name' => 'LICENSE', 'value' => 'x']],
            ['DELETE', 'software_params/1', ['name' => 'LICENSE']],
            ['PATCH', 'software_packages/1/versions/1', ['retired' => true]],
            ['POST', 'mesh/approve', ['device_ids' => [$this->dev]]],
            ['PUT', 'hypervisor/vms/1', ['ignored' => true]],
            ['PATCH', 'hypervisor/settings', ['features' => ['hypervisor' => true]]],
            ['POST', 'patches/approve', ['update_ids' => ['KB5034441'], 'device_id' => $this->dev]],
            ['POST', 'patches/decline', ['update_ids' => ['KB5034441'], 'device_id' => $this->dev]],
            ['PUT', '{d}/parent', ['parent_device_id' => $this->other]],
            ['POST', '{d}/mesh/install', ['force' => true]],
            ['POST', '{d}/mesh/uninstall', ['confirm' => true]],
            ['POST', '{d}/tags', ['tag' => 'matrix-tag']],
            ['PUT', '{d}/hypervisor', ['ignored' => true]],
        ];
    }

    /** @return list<string> */
    private function seg(string $path): array
    {
        return explode('/', str_replace('{d}', (string) $this->dev, $path));
    }

    public function testAViewerGets403WithAValidAnInvalidAndANonJsonBody(): void
    {
        $viewer = $this->h->principal(self::VIEWER, 'Viewer');
        foreach ($this->routes() as [$m, $path, $valid]) {
            $answers = [];
            foreach (['valid' => $valid, 'invalid' => ['bogus_field' => [1, 2]], 'not json' => 'not json at all', 'empty' => null] as $kind => $body) {
                [$c, $j] = $this->h->tech($m, $this->seg($path), $viewer, $body);
                $this->assertSame(403, $c, "$m $path with a $kind body: " . json_encode($j));
                $this->assertSame('forbidden', $j['code'] ?? null, "$m $path ($kind)");
                $answers[] = $j;
            }
            $this->assertCount(1, array_unique(array_map('json_encode', $answers)), "$m $path: the same answer whatever the body");
        }
    }

    /** The control: an authorized caller still gets the validation answers (400 for a body that is not JSON, 422 for a schema-invalid one), as before. */
    public function testAnAuthorizedCallerStillGetsTheValidationAnswers(): void
    {
        $admin = $this->h->principal(self::ADMIN);
        foreach ($this->routes() as [$m, $path]) {
            [$c] = $this->h->tech($m, $this->seg($path), $admin, 'not json at all');
            $this->assertSame(400, $c, "$m $path with a body that is not JSON");
            [$c, $j] = $this->h->tech($m, $this->seg($path), $admin, ['bogus_field' => [1, 2]]);
            $this->assertContains($c, [409, 422], "$m $path with a schema-invalid body: " . json_encode($j));
        }
    }

    /** CORE-API-7: patches/decline (and approve) check the ability before they read or look up an update id, also when called directly (edition web handlers). */
    public function testPatchDecisionsAuthorizeBeforeTheUpdateIdIsLookedUp(): void
    {
        $viewer = $this->h->principal(self::VIEWER, 'Viewer');
        $pa = $this->h->module->patchActions();
        foreach ([['update_ids' => ['KB123456']], ['update_ids' => 'nope'], [], ['update_ids' => ['KB5034441'], 'device_id' => $this->dev]] as $in) {
            $before = $this->h->counting->statements;
            $r = $pa->decline($viewer, $in);
            $this->assertSame(403, $r->http, json_encode($in) . ': ' . $r->message);
            $this->assertSame($before, $this->h->counting->statements, json_encode($in) . ': refused without a single query (no update was looked up)');
            $this->assertSame(403, $pa->approve($viewer, $in)->http);
        }
    }
}
