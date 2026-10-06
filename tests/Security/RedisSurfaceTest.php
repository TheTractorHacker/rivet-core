<?php

declare(strict_types=1);

namespace RivetCore\Tests\Security;

use RivetCore\Redis\CronGuard;
use RivetCore\Redis\LockManager;
use RivetCore\Redis\RateLimiter;
use RivetCore\Redis\RedisAdmin;
use RivetCore\Redis\RedisConnectionConfig;
use RivetCore\Tests\Support\TestRedis;

/** Redis surface: RC-SR2-02, -15, -21, -23 plus guards. */
final class RedisSurfaceTest extends SecurityTestCase
{
    private function closedPort(): int
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) stream_socket_get_name($sock, false), strlen('127.0.0.1:'));
        fclose($sock);

        return $port;
    }

    /**
     * Documents RC-SR2-02 (INFO, accepted design): when Redis is unreachable, or errors on the command, every lock is
     * reported as held (degraded() is true), so two overlapping runs of the same cron job both proceed. This is the
     * stated contract ("jobs keep running exactly as they did before locks existed"); the residual risk is a
     * non-idempotent cron job running twice during a Redis outage. Flip (if a fail-closed mode is added): assert the
     * second acquire() returns null when the caller opts in to failing closed.
     */
    public function testRc02FailOpenLocksLetTwoCronRunsOverlap(): void
    {
        $guard = new CronGuard(new LockManager($this->downRedis(), 'p:'));
        $first = $guard->acquire('sync-job');
        $second = $guard->acquire('sync-job');
        $this->assertNotNull($first);
        $this->assertNotNull($second, 'no mutual exclusion while Redis is down');
        $this->assertTrue($first->degraded());
        $this->assertTrue($second->degraded());

        // Redis "up" but failing every command (connection refused on each call) behaves the same way.
        $flapping = new TestRedis($this->closedPort());
        $a = (new LockManager($flapping, 'p:'))->acquire('j', 30);
        $b = (new LockManager($flapping, 'p:'))->acquire('j', 30);
        $this->assertTrue($a->held() && $b->held() && $a->degraded() && $b->degraded());
    }

    /**
     * Documents RC-SR2-23 (INFO): a lock is a plain TTL key. A job that outlives its TTL without calling extend() loses
     * the lock and a second runner acquires it; the first runner's extend() then reports false but nothing stops the
     * job. Callers must size the TTL (CronGuard default 900 s) or extend. The same happens if the key is evicted
     * (allkeys-lru under memory pressure) or cleared by an over-broad RedisAdmin group.
     */
    public function testRc23LockExpiresUnderALongRunningJob(): void
    {
        $r = $this->redis();
        $m = new LockManager($r, $this->prefix());
        $a = $m->acquire('long', 1);
        $this->assertTrue($a->held());
        usleep(2200000);
        $b = $m->acquire('long', 30);
        $this->assertTrue($b->held(), 'second runner holds the lock while the first is still running');
        $this->assertFalse($a->extend(30), 'the first runner has silently lost the lock');
        $b->release();
    }

    /**
     * Documents RC-SR2-23 (INFO): the rate-limit Lua only sets the expiry when the counter is created (INCR returns 1).
     * A counter key that exists without a TTL (written or PERSISTed by something else) is never given one, so the
     * bucket blocks forever and advertises retry_after = 1. Flip: expect the key to gain a TTL on the next hit.
     */
    public function testRc23RateLimitCounterWithoutTtlNeverExpires(): void
    {
        $r = $this->redis();
        $prefix = $this->prefix();
        $client = $r->client();
        $client->set($prefix . 'rl:stuck', '100');
        $limiter = new RateLimiter($r, $prefix);
        $hit = $limiter->hit('stuck', 5, 60);
        $this->assertFalse($hit['allowed']);
        $this->assertSame(1, $hit['retry_after']);
        $this->assertSame(-1, $client->ttl($prefix . 'rl:stuck'), 'still no expiry');
        $client->del([$prefix . 'rl:stuck']);
    }

    /** Guard: the limiter and the lock fail open when Redis is down (documented), and the lock key keeps the prefix layout. */
    public function testGuardRateLimiterFailsOpenAndKeysArePrefixed(): void
    {
        $this->assertTrue((new RateLimiter($this->downRedis(), 'p:'))->hit('b', 1, 60)['allowed']);
        $r = $this->redis();
        $prefix = $this->prefix();
        (new LockManager($r, $prefix))->acquire('x', 30);
        (new RateLimiter($r, $prefix))->hit('y', 5, 30);
        $this->assertSame(1, (int) $r->client()->exists($prefix . 'lock:x'));
        $this->assertSame(1, (int) $r->client()->exists($prefix . 'rl:y'));
        $r->client()->del([$prefix . 'lock:x', $prefix . 'rl:y']);
    }

    /**
     * Documents RC-SR2-15: RedisConnectionConfig promises the password is "never part of var_dump()/print_r() output or
     * error text", which __debugInfo() delivers, but the property is public readonly and the class has no
     * __serialize()/JsonSerializable guard: json_encode(), serialize(), var_export(), an (array) cast and
     * get_object_vars() all contain the password. A caller that logs, caches or exports the config leaks it. Flip: make
     * these outputs omit or mask the password.
     */
    public function testRc15PasswordSurvivesJsonSerializeAndVarExport(): void
    {
        $c = new RedisConnectionConfig('redis.internal', 6379, 0, 'Sup3rSecretPw');
        $this->assertStringContainsString('Sup3rSecretPw', (string) json_encode($c));
        $this->assertStringContainsString('Sup3rSecretPw', serialize($c));
        $this->assertStringContainsString('Sup3rSecretPw', var_export($c, true));
        $this->assertContains('Sup3rSecretPw', get_object_vars($c));
        $this->assertContains('Sup3rSecretPw', (array) $c);
        // the documented guarantees hold
        ob_start();
        var_dump($c);
        print_r($c);
        $dump = (string) ob_get_clean();
        $this->assertStringNotContainsString('Sup3rSecretPw', $dump);
    }

    /**
     * Documents RC-SR2-21 (INFO, admin-only): RedisAdmin::test() will open a TCP connection to any host/port an admin
     * types, and its reason code distinguishes a live Redis from a closed port, so the Redis settings page is a small
     * port-scan oracle with none of the UrlPolicy protections webhooks have. Also, turning certificate verification
     * off disables the host-name check too (verify_peer and verify_peer_name). Neither message ever contains the password.
     */
    public function testRc21AdminConnectionTestIsAHostPortOracle(): void
    {
        $closed = (new RedisAdmin([]))->test(['host' => '127.0.0.1', 'port' => $this->closedPort(), 'password' => 'Sup3rSecretPw']);
        $this->assertFalse($closed['ok']);
        $this->assertSame('unreachable', $closed['reason']);
        $this->assertStringNotContainsString('Sup3rSecretPw', $closed['message']);

        if (TestRedis::available()) {
            $open = (new RedisAdmin([]))->test(['host' => '127.0.0.1', 'port' => (int) getenv('RIVETCORE_TEST_REDIS_PORT')]);
            $this->assertSame('ok', $open['reason'], 'a live service answers differently from a closed port');
        }

        $params = (new RedisConnectionConfig('redis.internal', 6379, 0, null, null, true, false))->toPredisParameters();
        $this->assertFalse($params['ssl']['verify_peer']);
        $this->assertFalse($params['ssl']['verify_peer_name']);
    }

    /** Guard (no finding): a stored-password round trip never shows up in RedisAdmin::test() output, even on auth failure. */
    public function testGuardAuthFailureMessageHasNoPassword(): void
    {
        $r = (new RedisAdmin([]))->test(['host' => '127.0.0.1', 'port' => $this->closedPort(), 'password' => 'Pw-With-$pecial', 'username' => 'svc']);
        $this->assertStringNotContainsString('Pw-With', json_encode($r) ?: '');
    }
}
