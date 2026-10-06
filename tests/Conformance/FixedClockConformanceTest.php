<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use RivetCore\Contracts\ClockInterface;
use RivetCore\Tests\Support\FixedClock;

/** A fixed test clock satisfies the kit once it declares it is not a wall clock. */
final class FixedClockConformanceTest extends ClockConformanceTestCase
{
    protected function clock(): ClockInterface
    {
        return new FixedClock();
    }

    protected function isWallClock(): bool
    {
        return false;
    }
}
