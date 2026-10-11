<?php

declare(strict_types=1);

namespace RivetCore\Testing;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Capacity\TokenBucketStoreInterface;

/**
 * Conformance kit for {@see TokenBucketStoreInterface}, the store behind the per-client token buckets of the herd control. The store is given the clock
 * (`$nowMs`), so the case needs no sleeping: it moves time by passing larger values.
 *
 * Checks: a bucket starts full; exactly `$burst` takes succeed and the next is refused; a refusal says how long until the next token (about 1/rate seconds); tokens
 * refill with time at `$perSecond`; never above the burst however long the bucket sat idle; time going backwards never creates tokens; buckets are independent per
 * key; odd keys (colons, unicode, long) never throw; and a store that cannot be reached FAILS OPEN (admits) rather than refusing a fleet.
 *
 * @api
 */
abstract class TokenBucketStoreConformanceTestCase extends TestCase
{
    abstract protected function store(): TokenBucketStoreInterface;

    /**
     * Make the store unreachable and return a callable that repairs it, or null when the adapter cannot be broken from a test (the fail-open check is then skipped).
     */
    protected function breakStore(): ?callable
    {
        return null;
    }

    private const T0 = 1_800_000_000_000;

    private function key(): string
    {
        return 'kit:' . bin2hex(random_bytes(6));
    }

    public function testAFreshBucketStartsFullAndRefusesAfterTheBurst(): void
    {
        $key = $this->key();
        $got = [];
        for ($i = 0; $i < 6; ++$i) {
            $got[] = $this->store()->take($key, 3, 1.0, self::T0)['ok'];
        }
        $this->assertSame([true, true, true, false, false, false], $got);
    }

    public function testARefusalSaysHowLongUntilTheNextToken(): void
    {
        $key = $this->key();
        for ($i = 0; $i < 2; ++$i) {
            $this->store()->take($key, 2, 0.5, self::T0);
        }
        $r = $this->store()->take($key, 2, 0.5, self::T0);
        $this->assertFalse($r['ok']);
        $this->assertGreaterThanOrEqual(1800, $r['retry_ms'], 'one token at 0.5 a second is two seconds away');
        $this->assertLessThanOrEqual(2100, $r['retry_ms']);
        $this->assertSame(0, $this->store()->take($this->key(), 2, 0.5, self::T0)['retry_ms'], 'an accepted take has nothing to wait for');
    }

    public function testTokensRefillWithTime(): void
    {
        $key = $this->key();
        for ($i = 0; $i < 3; ++$i) {
            $this->assertTrue($this->store()->take($key, 3, 1.0, self::T0)['ok']);
        }
        $this->assertFalse($this->store()->take($key, 3, 1.0, self::T0 + 200)['ok'], 'a fifth of a token is not a token');
        $this->assertTrue($this->store()->take($key, 3, 1.0, self::T0 + 1500)['ok'], 'one token after 1.5 s at 1 a second');
        $this->assertFalse($this->store()->take($key, 3, 1.0, self::T0 + 1500)['ok'], 'and only one');
    }

    public function testAnIdleBucketNeverHoldsMoreThanTheBurst(): void
    {
        $key = $this->key();
        $this->store()->take($key, 3, 1.0, self::T0);
        $got = [];
        for ($i = 0; $i < 6; ++$i) {
            $got[] = $this->store()->take($key, 3, 1.0, self::T0 + 3_600_000)['ok'];
        }
        $this->assertSame([true, true, true, false, false, false], $got, 'an hour idle refills to the burst, not beyond');
    }

    public function testTimeGoingBackwardsCreatesNoTokens(): void
    {
        $key = $this->key();
        for ($i = 0; $i < 2; ++$i) {
            $this->assertTrue($this->store()->take($key, 2, 1.0, self::T0)['ok']);
        }
        $this->assertFalse($this->store()->take($key, 2, 1.0, self::T0 - 5000)['ok'], 'an earlier clock reading is not a refill');
    }

    public function testBucketsAreIndependentPerKey(): void
    {
        $a = $this->key();
        $b = $this->key();
        $this->assertTrue($this->store()->take($a, 1, 0.1, self::T0)['ok']);
        $this->assertFalse($this->store()->take($a, 1, 0.1, self::T0)['ok']);
        $this->assertTrue($this->store()->take($b, 1, 0.1, self::T0)['ok'], 'another key has its own tokens');
    }

    public function testOddKeysNeverThrow(): void
    {
        foreach (['checkin:7', "a'b\"c", 'ключ:клиент', str_repeat('k', 200), 'k:' . "\n" . 'x', ''] as $k) {
            $r = $this->store()->take($k . bin2hex(random_bytes(2)), 2, 1.0, self::T0);
            $this->assertSame(['ok', 'retry_ms'], array_keys($r));
        }
    }

    public function testAStoreThatCannotBeReachedAdmitsInsteadOfRefusing(): void
    {
        $repair = $this->breakStore();
        if ($repair === null) {
            $this->markTestSkipped('This adapter cannot be broken from a test.');
        }
        try {
            $r = $this->store()->take($this->key(), 1, 0.001, self::T0);
            $this->assertTrue($r['ok'], 'a guard that cannot be consulted must not refuse the fleet');
            $this->assertSame(0, $r['retry_ms']);
        } finally {
            $repair();
        }
    }
}
