<?php

declare(strict_types=1);

namespace RivetCore\Tests\Support;

use RivetCore\Rmm\Contracts\ExternalMetricStoreInterface;

/** An external store for tests: records the batches, fails from a script, and can run a hook while a write is in flight. */
final class ScriptedExternalStore implements ExternalMetricStoreInterface
{
    /** @var list<list<array{asset_id:int, key:string, instance:?string, value:int|float, at:\DateTimeImmutable, label:?string}>> */
    public array $batches = [];
    /** @var list<\Throwable|null> consumed one per write(): null = succeed */
    public array $script = [];
    /** @var (\Closure():void)|null runs inside write(), before it records */
    public ?\Closure $during = null;
    public int $writeCalls = 0;

    public function write(array $points): void
    {
        ++$this->writeCalls;
        if ($this->during !== null) {
            $hook = $this->during;
            $this->during = null;
            $hook();
        }
        $next = array_shift($this->script);
        if ($next !== null) {
            throw $next;
        }
        $this->batches[] = $points;
    }

    public function series(int $assetId, string $key, ?string $instance, \DateTimeImmutable $since, \DateTimeImmutable $until, int $stepSeconds): array
    {
        return [];
    }

    public function peak(int $assetId, string $key, ?string $instance, \DateTimeImmutable $since): ?array
    {
        return null;
    }

    public function ping(): bool
    {
        return true;
    }

    public function pointCount(): int
    {
        return array_sum(array_map('count', $this->batches));
    }
}
