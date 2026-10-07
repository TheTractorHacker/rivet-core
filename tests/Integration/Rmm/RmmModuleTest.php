<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Testing\InMemoryRmmModuleState;
use RivetCore\Tests\Support\RmmHarness;
use RivetCore\Tests\Support\RmmTestCase;

/** The composition root: lazy shared services, the effective on/off state, and the legacy versus module-state wire behaviour. */
final class RmmModuleTest extends RmmTestCase
{
    public function testServicesAreBuiltOnceAndShared(): void
    {
        $m = $this->h->module;
        $this->assertSame($m->settings(), $m->settings());
        $this->assertSame($m->sql(), $m->sql());
        $this->assertSame($m->devices(), $m->devices());
        $this->assertSame($m->jobs(), $m->jobs());
        $this->assertSame($m->updates(), $m->updates());
        $this->assertSame($m->enrollment(), $m->enrollment());
        $this->assertSame($m->jobs()->registry()->types(), ['powershell', 'reboot', 'collect']);
    }

    public function testEnabledIsTheEditionKillSwitchAndTheMasterSwitch(): void
    {
        $this->assertFalse($this->h->module->enabled(), 'off by default');
        $this->h->module->settings()->enable();
        $this->assertTrue($this->h->module->enabled());
        $h = new RmmHarness(null, new InMemoryRmmModuleState(false));
        $h->module->settings()->enable();
        $this->assertFalse($h->module->enabled(), 'the edition may refuse even when the master is on');
    }

    public function testTheDefaultAuditAndSinkAreNoOps(): void
    {
        $m = new \RivetCore\Rmm\RmmModule($this->h->db, $this->h->clock, $this->h->tenancy, $this->h->assets, $this->h->bridge, $this->h->box);
        $m->settings()->enable();
        $tok = $m->enrollment()->createToken($this->h->clientA, 0, 'stable', 1, 5, 'x', 1)['token'];
        $api = $m->deviceApi(static fn (): bool => true);
        $req = $this->h->request('POST', 'agent_enroll', (string) json_encode(['enrollment_token' => $tok, 'device' => $this->h::device()]));
        $this->assertSame(201, $api->handle($req)->status, 'a module built with only the four required adapters works');
    }
}
