<?php

declare(strict_types=1);

namespace RivetCore\Testing;

use RivetCore\Rmm\Contracts\RmmMetricReaderInterface;
use RivetCore\Rmm\Contracts\RmmMetricSinkInterface;

/**
 * Reference implementation (not API; tests may extend it to build a deliberately broken variant) of {@see RmmMetricSinkInterface} and
 * {@see RmmMetricReaderInterface}: keeps accepted samples, drops (never clamps) utilization values outside 0..100, and answers the reads
 * from them with hour-granular windows.
 *
 * @internal
 */
class InMemoryRmmMetricSink implements RmmMetricSinkInterface, RmmMetricReaderInterface
{
    /** @var list<array{asset_id:int, key:string, instance:?string, value:int|float, at:\DateTimeImmutable, label:?string}> */
    protected array $stored = [];

    public function ingest(array $samples, int $integrationId): void
    {
        foreach ($samples as $s) {
            if (str_ends_with($s['key'], '.utilization') && ($s['value'] < 0 || $s['value'] > 100)) {
                continue;
            }
            $this->stored[] = $s;
        }
    }

    /** @return list<array{asset_id:int, key:string, instance:?string, value:int|float, at:\DateTimeImmutable, label:?string}> */
    public function stored(): array
    {
        return $this->stored;
    }

    public function latest(int $assetId, ?array $keys = null): array
    {
        $best = [];
        foreach ($this->stored as $s) {
            if ($s['asset_id'] !== $assetId || ($keys !== null && !in_array($s['key'], $keys, true))) {
                continue;
            }
            $id = $s['key'] . "\0" . ($s['instance'] ?? '');
            if (!isset($best[$id]) || $s['at'] >= $best[$id]['at']) {
                $best[$id] = $s;
            }
        }
        ksort($best);
        $out = [];
        foreach ($best as $s) {
            $out[] = ['key' => $s['key'], 'instance' => $s['instance'], 'value' => $s['value'], 'at' => $s['at'], 'label' => $s['label']];
        }

        return $out;
    }

    public function peak(int $assetId, string $key, ?string $instance, \DateTimeImmutable $since): ?array
    {
        $from = $since->getTimestamp() - $since->getTimestamp() % 3600;
        $n = 0;
        $sum = 0.0;
        $lo = INF;
        $hi = -INF;
        $hiHour = 0;
        foreach ($this->stored as $s) {
            if ($s['asset_id'] !== $assetId || $s['key'] !== $key || $s['instance'] !== $instance || $s['at']->getTimestamp() < $from) {
                continue;
            }
            ++$n;
            $sum += (float) $s['value'];
            $lo = min($lo, (float) $s['value']);
            if ((float) $s['value'] >= $hi) {
                $hi = (float) $s['value'];
                $hiHour = $s['at']->getTimestamp() - $s['at']->getTimestamp() % 3600;
            }
        }

        return $n === 0 ? null : ['min' => $lo, 'max' => $hi, 'avg' => $sum / $n, 'samples' => $n, 'peak_at' => new \DateTimeImmutable('@' . $hiHour)];
    }

    public function series(int $assetId, string $key, ?string $instance, \DateTimeImmutable $since, \DateTimeImmutable $until): array
    {
        $from = $since->getTimestamp() - $since->getTimestamp() % 3600;
        $hours = [];
        foreach ($this->stored as $s) {
            $t = $s['at']->getTimestamp();
            if ($s['asset_id'] !== $assetId || $s['key'] !== $key || $s['instance'] !== $instance || $t < $from || $t - $t % 3600 > $until->getTimestamp()) {
                continue;
            }
            $hours[$t - $t % 3600][] = (float) $s['value'];
        }
        ksort($hours);
        $out = [];
        foreach ($hours as $h => $v) {
            $out[] = ['at' => new \DateTimeImmutable('@' . $h), 'min' => min($v), 'max' => max($v), 'avg' => array_sum($v) / count($v), 'samples' => count($v)];
        }

        return $out;
    }
}
