<?php

declare(strict_types=1);

namespace RivetCore\Redis;

/**
 * How Core obtains a Redis connection. The edition decides where host, port and password come from
 * (environment, settings table, defaults) and returns null when Redis is unavailable; every Core
 * Redis feature then fails open, because Redis is a coordination aid and never a hard dependency.
 *
 * @api
 */
interface RedisClientProviderInterface
{
    public function client(): ?\Predis\Client;
}
