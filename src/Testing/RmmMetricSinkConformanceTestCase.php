<?php

declare(strict_types=1);

namespace RivetCore\Testing;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Contracts\RmmMetricSinkInterface;

/**
 * Conformance kit for {@see RmmMetricSinkInterface}. The sink is optional, so only the safety rules are checked everywhere; a
 * sink that stores samples also tells the case how to read them back ({@see self::stored()}, null when it keeps nothing).
 *
 * Checks: an empty list and valid samples never throw; a utilization value outside 0..100 is dropped, never clamped (only
 * when samples are observable); valid samples of one call are all kept.
 *
 * @api
 */
abstract class RmmMetricSinkConformanceTestCase extends TestCase
{
    abstract protected function sink(): RmmMetricSinkInterface;

    /**
     * The samples stored for this asset, or null when the sink discards everything (the null sink).
     *
     * @return list<array{key:string, value:int|float}>|null
     */
    protected function stored(int $assetId): ?array
    {
        return null;
    }

    /** @return array{asset_id:int, key:string, instance:?string, value:int|float, at:\DateTimeImmutable, label:?string} */
    private function sample(int $asset, string $key, int|float $value): array
    {
        return ['asset_id' => $asset, 'key' => $key, 'instance' => null, 'value' => $value, 'at' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')), 'label' => null];
    }

    private function asset(): int
    {
        return random_int(1_000_000, 2_000_000_000);
    }

    public function testEmptyAndValidIngestNeverThrow(): void
    {
        $this->sink()->ingest([], 1);
        $a = $this->asset();
        $this->sink()->ingest([$this->sample($a, 'cpu.utilization', 12.5), $this->sample($a, 'memory.total_bytes', 8_589_934_592)], 1);
        $this->addToAssertionCount(1);
    }

    public function testValidSamplesAreKept(): void
    {
        $a = $this->asset();
        $this->sink()->ingest([$this->sample($a, 'cpu.utilization', 40), $this->sample($a, 'memory.utilization', 55.5)], 1);
        $stored = $this->stored($a);
        if ($stored === null) {
            $this->markTestSkipped('This sink does not keep samples.');
        }
        $keys = array_column($stored, 'key');
        sort($keys);
        $this->assertSame(['cpu.utilization', 'memory.utilization'], $keys);
    }

    public function testOutOfRangeUtilizationIsDroppedNotClamped(): void
    {
        $a = $this->asset();
        $this->sink()->ingest([$this->sample($a, 'cpu.utilization', 150), $this->sample($a, 'disk.utilization', -3), $this->sample($a, 'memory.utilization', 99)], 1);
        $stored = $this->stored($a);
        if ($stored === null) {
            $this->markTestSkipped('This sink does not keep samples.');
        }
        $this->assertSame(['memory.utilization'], array_column($stored, 'key'));
        $this->assertEquals(99, $stored[0]['value']);
    }
}
