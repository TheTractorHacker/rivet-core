<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use PHPUnit\Framework\TestCase;
use RivetCore\Redis\LockManager;
use RivetCore\Redis\RateLimiter;
use RivetCore\Redis\RedisClientProviderInterface;

/**
 * Behaviour every RedisClientProviderInterface adapter must have, and the fail-open promise built on top of it.
 *
 * Implement reachableProvider() with an adapter wired to a THROWAWAY Redis (return null to skip those tests), and
 * unavailableProvider() with an adapter whose Redis is down or misconfigured (a closed port, a wrong password).
 * Extend failOpenSecret() if the unavailable adapter uses a password, so the test can prove it never leaks.
 */
abstract class RedisClientProviderConformanceTestCase extends TestCase
{
    abstract protected function reachableProvider(): ?RedisClientProviderInterface;

    abstract protected function unavailableProvider(): RedisClientProviderInterface;

    /** The password the unavailable adapter was configured with, or null. */
    protected function failOpenSecret(): ?string
    {
        return null;
    }

    public function testReachableProviderGivesAWorkingClient(): void
    {
        $p = $this->reachableProvider();
        if ($p === null) {
            $this->markTestSkipped('no throwaway Redis configured');
        }
        $client = $p->client();
        $this->assertInstanceOf(\Predis\Client::class, $client);
        $this->assertSame('PONG', (string) $client->ping());
    }

    public function testUnavailableProviderNeverThrowsFromClient(): void
    {
        try {
            $client = $this->unavailableProvider()->client();
        } catch (\Throwable $e) {
            $this->fail('client() must return null or a client, never throw: ' . $e::class);
        }
        $this->assertTrue($client === null || $client instanceof \Predis\Client);
    }

    public function testLocksFailOpenWhenRedisIsUnavailable(): void
    {
        $lock = (new LockManager($this->unavailableProvider(), 'conformance:'))->acquire('job', 30);
        $this->assertTrue($lock->held(), 'with Redis down the work must go ahead');
        $lock->release();
        $this->assertTrue((new LockManager($this->unavailableProvider(), 'conformance:'))->acquire('job', 30)->held());
    }

    public function testRateLimiterFailsOpenWhenRedisIsUnavailable(): void
    {
        $r = (new RateLimiter($this->unavailableProvider(), 'conformance:'))->hit('bucket', 1, 60);
        $this->assertTrue((bool) ($r['allowed'] ?? false), 'a rate limit must not lock everyone out when Redis is down');
    }

    public function testFailureNeverLeaksTheSecret(): void
    {
        $secret = $this->failOpenSecret();
        if ($secret === null) {
            $this->markTestSkipped('the unavailable adapter uses no password');
        }
        $text = '';
        try {
            $this->unavailableProvider()->client()?->ping();
        } catch (\Throwable $e) {
            $text = $e->getMessage() . $e->getTraceAsString();
        }
        $this->assertStringNotContainsString($secret, $text);
    }

    public function testLockExcludesASecondHolderWhenReachable(): void
    {
        $p = $this->reachableProvider();
        if ($p === null) {
            $this->markTestSkipped('no throwaway Redis configured');
        }
        $m = new LockManager($p, 'conformance:' . bin2hex(random_bytes(4)) . ':');
        $first = $m->acquire('only-one', 30);
        $second = $m->acquire('only-one', 30);
        $this->assertTrue($first->held());
        $this->assertFalse($second->held());
        $first->release();
        $this->assertTrue($m->acquire('only-one', 30)->held());
    }
}
