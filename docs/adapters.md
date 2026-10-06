# Writing an edition adapter

An *edition* is an application that embeds Core (RivetIT and RivetMSP are the two reference editions). The adapter is the
thin layer that implements Core's interfaces on top of the application. Keep adapters in your own namespace
(`ITFlow\Core\Adapter\...`, `RivetMSP\Core\Adapter\...`); never put edition code in Core.

| Interface | You provide | Reference implementation |
|---|---|---|
| `Database\DatabaseInterface` | `fetchOne`, `fetchAll`, `execute` (prepared parameters), `transaction` | `MysqliDatabaseAdapter` (identical in both editions) |
| `Contracts\RequestContextInterface` | `ipAddress()`, `userAgent()`, `requestId()` for audit rows | `ServerRequestContext` |
| `Contracts\SettingsInterface` | read edition settings | `SettingsTableSettings` |
| `Contracts\ClockInterface` | current time (inject a fixed clock in tests) | `Support\SystemClock` |
| `Redis\RedisClientProviderInterface` | a connected Predis client or `null` | `GlobalRedisClientProvider` |
| `Webhooks\WebhookSubscriptionsInterface` / `WebhookSubscriptionLookupInterface` | which endpoints want which events | `WebhooksTableSubscriptions` |
| `ITSM\TicketProblemLinkInterface` | link a ticket to a problem | `TicketsProblemLink` |
| `Mcp\AgentDirectoryInterface` | map an identity to an agent | `UsersAgentDirectory` (RivetIT) |
| `Compliance\AttestationProviderInterface`, `CheckInterface` | what the edition can prove automatically | edition `ComplianceCatalog` |

## Rules

1. Verify your database adapter with `RivetCore\Testing\DatabaseContractTestCase`: extend it in your test suite and
   implement `database()`. It checks insert ids and affected rows, parameter types (NULL, bool, float, LIMIT), that parameters are never interpolated, transactions (commit, rollback, nesting) and error mapping.
2. Fail open around optional services (Redis, jobs): a Core outage must not break sign-in or a page.
3. Gate each module behind a setting so it can be switched off without code changes.
4. Run `MigrationRunner::run()` from your updater, then bump your own DB version; both editions append Core's new
   migration ids to their install SQL when they snapshot a schema.
5. Pin an exact tag, never `main`.

## A minimal edition you can run

[examples/minimal-edition.php](examples/minimal-edition.php) implements settings, request context, clock and webhook subscriptions, wires the
database adapter ([examples/MysqliDatabaseAdapter.php](examples/MysqliDatabaseAdapter.php), copy it), runs the migrations, writes an audit row, runs a job
through the worker, delivers a signed webhook through a fake transport, and takes a lock with no Redis (it fails open). Run it against a
scratch database: `RIVETCORE_EXAMPLE_DB_NAME=rivetcore_scratch_x php docs/examples/minimal-edition.php`. `tests/Integration/DocSamplesTest.php` runs it.

## Conformance kit

`tests/Conformance/` holds one abstract test case per contract an edition implements. An edition extends the case, implements two or three
small hooks that arrange data in its own storage, and the case checks the behaviour Core relies on (issue #38).

| Test case | Adapter under test | Hooks you implement |
|---|---|---|
| `DatabaseConformanceTestCase` (extends `Testing\DatabaseContractTestCase`) | `DatabaseInterface` | `database()`; also runs every Core migration twice, an audit round trip with 4-byte characters, an exclusive job claim, `LIKE`/`IN`/600-row parameter lists |
| `SettingsConformanceTestCase` | `SettingsInterface` | `settingsWith(array)` |
| `RequestContextConformanceTestCase` | `RequestContextInterface` | `context()` (feed it hostile headers: control characters, a forged request id) |
| `ClockConformanceTestCase` | `ClockInterface` | `clock()`, `isWallClock()` |
| `RedisClientProviderConformanceTestCase` | `RedisClientProviderInterface` | `reachableProvider()`, `unavailableProvider()`, `failOpenSecret()`; also proves locks and rate limits fail open |
| `WebhookSubscriptionsConformanceTestCase` | `WebhookSubscriptionsInterface` and the lookup | `givenEndpoint()`, `givenDisabledEndpoint()`, `adapter()` |
| `AgentDirectoryConformanceTestCase` | `Mcp\AgentDirectoryInterface` | `givenAgent()`, `directory()` (identities must be case sensitive) |
| `TicketProblemLinkConformanceTestCase` | `ITSM\TicketProblemLinkInterface` | `givenTicket()`, `linkedProblem()`, `link()` |
| `AccessPolicyConformanceTestCase` | `Contracts\AccessPolicyInterface` | `policy()`, `unprivilegedUserId()` (default deny) |

Reference runs live next to the cases: `tests/Conformance/Reference/` has in-memory adapters (request context, webhook subscriptions, agent
directory, ticket link, access policy, Redis provider) and `MysqliDatabaseConformanceTest` runs the database case against a real MariaDB or
MySQL in this repository's CI. `KitDetectsBrokenAdaptersTest` proves the kit fails on deliberately wrong adapters (a settings adapter that
ignores defaults, a request context that trusts a client id, an allow-all policy, a case-insensitive agent directory).

### Using it from an edition

1. Get the files. `tests/` is not in the dist archive (`export-ignore`), so install Core from source in the edition's dev environment:
   `composer install --prefer-source` (or `composer require --dev rivet/rivet-core:<tag> --prefer-source`), then map the namespace in the
   edition's `composer.json`:
   ```json
   "autoload-dev": { "psr-4": { "RivetCore\\Tests\\Conformance\\": "vendor/rivet/rivet-core/tests/Conformance/" } }
   ```
   Where the kit should live (this repository's tests, `src/Testing`, or a separate dev package) is open decision #49.
2. Write one small test class per adapter, e.g. `docs/examples/EditionDatabaseConformanceTest.php` (a runnable one for the example adapter).
3. Point the database case at a **scratch** database only (it creates and drops the Core tables and `rc_contract`).
4. Run it in the edition's CI against the Core tag it pins; a Core release cannot be adopted if an adapter fails.
