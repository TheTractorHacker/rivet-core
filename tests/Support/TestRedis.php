<?php

declare(strict_types=1);

namespace RivetCore\Tests\Support;

use RivetCore\Redis\RedisClientProviderInterface;

/** Provider for tests: RIVETCORE_TEST_REDIS_PORT names a throwaway Redis; null client when unset/unreachable. */
final class TestRedis implements RedisClientProviderInterface
{
    public function __construct(private ?int $port = null, private bool $down = false)
    {
    }

    public static function available(): bool
    {
        return (bool) getenv('RIVETCORE_TEST_REDIS_PORT');
    }

    public function client(): ?\Predis\Client
    {
        if ($this->down) {
            return null;
        }
        $port = $this->port ?? (int) getenv('RIVETCORE_TEST_REDIS_PORT');
        if (!$port) {
            return null;
        }

        return new \Predis\Client(['scheme' => 'tcp', 'host' => '127.0.0.1', 'port' => $port, 'timeout' => 0.5]);
    }
}
