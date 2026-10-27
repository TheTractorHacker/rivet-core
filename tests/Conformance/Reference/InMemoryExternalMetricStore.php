<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance\Reference;

use RivetCore\Rmm\Contracts\ExternalMetricStoreInterface;
use RivetCore\Rmm\Metrics\ExternalStoreException;
use RivetCore\Rmm\Metrics\MetricSteps;

/** A correct in-memory external store (one value per series and second), overridable in small steps to build the broken variants. */
class InMemoryExternalMetricStore implements ExternalMetricStoreInterface
{
    /** @var array<string,array{asset:int,key:string,inst:string,ts:int,value:float}> */
    protected array $rows = [];
    protected bool $broken = false;
    private int $counter = 0;

    /** Makes every call fail; the returned callable repairs the store. */
    public function breaker(): callable
    {
        return function (): callable {
            $this->broken = true;

            return function (): void {
                $this->broken = false;
            };
        };
    }

    public function write(array $points): void
    {
        $this->guard();
        foreach ($points as $p) {
            if (!is_finite((float) $p['value'])) {
                continue;
            }
            $inst = $p['instance'] ?? '';
            $ts = $p['at']->getTimestamp();
            $id = $this->dedupe() ? $p['asset_id'] . "\0" . $p['key'] . "\0" . $inst . "\0" . $ts : 'n' . $this->counter++;
            $this->rows[$id] = ['asset' => $p['asset_id'], 'key' => $p['key'], 'inst' => $inst, 'ts' => $ts, 'value' => (float) $p['value']];
        }
    }

    public function series(int $assetId, string $key, ?string $instance, \DateTimeImmutable $since, \DateTimeImmutable $until, int $stepSeconds): array
    {
        $this->guard();
        $step = max(1, $stepSeconds);
        $from = MetricSteps::floor($since->getTimestamp(), $step);
        $to = MetricSteps::floor($until->getTimestamp(), $step) + $step;
        /** @var array<int,list<float>> $buckets */
        $buckets = [];
        foreach ($this->select($assetId, $key, $instance) as $r) {
            if ($r['ts'] >= $from && $r['ts'] < $to) {
                $buckets[MetricSteps::floor($r['ts'], $step)][] = $r['value'];
            }
        }
        ksort($buckets);
        $out = [];
        foreach ($buckets as $start => $values) {
            $out[] = ['at' => new \DateTimeImmutable('@' . $start), 'min' => min($values), 'max' => max($values), 'avg' => array_sum($values) / count($values), 'samples' => count($values)];
        }

        return $out;
    }

    public function peak(int $assetId, string $key, ?string $instance, \DateTimeImmutable $since): ?array
    {
        $this->guard();
        $now = time();
        $from = MetricSteps::floor($since->getTimestamp(), MetricSteps::HOUR);
        $step = MetricSteps::forSpan(max(0, $now - $from));
        if ($step === MetricSteps::DAY) {
            $from = MetricSteps::floor($from, $step);
        }
        $values = [];
        $maxByStep = [];
        foreach ($this->select($assetId, $key, $instance) as $r) {
            if ($r['ts'] >= $from && $r['ts'] <= $now) {
                $values[] = $r['value'];
                $s = MetricSteps::floor($r['ts'], $step);
                $maxByStep[$s] = isset($maxByStep[$s]) ? max($maxByStep[$s], $r['value']) : $r['value'];
            }
        }
        if ($values === []) {
            return null;
        }
        ksort($maxByStep);
        $peakAt = 0;
        $best = null;
        foreach ($maxByStep as $s => $v) {
            if ($best === null || $v >= $best) {
                $best = $v;
                $peakAt = $s;
            }
        }

        return ['min' => min($values), 'max' => max($values), 'avg' => array_sum($values) / count($values), 'samples' => count($values), 'peak_at' => new \DateTimeImmutable('@' . $peakAt)];
    }

    public function ping(): bool
    {
        return !$this->broken;
    }

    protected function dedupe(): bool
    {
        return true;
    }

    protected function matches(array $row, int $assetId, string $key, ?string $instance): bool
    {
        return $row['asset'] === $assetId && $row['key'] === $key && $row['inst'] === ($instance ?? '');
    }

    protected function guard(): void
    {
        if ($this->broken) {
            throw new ExternalStoreException('store unavailable');
        }
    }

    /** @return list<array{asset:int,key:string,inst:string,ts:int,value:float}> */
    protected function select(int $assetId, string $key, ?string $instance): array
    {
        return array_values(array_filter($this->rows, fn (array $r): bool => $this->matches($r, $assetId, $key, $instance)));
    }
}
