<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance\Reference;

use RivetCore\Rmm\Metrics\ExternalStoreException;

/**
 * The in-memory store with one named flaw: not_idempotent, empty_instance_differs, instance_blur, unknown_throws, wrong_peak_at,
 * throws_hostile, hostile_wildcard, write_swallows, ping_throws, ping_true, empty_write_throws, avg_midrange, samples_distinct,
 * newest_first, no_window, no_peak_window, read_swallows, plain_exception.
 */
final class FlawedExternalMetricStore extends InMemoryExternalMetricStore
{
    public function __construct(private ?string $flaw = null)
    {
    }

    public function write(array $points): void
    {
        if ($this->flaw === 'empty_write_throws' && $points === []) {
            throw new ExternalStoreException('nothing to write');
        }
        if ($this->flaw === 'write_swallows' && $this->broken) {
            return;
        }
        if ($this->flaw === 'plain_exception' && $this->broken) {
            throw new \RuntimeException('store unavailable');
        }
        if ($this->flaw === 'throws_hostile') {
            foreach ($points as $p) {
                if (preg_match('/["\\\\{}\n]/', (string) $p['instance']) === 1) {
                    throw new \InvalidArgumentException('bad instance');
                }
            }
        }
        parent::write($points);
    }

    public function series(int $assetId, string $key, ?string $instance, \DateTimeImmutable $since, \DateTimeImmutable $until, int $stepSeconds): array
    {
        if ($this->flaw === 'read_swallows' && $this->broken) {
            return [];
        }
        if ($this->flaw === 'throws_hostile' && preg_match('/["\\\\{}\n]/', (string) $instance) === 1) {
            throw new \InvalidArgumentException('bad instance');
        }
        $out = parent::series($assetId, $key, $instance, $this->flaw === 'no_window' ? new \DateTimeImmutable('@0') : $since, $this->flaw === 'no_window' ? new \DateTimeImmutable('@' . (time() + 86400)) : $until, $stepSeconds);
        if ($this->flaw === 'unknown_throws' && $out === []) {
            throw new ExternalStoreException('no such series');
        }
        if ($this->flaw === 'newest_first') {
            $out = array_reverse($out);
        }
        foreach ($out as &$p) {
            if ($this->flaw === 'avg_midrange') {
                $p['avg'] = ($p['min'] + $p['max']) / 2;
            }
            if ($this->flaw === 'samples_distinct') {
                $p['samples'] = max(1, $p['samples'] - 1);
            }
        }
        unset($p);

        return $out;
    }

    public function peak(int $assetId, string $key, ?string $instance, \DateTimeImmutable $since): ?array
    {
        if ($this->flaw === 'read_swallows' && $this->broken) {
            return null;
        }
        $p = parent::peak($assetId, $key, $instance, $this->flaw === 'no_peak_window' ? new \DateTimeImmutable('@0') : $since);
        if ($p !== null && $this->flaw === 'wrong_peak_at') {
            $p['peak_at'] = $p['peak_at']->modify('+1 hour');
        }
        if ($p !== null && $this->flaw === 'avg_midrange') {
            $p['avg'] = ($p['min'] + $p['max']) / 2;
        }

        return $p;
    }

    public function ping(): bool
    {
        if ($this->flaw === 'ping_throws' && $this->broken) {
            throw new \RuntimeException('down');
        }

        return $this->flaw === 'ping_true' ? true : parent::ping();
    }

    protected function dedupe(): bool
    {
        return $this->flaw !== 'not_idempotent';
    }

    protected function matches(array $row, int $assetId, string $key, ?string $instance): bool
    {
        if ($this->flaw === 'empty_instance_differs') {
            return $row['asset'] === $assetId && $row['key'] === $key && $row['inst'] === ($instance === null ? "\0null" : $instance);
        }
        if ($this->flaw === 'instance_blur') {
            return $row['asset'] === $assetId && $row['key'] === $key;
        }
        if ($this->flaw === 'hostile_wildcard' && $instance !== null && str_contains($instance, '}')) {
            return $row['asset'] === $assetId && $row['key'] === $key;
        }

        return parent::matches($row, $assetId, $key, $instance);
    }
}
