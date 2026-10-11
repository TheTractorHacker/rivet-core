<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm\Metrics;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Contracts\RmmMetricSinkInterface;
use RivetCore\Rmm\Metrics\CompositeMetricSink;
use RivetCore\Rmm\Metrics\ExternalMetricReader;
use RivetCore\Rmm\Metrics\ExternalStoreException;
use RivetCore\Testing\InMemoryRmmMetricSink;
use RivetCore\Tests\Conformance\Reference\InMemoryExternalMetricStore;

final class CompositeAndReaderTest extends TestCase
{
    /** @return array{asset_id:int,key:string,instance:?string,value:int|float,at:\DateTimeImmutable,label:?string} */
    private static function s(int $asset, float $v, int $ts): array
    {
        return ['asset_id' => $asset, 'key' => 'cpu.utilization', 'instance' => null, 'value' => $v, 'at' => new \DateTimeImmutable('@' . $ts), 'label' => null];
    }

    private static function throwing(string $message): RmmMetricSinkInterface
    {
        return new class($message) implements RmmMetricSinkInterface {
            public int $calls = 0;

            public function __construct(private string $message)
            {
            }

            public function ingest(array $samples, int $integrationId): void
            {
                ++$this->calls;
                throw new \RuntimeException($this->message);
            }
        };
    }

    public function testASecondarySinkFailureStopsNobodyAndPropagatesNothing(): void
    {
        $a = new InMemoryRmmMetricSink();
        $bad = self::throwing('external down');
        $c = new InMemoryRmmMetricSink();
        $composite = new CompositeMetricSink([[$a, true], [$bad, false], [$c, false]]);
        $composite->ingest([self::s(1, 5, 1_700_000_000)], 1);
        $this->assertCount(1, $a->stored());
        $this->assertCount(1, $c->stored(), 'the sink after the failing one still ran');
        $this->assertSame([['sink' => $bad::class, 'message' => 'external down']], $composite->failures());
        $composite->clearFailures();
        $this->assertSame([], $composite->failures());
    }

    public function testAPrimaryFailureIsRethrownAfterEverySinkRan(): void
    {
        $bad = self::throwing('db down');
        $other = self::throwing('second');
        $c = new InMemoryRmmMetricSink();
        $composite = new CompositeMetricSink([[$bad, true], [$other, true], [$c, false]]);
        try {
            $composite->ingest([self::s(1, 5, 1_700_000_000)], 1);
            $this->fail('the primary failure must be rethrown');
        } catch (\RuntimeException $e) {
            $this->assertSame('db down', $e->getMessage(), 'the first primary failure');
        }
        $this->assertCount(1, $c->stored());
        $this->assertSame(1, $other->calls);
        $this->assertCount(2, $composite->failures());
    }

    public function testTheFailureListIsBounded(): void
    {
        $composite = new CompositeMetricSink([[self::throwing('x'), false]]);
        for ($i = 0; $i < 150; ++$i) {
            $composite->ingest([], 1);
        }
        $this->assertCount(100, $composite->failures());
    }

    public function testTheReaderAsksTheStoreForSeriesAndPeakAndTheFallbackForLatest(): void
    {
        $store = new InMemoryExternalMetricStore();
        $local = new InMemoryRmmMetricSink();
        $b = intdiv(time(), 3600) * 3600 - 3 * 3600;
        $store->write([self::s(5, 80, $b + 10), self::s(5, 20, $b + 20)]);
        $local->ingest([self::s(5, 11, $b + 30)], 1);
        $reader = new ExternalMetricReader($store, $local);
        $this->assertEquals(11, $reader->latest(5)[0]['value'], 'latest comes from the fallback');
        $series = $reader->series(5, 'cpu.utilization', null, new \DateTimeImmutable('@' . $b), new \DateTimeImmutable('@' . ($b + 3599)));
        $this->assertSame(2, $series[0]['samples']);
        $this->assertEquals(50.0, $series[0]['avg']);
        $this->assertEquals(80.0, $reader->peak(5, 'cpu.utilization', null, new \DateTimeImmutable('@' . $b))['max'] ?? null);
    }

    public function testAnUnreachableStoreFallsBackAndNeverThrows(): void
    {
        $store = new InMemoryExternalMetricStore();
        $local = new InMemoryRmmMetricSink();
        $b = intdiv(time(), 3600) * 3600 - 3 * 3600;
        $local->ingest([self::s(5, 33, $b + 30)], 1);
        $store->breaker()();
        $reader = new ExternalMetricReader($store, $local);
        $this->assertEquals(33, $reader->peak(5, 'cpu.utilization', null, new \DateTimeImmutable('@' . $b))['max'] ?? null);
        $series = $reader->series(5, 'cpu.utilization', null, new \DateTimeImmutable('@' . $b), new \DateTimeImmutable('@' . ($b + 3599)));
        $this->assertEquals(33, $series[0]['max'] ?? null);
        $this->assertEquals(33, $reader->latest(5)[0]['value']);
        try {
            $store->series(5, 'cpu.utilization', null, new \DateTimeImmutable('@' . $b), new \DateTimeImmutable('@' . ($b + 1)), 3600);
            $this->fail('the store itself does throw');
        } catch (ExternalStoreException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testAnEmptyAnswerFromTheStoreFallsBackToo(): void
    {
        $local = new InMemoryRmmMetricSink();
        $b = intdiv(time(), 3600) * 3600 - 3 * 3600;
        $local->ingest([self::s(5, 44, $b + 30)], 1);
        $reader = new ExternalMetricReader(new InMemoryExternalMetricStore(), $local);
        $this->assertEquals(44, $reader->peak(5, 'cpu.utilization', null, new \DateTimeImmutable('@' . $b))['max'] ?? null);
    }

    public function testTheStepFollowsTheRange(): void
    {
        $seen = [];
        $store = new class($seen) extends InMemoryExternalMetricStore {
            /** @param list<int> $seen */
            public function __construct(private array &$seen)
            {
            }

            public function series(int $assetId, string $key, ?string $instance, \DateTimeImmutable $since, \DateTimeImmutable $until, int $stepSeconds): array
            {
                $this->seen[] = $stepSeconds;

                return [];
            }
        };
        $reader = new ExternalMetricReader($store, new InMemoryRmmMetricSink());
        $t = 1_700_000_000;
        foreach ([3600, 2 * 86400, 2 * 86400 + 1, 30 * 86400] as $span) {
            $reader->series(1, 'cpu.utilization', null, new \DateTimeImmutable('@' . $t), new \DateTimeImmutable('@' . ($t + $span)));
        }
        $this->assertSame([3600, 3600, 86400, 86400], $seen);
    }
}
