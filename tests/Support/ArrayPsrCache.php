<?php

declare(strict_types=1);

namespace RivetCore\Tests\Support;

use Psr\SimpleCache\CacheInterface;

/** A PSR-16 cache in memory with a controllable clock and an optional failure switch (for the read-path cache tests). */
final class ArrayPsrCache implements CacheInterface
{
    /** @var array<string,array{0:mixed,1:int}> */
    public array $items = [];
    public bool $failing = false;
    public int $gets = 0;
    public int $sets = 0;

    public function __construct(private readonly MutableClock $clock)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->check();
        ++$this->gets;
        $e = $this->items[$key] ?? null;
        if ($e === null || $e[1] <= $this->clock->now()->getTimestamp()) {
            return $default;
        }

        return $e[0];
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $this->check();
        ++$this->sets;
        $this->items[$key] = [$value, $this->clock->now()->getTimestamp() + (is_int($ttl) ? $ttl : 3600)];

        return true;
    }

    public function delete(string $key): bool
    {
        $this->check();
        unset($this->items[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->items = [];

        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = $this->get($k, $default);
        }

        return $out;
    }

    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        foreach ($values as $k => $v) {
            $this->set($k, $v, $ttl);
        }

        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $k) {
            $this->delete($k);
        }

        return true;
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    private function check(): void
    {
        if ($this->failing) {
            throw new \RuntimeException('cache down');
        }
    }
}
