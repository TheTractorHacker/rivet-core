<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance\Reference;

use RivetCore\Testing\InMemoryRmmMetricSink;

/** The reference sink and reader with an optional named flaw: last_arrival_wins, no_window, instance_blur, series_newest_first. */
final class FlawedRmmMetricReader extends InMemoryRmmMetricSink
{
    public function __construct(private ?string $flaw = null)
    {
    }

    public function latest(int $assetId, ?array $keys = null): array
    {
        if ($this->flaw === 'last_arrival_wins') {
            $best = [];
            foreach ($this->stored as $x) {
                if ($x['asset_id'] === $assetId && ($keys === null || in_array($x['key'], $keys, true))) {
                    $best[$x['key'] . "\0" . ($x['instance'] ?? '')] = ['key' => $x['key'], 'instance' => $x['instance'], 'value' => $x['value'], 'at' => $x['at'], 'label' => $x['label']];
                }
            }

            return array_values($best);
        }

        return parent::latest($assetId, $keys);
    }

    public function peak(int $assetId, string $key, ?string $instance, \DateTimeImmutable $since): ?array
    {
        if ($this->flaw === 'no_window') {
            $since = new \DateTimeImmutable('@0');
        }
        if ($this->flaw === 'instance_blur') {
            $best = null;
            foreach ([$instance, 'total', null] as $i) {
                $best ??= parent::peak($assetId, $key, $i, $since);
            }

            return $best;
        }

        return parent::peak($assetId, $key, $instance, $since);
    }

    public function series(int $assetId, string $key, ?string $instance, \DateTimeImmutable $since, \DateTimeImmutable $until): array
    {
        $out = parent::series($assetId, $key, $instance, $since, $until);

        return $this->flaw === 'series_newest_first' ? array_reverse($out) : $out;
    }
}
