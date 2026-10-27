<?php

declare(strict_types=1);

namespace RivetCore\Testing;

use PHPUnit\Framework\TestCase;
use RivetCore\Contracts\ClockInterface;

/**
 * Conformance kit for {@see ClockInterface}. Extend it in an edition's test suite and implement {@see self::clock()}.
 *
 * Checks the documented contract: now() is an immutable instant that never goes backwards between calls, matches real
 * time (unless the clock is a test double: override {@see self::isRealTime()}), and carries a valid timezone.
 *
 * @api
 */
abstract class ClockConformanceTestCase extends TestCase
{
    /** The adapter under test. A fresh instance is fine; the case calls it repeatedly. */
    abstract protected function clock(): ClockInterface;

    /** Return false for a frozen / fake clock: the "close to real time" check is then skipped. */
    protected function isRealTime(): bool
    {
        return true;
    }

    /** How far from the system clock a real clock may be, in seconds. */
    protected function maxSkewSeconds(): int
    {
        return 5;
    }

    /** The IANA timezone name the clock is documented to report (e.g. the app timezone), or null to not check it. */
    protected function expectedTimezone(): ?string
    {
        return null;
    }

    public function testNowIsAnImmutableInstantUnaffectedByLaterCalls(): void
    {
        $clock = $this->clock();
        $a = $clock->now();
        $this->assertInstanceOf(\DateTimeImmutable::class, $a);
        $ts = $a->format('U.u');
        $b = $a->modify('+1 day');
        $this->assertNotSame($a, $b, 'modify() must return a new instance');
        $clock->now();
        $this->assertSame($ts, $a->format('U.u'), 'a value returned earlier must not change');
        $this->assertGreaterThan(strtotime('2020-01-01'), $clock->now()->getTimestamp(), 'now() must not be a mutated or epoch-based value');
    }

    public function testSuccessiveCallsNeverGoBackwards(): void
    {
        $clock = $this->clock();
        $previous = $clock->now();
        for ($i = 0; $i < 200; $i++) {
            $now = $clock->now();
            $this->assertGreaterThanOrEqual($previous, $now, 'now() went backwards on call ' . $i);
            $previous = $now;
        }
    }

    public function testRealClockIsCloseToSystemTime(): void
    {
        if (!$this->isRealTime()) {
            $this->markTestSkipped('Clock declared as not real time (isRealTime() === false).');
        }
        $skew = abs($this->clock()->now()->getTimestamp() - time());
        $this->assertLessThanOrEqual($this->maxSkewSeconds(), $skew, "now() is {$skew}s away from the system clock: a wall-clock string was probably built in the wrong timezone");
    }

    public function testTimezoneIsValidAndConsistent(): void
    {
        $now = $this->clock()->now();
        $tz = $now->getTimezone();
        $this->assertNotSame('', $tz->getName());
        $this->assertLessThanOrEqual(14 * 3600, abs($now->getOffset()));
        // Rendering the wall clock in its own timezone and parsing it back must give the same instant.
        $round = new \DateTimeImmutable($now->format('Y-m-d H:i:s'), $tz);
        $this->assertSame($now->getTimestamp(), $round->getTimestamp());
        $expected = $this->expectedTimezone();
        if ($expected !== null) {
            $this->assertSame($expected, $tz->getName(), 'now() is not reported in the expected timezone');
        }
    }
}
