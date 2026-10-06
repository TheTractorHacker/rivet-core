# Redis and health

## Overview

`RivetCore\Redis` and `RivetCore\Health` give editions optional coordination (locks, rate limits, cron guards), Redis admin tooling and a readiness report.

- **Owns no tables and no migration.**
- Redis is a coordination aid, never a hard dependency. Every feature here fails open when Redis is missing or unreachable.
- Predis (`predis/predis`) is the client. The edition decides where connection values come from.

## Contracts an edition must implement

| Interface | Method | What Core expects |
|---|---|---|
| `Redis\RedisClientProviderInterface` | `client(): ?\Predis\Client` | A usable client, or `null` when Redis is not configured or unavailable. Return `null` rather than throwing. |
| `Health\ReadinessChecker` constructor | `callable(): bool $schemaIsCurrent` | True when the stored schema version matches the code. The edition owns that comparison. |
| `Database\DatabaseInterface` | see [../adapters.md](../adapters.md) | Used by the readiness check (`SELECT 1 AS ok`). |

Which key groups an admin may clear (`RedisAdmin` constructor) is also edition data: `array<string, array{label:string, patterns:list<string>}>`.

## Key classes

### RedisConnectionConfig

Plain values for one connection. `fromArray()` accepts `host`, `port`, `db` (or `database`), `password`, `username`, `tls`, `tls_verify`, `tls_ca_file`, `tls_cert_file`, `tls_key_file`; empty strings count as unset.

```php
use RivetCore\Redis\RedisConnectionConfig;

$cfg = RedisConnectionConfig::fromArray([
    'host' => 'redis.internal', 'port' => 6380, 'password' => 's3cret',
    'username' => 'rivet', 'tls' => true, 'tls_ca_file' => '/etc/ssl/redis-ca.pem',
]);
$cfg->validate();                  // null when acceptable, else an error message
$cfg->validate(true);              // also checks that the cert files are readable
$cfg->toPredisParameters(1.0);     // scheme tls, ssl verify_peer, cafile ...
$cfg->redact($errorText);          // password replaced by ***
```

### RedisAdmin

```php
use RivetCore\Redis\RedisAdmin;

$admin = new RedisAdmin(['cache' => ['label' => 'Cache', 'patterns' => ['rivetit:cache:*']]]);
$admin->test($cfg);                 // ['ok'=>bool, 'message'=>string, 'reason'=>'ok|invalid|auth|tls|unreachable|unexpected']
$client = $admin->client($cfg);     // Predis\Client
$admin->stats($client);             // version, memory, hit_rate, keys, ...
$admin->groupCounts($client);       // bounded SCAN counts per group
$admin->clear($client, 'cache');    // deletes only that allowlisted group; unknown group throws InvalidArgumentException
$admin->setMemory($client, 256, 'allkeys-lru');   // 64..65536 MB, policy from RedisAdmin::POLICIES
```

`RedisAdmin::validate($host, $port, $db, $password)` is the static check for the plain four values.

### LockManager and Lock

```php
use RivetCore\Redis\LockManager;

$locks = new LockManager($provider, 'rivetit:');       // key prefix is the edition's
$lock = $locks->acquire('cron:sync', 900);             // SET NX EX
if ($lock->held()) { /* work */ $lock->extend(900); $lock->release(); }

[$ran, $value] = $locks->run('report', 60, fn () => 42);   // ran=false: another holder had it
```

`held()` is true when this caller may proceed. `degraded()` is true when Redis could not be reached, so no exclusion is actually in force. Release and extend are compare-and-act on a random token, so a lock that expired and was taken by someone else is never deleted or extended.

### RateLimiter

```php
use RivetCore\Redis\RateLimiter;

$r = (new RateLimiter($provider, 'rivetit:'))->hit('login:1.2.3.4', 10, 60);
// ['allowed' => bool, 'remaining' => int, 'retry_after' => int]
```

Fixed window; counter and TTL are set in one Lua call.

### CronGuard

```php
use RivetCore\Redis\CronGuard;

$lock = (new CronGuard($locks))->acquire('backup', 3600);
if ($lock === null) { echo "already running\n"; exit(0); }   // the CLI echo/exit stays with the caller
```

The lock is released automatically at shutdown. Key is `<prefix>lock:cron:<job>`.

### Health\ReadinessChecker

```php
use RivetCore\Health\ReadinessChecker;

$report = (new ReadinessChecker($database, fn () => $schemaOk, $provider))->check();
// ['ready' => bool, 'checks' => ['database' => 'ok|fail', 'schema' => 'ok|fail', 'redis' => 'ok|unavailable']]
```

`ready` depends on database and schema only. The report carries no hostnames, versions or error text.

## Configuration

- Constructor options: key prefix for `LockManager`/`RateLimiter` (for example `rivetit:`), TTL and limit arguments per call, `RedisAdmin` clearable groups, optional provider on `ReadinessChecker` (null reports redis as `unavailable`).
- Connection settings are read by the edition and passed through `RedisConnectionConfig::fromArray()`. Validation limits: host up to 253 characters, port 1..65535, db 0..15, password up to 500 characters with no NUL/CR/LF, username `[A-Za-z0-9._@:-]` up to 128 and only with a password, certificate files only with TLS, a client key needs a client cert, paths without `://`.
- TLS: `tls` selects the `tls` scheme; `tls_verify` (default true) sets `verify_peer` and `verify_peer_name`; optional CA file, client cert and client key.
- Tests use `RIVETCORE_TEST_REDIS_PORT` (see `tests/Support/TestRedis.php`).

## How it fails

- `LockManager::acquire()` fails open: no client or any exception returns a lock with `held() === true` and `degraded() === true`. Code that must not run without exclusion has to check `degraded()`.
- `RateLimiter::hit()` fails open: returns allowed with `remaining = limit`.
- `Lock::release()` swallows errors (the TTL clears the key); `extend()` returns false on error.
- `CronGuard::acquire()` returns null only when another holder really has the lock.
- `RedisAdmin::test()` never throws; it returns a `reason` and a fixed message, with the password redacted and no server error text. TLS handshake warnings are suppressed during the test. `clear()` throws `InvalidArgumentException` for a group not in the allowlist. `setMemory()` returns `ok=false` with a message instead of throwing.
- `ReadinessChecker` reports `fail` or `unavailable`; it does not throw.

## Security notes

- The password is a `#[\SensitiveParameter]`, is shown as `***` in `var_dump`/`print_r`, and is stripped from error text by `redact()`.
- `RedisAdmin::clear()` only deletes keys matching patterns the edition allowlisted.
- Rate limiting is an abuse guard, not access control, which is why it fails open.
- Verify TLS peers in production; turning `tls_verify` off is for tests.

## Used by

- **RivetIT:** `includes/redis_guards.php` (`rivetLocks()`, `rivetCronGuard()` used by scripts such as `cron/backup_cron.php`, `cron/workflow_cron.php`; `RateLimiter` wrapper), `src/Core/Adapter/Redis/GlobalRedisClientProvider.php` (provider), `src/Redis/RedisSettings.php` and `src/Redis/RateLimit.php`, `health/ready.php` (`ReadinessChecker`), `mcp_server/ReadTools.php` (rate limiter for the MCP pipeline).
- **RivetMSP:** `src/Core/CoreBridge.php` (`locks()`, `cronGuard()`, `readiness()`), `src/Core/Adapter/Redis/GlobalRedisClientProvider.php`, `src/Redis/RedisSettings.php`, `includes/redis_guards.php`, `health/ready.php`.
- Core: `Mcp\RedisMetadataCache` and `Mcp\ToolPipeline` use the provider and `RateLimiter`.

## Links

- CHANGELOG: 0.2.0 (Redis module and `ReadinessChecker`), 0.17.0 (`RedisConnectionConfig`, TLS/auth, `RedisAdmin::test()`), 0.18.1 (SECURITY.md Redis TLS surface). See [../../CHANGELOG.md](../../CHANGELOG.md).
- Tests: `tests/Integration/RedisTest.php`, `tests/Integration/RedisAuthTlsTest.php`, `tests/Unit/RedisConnectionConfigTest.php`, `tests/Integration/ReadinessTest.php`.
- Related: [cron.md](cron.md), [jobs.md](jobs.md), [../adapters.md](../adapters.md).
