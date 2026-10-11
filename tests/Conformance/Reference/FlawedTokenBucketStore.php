<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance\Reference;

/**
 * The in-memory store with one named flaw: no_cap (an idle bucket keeps growing), no_refill, shared_key (the key is ignored), starts_empty, retry_zero,
 * backwards_refills (a past clock reading refills), throws_odd, closed_on_failure (an unreachable store refuses), slow_retry (retry_ms far too large).
 */
final class FlawedTokenBucketStore extends InMemoryTokenBucketStore
{
    public function __construct(private ?string $flaw = null)
    {
    }

    public function take(string $key, int $burst, float $perSecond, int $nowMs): array
    {
        $f = $this->flaw;
        if ($f === 'throws_odd' && (str_contains($key, "'") || strlen($key) > 100 || $key === '' || preg_match('/[^\x20-\x7e]/', $key) === 1)) {
            throw new \InvalidArgumentException('odd key');
        }
        if ($f === 'closed_on_failure' && $this->down) {
            return ['ok' => false, 'retry_ms' => 1000];
        }
        if ($f === 'shared_key') {
            $key = 'one';
        }
        if ($this->down) {
            return ['ok' => true, 'retry_ms' => 0];
        }
        [$tokens, $at] = $this->buckets[$key] ?? [$f === 'starts_empty' ? 0.0 : (float) $burst, $nowMs];
        $elapsed = $nowMs - $at;
        if ($f === 'backwards_refills') {
            $elapsed = abs($elapsed);
        }
        $refill = $f === 'no_refill' ? 0.0 : max(0, $elapsed) * $perSecond / 1000.0;
        $tokens = $f === 'no_cap' ? $tokens + $refill : min((float) $burst, $tokens + $refill);
        $ok = $tokens >= 1.0;
        $retry = $ok ? 0 : (int) ceil((1.0 - $tokens) / max($perSecond, 1e-9) * 1000);
        if ($f === 'retry_zero') {
            $retry = 0;
        }
        if ($f === 'slow_retry' && !$ok) {
            $retry *= 10;
        }
        $this->buckets[$key] = [$ok ? $tokens - 1.0 : $tokens, max($at, $nowMs)];

        return ['ok' => $ok, 'retry_ms' => $retry];
    }
}
