<?php

declare(strict_types=1);

namespace RivetCore\Testing;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Contracts\ExternalMetricStoreInterface;
use RivetCore\Rmm\Metrics\ExternalStoreException;

/**
 * Conformance kit for {@see ExternalMetricStoreInterface}. The adapter under test supplies {@see store()}; a store whose writes are
 * asynchronous overrides {@see settle()} to wait until written points can be read; {@see breakStore()} lets the case make the store
 * fail (return null to skip those rules).
 *
 * Checks: an empty write never throws; written points read back through series() (samples, min, max, mean per step, oldest first,
 * bounded by the window) and peak() (min, max, mean, samples, peak_at, window start); writing the same batch twice changes nothing;
 * an unknown asset has no data; instance null and '' are one series and two instances are two; hostile keys and instances never
 * throw anything but ExternalStoreException, never change a query and never leak another series' data; ping() is a bool that is
 * false for a broken store and never throws; a failing write is an ExternalStoreException.
 *
 * Points use whole seconds that are distinct per series (a store keeps one value per series and second).
 *
 * @api
 */
abstract class ExternalMetricStoreConformanceTestCase extends TestCase
{
    abstract protected function store(): ExternalMetricStoreInterface;

    /** Wait until everything written so far can be read. Default: nothing to wait for. */
    protected function settle(): void
    {
    }

    /**
     * A callable that makes the store fail every call (it may return a callable that repairs it), or null when the adapter cannot be broken.
     */
    protected function breakStore(): ?callable
    {
        return null;
    }

    private function asset(): int
    {
        return random_int(1_000_000, 2_000_000_000);
    }

    private function base(): int
    {
        return intdiv(time(), 3600) * 3600 - 6 * 3600;
    }

    private function at(int $ts): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@' . $ts);
    }

    /** @return array{asset_id:int, key:string, instance:?string, value:int|float, at:\DateTimeImmutable, label:?string} */
    private function point(int $asset, string $key, int|float $value, int $ts, ?string $instance = null): array
    {
        return ['asset_id' => $asset, 'key' => $key, 'instance' => $instance, 'value' => $value, 'at' => $this->at($ts), 'label' => null];
    }

    /**
     * @param list<array{asset_id:int, key:string, instance:?string, value:int|float, at:\DateTimeImmutable, label:?string}> $points
     */
    private function write(array $points): void
    {
        $this->store()->write($points);
        $this->settle();
    }

    /** @return list<array{at:\DateTimeImmutable, min:float, max:float, avg:float, samples:int}> */
    private function hours(int $asset, string $key, ?string $instance, int $from, int $to): array
    {
        return $this->store()->series($asset, $key, $instance, $this->at($from), $this->at($to), 3600);
    }

    public function testEmptyWriteNeverThrows(): void
    {
        $this->store()->write([]);
        $this->addToAssertionCount(1);
    }

    public function testWrittenPointsReadBackAsSeries(): void
    {
        $a = $this->asset();
        $b = $this->base();
        $this->write([
            $this->point($a, 'cpu.utilization', 10, $b + 60), $this->point($a, 'cpu.utilization', 20, $b + 120), $this->point($a, 'cpu.utilization', 60, $b + 180),
            $this->point($a, 'cpu.utilization', 90, $b + 3600 + 5), $this->point($a, 'cpu.utilization', 30, $b + 3600 + 6),
            $this->point($a, 'cpu.utilization', 50, $b + 7200 + 9),
        ]);
        $s = $this->hours($a, 'cpu.utilization', null, $b, $b + 7200 + 3599);
        $this->assertCount(3, $s);
        $this->assertSame([$b, $b + 3600, $b + 7200], array_map(static fn (array $p): int => $p['at']->getTimestamp(), $s), 'one point per step that has data, oldest first, at the start of the step');
        $this->assertSame([3, 2, 1], array_column($s, 'samples'));
        $this->assertEqualsWithDelta([10, 30, 50], array_column($s, 'min'), 1e-9);
        $this->assertEqualsWithDelta([60, 90, 50], array_column($s, 'max'), 1e-9);
        $this->assertEqualsWithDelta([30, 60, 50], array_column($s, 'avg'), 1e-9);
    }

    public function testSeriesIsBoundedByTheWindow(): void
    {
        $a = $this->asset();
        $b = $this->base();
        $this->write([$this->point($a, 'cpu.utilization', 10, $b + 60), $this->point($a, 'cpu.utilization', 60, $b + 3600 + 60), $this->point($a, 'cpu.utilization', 80, $b + 7200 + 60)]);
        $s = $this->hours($a, 'cpu.utilization', null, $b + 3600 + 30, $b + 3600 + 31);
        $this->assertCount(1, $s, 'the step holding the window start is included, neighbours are not');
        $this->assertEqualsWithDelta(60, $s[0]['max'], 1e-9);
        $this->assertSame([], $this->hours($a, 'cpu.utilization', null, $b + 4 * 3600, $b + 5 * 3600));
        $this->assertSame([], $this->hours($a, 'cpu.utilization', null, $b + 3600, $b), 'a window that ends before it starts is empty');
    }

    public function testPeakIsTheMinMaxAndMeanOfTheWindow(): void
    {
        $a = $this->asset();
        $b = $this->base();
        $this->write([
            $this->point($a, 'cpu.utilization', 10, $b + 60), $this->point($a, 'cpu.utilization', 20, $b + 120),
            $this->point($a, 'cpu.utilization', 90, $b + 3600 + 5), $this->point($a, 'cpu.utilization', 30, $b + 3600 + 6),
            $this->point($a, 'cpu.utilization', 50, $b + 7200 + 9),
        ]);
        $p = $this->store()->peak($a, 'cpu.utilization', null, $this->at($b));
        $this->assertNotNull($p);
        $this->assertEqualsWithDelta(10, $p['min'], 1e-9);
        $this->assertEqualsWithDelta(90, $p['max'], 1e-9);
        $this->assertEqualsWithDelta(40, $p['avg'], 1e-9);
        $this->assertSame(5, $p['samples']);
        $this->assertSame($b + 3600, $p['peak_at']->getTimestamp(), 'peak_at is the start of the step that held the maximum');
    }

    public function testPeakExcludesDataBeforeTheWindow(): void
    {
        $a = $this->asset();
        $b = $this->base();
        $this->write([$this->point($a, 'cpu.utilization', 99, $b + 60), $this->point($a, 'cpu.utilization', 30, $b + 3600 + 60), $this->point($a, 'cpu.utilization', 50, $b + 7200 + 60)]);
        $p = $this->store()->peak($a, 'cpu.utilization', null, $this->at($b + 3600 + 1800));
        $this->assertNotNull($p);
        $this->assertEqualsWithDelta(50, $p['max'], 1e-9, 'the window starts in the hour of the 30 (hour granular): 99 is outside, 30 and 50 are inside');
        $this->assertEqualsWithDelta(30, $p['min'], 1e-9);
        $this->assertSame(2, $p['samples']);
        $this->assertSame($b + 7200, $p['peak_at']->getTimestamp());
    }

    public function testWritingTheSameBatchTwiceChangesNothing(): void
    {
        $a = $this->asset();
        $b = $this->base();
        $batch = [$this->point($a, 'memory.utilization', 40, $b + 10), $this->point($a, 'memory.utilization', 60, $b + 20), $this->point($a, 'memory.utilization', 80, $b + 3600 + 5)];
        $this->write($batch);
        $series = $this->hours($a, 'memory.utilization', null, $b, $b + 3600 + 100);
        $peak = $this->store()->peak($a, 'memory.utilization', null, $this->at($b));
        $this->write($batch);
        $this->write($batch);
        $this->assertEquals($series, $this->hours($a, 'memory.utilization', null, $b, $b + 3600 + 100), 'a re-delivered batch must not change count, min, max or mean');
        $this->assertEquals($peak, $this->store()->peak($a, 'memory.utilization', null, $this->at($b)));
        $this->assertSame(3, array_sum(array_column($series, 'samples')));
    }

    public function testUnknownAssetKeyAndInstanceHaveNoData(): void
    {
        $a = $this->asset();
        $b = $this->base();
        $this->write([$this->point($a, 'cpu.utilization', 10, $b + 60)]);
        $other = $this->asset();
        $this->assertSame([], $this->hours($other, 'cpu.utilization', null, $b, $b + 3599));
        $this->assertNull($this->store()->peak($other, 'cpu.utilization', null, $this->at($b)));
        $this->assertSame([], $this->hours($a, 'memory.utilization', null, $b, $b + 3599));
        $this->assertNull($this->store()->peak($a, 'memory.utilization', null, $this->at($b)));
        $this->assertSame([], $this->hours($a, 'cpu.utilization', 'nope', $b, $b + 3599));
        $this->assertNull($this->store()->peak($a, 'cpu.utilization', 'nope', $this->at($b)));
        $this->assertSame([], $this->hours($a, 'cpu.utilization', null, $b - 7200, $b - 3601), 'no data in that window');
    }

    public function testNullAndEmptyInstanceAreTheSameSeries(): void
    {
        $a = $this->asset();
        $b = $this->base();
        $this->write([$this->point($a, 'cpu.utilization', 10, $b + 10, null), $this->point($a, 'cpu.utilization', 30, $b + 20, '')]);
        foreach ([null, ''] as $instance) {
            $s = $this->hours($a, 'cpu.utilization', $instance, $b, $b + 3599);
            $this->assertCount(1, $s);
            $this->assertSame(2, $s[0]['samples'], 'null and the empty string are one series');
            $this->assertEqualsWithDelta(20, $s[0]['avg'], 1e-9);
        }
    }

    public function testInstancesAreSeparateSeries(): void
    {
        $a = $this->asset();
        $b = $this->base();
        $this->write([$this->point($a, 'disk.utilization', 40, $b + 10, 'C:'), $this->point($a, 'disk.utilization', 70, $b + 20, 'D:'), $this->point($a, 'disk.utilization', 5, $b + 30, null)]);
        $c = $this->hours($a, 'disk.utilization', 'C:', $b, $b + 3599);
        $d = $this->hours($a, 'disk.utilization', 'D:', $b, $b + 3599);
        $n = $this->hours($a, 'disk.utilization', null, $b, $b + 3599);
        $this->assertSame([1, 1, 1], [$c[0]['samples'] ?? 0, $d[0]['samples'] ?? 0, $n[0]['samples'] ?? 0]);
        $this->assertEqualsWithDelta([40, 70, 5], [$c[0]['max'], $d[0]['max'], $n[0]['max']], 1e-9);
        $this->assertEqualsWithDelta(70, $this->store()->peak($a, 'disk.utilization', 'D:', $this->at($b))['max'] ?? -1, 1e-9);
        $this->assertSame([], $this->hours($a, 'disk.utilization', 'c:', $b, $b + 3599), 'instances are case sensitive');
    }

    public function testInstancesWithSpecialCharactersRoundTrip(): void
    {
        $a = $this->asset();
        $b = $this->base();
        $names = ['C:\\', 'Local Disk, D', 'a=b', 'quote"d', 'back\\slash', 'ünïcode', 'x y,z=1 w'];
        $points = [];
        foreach ($names as $i => $name) {
            $points[] = $this->point($a, 'disk.utilization', 10 + $i, $b + 10 + $i, $name);
        }
        $this->write($points);
        foreach ($names as $i => $name) {
            $s = $this->hours($a, 'disk.utilization', $name, $b, $b + 3599);
            $this->assertCount(1, $s, "instance '$name' reads back as its own series");
            $this->assertEqualsWithDelta(10 + $i, $s[0]['max'], 1e-9);
            $this->assertSame(1, $s[0]['samples']);
        }
    }

    public function testHostileStringsNeverLeakNorBreakAQuery(): void
    {
        $a = $this->asset();
        $other = $this->asset();
        $b = $this->base();
        $this->write([$this->point($a, 'cpu.utilization', 50, $b + 10), $this->point($other, 'cpu.utilization', 7, $b + 10), $this->point($a, 'cpu.utilization', 8, $b + 10, 'real')]);
        $hostile = ['"} or vector(1) #', '} or vector(1)', "x\"}[1h]) or vector(1) #", '\\"} or rmm_value{asset=~".*', "line\nbreak", "tab\there", '{}', "a\0b", str_repeat('x', 5000), '` ; DROP TABLE x; --', "'; DELETE FROM metrics; --", '\\', '\\\\"'];
        foreach ($hostile as $h) {
            try {
                $this->write([$this->point($a, 'cpu.utilization', 99, $b + 10, $h)]);
            } catch (ExternalStoreException) {
                // refusing is allowed
            }
            try {
                $s = $this->hours($a, 'cpu.utilization', $h, $b, $b + 3599);
                foreach ($s as $p) {
                    $this->assertEqualsWithDelta($p['min'], $p['max'], 1e-9, 'a hostile instance must not pick up another series');
                    $this->assertNotEqualsWithDelta(50.0, $p['max'], 1e-9, 'data of the unnamed instance leaked into a hostile one');
                    $this->assertNotEqualsWithDelta(7.0, $p['max'], 1e-9, 'data of another asset leaked');
                    $this->assertNotEqualsWithDelta(8.0, $p['max'], 1e-9, 'data of another instance leaked');
                }
                $pk = $this->store()->peak($a, 'cpu.utilization', $h, $this->at($b));
                if ($pk !== null) {
                    $this->assertEqualsWithDelta($pk['min'], $pk['max'], 1e-9);
                    $this->assertNotEqualsWithDelta(50.0, $pk['max'], 1e-9);
                }
            } catch (ExternalStoreException) {
                // refusing is allowed
            }
        }
        foreach (['cpu.utilization"} or vector(1) #', "cpu\nutil", '', str_repeat('k', 200), 'CPU.Utilization', '*', "cpu.utilization\0"] as $key) {
            try {
                $this->write([$this->point($a, $key, 99, $b + 10)]);
                foreach ($this->hours($a, $key, null, $b, $b + 3599) as $p) {
                    $this->assertNotEqualsWithDelta(50.0, $p['max'], 1e-9, 'a hostile key must not select the data of another series');
                }
            } catch (ExternalStoreException) {
                // refusing is allowed
            }
        }
        $normal = $this->hours($a, 'cpu.utilization', null, $b, $b + 3599);
        $this->assertCount(1, $normal);
        $this->assertSame(1, $normal[0]['samples'], 'the normal series is unchanged by anything the hostile writes did');
        $this->assertEqualsWithDelta(50, $normal[0]['max'], 1e-9);
        $this->assertEqualsWithDelta(7, $this->hours($other, 'cpu.utilization', null, $b, $b + 3599)[0]['max'] ?? -1, 1e-9);
    }

    public function testPingIsABoolAndNeverThrows(): void
    {
        $this->assertTrue($this->store()->ping(), 'a healthy store answers');
        $break = $this->breakStore();
        if ($break === null) {
            return;
        }
        $repair = $break();
        try {
            $this->assertFalse($this->store()->ping(), 'a broken store does not answer');
        } finally {
            if (is_callable($repair)) {
                $repair();
            }
        }
    }

    public function testAFailingWriteIsAnExternalStoreException(): void
    {
        $break = $this->breakStore();
        if ($break === null) {
            $this->markTestSkipped('This adapter cannot be made to fail.');
        }
        $repair = $break();
        try {
            $thrown = null;
            try {
                $this->store()->write([$this->point($this->asset(), 'cpu.utilization', 1, $this->base() + 10)]);
            } catch (\Throwable $e) {
                $thrown = $e;
            }
            $this->assertInstanceOf(ExternalStoreException::class, $thrown, 'a failed write must throw ExternalStoreException, not succeed silently or throw something else');
        } finally {
            if (is_callable($repair)) {
                $repair();
            }
        }
    }

    public function testAFailingReadIsAnExternalStoreException(): void
    {
        $break = $this->breakStore();
        if ($break === null) {
            $this->markTestSkipped('This adapter cannot be made to fail.');
        }
        $repair = $break();
        try {
            foreach ([
                fn () => $this->store()->series($this->asset(), 'cpu.utilization', null, $this->at($this->base()), $this->at($this->base() + 3599), 3600),
                fn () => $this->store()->peak($this->asset(), 'cpu.utilization', null, $this->at($this->base())),
            ] as $read) {
                try {
                    $read();
                    $this->fail('a read from a broken store must throw ExternalStoreException');
                } catch (ExternalStoreException) {
                    $this->addToAssertionCount(1);
                }
            }
        } finally {
            if (is_callable($repair)) {
                $repair();
            }
        }
    }
}
