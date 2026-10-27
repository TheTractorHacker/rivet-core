<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Capacity\DbTokenBucketStore;
use RivetCore\Rmm\Capacity\RedisTokenBucketStore;
use RivetCore\Rmm\Capacity\TokenBucketStoreInterface;
use RivetCore\Rmm\Support\Sql;
use RivetCore\Testing\TokenBucketStoreConformanceTestCase;
use RivetCore\Tests\Support\MutableClock;
use RivetCore\Tests\Support\MysqliDatabase;
use RivetCore\Tests\Support\ScratchDb;
use RivetCore\Tests\Support\TestRedis;

/** The two real stores against the kit: the table (needs the scratch database) and Redis (needs the throwaway Redis). */
final class TokenBucketStoreKitsTest extends TokenBucketStoreConformanceTestCase
{
    private ?\mysqli $conn = null;
    private ?TokenBucketStoreInterface $store = null;
    private bool $redisCase = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (!getenv('RIVETCORE_TEST_DB_NAME') || !str_contains((string) getenv('RIVETCORE_TEST_DB_NAME'), 'scratch')) {
            $this->markTestSkipped('A scratch database is required.');
        }
        $this->conn = ScratchDb::connect();
        $this->assertNotNull($this->conn);
        (new \RivetCore\Rmm\Migration\Migration0021ScaleStorage())->up(new MysqliDatabase($this->conn));
    }

    protected function tearDown(): void
    {
        $this->conn?->close();
        parent::tearDown();
    }

    protected function store(): TokenBucketStoreInterface
    {
        return $this->store ??= new DbTokenBucketStore(new Sql(new MysqliDatabase($this->conn ?? throw new \LogicException('no connection')), new MutableClock()));
    }

    protected function breakStore(): ?callable
    {
        // the table disappears: the store must admit
        $this->conn?->query('DROP TABLE rmm_token_buckets');

        return function (): void {
            (new \RivetCore\Rmm\Migration\Migration0021ScaleStorage())->up(new MysqliDatabase($this->conn ?? throw new \LogicException('no connection')));
        };
    }

    public function testTheRedisStoreMeetsTheSameKit(): void
    {
        if (!TestRedis::available()) {
            $this->markTestSkipped('RIVETCORE_TEST_REDIS_PORT (a throwaway Redis) is required.');
        }
        $redis = new RedisTokenBucketStore(new TestRedis(), 'kit_' . getmypid() . ':');
        $this->store = $redis;
        $this->redisCase = true;
        $this->testAFreshBucketStartsFullAndRefusesAfterTheBurst();
        $this->testARefusalSaysHowLongUntilTheNextToken();
        $this->testTokensRefillWithTime();
        $this->testAnIdleBucketNeverHoldsMoreThanTheBurst();
        $this->testBucketsAreIndependentPerKey();
        $this->assertTrue($this->redisCase);
        $this->assertTrue((new RedisTokenBucketStore(new TestRedis(null, true)))->take('k', 1, 0.001, 1)['ok'], 'Redis down admits');
    }
}
