<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Alerting\MaintenanceScheduleGate;
use RivetCore\Rmm\Contracts\ScheduleGateInterface;
use RivetCore\Testing\ScheduleGateConformanceTestCase;
use RivetCore\Tests\Support\RmmHarness;

/** The maintenance windows' schedule gate passes the conformance kit of Phase 2's ScheduleGateInterface (on the scratch database). */
final class MaintenanceGateKitTest extends ScheduleGateConformanceTestCase
{
    private ?RmmHarness $h = null;

    protected function setUp(): void
    {
        if (!getenv('RIVETCORE_TEST_DB_NAME') || !str_contains((string) getenv('RIVETCORE_TEST_DB_NAME'), 'scratch')) {
            $this->markTestSkipped('A scratch database (name containing "scratch") is required.');
        }
        $this->h = new RmmHarness();
    }

    protected function tearDown(): void
    {
        RmmHarness::closeAll();
    }

    protected function gate(): ScheduleGateInterface
    {
        $h = $this->h;
        $this->assertNotNull($h);
        $h->enable();
        $h->module->settings()->update(['features_json' => ['monitoring' => true, 'alerting' => true]]);

        return new MaintenanceScheduleGate($h->module->maintenance(), $h->module->settings());
    }
}
