<?php

declare(strict_types=1);

namespace RivetCore\Testing;

use PHPUnit\Framework\TestCase;
use RivetCore\Redis\RedisClientProviderInterface;

/**
 * Conformance kit for {@see RedisClientProviderInterface}.
 *
 * Checks the documented contract: client() returns null when Redis is unavailable and never throws (Core fails open);
 * when Redis is up it returns a working Predis client (ping, set/get/del, TTL, counters). Every key the case writes lives
 * under a unique prefix ({@see self::key()}) and is deleted afterwards, so it can run against a throwaway Redis
 * (RIVETCORE_TEST_REDIS_PORT) without flushing anything.
 *
 * The "Redis is down" check runs in its own PHP process: editions typically resolve host/port from the environment once
 * and cache the connection (or the failure) in a static, so a dead Redis and a live one cannot share a process. Point the
 * config at the closed port inside {@see self::unreachableProvider()} (for example with putenv()).
 *
 * @api
 */
abstract class RedisClientProviderConformanceTestCase extends TestCase
{
    /** @var list<string> */
    private array $keys = [];
    private ?string $prefix = null;

    /** The edition's provider configured for the throwaway Redis used in CI. */
    abstract protected function provider(): RedisClientProviderInterface;

    /** The SAME provider class configured to point at a port where nothing listens (see {@see self::closedPort()}). */
    abstract protected function unreachableProvider(): RedisClientProviderInterface;

    /** Is a throwaway Redis reachable for the live checks? Default: RIVETCORE_TEST_REDIS_PORT is set. */
    protected function redisAvailable(): bool
    {
        $p = getenv('RIVETCORE_TEST_REDIS_PORT');

        return $p !== false && $p !== '';
    }

    /** Upper bound for client() against a dead Redis; it must fail fast, not hang a page. */
    protected function maxUnreachableSeconds(): float
    {
        return 10.0;
    }

    /** A TCP port nothing listens on (bind to 0, read the port, close). */
    protected static function closedPort(): int
    {
        $s = stream_socket_server('tcp://127.0.0.1:0', $errno, $err);
        if ($s === false) {
            return 1; // privileged port nobody serves Redis on
        }
        $name = (string) stream_socket_get_name($s, false);
        fclose($s);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    /** A unique key for this test run; deleted in tearDown. */
    protected function key(string $name, ?string $prefix = null): string
    {
        $this->prefix ??= 'rc_conf:' . bin2hex(random_bytes(6)) . ':';
        $key = ($prefix ?? $this->prefix) . $name;
        $this->keys[] = $key;

        return $key;
    }

    protected function tearDown(): void
    {
        if ($this->keys !== [] && $this->redisAvailable()) {
            try {
                $c = $this->provider()->client();
                $c?->del($this->keys);
            } catch (\Throwable) {
                // best effort
            }
        }
        $this->keys = [];
        $this->prefix = null;
    }

    private function live(): \Predis\Client
    {
        if (!$this->redisAvailable()) {
            $this->markTestSkipped('No throwaway Redis (set RIVETCORE_TEST_REDIS_PORT).');
        }
        $client = $this->provider()->client();
        $this->assertNotNull($client, 'Redis is reachable, so client() must return a client, not null');

        return $client;
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testUnreachableRedisYieldsNullAndNeverThrows(): void
    {
        $provider = $this->unreachableProvider();
        $start = microtime(true);
        for ($i = 0; $i < 2; $i++) {
            try {
                $client = $provider->client();
            } catch (\Throwable $e) {
                $this->fail('client() threw when Redis is down: ' . $e::class . ': ' . $e->getMessage());
            }
            $this->assertNull($client, 'client() must return null when Redis cannot be reached (Core fails open)');
        }
        $this->assertLessThan($this->maxUnreachableSeconds() * 2, microtime(true) - $start, 'client() must fail fast against a dead Redis');
    }

    public function testLiveProviderReturnsAWorkingPredisClient(): void
    {
        $client = $this->live();
        $this->assertInstanceOf(\Predis\Client::class, $client);
        $this->assertSame('PONG', (string) $client->ping());
    }

    public function testSetGetDeleteRoundTrip(): void
    {
        $c = $this->live();
        $k = $this->key('roundtrip');
        $this->assertNull($c->get($k));
        $c->set($k, "value \u{00e9}\u{1F600}");
        $this->assertSame("value \u{00e9}\u{1F600}", $c->get($k));
        $this->assertSame(1, (int) $c->exists($k));
        $this->assertSame(1, (int) $c->del([$k]));
        $this->assertNull($c->get($k));
        $this->assertSame(0, (int) $c->exists($k));
    }

    public function testExpiryAndCounters(): void
    {
        $c = $this->live();
        $k = $this->key('ttl');
        $c->set($k, '1', 'EX', 30);
        $ttl = (int) $c->ttl($k);
        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(30, $ttl);
        $n = $this->key('counter');
        $this->assertSame(1, (int) $c->incr($n));
        $this->assertSame(2, (int) $c->incr($n));
        $this->assertSame(1, (int) $c->setnx($this->key('nx'), 'a'));
        $this->assertSame(0, (int) $c->setnx($this->key('nx'), 'b'));
    }

    public function testKeyPrefixesIsolateOneConsumerFromAnother(): void
    {
        $c = $this->live();
        $a = $this->key('shared', 'rc_conf:a:' . bin2hex(random_bytes(4)) . ':');
        $b = $this->key('shared', 'rc_conf:b:' . bin2hex(random_bytes(4)) . ':');
        $c->set($a, 'from-a');
        $this->assertNull($c->get($b), 'a key under another prefix must not be visible');
        $c->set($b, 'from-b');
        $this->assertSame('from-a', $c->get($a));
        $this->assertSame('from-b', $c->get($b));
    }

    public function testClientIsUsableOnRepeatedCalls(): void
    {
        $first = $this->live();
        $k = $this->key('repeat');
        $first->set($k, 'x');
        $second = $this->provider()->client();
        $this->assertNotNull($second, 'a second client() call must also succeed');
        $this->assertSame('x', $second->get($k), 'both clients must talk to the same Redis database');
    }
}
