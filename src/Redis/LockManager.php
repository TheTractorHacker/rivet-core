<?php

declare(strict_types=1);

namespace RivetCore\Redis;

/**
 * Redis mutex (SET NX EX + compare-and-delete release) so two processes - a cron run and a manual
 * "Sync now", or two workers - do not run the same job at once.
 *
 * FAILS OPEN: when Redis is unreachable acquire() reports the lock as held (degraded() is true) so
 * jobs keep running exactly as they did before locks existed. Callers that must not run without
 * mutual exclusion should check degraded() themselves.
 *
 * @api
 */
final class LockManager
{
    public function __construct(
        private RedisClientProviderInterface $redis,
        private string $keyPrefix,
    ) {
    }

    public function acquire(string $name, int $ttlSeconds): Lock
    {
        $key = $this->keyPrefix . 'lock:' . $name;
        $token = bin2hex(random_bytes(16));
        $client = $this->redis->client();
        if (!$client) {
            return new Lock($this->redis, $key, $token, true, true);
        }
        try {
            $ok = $client->set($key, $token, 'EX', max(1, $ttlSeconds), 'NX');

            return new Lock($this->redis, $key, $token, $ok !== null && (string) $ok === 'OK', false);
        } catch (\Throwable) {
            return new Lock($this->redis, $key, $token, true, true);
        }
    }

    /**
     * Run $fn under the lock; returns [ran, value]. ran=false means another holder had it.
     *
     * @return array{0:bool, 1:mixed}
     */
    public function run(string $name, int $ttlSeconds, callable $fn): array
    {
        $lock = $this->acquire($name, $ttlSeconds);
        if (!$lock->held()) {
            return [false, null];
        }
        try {
            return [true, $fn($lock)];
        } finally {
            $lock->release();
        }
    }
}
