<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm\Alerting;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Alerting\FlapDetector;

final class FlapDetectorTest extends TestCase
{
    public function testNormalize(): void
    {
        [$f, $e] = FlapDetector::normalize([]);
        $this->assertNull($e);
        $this->assertSame(['window' => 20, 'high_pct' => 50, 'low_pct' => 25], $f);
        foreach ([['window' => 5], ['window' => 51], ['window' => 'x'], ['high_pct' => 20, 'low_pct' => 30], ['low_pct' => 0], ['high_pct' => 101], ['nope' => 1], 'x', [1]] as $bad) {
            [$f, $e] = FlapDetector::normalize($bad);
            $this->assertNull($f, json_encode($bad));
            $this->assertNotNull($e);
        }
    }

    /** @param list<int> $bits oldest first */
    private function feed(array $bits, array $cfg = ['window' => 10, 'high_pct' => 50, 'low_pct' => 25]): array
    {
        $s = ['bits' => 0, 'n' => 0, 'flapping' => false];
        $r = ['pct' => 0] + $s;
        foreach ($bits as $b) {
            $r = FlapDetector::record($s, $b === 1, $cfg);
            $s = ['bits' => $r['bits'], 'n' => $r['n'], 'flapping' => $r['flapping']];
        }

        return $r;
    }

    public function testSteadyBadNeverFlaps(): void
    {
        $r = $this->feed(array_fill(0, 30, 1));
        $this->assertFalse($r['flapping']);
        $this->assertSame(0, $r['pct']);
    }

    public function testAlternatingFlapsOnceHalfTheWindowIsSeen(): void
    {
        $r = $this->feed([1, 0, 1, 0]);
        $this->assertFalse($r['flapping'], 'four samples of a ten sample window: too early to judge');
        $r = $this->feed([1, 0, 1, 0, 1, 0]);
        $this->assertTrue($r['flapping']);
        $this->assertSame(100, $r['pct']);
    }

    public function testFlappingEndsOnlyBelowTheLowMark(): void
    {
        $bits = [1, 0, 1, 0, 1, 0, 1, 0, 1, 0];
        $this->assertTrue($this->feed($bits)['flapping']);
        // a few steady samples: changes drop but stay between the marks (hysteresis), still flapping
        $r = $this->feed(array_merge($bits, [0, 0, 0]));
        $this->assertTrue($r['flapping'], 'pct ' . $r['pct']);
        $r = $this->feed(array_merge($bits, array_fill(0, 10, 0)));
        $this->assertFalse($r['flapping']);
    }

    public function testPercentCountsAdjacentChanges(): void
    {
        $this->assertSame(0, FlapDetector::percent(0, 1));
        $this->assertSame(100, FlapDetector::percent(0b101010, 6));
        $this->assertSame(20, FlapDetector::percent(0b000011, 6), 'one change in five pairs');
    }

    public function testTheBitsStayInsideTheWindow(): void
    {
        $r = $this->feed(array_fill(0, 200, 1), ['window' => 50, 'high_pct' => 60, 'low_pct' => 10]);
        $this->assertLessThan(1 << 50, $r['bits']);
        $this->assertSame(50, $r['n']);
    }
}
