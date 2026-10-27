<?php

declare(strict_types=1);

namespace RivetCore\Redis;

/**
 * How Core obtains a Redis connection. The edition decides where host, port and password come from
 * (environment, settings table, defaults) and returns null when Redis is unavailable; every Core
 * Redis feature then fails open, because Redis is a coordination aid and never a hard dependency.
 *
 * Contract (checked by Testing\RedisClientProviderConformanceTestCase): client() never throws; with Redis unreachable it returns
 * null promptly (it must not hand back an unconnected client); with Redis up it returns a working Predis client on the same
 * database every time.
 *
 * @api
 */
interface RedisClientProviderInterface
{
    public function client(): ?\Predis\Client;
}
