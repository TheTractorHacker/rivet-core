<?php

declare(strict_types=1);

namespace RivetCore\Mcp;

use Psr\SimpleCache\CacheInterface;
use RivetCore\Redis\RedisClientProviderInterface;

/** Public OAuth discovery/JWKS cache (PSR-16). Redis outages are treated as cache misses. Not final so an edition can pin its default provider.
 *
 * @api
 */
class RedisMetadataCache implements CacheInterface
{
    public function __construct(private RedisClientProviderInterface $redis, private string $keyPrefix = 'mcp_metadata:') {}

    private function key(string $key): string { return $this->keyPrefix . hash('sha256', $key); }

    public function get(string $key, mixed $default = null): mixed
    {
        try {
            $value = $this->redis->client()?->get($this->key($key));
            return is_string($value) ? json_decode($value, true, 32, JSON_THROW_ON_ERROR) : $default;
        } catch (\Throwable) { return $default; }
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $seconds = $ttl instanceof \DateInterval ? (new \DateTimeImmutable())->add($ttl)->getTimestamp() - time() : ($ttl ?? 3600);
        if ($seconds < 1) return $this->delete($key);
        try {
            $redis = $this->redis->client();
            if (!$redis) return false;
            $redis->setex($this->key($key), $seconds, json_encode($value, JSON_THROW_ON_ERROR));
            return true;
        } catch (\Throwable) { return false; }
    }

    public function delete(string $key): bool
    {
        try { return $this->redis->client()?->del([$this->key($key)]) !== null; }
        catch (\Throwable) { return false; }
    }

    public function clear(): bool
    {
        // Clearing the shared Redis database would affect unrelated app data.
        return false;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $values = [];
        foreach ($keys as $key) $values[$key] = $this->get($key, $default);
        return $values;
    }

    /** @param iterable<string,mixed> $values */
    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        $ok = true;
        foreach ($values as $key => $value) $ok = $this->set($key, $value, $ttl) && $ok;
        return $ok;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $ok = true;
        foreach ($keys as $key) $ok = $this->delete($key) && $ok;
        return $ok;
    }

    public function has(string $key): bool { return $this->get($key) !== null; }
}
