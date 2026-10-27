# Redis: configuration, authentication and TLS (issue #28)

Redis is optional everywhere in RivetCore. This page is how an edition configures a connection with a password, an ACL user and TLS,
what the helpers do when the connection is wrong or gone, and the evidence for it. The behaviour below is asserted by
`tests/Integration/RedisAuthFailOpenTest.php` (the helpers, against real throwaway servers) and `tests/Integration/RedisAuthTlsTest.php`
(`RedisAdmin::test()` and the config), both of which start their own `redis-server` and skip when it is missing.

## Describe the connection once

`RedisConnectionConfig` is a plain value object. The edition builds it from its own sources (environment, settings table, installer
output) and hands it to Core.

<!-- run -->
```php
use RivetCore\Redis\{RedisAdmin, RedisConnectionConfig};

// The settings-array shape both editions already store: host, port, db, password, plus optional username and tls_* keys.
$cfg = RedisConnectionConfig::fromArray([
    'host' => 'redis.internal.example', 'port' => 6380, 'db' => 0,
    'username' => 'rivet', 'password' => 'correct horse battery staple',
    'tls' => true, 'tls_verify' => true, 'tls_ca_file' => '/etc/rivet/redis-ca.pem',
]);

var_dump($cfg->validate());                       // string error, or NULL when acceptable (pass true to also require the files to exist)
$parameters = $cfg->toPredisParameters(0.5);      // for `new Predis\Client($parameters)`; scheme is "tls" when TLS is on
echo $parameters['scheme'], ' ', $parameters['host'], ':', $parameters['port'], "\n";
echo $cfg->redact('AUTH failed for correct horse battery staple'), "\n";    // the password is replaced by ***
echo (new RedisAdmin([]))->test(['host' => '127.0.0.1', 'port' => 1])['reason'], "\n";   // unreachable: a distinct, password-free result
```

Fields: `host`, `port` (1..65535), `db` (0..15), `password` (optional, at most 500 characters, no line break or NUL), `username` (an ACL user;
letters, digits and `. _ @ : -`; needs a password), `tls`, `verifyPeer` (default **true**), `caFile`, `certFile`, `keyFile` (client
certificate for mutual TLS; a key needs a certificate; certificate files only apply when TLS is on). `validate()` returns a message
meant to be shown to an administrator.

`RedisClientProviderInterface::client()` is the only thing the helpers call. Build the provider from the config and return `null`
when Redis is not configured. Predis connects lazily, so an unreachable server shows up on the first command, not in `client()`.

## Server side

Plain password:

```
requirepass <long random secret>
```

ACL user (Redis 6+), restricted to the key prefix the edition passes to `LockManager`/`RateLimiter` (here `rivet:`):

```
user rivet on >secret ~rivet:* +@all -@dangerous +info +config|get +config|set +config|rewrite
```

Verified against Redis 8.0.5 with exactly this rule: lock acquire, extend and release, the rate limiter, and `RedisAdmin` stats, group counts, clear and
`setMemory()` all worked; `setMemory()` reports "Applied now, but Redis could not save it" when the server has no writable config file, which is not an error.
Leave `+config|*` out and `setMemory()` is refused (the admin page then shows the server's refusal); the locks and rate limits do not need it.

TLS (the build must be compiled with it; on Ubuntu 24.04 and the redis.io packages it is):

```
port 0
tls-port 6380
tls-cert-file /etc/redis/tls/server.crt
tls-key-file  /etc/redis/tls/server.key
tls-ca-cert-file /etc/redis/tls/ca.crt
tls-auth-clients no          # "yes" for mutual TLS: then set certFile/keyFile on the client
```

The installer should generate the secret (`openssl rand -hex 24` gives 48 characters of hex, no characters that need escaping), write it
where the edition reads it with `0600` permissions, and never pass it on a command line.

## What happens when it is wrong (fail open)

Proved with real servers (password server, TLS server, a server stopped mid-run). "Helpers" are `LockManager`/`Lock`, `CronGuard`, `RateLimiter`
and `ReadinessChecker`.

| Situation | Helpers | `RedisAdmin::test()` |
|---|---|---|
| right password / ACL user / CA | work; a second lock holder is refused; the third rate-limit hit is refused | `ok` |
| wrong password, wrong ACL user, no password on a protected server | **fail open**: `held() === true`, `degraded() === true`, rate limit allows, `CronGuard` returns a lock, `LockManager::run()` runs the callback; nothing throws | `auth` |
| TLS with the wrong CA | fail open (see the warning note below) | `tls` |
| TLS client to a plain port, plain client to a TLS port | fail open | `tls` / `unreachable` |
| server stopped mid-run | fail open within the client timeout (0.5 s in the test; the default is 1.0 s) | `unreachable` |
| Redis not configured (`client()` returns `null`) | fail open, same as above | n/a |

`ReadinessChecker` reports `redis: unavailable` and stays ready. Fail open means **no mutual exclusion while Redis is unavailable**:
design jobs to be idempotent and use `Lock::degraded()` to log that a run was unguarded (see [modules/redis.md](modules/redis.md)).

### The password never leaves

For a wrong password, a wrong ACL user and a TLS failure the tests assert that neither the configured password, the wrong one, nor an ACL
password appears in: the exception message Predis throws, `print_r`/`var_dump` of the config (`__debugInfo` masks it), the
`RedisAdmin::test()` result, the readiness report, any PHP warning raised, or the PHP error log. `RedisConnectionConfig::redact()` is
available to scrub text an edition logs itself.

**Known rough edge (not a leak):** a failed TLS handshake makes Predis' stream factory raise an `E_WARNING` ("certificate verify failed",
"handshake timed out"). `RedisAdmin::test()` silences it; the lock, rate-limit and cron helpers do not, so an edition with
`display_errors` on or a warning-to-exception handler will see it (the helpers still fail open, because they catch `Throwable`). Run
production with `display_errors=0` and log warnings. See [SECURITY-REVIEW-2.md](SECURITY-REVIEW-2.md).

**Not covered by the tests:** Redis Sentinel and Cluster (the config describes one host), `rediss://` URLs (use `tls => true`),
password rotation (change the secret on the server and in the edition's settings together; until both match every helper fails open),
and mutual TLS (`certFile`/`keyFile` are passed through to the stream context but no test presents a client certificate).

## Reproduce it

```bash
export RIVETCORE_TEST_REDIS_AUTH_PORT_BASE=6450   # any free block of 6 ports; the tests start redis-server themselves
vendor/bin/phpunit tests/Integration/RedisAuthFailOpenTest.php tests/Integration/RedisAuthTlsTest.php
```

Each test stops the servers it started by PID, refuses to adopt a port something else already listens on, and skips with a message
when `redis-server` is missing or the build has no TLS.
