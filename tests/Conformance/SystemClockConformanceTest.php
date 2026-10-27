<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use RivetCore\Contracts\ClockInterface;
use RivetCore\Support\SystemClock;

final class SystemClockConformanceTest extends ClockConformanceTestCase
{
    protected function clock(): ClockInterface
    {
        return new SystemClock();
    }
}
