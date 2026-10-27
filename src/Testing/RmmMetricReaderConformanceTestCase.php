<?php

declare(strict_types=1);

namespace RivetCore\Testing;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Contracts\RmmMetricReaderInterface;
use RivetCore\Rmm\Contracts\RmmMetricSinkInterface;

/**
 * Conformance kit for {@see RmmMetricReaderInterface}: the answers a metric sink that keeps history must give. The case supplies the
 * sink-and-reader object (one object that implements both contracts).
 *
 * Checks: the newest reading wins whatever the order of arrival; keys filter; instances are separate and a null instance reads back as
 * null; peak returns the minimum, maximum and mean of the window and excludes hours before it; an unknown asset or key is empty/null;
 * series is oldest first with one point per hour that has data; out-of-range utilization never shows up.
 *
 * @api
 */
abstract class RmmMetricReaderConformanceTestCase extends TestCase
{
    /** @return RmmMetricSinkInterface&RmmMetricReaderInterface */
    abstract protected function store(): RmmMetricSinkInterface&RmmMetricReaderInterface;

    private function asset(): int
    {
        return random_int(1_000_000, 2_000_000_000);
    }

    private function at(int $secondsAgo): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@' . (time() - $secondsAgo), new \DateTimeZone('UTC'));
    }

    /** @return array{asset_id:int, key:string, instance:?string, value:int|float, at:\DateTimeImmutable, label:?string} */
    private function sample(int $asset, string $key, int|float $value, \DateTimeImmutable $at, ?string $instance = null): array
    {
        return ['asset_id' => $asset, 'key' => $key, 'instance' => $instance, 'value' => $value, 'at' => $at, 'label' => $instance];
    }

    public function testTheNewestReadingWinsWhateverTheOrderOfArrival(): void
    {
        $a = $this->asset();
        $s = $this->store();
        $s->ingest([$this->sample($a, 'cpu.utilization', 80, $this->at(60))], 1);
        $s->ingest([$this->sample($a, 'cpu.utilization', 10, $this->at(600))], 1);   // older, arrives later
        $latest = $s->latest($a);
        $this->assertCount(1, $latest);
        $this->assertSame('cpu.utilization', $latest[0]['key']);
        $this->assertNull($latest[0]['instance']);
        $this->assertEquals(80, $latest[0]['value']);
        $this->assertInstanceOf(\DateTimeImmutable::class, $latest[0]['at']);
    }

    public function testKeysFilterAndInstancesAreSeparate(): void
    {
        $a = $this->asset();
        $s = $this->store();
        $s->ingest([$this->sample($a, 'cpu.utilization', 20, $this->at(30)), $this->sample($a, 'disk.utilization', 50, $this->at(30), 'C:'), $this->sample($a, 'disk.utilization', 70, $this->at(30), 'D:')], 1);
        $this->assertCount(3, $s->latest($a));
        $this->assertCount(2, $s->latest($a, ['disk.utilization']));
        $this->assertSame([], $s->latest($a, []));
        $instances = array_column($s->latest($a, ['disk.utilization']), 'instance');
        sort($instances);
        $this->assertSame(['C:', 'D:'], $instances);
        $this->assertSame([], $s->latest($this->asset()), 'an unknown asset has no readings');
    }

    public function testPeakIsTheMinMaxAndMeanOfTheWindow(): void
    {
        $a = $this->asset();
        $s = $this->store();
        $s->ingest([
            $this->sample($a, 'network.rx_bytes_per_s', 1000, $this->at(1200), 'total'),
            $this->sample($a, 'network.rx_bytes_per_s', 5000, $this->at(900), 'total'),
            $this->sample($a, 'network.rx_bytes_per_s', 3000, $this->at(300), 'total'),
            $this->sample($a, 'network.rx_bytes_per_s', 999999, $this->at(3 * 3600 + 120), 'total'),   // hours before the window
        ], 1);
        $p = $s->peak($a, 'network.rx_bytes_per_s', 'total', $this->at(3600));
        $this->assertNotNull($p);
        $this->assertEquals(5000, $p['max']);
        $this->assertEquals(1000, $p['min']);
        $this->assertEquals(3000, $p['avg']);
        $this->assertSame(3, $p['samples']);
        $this->assertInstanceOf(\DateTimeImmutable::class, $p['peak_at']);
        $this->assertNull($s->peak($a, 'network.tx_bytes_per_s', 'total', $this->at(3600)), 'no data for that key');
        $this->assertNull($s->peak($a, 'network.rx_bytes_per_s', null, $this->at(3600)), 'the null instance is not the total instance');
        $this->assertNull($s->peak($this->asset(), 'network.rx_bytes_per_s', 'total', $this->at(3600)));
    }

    public function testPeakExcludesHoursBeforeTheWindow(): void
    {
        $a = $this->asset();
        $s = $this->store();
        $s->ingest([$this->sample($a, 'cpu.utilization', 99, $this->at(5 * 3600)), $this->sample($a, 'cpu.utilization', 15, $this->at(120))], 1);
        $p = $s->peak($a, 'cpu.utilization', null, $this->at(3600));
        $this->assertNotNull($p);
        $this->assertEquals(15, $p['max'], 'the 99 from five hours ago is outside a one hour window');
    }

    public function testOutOfRangeUtilizationNeverShowsUp(): void
    {
        $a = $this->asset();
        $s = $this->store();
        $s->ingest([$this->sample($a, 'cpu.utilization', 250, $this->at(60)), $this->sample($a, 'cpu.utilization', 40, $this->at(90))], 1);
        $this->assertEquals(40, $s->latest($a, ['cpu.utilization'])[0]['value']);
        $this->assertEquals(40, $s->peak($a, 'cpu.utilization', null, $this->at(3600))['max'] ?? null);
    }

    public function testSeriesIsOldestFirstWithOnePointPerHour(): void
    {
        $a = $this->asset();
        $s = $this->store();
        $s->ingest([
            $this->sample($a, 'cpu.utilization', 30, $this->at(2 * 3600 + 60)),
            $this->sample($a, 'cpu.utilization', 50, $this->at(60)),
            $this->sample($a, 'cpu.utilization', 70, $this->at(30)),
        ], 1);
        $points = $s->series($a, 'cpu.utilization', null, $this->at(4 * 3600), $this->at(0));
        $this->assertGreaterThanOrEqual(2, count($points));
        $times = array_map(static fn (array $p): int => $p['at']->getTimestamp(), $points);
        $sorted = $times;
        sort($sorted);
        $this->assertSame($sorted, $times, 'oldest first');
        $this->assertSame($times, array_values(array_unique($times)), 'one point per hour');
        foreach ($points as $p) {
            $this->assertLessThanOrEqual($p['max'], $p['avg']);
            $this->assertGreaterThanOrEqual($p['min'], $p['avg']);
        }
        $this->assertSame([], $s->series($this->asset(), 'cpu.utilization', null, $this->at(4 * 3600), $this->at(0)));
    }
}
