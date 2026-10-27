<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm\Metrics;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Metrics\ExternalMetricSink;

final class OutboxBackoffTest extends TestCase
{
    public function testTheDelayDoublesFromFiveSecondsAndStopsAtFifteenMinutes(): void
    {
        $this->assertSame([5, 10, 20, 40, 80, 160, 320, 640, 900, 900], array_map(static fn (int $a): int => ExternalMetricSink::backoffSeconds($a), range(0, 9)));
        $this->assertSame(900, ExternalMetricSink::backoffSeconds(1000));
        $this->assertSame(5, ExternalMetricSink::backoffSeconds(-3));
    }

    public function testJitterIsPlusOrMinusTwentyPercentAndClamped(): void
    {
        $this->assertSame(4, ExternalMetricSink::backoffSeconds(0, -0.2));
        $this->assertSame(6, ExternalMetricSink::backoffSeconds(0, 0.2));
        $this->assertSame(720, ExternalMetricSink::backoffSeconds(20, -0.2));
        $this->assertSame(1080, ExternalMetricSink::backoffSeconds(20, 0.2));
        $this->assertSame(ExternalMetricSink::backoffSeconds(3, 0.2), ExternalMetricSink::backoffSeconds(3, 5.0), 'jitter is clamped');
        $this->assertGreaterThanOrEqual(1, ExternalMetricSink::backoffSeconds(0, -1.0));
    }
}
