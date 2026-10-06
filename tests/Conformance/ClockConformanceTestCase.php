<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use PHPUnit\Framework\TestCase;
use RivetCore\Contracts\ClockInterface;

/**
 * Behaviour every ClockInterface adapter must have. A production clock follows wall time; a test clock is allowed to be
 * fixed, in which case override isWallClock() to return false.
 */
abstract class ClockConformanceTestCase extends TestCase
{
    abstract protected function clock(): ClockInterface;

    protected function isWallClock(): bool
    {
        return true;
    }

    public function testNowIsAnImmutableDateTime(): void
    {
        $this->assertInstanceOf(\DateTimeImmutable::class, $this->clock()->now());
    }

    public function testTimeNeverGoesBackwards(): void
    {
        $c = $this->clock();
        $a = $c->now();
        $b = $c->now();
        $this->assertGreaterThanOrEqual($a->getTimestamp(), $b->getTimestamp());
    }

    public function testAWallClockAgreesWithTheSystemClock(): void
    {
        if (!$this->isWallClock()) {
            $this->markTestSkipped('fixed test clock');
        }
        $this->assertEqualsWithDelta(time(), $this->clock()->now()->getTimestamp(), 5, 'the clock is more than 5 seconds off the system clock');
    }
}
