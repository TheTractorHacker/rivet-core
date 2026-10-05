<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RivetCore\Redis\CronGuard;
use RivetCore\Redis\LockManager;
use RivetCore\Redis\RateLimiter;
use RivetCore\Redis\RedisAdmin;
use RivetCore\Tests\Support\TestRedis;

final class RedisTest extends TestCase
{
    private function needRedis(): TestRedis
    {
        if (!TestRedis::available()) {
            $this->markTestSkipped('RIVETCORE_TEST_REDIS_PORT not set (throwaway Redis required).');
        }
        $r = new TestRedis();
        $r->client()->flushdb();

        return $r;
    }

    public function testLockIsExclusiveAndReleasable(): void
    {
        $m = new LockManager($this->needRedis(), 'rivetit:');
        $a = $m->acquire('job', 30);
        $b = $m->acquire('job', 30);
        $this->assertTrue($a->held());
        $this->assertFalse($a->degraded());
        $this->assertFalse($b->held());
        $a->release();
        $this->assertTrue($m->acquire('job', 30)->held());
    }

    public function testLockKeyKeepsTheEditionPrefixLayout(): void
    {
        $r = $this->needRedis();
        (new LockManager($r, 'rivetit:'))->acquire('x', 30);
        $this->assertSame(1, (int) $r->client()->exists('rivetit:lock:x'));
    }

    public function testReleaseOnlyDeletesOwnToken(): void
    {
        $r = $this->needRedis();
        $m = new LockManager($r, 'p:');
        $a = $m->acquire('j', 30);
        $r->client()->set('p:lock:j', 'someone-else');
        $a->release();
        $this->assertSame('someone-else', $r->client()->get('p:lock:j'));
    }

    public function testExtendAndRun(): void
    {
        $r = $this->needRedis();
        $m = new LockManager($r, 'p:');
        $a = $m->acquire('j', 5);
        $this->assertTrue($a->extend(60));
        $this->assertGreaterThan(5, $r->client()->ttl('p:lock:j'));
        $a->release();
        [$ran, $v] = $m->run('k', 30, fn () => 'done');
        $this->assertTrue($ran);
        $this->assertSame('done', $v);
        $this->assertSame(0, (int) $r->client()->exists('p:lock:k'));
        $held = $m->acquire('k', 30);
        [$ran2] = $m->run('k', 30, fn () => 'no');
        $this->assertFalse($ran2);
        $held->release();
    }

    public function testLockFailsOpenWhenRedisIsDown(): void
    {
        $l = (new LockManager(new TestRedis(null, true), 'p:'))->acquire('j', 30);
        $this->assertTrue($l->held());
        $this->assertTrue($l->degraded());
        $l->release();
        $unreachable = (new LockManager(new TestRedis(1), 'p:'))->acquire('j', 30);
        $this->assertTrue($unreachable->held());
        $this->assertTrue($unreachable->degraded());
    }

    public function testRateLimiterWindowAndFailOpen(): void
    {
        $rl = new RateLimiter($this->needRedis(), 'rivetit:');
        $this->assertTrue($rl->hit('b', 2, 60)['allowed']);
        $this->assertSame(0, $rl->hit('b', 2, 60)['remaining']);
        $third = $rl->hit('b', 2, 60);
        $this->assertFalse($third['allowed']);
        $this->assertGreaterThan(0, $third['retry_after']);
        $this->assertTrue((new RateLimiter(new TestRedis(null, true), 'p:'))->hit('b', 1, 60)['allowed']);
    }

    public function testCronGuard(): void
    {
        $m = new LockManager($this->needRedis(), 'p:');
        $g = new CronGuard($m);
        $first = $g->acquire('nightly');
        $this->assertNotNull($first);
        $this->assertNull($g->acquire('nightly'));
        $first->release();
    }

    public function testAdminClearsOnlyAllowlistedGroupsAndCounts(): void
    {
        $r = $this->needRedis();
        $c = $r->client();
        $c->set('rivetit:rl:a', '1');
        $c->set('api_rl:b', '1');
        $c->set('keep:me', '1');
        $admin = new RedisAdmin(['rate_limits' => ['label' => 'RL', 'patterns' => ['rivetit:rl:*', 'api_rl:*']]]);
        $this->assertSame(['rate_limits' => 2], $admin->groupCounts($c));
        $this->assertSame(2, $admin->clear($c, 'rate_limits'));
        $this->assertSame(1, (int) $c->exists('keep:me'));
        $this->expectException(\InvalidArgumentException::class);
        $admin->clear($c, 'everything');
    }

    public function testAdminValidateTestStatsMemory(): void
    {
        $r = $this->needRedis();
        $this->assertNull(RedisAdmin::validate('127.0.0.1', 6379, 0, ''));
        $this->assertNotNull(RedisAdmin::validate('bad host;rm', 6379, 0, ''));
        $this->assertNotNull(RedisAdmin::validate('h', 70000, 0, ''));
        $this->assertNotNull(RedisAdmin::validate('h', 6379, 16, ''));
        $admin = new RedisAdmin([]);
        $port = (int) getenv('RIVETCORE_TEST_REDIS_PORT');
        $this->assertTrue($admin->test(['host' => '127.0.0.1', 'port' => $port, 'password' => null, 'db' => 0])['ok']);
        $this->assertFalse($admin->test(['host' => '127.0.0.1', 'port' => 1, 'password' => null, 'db' => 0])['ok']);
        $stats = $admin->stats($r->client());
        $this->assertNotSame('?', $stats['version']);
        $this->assertFalse($admin->setMemory($r->client(), 1, 'allkeys-lru')['ok']);
        $this->assertFalse($admin->setMemory($r->client(), 128, 'bogus')['ok']);
        $this->assertTrue($admin->setMemory($r->client(), 128, 'allkeys-lru')['ok']);
    }
}
