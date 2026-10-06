<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use RivetCore\Contracts\ClockInterface;
use RivetCore\Testing\ClockConformanceTestCase;
use RivetCore\Tests\Conformance\Reference\FlawedClock;

/** The kit against a correct real-time clock (and the harness target for the clock mutants). */
final class ClockKitTest extends ClockConformanceTestCase
{
    use Flaw;

    protected function clock(): ClockInterface
    {
        return new FlawedClock(self::$flaw);
    }

    protected function expectedTimezone(): ?string
    {
        return date_default_timezone_get();
    }
}
