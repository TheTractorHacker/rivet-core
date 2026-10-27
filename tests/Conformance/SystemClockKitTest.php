<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use RivetCore\Contracts\ClockInterface;
use RivetCore\Support\SystemClock;
use RivetCore\Testing\ClockConformanceTestCase;

/** Core's own SystemClock. */
final class SystemClockKitTest extends ClockConformanceTestCase
{
    protected function clock(): ClockInterface
    {
        return new SystemClock();
    }

    protected function expectedTimezone(): ?string
    {
        return date_default_timezone_get();
    }
}
