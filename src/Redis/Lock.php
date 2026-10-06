<?php

declare(strict_types=1);

namespace RivetCore\Redis;

/** A lock handed out by LockManager.
 *
 * @api
 */
final class Lock
{
    private const RELEASE = "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end";
    private const EXTEND = "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('pexpire', KEYS[1], ARGV[2]) else return 0 end";

    public function __construct(
        private RedisClientProviderInterface $redis,
        private string $key,
        private string $token,
        private bool $held,
        private bool $degraded,
    ) {
    }

    /** True when this caller may proceed (it owns the lock, or Redis is unavailable). */
    public function held(): bool
    {
        return $this->held;
    }

    /** True when Redis could not be reached, so no mutual exclusion is actually in force. */
    public function degraded(): bool
    {
        return $this->degraded;
    }

    /** Push the expiry out for a job that outlives its initial TTL. */
    public function extend(int $ttlSeconds): bool
    {
        if (!$this->held || $this->degraded) {
            return $this->held;
        }
        try {
            return (int) $this->redis->client()?->eval(self::EXTEND, 1, $this->key, $this->token, (string) (max(1, $ttlSeconds) * 1000)) === 1;
        } catch (\Throwable) {
            return false;
        }
    }

    public function release(): void
    {
        if (!$this->held || $this->degraded) {
            return;
        }
        $this->held = false;
        try {
            $this->redis->client()?->eval(self::RELEASE, 1, $this->key, $this->token);
        } catch (\Throwable) {
            // the TTL will clear it
        }
    }
}
