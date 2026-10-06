# Redis and health

`RivetCore\Redis` (locks, rate limits, cron guard, admin tooling, connection config) and `RivetCore\Health` (readiness).
Full configuration, authentication and TLS: [REDIS.md](../REDIS.md).

## What it owns

No tables. Keys are written under a prefix the edition chooses (`LockManager`, `RateLimiter` take it in the constructor).

## You supply

A `RedisClientProviderInterface` returning a connected Predis client or `null`. Everything else is built on that.

## Flags

None in Core. The edition decides whether Redis is configured at all; with no client every helper behaves as "Redis is down".

## Use it

<!-- run -->
```php
use RivetCore\Redis\{LockManager, RateLimiter, CronGuard};

// $redis is your RedisClientProviderInterface; here it may be a down provider, and the code below still works.
$locks = new LockManager($redis, 'myapp:');
[$ran, $value] = $locks->run('nightly-report', 600, fn () => 'did the work');

$limit = (new RateLimiter($redis, 'myapp:'))->hit('api:user:7', 100, 60);
echo $ran ? 'ran' : 'skipped', ', allowed=', var_export($limit['allowed'], true), "\n";
```

## How it fails: open, always

Redis is a coordination aid, never a hard dependency.

| Situation | Result |
|---|---|
| No client (not configured), server down, wrong password, command error | `LockManager::acquire()` returns a lock with `held() === true` and `degraded() === true`; `RateLimiter::hit()` returns `allowed: true`; `RedisMetadataCache` treats it as a cache miss; `ReadinessChecker` reports Redis but never fails readiness |
| Another process holds the lock | `held() === false`: the work is skipped |

**Consequence to design for:** while Redis is unavailable, nothing excludes a second copy of a cron job. Jobs guarded this way must be
idempotent (or use a database-level guard as well). `degraded()` lets the caller log that it ran unguarded.

Errors never include the password: `RedisConnectionConfig` hides it from `var_dump()`/`print_r()` and `RedisAdmin::test()` reports
auth, TLS and unreachable as distinct, password-free results.

`RedisAdmin::clear()` deletes only the key groups the edition passed to its constructor, never an arbitrary pattern.

## Health

`ReadinessChecker::check()` returns ok/fail for the database (answers, schema current via the edition's callback) and reports Redis
without failing on it. The report contains no host names, versions or error text, so it is safe to expose to a load balancer.
