<?php

declare(strict_types=1);

namespace RivetCore\Redis;

use Predis\Client;

/**
 * Admin tooling around a Redis server: validate and test connection values, read stats, count and clear an
 * allowlisted set of key groups, and set the memory limit. Where the connection comes from (environment,
 * settings table) and which key groups may be cleared are the edition's business: pass the groups in.
 *
 * @api
 */
final class RedisAdmin
{
    public const POLICIES = ['allkeys-lru', 'volatile-lru', 'allkeys-lfu', 'volatile-lfu', 'noeviction'];

    /** @param array<string,array{label:string,patterns:list<string>}> $clearable */
    public function __construct(private array $clearable)
    {
    }

    /** @return ?string an error message, or null when the values are acceptable (plain host/port/db/password check; see RedisConnectionConfig::validate() for TLS and username) */
    public static function validate(string $host, int $port, int $db, string $password): ?string
    {
        return (new RedisConnectionConfig($host, $port, $db, $password === '' ? null : $password))->validate();
    }

    /**
     * Build a Predis client from the edition's settings array (host, port, db, password, optional username, tls, ...)
     * or from a RedisConnectionConfig.
     *
     * @param array<string,mixed>|RedisConnectionConfig $p
     */
    public function client(array|RedisConnectionConfig $p, float $timeout = 1.0): Client
    {
        $config = $p instanceof RedisConnectionConfig ? $p : RedisConnectionConfig::fromArray($p);

        return new Client($config->toPredisParameters($timeout));
    }

    /**
     * Try a connection and say why it failed. 'reason' is one of ok, invalid, auth, tls, unreachable, unexpected.
     * No message ever contains the password (or any text taken from the server's error).
     *
     * @param array<string,mixed>|RedisConnectionConfig $p
     * @return array{ok:bool, message:string, reason:string}
     */
    public function test(array|RedisConnectionConfig $p): array
    {
        $config = $p instanceof RedisConnectionConfig ? $p : RedisConnectionConfig::fromArray($p);
        $invalid = $config->validate(true);
        if ($invalid !== null) {
            return ['ok' => false, 'message' => $invalid, 'reason' => 'invalid'];
        }
        // Predis surfaces a failed TLS handshake as a PHP warning as well as an exception; keep it out of the caller's logs.
        set_error_handler(static fn (): bool => true, E_WARNING | E_NOTICE);
        try {
            $c = $this->client($config);
            $c->connect();
            $pong = (string) $c->ping();

            return $pong === 'PONG'
                ? ['ok' => true, 'message' => 'Connected.', 'reason' => 'ok']
                : ['ok' => false, 'message' => 'Unexpected reply from the server.', 'reason' => 'unexpected'];
        } catch (\Throwable $e) {
            $reason = self::classify($e->getMessage(), $config);
            $message = match ($reason) {
                'auth' => $config->username !== null && $config->username !== ''
                    ? 'Authentication failed: the server rejected this username and password, or the user may not use this database.'
                    : 'Authentication failed: the server wants a password, or the password is wrong.',
                'tls' => 'Could not complete the TLS handshake. Check that the server has TLS enabled on this port, and that the CA file matches the server certificate' . ($config->verifyPeer ? ' (or turn certificate verification off for a test).' : '.'),
                default => 'Could not connect. Check the host and port, and that Redis is running.',
            };

            return ['ok' => false, 'message' => $config->redact($message), 'reason' => $reason];
        } finally {
            restore_error_handler();
        }
    }

    /** @return 'auth'|'tls'|'unreachable' */
    private static function classify(string $error, RedisConnectionConfig $config): string
    {
        $m = $config->redact($error);
        if (preg_match('/NOAUTH|WRONGPASS|invalid (username-)?password|ERR AUTH|NOPERM|AUTH failed|authentication/i', $m)) {
            return 'auth';
        }
        if ($config->tls && preg_match('/\bssl\b|\btls\b|crypto|certificate|handshake|peer|wrong version number|unexpected eof/i', $m)) {
            return 'tls';
        }

        return 'unreachable';
    }

    /** Flatten INFO sections, tolerating Predis returning either capitalised or lower-case section names. */
    public function stats(Client $c): array
    {
        $info = $c->info();
        $flat = [];
        foreach ($info as $section => $values) {
            if (is_array($values)) foreach ($values as $k => $v) $flat[$k] = $v;
        }
        $hits = (int) ($flat['keyspace_hits'] ?? 0);
        $miss = (int) ($flat['keyspace_misses'] ?? 0);
        $keys = 0;
        foreach ($flat as $k => $v) {
            if (preg_match('/^db\d+$/', (string) $k)) $keys += is_array($v) ? (int) ($v['keys'] ?? 0) : (preg_match('/keys=(\d+)/', (string) $v, $m) ? (int) $m[1] : 0);
        }
        $policy = (string) ($flat['maxmemory_policy'] ?? '');
        return [
            'version' => (string) ($flat['redis_version'] ?? '?'),
            'uptime_seconds' => (int) ($flat['uptime_in_seconds'] ?? 0),
            'clients' => (int) ($flat['connected_clients'] ?? 0),
            'memory_used' => (string) ($flat['used_memory_human'] ?? '?'),
            'maxmemory' => (int) ($flat['maxmemory'] ?? 0),
            'policy' => $policy,
            'ops_per_sec' => (int) ($flat['instantaneous_ops_per_sec'] ?? 0),
            'hit_rate' => ($hits + $miss) > 0 ? round($hits / ($hits + $miss) * 100) : null,
            'keys' => $keys,
            'aof' => (string) ($flat['aof_enabled'] ?? '0') === '1',
            'last_save' => (int) ($flat['rdb_last_save_time'] ?? 0),
        ];
    }

    /** Count keys per clearable group (bounded SCAN so a huge keyspace cannot stall the page). @return array<string,int> */
    public function groupCounts(Client $c, int $cap = 5000): array
    {
        $out = [];
        foreach ($this->clearable as $group => $def) {
            $n = 0;
            foreach ($def['patterns'] as $pattern) {
                foreach (new \Predis\Collection\Iterator\Keyspace($c, $pattern, 500) as $_) {
                    if (++$n >= $cap) break;
                }
            }
            $out[$group] = $n;
        }
        return $out;
    }

    /** Delete one allowlisted group of keys. Returns how many were removed. */
    public function clear(Client $c, string $group): int
    {
        if (!isset($this->clearable[$group])) throw new \InvalidArgumentException('Unknown group.');
        $removed = 0;
        foreach ($this->clearable[$group]['patterns'] as $pattern) {
            $batch = [];
            foreach (new \Predis\Collection\Iterator\Keyspace($c, $pattern, 500) as $key) {
                $batch[] = $key;
                if (count($batch) >= 200) { $removed += (int) $c->del($batch); $batch = []; }
            }
            if ($batch) $removed += (int) $c->del($batch);
        }
        return $removed;
    }

    /** @return array{ok:bool, persisted:bool, message:string} */
    public function setMemory(Client $c, int $megabytes, string $policy): array
    {
        if ($megabytes < 64 || $megabytes > 65536) return ['ok' => false, 'persisted' => false, 'message' => 'Choose a limit between 64 MB and 65536 MB.'];
        if (!in_array($policy, self::POLICIES, true)) return ['ok' => false, 'persisted' => false, 'message' => 'Unknown eviction policy.'];
        try {
            // executeRaw() returns a server error (NOPERM, "unknown command") as text instead of throwing; $error says so.
            foreach ([['maxmemory', (string) ($megabytes * 1048576)], ['maxmemory-policy', $policy]] as [$name, $value]) {
                $c->executeRaw(['CONFIG', 'SET', $name, $value], $error);
                if ($error) {
                    throw new \RuntimeException('CONFIG SET refused');
                }
            }
        } catch (\Throwable) {
            return ['ok' => false, 'persisted' => false, 'message' => 'Redis refused the change (CONFIG may be disabled).'];
        }
        try {
            $c->config('REWRITE');
            return ['ok' => true, 'persisted' => true, 'message' => 'Applied and saved to the Redis config file.'];
        } catch (\Throwable) {
            return ['ok' => true, 'persisted' => false, 'message' => 'Applied now, but Redis could not save it to its config file, so a restart will undo it. Add these lines to redis.conf to keep it: maxmemory ' . $megabytes . 'mb and maxmemory-policy ' . $policy . '.'];
        }
    }
}
