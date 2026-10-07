<?php

declare(strict_types=1);

namespace RivetCore\Testing;

use RivetCore\Rmm\Contracts\RmmMetricSinkInterface;

/**
 * Reference implementation (not API; tests may extend it to build a deliberately broken variant) of {@see RmmMetricSinkInterface}: keeps accepted samples, drops (never clamps) utilization values
 * outside 0..100.
 *
 * @internal
 */
class InMemoryRmmMetricSink implements RmmMetricSinkInterface
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
}
