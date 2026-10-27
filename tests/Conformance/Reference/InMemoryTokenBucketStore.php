<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance\Reference;

use RivetCore\Rmm\Capacity\DbTokenBucketStore;
use RivetCore\Rmm\Capacity\TokenBucketStoreInterface;

/** The token bucket arithmetic over an array: the reference adapter of the kit (and the target of its mutants). */
class InMemoryTokenBucketStore implements TokenBucketStoreInterface
{
    /** @var array<string,array{0:float,1:int}> */
    protected array $buckets = [];
    protected bool $down = false;

    public function take(string $key, int $burst, float $perSecond, int $nowMs): array
    {
        if ($this->down) {
            return ['ok' => true, 'retry_ms' => 0];
        }
        [$tokens, $at] = $this->buckets[$key] ?? [(float) $burst, $nowMs];
        $r = DbTokenBucketStore::step($tokens, $at, $burst, $perSecond, $nowMs);
        $this->buckets[$key] = [$r['tokens'], max($at, $nowMs)];

        return ['ok' => $r['ok'], 'retry_ms' => $r['retry_ms']];
    }

    public function breakIt(): \Closure
    {
        $this->down = true;

        return function (): void {
            $this->down = false;
        };
    }
}
