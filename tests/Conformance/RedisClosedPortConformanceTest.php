<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use RivetCore\Redis\RedisClientProviderInterface;
use RivetCore\Tests\Conformance\Reference\StaticRedisProvider;
use RivetCore\Tests\Support\TestRedis;

/** Reference run: reachable = the throwaway Redis named by RIVETCORE_TEST_REDIS_PORT (skipped when unset); unavailable = a closed port. */
final class RedisClosedPortConformanceTest extends RedisClientProviderConformanceTestCase
{
    protected function reachableProvider(): ?RedisClientProviderInterface
    {
        return TestRedis::available() ? new StaticRedisProvider(['host' => '127.0.0.1', 'port' => (int) getenv('RIVETCORE_TEST_REDIS_PORT')]) : null;
    }

    protected function unavailableProvider(): RedisClientProviderInterface
    {
        return new StaticRedisProvider(['host' => '127.0.0.1', 'port' => 1, 'password' => 'never-appears-in-output']);
    }

    protected function failOpenSecret(): ?string
    {
        return 'never-appears-in-output';
    }
}
