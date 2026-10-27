<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use RivetCore\Rmm\Contracts\RmmEventsInterface;
use RivetCore\Rmm\Support\NullRmmEvents;
use RivetCore\Testing\RmmEventsConformanceTestCase;

/** Core's own null bus passes the kit (the observable checks are skipped: it keeps nothing). */
final class NullRmmEventsKitTest extends RmmEventsConformanceTestCase
{
    protected function events(): RmmEventsInterface
    {
        return new NullRmmEvents();
    }
}
