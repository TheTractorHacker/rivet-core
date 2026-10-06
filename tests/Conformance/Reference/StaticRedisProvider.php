<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance\Reference;

use RivetCore\Redis\RedisClientProviderInterface;

/**
 * Reference RedisClientProviderInterface: builds a client from explicit parameters, and returns null instead of throwing
 * when it cannot even construct one. (Predis connects lazily, so an unreachable server shows up on the first command,
 * which Core's helpers catch.)
 */
final class StaticRedisProvider implements RedisClientProviderInterface
{
    /** @param array<string,mixed>|null $parameters null means "not configured" */
    public function __construct(private ?array $parameters)
    {
    }

    public function client(): ?\Predis\Client
    {
        if ($this->parameters === null) {
            return null;
        }
        try {
            return new \Predis\Client($this->parameters + ['timeout' => 0.3, 'read_write_timeout' => 0.3]);
        } catch (\Throwable) {
            return null;
        }
    }
}
