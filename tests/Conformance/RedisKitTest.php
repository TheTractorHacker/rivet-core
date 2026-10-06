<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use RivetCore\Redis\RedisClientProviderInterface;
use RivetCore\Testing\RedisClientProviderConformanceTestCase;
use RivetCore\Tests\Conformance\Reference\PingingRedisProvider;

/** The kit against a probing provider (live checks need RIVETCORE_TEST_REDIS_PORT; the "Redis is down" checks always run). */
final class RedisKitTest extends RedisClientProviderConformanceTestCase
{
    use Flaw;

    private ?PingingRedisProvider $live = null;

    protected function provider(): RedisClientProviderInterface
    {
        return $this->live ??= new PingingRedisProvider((int) getenv('RIVETCORE_TEST_REDIS_PORT'), self::$flaw);
    }

    protected function unreachableProvider(): RedisClientProviderInterface
    {
        return new PingingRedisProvider(self::closedPort(), self::$flaw);
    }
}
