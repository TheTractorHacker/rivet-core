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
| `Rmm\Contracts\RmmTenancyInterface`, `RmmAssetsInterface`, `RmmBridgeInterface`, `SecretBoxInterface` (+ optional `RmmMetricSinkInterface` and `RmmMetricReaderInterface` (`DatabaseMetricSink` implements both), `RmmAuditInterface`, `RmmModuleStateInterface`, `RmmEventsInterface`) and your `AccessPolicyInterface` | clients and scope, asset matching, the RMM tables (links, alerts, saved scripts, session log), key encryption; the abilities `rmm.*` | `Testing\InMemoryRmm*` and the `Testing\Rmm*ConformanceTestCase` kit; see [modules/rmm.md](modules/rmm.md) |
| `Compliance\AttestationProviderInterface`, `CheckInterface` | what the edition can prove automatically | edition `ComplianceCatalog` |

## Rules

1. Verify your database adapter with `RivetCore\Testing\DatabaseContractTestCase`: extend it in your test suite and
   implement `database()`. It checks insert ids and affected rows, parameter types (NULL, bool, float, LIMIT), that parameters are never interpolated, transactions (commit, rollback, nesting) and error mapping.
2. Fail open around optional services (Redis, jobs): a Core outage must not break sign-in or a page.
3. Gate each module behind a setting so it can be switched off without code changes.
4. Run `MigrationRunner::run()` from your updater, then bump your own DB version; both editions append Core's new
   migration ids to their install SQL when they snapshot a schema.
5. Pin an exact tag, never `main`.
