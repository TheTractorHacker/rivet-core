# MCP

Namespace `RivetCore\Mcp`. The edition-neutral parts of a remote, read-only MCP (Model Context Protocol) server that authenticates agents with OAuth access tokens from an external identity provider: settings resolution, extra token checks, the common tool-call path (rate limit, permission, audit, standard envelope), the "identity not linked yet" bookkeeping, a setup self-test, and a discovery cache.

Core does not require `mcp/sdk`. The editions own the SDK glue (routes, JWT signature validation, tool definitions) and every tool body; Core only provides what is identical between editions. Its hard dependencies for this module are `guzzlehttp/guzzle` (diagnostics), `psr/simple-cache` and `psr/log`, plus Core's own Redis, Audit and Database modules.

## Overview

Owns one table, created by migration `0003_mcp_unlinked_identities` (run it through `Migration\MigrationRunner`):

`mcp_unlinked_identities`: `mcp_unlinked_id`, `issuer`, `subject`, `email`, `display_name`, `attempts`, `first_seen_at`, `last_seen_at`, unique key on `(issuer, subject)`. It holds valid OAuth identities that are not linked to an agent yet, so an administrator can pick the agent from a list instead of copying subject ids. The migration uses `CREATE TABLE IF NOT EXISTS`, so it is a no-op on RivetIT, which created the identical table earlier (its migration 2.6.123).

## Contracts an edition must implement

**`AgentDirectoryInterface`**: the edition's view of its agents (staff users). Core never queries the edition's user table. Implementations must run on the same connection as the `DatabaseInterface` given to `IdentityLinker`, so lookups join its transaction.

| Method | Must return |
|---|---|
| `linkableAgents()` | active agents without a link: list of `['user_id', 'user_name', 'user_email']` |
| `linkedAgents()` | agents that have an identity linked (any row shape the edition's UI needs) |
| `linkedCount()` | number of linked agents |
| `findActiveAgent(int $userId)` | `['linked' => bool]` for an active agent (lock the row `FOR UPDATE` when in a transaction), or `null` if there is no such active agent |
| `identityTaken(string $issuer, string $subject)` | whether this issuer plus subject is already linked to some agent |
| `link(int $userId, string $issuer, string $subject)` | store the link |
| `unlink(int $userId)` | remove the link |

Also supplied by the edition:

- `Contracts\SettingsInterface` for `McpConfig` (keys `mcp.issuer`, `mcp.audience`, `mcp.enabled`).
- `Contracts\RequestContextInterface` (`requestId()` is used as the response `request_id`).
- `Redis\RedisClientProviderInterface`, `Audit\AuditService` and a `DatabaseInterface` (see [adapters](../adapters.md)).
- Token authentication (signature, issuer, expiry) and mapping the token subject to a user id. The edition calls `TokenClaimsGuard`, then `ToolPipeline::run()` with the resolved user id.
- A permission callback (`$allow`) and the tool body (`$body`) per tool.

## Key classes

### McpConfig

`McpConfig::resolve(SettingsInterface $settings, callable $env, string $envPrefix): array` returns `schema_ready`, `module_on`, `killed`, `enabled`, `issuer`, `audience`, `issuer_from_env`, `audience_from_env`, `configured`. Environment variables `<PREFIX>ISSUER` and `<PREFIX>AUDIENCE` win over settings when set; `<PREFIX>ENABLED=0` is a hard off switch that no setting can undo. `enabled` is the module setting on and not killed; `configured` means a valid issuer and audience. `schema_ready` is false when `mcp.issuer` reads as `null` (an older schema without the setting). `issuerValid()` requires `https`, a host, at most 255 characters, and no user info, query or fragment. `audienceValid()` requires 1 to 255 characters with no whitespace or control characters.

```php
use RivetCore\Mcp\McpConfig;
use RivetCore\Support\ArraySettings;

$cfg = McpConfig::resolve(
    new ArraySettings(['mcp.issuer' => 'https://idp.example/app/', 'mcp.audience' => 'client-id', 'mcp.enabled' => 1]),
    static fn (string $name) => getenv($name),
    'MYAPP_MCP_'
);
$cfg['enabled'];     // true
$cfg['configured'];  // true
```

### TokenClaimsGuard

Extra checks on a token that already passed signature, issuer and expiry validation. `hasDedicatedAudience($claims, $audience)` requires `aud` to be exactly the MCP audience (a string, or a one-element array); a token that is also addressed to another service is refused. `acceptable($subject, $scopes, $claims, $audience, $requiredScope = 'mcp:read', $now = null)` additionally requires a non-empty subject of at most 255 characters, the required scope, integer `iat` and `exp`, `iat` no more than 60 seconds in the future, `exp` after `iat`, and a lifetime of at most one hour.

```php
use RivetCore\Mcp\TokenClaimsGuard;

$now = time();
TokenClaimsGuard::acceptable('user-123', ['mcp:read'], ['aud' => 'client-id', 'iat' => $now, 'exp' => $now + 600], 'client-id');   // true
```

### ToolPipeline

The path every tool call takes: caller, rate limit, permission, the tool's own scoped query, audit row, then the envelope `{success, request_id, data, errors: [{code, message}]}`.

`new ToolPipeline(RateLimiter $rateLimiter, AuditService $audit, RequestContextInterface $request, int $rateLimit = 60, int $rateWindow = 60, string $source = 'mcp', Closure|LoggerInterface|null $logError = null)`; then `run(?int $userId, string $tool, array $args, callable $allow, callable $body, string $roleName = 'role')`.

| Situation | Error code | Audited |
|---|---|---|
| `$userId` is `null` or below 1 | `PERMISSION_DENIED` | no |
| over the rate limit | `RATE_LIMITED` (message includes retry seconds) | yes, `rate_limited` |
| `$allow($userId)` is false | `PERMISSION_DENIED` | yes, `denied` |
| body throws `NotFoundException` | `NOT_FOUND` | yes, `not_found` |
| body throws anything else | `INTERNAL_ERROR` (generic message) | yes, `error` |
| success | none | yes, `ok` with row count |

```php
use RivetCore\Audit\AuditService;
use RivetCore\Mcp\{ToolPipeline, NotFoundException};
use RivetCore\Redis\RateLimiter;

$pipeline = new ToolPipeline(new RateLimiter($redisProvider, 'myapp:'), new AuditService($db, $request), $request, 60, 60, 'mcp');

$result = $pipeline->run($userId, 'get_ticket', ['id' => 99], fn (int $uid) => $roleAllows($uid), function (int $uid) {
    $row = findTicketForUser($uid, 99);
    return $row ?? throw new NotFoundException();   // missing or out of the caller's scope
});
```

Audit rows use event type `<source>.tool_call`, entity type `<source>_tool`, action `read`, with the tool name, outcome and arguments; string arguments are clipped to 100 characters. `NotFoundException` (extends `\RuntimeException`) is the signal for "not found or out of scope"; a tool must throw it rather than reveal that an id exists in another scope.

### UnlinkedIdentityStore and IdentityLinker

`UnlinkedIdentityStore` remembers identities whose tokens passed every check but have no agent yet. `record($issuer, $subject, $claims)` increments a repeat counter or inserts a row (up to `MAX_PENDING` = 200 rows; control characters stripped, text clipped to 200 characters, `email` and `name`/`preferred_username` taken from the claims). `pending()` first deletes entries not seen for `KEEP_DAYS` = 30 days, then lists the rest. `find($id, $lock)` and `dismiss($id)` complete it.

`IdentityLinker::link(int $pendingId, int $userId): array{0:bool,1:string}` links a pending identity to an agent inside one transaction: the pending row must still exist, the agent must be active and unlinked, and the identity must not already belong to another agent. On success it removes the pending row. It returns `[false, message]` for each refusal, with messages safe to show an administrator. Linking is always an explicit administrator action; nothing links automatically.

```php
use RivetCore\Mcp\{IdentityLinker, UnlinkedIdentityStore};

$store = new UnlinkedIdentityStore($db);
$store->record($issuer, $subject, $claims);          // when a valid token has no linked agent; never throws
[$ok, $message] = (new IdentityLinker($db, $store, $agents))->link($pendingId, $userId);
```

### McpDiagnostics

The "will this work?" panel for the admin page. `new McpDiagnostics(AgentDirectoryInterface $agents, GuzzleHttp\ClientInterface $http, string $envVarName = 'MCP_ENABLED', string $appName = 'this app')`, then `run(array $cfg, string $baseHost)` where `$cfg` is the `McpConfig::resolve()` result. It returns a list of `['status' => ok|warn|fail|skip, 'label', 'detail']` covering: module switch (and kill switch), issuer and audience validity, the provider's discovery document and exact issuer match, an RSA signing key in the JWKS (HS256 is not accepted), PKCE `S256`, the `mcp:read` scope, whether `https://<baseHost>/.well-known/oauth-protected-resource` and `/mcp` answer correctly (only once enabled and configured), and the number of linked agents. All HTTP calls use a 5 second timeout and no redirects, and go through the injected client, so tests can use a Guzzle `MockHandler`.

### RedisMetadataCache

A PSR-16 cache for OAuth discovery and JWKS documents, keyed by `<prefix>` plus the SHA-256 of the key (default prefix `mcp_metadata:`), values JSON-encoded, default TTL one hour. `new RedisMetadataCache(RedisClientProviderInterface $redis, string $keyPrefix = 'mcp_metadata:')`. The class is not final so an edition can subclass it to pin its default provider and prefix. `clear()` always returns `false` on purpose, because flushing a shared Redis database would hit unrelated data.

```php
use RivetCore\Mcp\RedisMetadataCache;

$cache = new RedisMetadataCache($redisProvider);
$cache->set('jwks', $keys, 3600);
$cache->get('jwks', null);          // the default, whenever Redis is down or the value is unreadable
```

## Configuration

| Source | Key | Meaning |
|---|---|---|
| Settings | `mcp.enabled` | module switch (1 = on) |
| Settings | `mcp.issuer`, `mcp.audience` | OAuth issuer (https URL) and the audience the tokens must carry |
| Environment | `<PREFIX>ISSUER`, `<PREFIX>AUDIENCE` | override the settings when non-empty |
| Environment | `<PREFIX>ENABLED=0` | hard off switch |

The prefix is chosen by the edition (RivetIT uses `RIVETIT_MCP_`). Pipeline limits are constructor arguments (default 60 calls per 60 seconds per `<source>:u<userId>` bucket).

## How it fails

- **Rate limiter:** `RateLimiter` fails open when Redis is unavailable (it is an abuse guard, not access control).
- **Cache:** `RedisMetadataCache` turns any Redis error into a cache miss and `set()` into `false`.
- **Audit:** an audit failure never turns a permitted read into an error; it is logged and the call continues.
- **Tool bodies:** exceptions become a generic `INTERNAL_ERROR`; the real message goes to the logger only (a PSR-3 logger or the legacy closure; default `Support\ErrorLogLogger`).
- **Unlinked identities:** `UnlinkedIdentityStore::record()` never throws, so recording a sighting cannot change the 403 the caller gets. `IdentityLinker::link()` rolls back and returns `[false, 'Could not link. Nothing was changed.']` if anything throws.
- **Closed by default:** an unconfigured, disabled or killed module reports `enabled = false`; the edition must not serve `/mcp` then.

## Security notes

- Always validate signature, issuer and expiry first, then `TokenClaimsGuard`. The dedicated-audience rule stops a token issued for another service from being replayed here.
- Only identities from fully validated tokens may be passed to `UnlinkedIdentityStore::record()`. The cap of 200 pending rows bounds what a flood of valid identities can store.
- The identity is the pair issuer plus immutable subject, never an email address.
- Error messages are generic by design and tool arguments are clipped before they are audited; `AuditService` also redacts secret-like metadata keys.
- The tools are read-only; permission and tenant scoping are the edition's `$allow` and `$body`. See ADR-003 for the authorization contract.

## Used by

- RivetIT (`/var/www/mw-itflow.foleyit.com`): `mcp_server/McpIdentityMiddleware.php` (`TokenClaimsGuard`), `mcp_server/ReadTools.php` (builds the `ToolPipeline`; `McpNotFound` extends `NotFoundException`), `mcp_server/RedisMetadataCache.php` (subclass), `src/Mcp/McpConfig.php` (shim over `McpConfig::resolve` with prefix `RIVETIT_MCP_`), `src/Mcp/McpIdentityLinks.php` (`UnlinkedIdentityStore`, `IdentityLinker`), `src/Mcp/McpDiagnostics.php` (shim; linked-agent count from RivetIT users), and the `AgentDirectoryInterface` implementation `src/Core/Adapter/Mcp/UsersAgentDirectory.php`.
- RivetMSP (`/home/sysadmin/rivetmsp-beta`): not used. No source file outside `vendor/` references `RivetCore\Mcp`; ROADMAP notes it needs agent single sign-on columns first.

## Links

- [CHANGELOG](../../CHANGELOG.md): 0.4.0 (module introduced, migration 0003, no `mcp/sdk` requirement), 0.17.0 (PSR-3 logging; the old closure is still accepted).
- [ADR-003: authorization contract](../architecture/ADR-003-authorization-contract.md), [Writing an edition adapter](../adapters.md), [modules overview](README.md).
- Tests: `tests/Unit/McpTest.php`, `tests/Integration/McpIdentityTest.php`.
