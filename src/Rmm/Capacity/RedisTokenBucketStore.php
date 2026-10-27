<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Capacity;

use RivetCore\Redis\RedisClientProviderInterface;

/**
 * Token buckets in Redis: one Lua call per take, atomic, no database statement. Fails open when Redis is unreachable.
 *
 * @api
 */
final class RedisTokenBucketStore implements TokenBucketStoreInterface
{
    private const SCRIPT = <<<'LUA'
local h = redis.call('hmget', KEYS[1], 't', 'ms')
local burst = tonumber(ARGV[1])
local rate = tonumber(ARGV[2])
local now = tonumber(ARGV[3])
local tokens = tonumber(h[1])
local ms = tonumber(h[2])
if tokens == nil or ms == nil then tokens = burst ms = now end
tokens = math.min(burst, tokens + math.max(0, now - ms) * rate / 1000)
local ok = 0
local retry = 0
if tokens >= 1 then
  tokens = tokens - 1
  ok = 1
else
  if rate > 0 then retry = math.ceil((1 - tokens) / rate * 1000) else retry = 60000 end
end
redis.call('hmset', KEYS[1], 't', tostring(tokens), 'ms', tostring(now))
local ttl = 60000
if rate > 0 then ttl = math.ceil(burst / rate * 1000) + 60000 end
redis.call('pexpire', KEYS[1], ttl)
return {ok, retry}
LUA;

    public function __construct(private readonly RedisClientProviderInterface $redis, private readonly string $keyPrefix = 'rivetcore:rmm:')
    {
    }

    public function take(string $key, int $burst, float $perSecond, int $nowMs): array
    {
        $client = $this->redis->client();
        if (!$client) {
            return ['ok' => true, 'retry_ms' => 0];
        }
        try {
            [$ok, $retry] = $client->eval(self::SCRIPT, 1, $this->keyPrefix . 'tb:' . $key, (string) $burst, (string) $perSecond, (string) $nowMs);

            return ['ok' => (int) $ok === 1, 'retry_ms' => (int) $retry];
        } catch (\Throwable) {
            return ['ok' => true, 'retry_ms' => 0];
        }
    }
}
