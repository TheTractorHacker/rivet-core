<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Support;

use Psr\SimpleCache\CacheInterface;

/**
 * The read-path cache of the RMM (scaling item S8): the fleet counters of the dashboard, the capacity panel's device aggregate and the active releases a check-in
 * looks up. A short TTL with STALE-WHILE-REVALIDATE and SINGLE-FLIGHT refresh, so ten thousand devices and a dozen open dashboards cannot each run the
 * expensive query: an entry is FRESH for `$freshS` seconds, then STALE for a further `$staleS` during which every reader still gets the old value instantly and exactly
 * ONE request (the one that wins a server-side named lock) recomputes it; only a missing or fully expired entry makes a caller compute inline.
 *
 * Backends: an injected PSR-16 cache (Core's Redis metadata cache, or any edition cache; shared by all web nodes) or, when none is given, the `rmm_cache` table
 * (migration 0021). The database backend costs one primary-key read per lookup, so it is only worth it for expensive queries ({@see shared()} says which one this is;
 * the per-check-in release lookup is cached only on a shared cache). Values are JSON, so only arrays and scalars can be cached. A cache that fails (an outage of
 * the shared cache, a missing table) NEVER fails the caller: it computes and returns the value.
 *
 * INVALIDATION. {@see invalidate()} marks every entry of a group stale (soft: readers keep getting the old value while one request refreshes it, so an invalidation
 * is never a stampede); {@see forget()} removes one entry. Core calls invalidate('fleet') when a device is enrolled, approved, revoked, retired or deleted and
 * invalidate('releases') when a release changes; an edition that writes those tables itself calls it too.
 *
 * @api
 */
final class RmmCache
{
    public const GROUP_FLEET = 'fleet';
    public const GROUP_RELEASES = 'releases';
    public const GROUP_CAPACITY = 'capacity';

    /** @var array{hit:int,stale:int,miss:int,refresh:int,error:int} */
    private array $stats = ['hit' => 0, 'stale' => 0, 'miss' => 0, 'refresh' => 0, 'error' => 0];
    /** @var array<string,int> request-local generation per group (PSR backend), so the generation is read once per request */
    private array $generations = [];

    public function __construct(private readonly Sql $sql, private readonly ?CacheInterface $shared = null, private readonly string $prefix = 'rivetcore:rmm:')
    {
    }

    /** True when the cache is a shared PSR-16 cache (cheap to read), false for the database table (one statement per lookup). */
    public function shared(): bool
    {
        return $this->shared !== null;
    }

    /** @return array{hit:int,stale:int,miss:int,refresh:int,error:int} counters of this process, for tests and the panel */
    public function stats(): array
    {
        return $this->stats;
    }

    /**
     * The cached value of $key, computed by $compute when it is missing or expired.
     *
     * @param string $group invalidation group ({@see invalidate()})
     * @param int $freshS seconds the value is served without question; 0 disables caching (the closure just runs)
     * @param int $staleS further seconds an old value may be served while one request refreshes it
     * @param \Closure():mixed $compute must return JSON-encodable data
     */
    public function remember(string $group, string $key, int $freshS, int $staleS, \Closure $compute): mixed
    {
        if ($freshS <= 0) {
            return $compute();
        }
        $full = $group . ':' . $key;
        $now = $this->sql->time();
        $entry = $this->read($group, $full);
        if ($entry !== null && $entry['fresh_until'] > $now) {
            ++$this->stats['hit'];

            return $entry['value'];
        }
        if ($entry !== null && $entry['stale_until'] > $now) {
            // stale: serve it, and let exactly one request refresh it
            if ($this->claimRefresh($full)) {
                ++$this->stats['refresh'];
                try {
                    $this->write($group, $full, $compute(), $freshS, $staleS);
                } finally {
                    $this->releaseRefresh($full);
                }
            }
            ++$this->stats['stale'];

            return $entry['value'];
        }
        ++$this->stats['miss'];
        $value = $compute();
        $this->write($group, $full, $value, $freshS, $staleS);

        return $value;
    }

    /**
     * Mark every entry of the group stale. SOFT (default): the next readers still get the old value while one of them refreshes it, so a frequent event never causes a stampede.
     * HARD: the entries are dropped, the next reader computes inline (for the rare change an administrator expects to see on the next page load).
     */
    public function invalidate(string $group, bool $hard = false): void
    {
        try {
            if ($this->shared !== null) {
                $this->shared->set($this->prefix . ($hard ? 'hard:' : 'gen:') . $group, $this->generation($group, $hard) + 1, 86400 * 30);
                unset($this->generations[$group . ($hard ? '#h' : '')]);

                return;
            }
            $this->sql->run('UPDATE rmm_cache SET fresh_until = ?' . ($hard ? ', stale_until = ?' : '') . ' WHERE cache_key LIKE ?',
                $hard ? [$this->sql->utcAt(-1), $this->sql->utcAt(-1), $this->like($group . ':')] : [$this->sql->utcAt(-1), $this->like($group . ':')]);
        } catch (\Throwable) {
            ++$this->stats['error'];
        }
    }

    /** Remove one entry outright. */
    public function forget(string $group, string $key): void
    {
        $full = $group . ':' . $key;
        try {
            if ($this->shared !== null) {
                $this->shared->delete($this->prefix . 'e:' . md5($full));

                return;
            }
            $this->sql->run('DELETE FROM rmm_cache WHERE cache_key = ?', [$this->dbKey($full)]);
        } catch (\Throwable) {
            ++$this->stats['error'];
        }
    }

    /** Delete entries that are past their stale window (the database backend only; a PSR cache expires them itself). @return int rows removed */
    public function prune(): int
    {
        if ($this->shared !== null) {
            return 0;
        }
        try {
            return $this->sql->run('DELETE FROM rmm_cache WHERE stale_until < ? LIMIT 5000', [$this->sql->utcNow()]);
        } catch (\Throwable) {
            return 0;
        }
    }

    // ------------------------------------------------------------------ backends

    /** @return array{value:mixed,fresh_until:int,stale_until:int}|null */
    private function read(string $group, string $full): ?array
    {
        try {
            if ($this->shared !== null) {
                $raw = $this->shared->get($this->prefix . 'e:' . md5($full));
                if (is_array($raw) && ($raw['hard'] ?? null) !== $this->generation($group, true)) {
                    return null;   // a hard invalidation since it was written: gone
                }
                if (!is_array($raw) || ($raw['gen'] ?? null) !== $this->generation($group)) {
                    // a different generation: still usable as a STALE value, never as a fresh one
                    return is_array($raw) && array_key_exists('v', $raw) ? ['value' => $raw['v'], 'fresh_until' => 0, 'stale_until' => (int) ($raw['stale'] ?? 0)] : null;
                }

                return ['value' => $raw['v'], 'fresh_until' => (int) $raw['fresh'], 'stale_until' => (int) $raw['stale']];
            }
            $r = $this->sql->one('SELECT cache_value, fresh_until, stale_until FROM rmm_cache WHERE cache_key = ?', [$this->dbKey($full)]);
            if ($r === null) {
                return null;
            }
            $decoded = json_decode((string) $r['cache_value'], true);

            return ['value' => $decoded['v'] ?? null, 'fresh_until' => Sql::ts((string) $r['fresh_until']), 'stale_until' => Sql::ts((string) $r['stale_until'])];
        } catch (\Throwable) {
            ++$this->stats['error'];

            return null;
        }
    }

    private function write(string $group, string $full, mixed $value, int $freshS, int $staleS): void
    {
        $now = $this->sql->time();
        try {
            if ($this->shared !== null) {
                $this->shared->set($this->prefix . 'e:' . md5($full), ['v' => $value, 'gen' => $this->generation($group), 'hard' => $this->generation($group, true), 'fresh' => $now + $freshS, 'stale' => $now + $freshS + $staleS], $freshS + $staleS);

                return;
            }
            $this->sql->run('INSERT INTO rmm_cache (cache_key, cache_value, fresh_until, stale_until, refresh_until) VALUES (?, ?, ?, ?, NULL)
                ON DUPLICATE KEY UPDATE cache_value = VALUES(cache_value), fresh_until = VALUES(fresh_until), stale_until = VALUES(stale_until), refresh_until = NULL',
                [$this->dbKey($full), (string) json_encode(['v' => $value]), gmdate('Y-m-d H:i:s', $now + $freshS), gmdate('Y-m-d H:i:s', $now + $freshS + $staleS)]);
        } catch (\Throwable) {
            ++$this->stats['error'];
        }
    }

    private function generation(string $group, bool $hard = false): int
    {
        $slot = $group . ($hard ? '#h' : '');
        if (isset($this->generations[$slot])) {
            return $this->generations[$slot];
        }
        $g = $this->shared?->get($this->prefix . ($hard ? 'hard:' : 'gen:') . $group);

        return $this->generations[$slot] = is_int($g) ? $g : 0;
    }

    /** Single-flight: a server-side named lock (works across processes and web nodes that share the database), never waiting. */
    private function claimRefresh(string $full): bool
    {
        try {
            return (int) $this->sql->val("SELECT GET_LOCK(CONCAT(?, ':', LEFT(MD5(IFNULL(DATABASE(), '')), 10)), 0)", ['rmm_cache_' . md5($full)]) === 1;
        } catch (\Throwable) {
            return false;
        }
    }

    private function releaseRefresh(string $full): void
    {
        try {
            $this->sql->val("SELECT RELEASE_LOCK(CONCAT(?, ':', LEFT(MD5(IFNULL(DATABASE(), '')), 10)))", ['rmm_cache_' . md5($full)]);
        } catch (\Throwable) {
            // the lock goes with the connection
        }
    }

    /** The table key: the readable key when it fits, otherwise its hash (the column is 100 characters). */
    private function dbKey(string $full): string
    {
        return strlen($full) <= 100 ? $full : substr($full, 0, 60) . '#' . md5($full);
    }

    private function like(string $prefix): string
    {
        return addcslashes($prefix, '%_\\') . '%';
    }
}
