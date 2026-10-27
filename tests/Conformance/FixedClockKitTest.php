<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use RivetCore\Contracts\ClockInterface;
use RivetCore\Testing\ClockConformanceTestCase;
use RivetCore\Tests\Support\FixedClock;

/** A frozen test clock passes everything that applies to it; it opts out of the real-time check. */
final class FixedClockKitTest extends ClockConformanceTestCase
{
    protected function clock(): ClockInterface
    {
        return new FixedClock();
    }

    protected function isRealTime(): bool
    {
        return false;
    }
}
