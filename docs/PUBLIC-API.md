# Public API (issue #30)

What an edition may rely on, at which stability level, and how a change to it is caught. This is the document the 1.0 freeze is
reviewed against. The signature-level list is [api-surface.md](api-surface.md) (generated for reading); the machine-checked source of
truth is [tests/api-surface.json](../tests/api-surface.json). The policy is in
[ADR-004](architecture/ADR-004-versioning-policy.md). Earlier review notes: [api-freeze-review.md](api-freeze-review.md).

Status: written against 0.21.0. Every stability level below is a **proposal for the owner to confirm**; nothing here changes code.

## What counts as public

| Part | Public means | Where it is recorded |
|---|---|---|
| **Types** | every class, interface, enum and trait whose docblock says `@api`: its kind (final/abstract/readonly), parents, interfaces, public constants and their values, every public method signature, public properties | `types` in the snapshot |
| **Internal types** | named `@internal` (migration classes, `Testing\DatabaseContractTestCase`): outside the promise, but moving a type between public and internal is a visible change | `internal` |
| **Tables** | the 16 Core tables and `rivet_core_migrations`: names, columns, column types and nullability. Editions may read them; writing them is only supported through Core's services | `tables` (captured from a database where the migrations ran) |
| **Migrations** | the ordered ids from `CoreMigrations::all()`; ids and meaning never change, new ones are appended | `migrations` |
| **Vocabularies** | identifiers editions persist or send: the 113 webhook event ids, the 24 webhook destination ids, the payload formats, date-range preset ids, compliance framework ids, retention profile ids | `vocabularies` |
| **Wire formats** | webhook request headers (`X-Rivet-Signature-V2`, `X-Rivet-Timestamp`, legacy `<prefix>-Signature`/`-Event`), the JSON body shape `{event, timestamp, data}`, the MCP envelope `{success, request_id, data, errors}` | tests (`WebhookHardeningTest`, `McpTest`) and [webhooks.md](webhooks.md) |
| **Flags and settings** | see below | this document |

### Flags, settings and environment

Core has very few switches by design: a module is off until the edition calls it.

| Name | Read by | Meaning |
|---|---|---|
| `mcp.enabled`, `mcp.issuer`, `mcp.audience` (settings keys) | `Mcp\McpConfig` through `SettingsInterface` | module switch, token issuer, token audience |
| `<PREFIX>ENABLED`, `<PREFIX>ISSUER`, `<PREFIX>AUDIENCE` (environment, prefix chosen by the edition) | `Mcp\McpConfig` | override the settings; `<PREFIX>ENABLED=0` is a hard off switch |
| `RetentionService` horizons and profile; `WebhookDispatcher` `requireUrlPolicy`; `UrlPolicy` `allowPrivate` / `allowedNetworks`; per-rule `is_enabled` | constructor/method arguments | the edition passes them in from its own settings |
| `RIVETCORE_TEST_*` | the test suite only | not API |

Core never reads `$_SERVER`, `$_ENV` (other than through the edition-supplied environment callable), superglobals or edition functions
(CI greps for this).

## Stability levels

- **Stable.** Covered by semantic versioning from 1.0: no removal or incompatible change before 2.0. A bug fix that changes behaviour
  to match the documented contract is a patch.
- **Provisional.** Public and supported, but the shape may still change in a minor release before 1.0 is tagged, with a changelog
  entry. Each one has a stated reason below. The aim is zero Provisional at `1.0.0-rc.1`: each is either promoted to Stable or
  moved to `@internal`.
- **Internal.** Not part of the promise.

## Contracts an edition implements

These are the interfaces an edition writes adapters for. The [conformance kit](adapters.md#conformance-kit) checks them.

| Interface | Purpose | Notes |
|---|---|---|
| `Database\DatabaseInterface` | all storage | verified by `Testing\DatabaseContractTestCase` and the kit; parameters are `string|int|float|bool|null` |
| `Contracts\ClockInterface` | time | |
| `Contracts\SettingsInterface` | read edition settings | |
| `Contracts\RequestContextInterface` | IP, user agent, request id for audit rows | request id must be server-generated |
| `Redis\RedisClientProviderInterface` | a Predis client or `null` | `null` means "Redis unavailable"; everything fails open |
| `Webhooks\WebhookSubscriptionsInterface` (+ optional `WebhookSubscriptionLookupInterface`) | endpoints for an event | |
| `ITSM\TicketProblemLinkInterface` | tickets belong to the edition | |
| `Mcp\AgentDirectoryInterface` | agents (staff users) for identity linking | identities are case sensitive |
| `Compliance\CheckInterface`, `AttestationProviderInterface` | what the edition can prove | |
| `Contracts\AccessPolicyInterface` | authorization (ADR-003) | Provisional: nothing enforces it yet |
| `Migration\MigrationInterface` | only Core implements it | edition migrations are the edition's |

## Error contract

- Storage failures: `Database\DatabaseException`. Callers that must not fail (audit in a request) catch it.
- Refusals: the module's own exception (`PermanentJobFailure`, `DocxConversionException`, `PdfConversionException`, `NotFoundException`,
  `Contracts\AccessDenied`) or `InvalidArgumentException`/`RuntimeException` for an illegal state transition or invalid input.
- Redis and webhook delivery: never throw; they report ("not acquired", `degraded`, `ok: false`).

## Per-type table

Generated by `php scripts/public-api-table.php --write` from the snapshot; do not edit between the markers.

<!-- types:begin -->
| Type | Kind | Edition does | Proposed stability | Why provisional |
|---|---|---|---|---|
| `Audit\AuditPage` | final class | call | Stable |  |
| `Audit\AuditReader` | final class | call | Stable |  |
| `Audit\AuditService` | final class | call | Stable |  |
| `Automation\AutomationExecutor` | final class | call | Stable |  |
| `Automation\AutomationRuleEvaluator` | class | call | Stable |  |
| `Automation\AutomationRuleStore` | final class | call | Stable |  |
| `Automation\EventContext` | final class | call | Stable |  |
| `Compliance\Assessment` | final class readonly | call | Stable |  |
| `Compliance\AttestationProviderInterface` | interface | implement | Stable |  |
| `Compliance\AttestationStore` | final class | call | Stable |  |
| `Compliance\CheckInterface` | interface | implement | Stable |  |
| `Compliance\CheckResult` | final class readonly | call | Stable |  |
| `Compliance\Check\AuditTrailRecordingCheck` | final class | register as a check | Stable |  |
| `Compliance\Check\RetentionMeetsPresetCheck` | final class | register as a check | Stable |  |
| `Compliance\ClientChecklist` | final class | call | Provisional | one consumer so far |
| `Compliance\ComplianceAssessor` | final class | call | Stable |  |
| `Compliance\Framework` | final class | call | Stable |  |
| `Compliance\ManualItem` | final class readonly | call | Stable |  |
| `Compliance\Nist171Map` | final class | call | Stable |  |
| `Compliance\ReportRenderer` | final class | call | Stable |  |
| `Compliance\ResponsibilityStore` | final class | call | Provisional | one consumer so far |
| `Compliance\RetentionPolicy` | final class | call | Stable |  |
| `Compliance\SharedReport` | final class | call | Stable |  |
| `Compliance\SnapshotStore` | final class | call | Stable |  |
| `Compliance\Status` | enum | call | Stable |  |
| `Compliance\SubjectCompliance` | final class | call | Provisional | MSP-only consumer so far |
| `Contracts\AccessDenied` | final class | catch / throw | Provisional | ADR-003 |
| `Contracts\AccessPolicyInterface` | interface | implement (optional) | Provisional | ADR-003: no service enforces it yet |
| `Contracts\ClockInterface` | interface | implement | Stable |  |
| `Contracts\RequestContextInterface` | interface | implement | Stable |  |
| `Contracts\SettingsInterface` | interface | implement | Stable |  |
| `Cron\JobCatalog` | final class | call | Stable |  |
| `Cron\JobRunner` | class | call | Provisional | subclassed by editions; process-spawning details may change |
| `Database\DatabaseException` | class | catch / throw | Stable |  |
| `Database\DatabaseInterface` | interface | implement | Stable |  |
| `Database\ExecutionResult` | final class readonly | call | Stable |  |
| `Health\ReadinessChecker` | final class | call | Stable |  |
| `ITSM\ChangeService` | class | call | Stable |  |
| `ITSM\ProblemService` | class | call | Stable |  |
| `ITSM\TicketProblemLinkInterface` | interface | implement | Stable |  |
| `Jobs\JobContext` | final class | call | Stable |  |
| `Jobs\JobQueue` | final class | call | Stable |  |
| `Jobs\JobTimeout` | final class | catch / throw | Stable |  |
| `Jobs\JobWorker` | final class | call | Stable |  |
| `Jobs\PermanentJobFailure` | final class | catch / throw | Stable |  |
| `KB\DocxConversionException` | class | catch / throw | Stable |  |
| `KB\DocxConverter` | class | call | Stable |  |
| `KB\PdfConversionException` | class | catch / throw | Stable |  |
| `KB\PdfConverter` | final class | call | Stable |  |
| `Knowledge\CredentialReferenceRenderer` | class | call | Stable |  |
| `Mcp\AgentDirectoryInterface` | interface | implement | Stable |  |
| `Mcp\IdentityLinker` | final class | call | Provisional | closure-or-logger union |
| `Mcp\McpConfig` | final class | call | Stable |  |
| `Mcp\McpDiagnostics` | final class | call | Stable |  |
| `Mcp\NotFoundException` | class | catch / throw | Stable |  |
| `Mcp\RedisMetadataCache` | class | call | Stable |  |
| `Mcp\TokenClaimsGuard` | final class | call | Stable |  |
| `Mcp\ToolPipeline` | final class | call | Provisional | closure-or-logger union (freeze review item 4) |
| `Mcp\UnlinkedIdentityStore` | final class | call | Provisional | closure-or-logger union |
| `Migration\CoreMigrations` | final class | call | Stable |  |
| `Migration\MigrationInterface` | interface | implement | Stable |  |
| `Migration\MigrationRunner` | final class | call | Stable |  |
| `Redis\CronGuard` | final class | call | Stable |  |
| `Redis\Lock` | final class | call | Stable |  |
| `Redis\LockManager` | final class | call | Stable |  |
| `Redis\RateLimiter` | final class | call | Stable |  |
| `Redis\RedisAdmin` | final class | call | Stable |  |
| `Redis\RedisClientProviderInterface` | interface | implement | Stable |  |
| `Redis\RedisConnectionConfig` | final class | call | Provisional | ten positional parameters; use named arguments |
| `Retention\RetentionService` | final class | call | Stable |  |
| `Support\AllowAllPolicy` | final class | call | Provisional | ADR-003 |
| `Support\ArraySettings` | final class | call | Stable |  |
| `Support\DenyAllPolicy` | final class | call | Provisional | ADR-003 |
| `Support\ErrorLogLogger` | final class | call | Stable |  |
| `Support\LocalNetworks` | final class | call | Stable |  |
| `Support\NullRequestContext` | final class | call | Stable |  |
| `Support\SystemClock` | final class | call | Stable |  |
| `Ui\DateRange` | final class | call | Provisional | new in 0.20 |
| `Ui\IconCatalog` | final class | call | Provisional | new in 0.19 |
| `Webhooks\Authentication` | final class | call | Provisional | new in 0.21 |
| `Webhooks\Destination` | final class readonly | call | Provisional | preset catalogue added in 0.21 |
| `Webhooks\DestinationField` | final class readonly | call | Provisional | preset catalogue added in 0.21 |
| `Webhooks\Destinations` | final class | call | Provisional | preset catalogue added in 0.21 |
| `Webhooks\EventCatalog` | final class | call | Provisional | event list grows every release; ids are stable, the class shape is new in 0.21 |
| `Webhooks\EventDefinition` | final class readonly | call | Provisional | new in 0.21 |
| `Webhooks\EventSummary` | final class | call | Provisional | new in 0.21 |
| `Webhooks\FormattedPayload` | final class readonly | call | Provisional | new in 0.21 |
| `Webhooks\NetworkList` | final class | call | Stable |  |
| `Webhooks\PayloadFormatter` | final class | call | Provisional | new in 0.21 |
| `Webhooks\PayloadTemplate` | final class | call | Provisional | new in 0.21 |
| `Webhooks\UrlPolicy` | final class | call | Stable |  |
| `Webhooks\WebhookDispatcher` | class | call | Provisional | long positional constructor; options object proposed in the freeze review |
| `Webhooks\WebhookSubscription` | final class readonly | call | Stable |  |
| `Webhooks\WebhookSubscriptionLookupInterface` | interface | implement | Stable |  |
| `Webhooks\WebhookSubscriptionsInterface` | interface | implement | Stable |  |
| `Workflow\WorkflowService` | class | call | Stable |  |
<!-- types:end -->

## How a change is caught

1. `php scripts/api-surface-check.php` rebuilds the surface by reflection and compares it with `tests/api-surface.json`. **Any**
   difference fails, labelled `BREAKING` (removal or change) or `ADDITIVE` (addition). The author runs it with `--update`, reviews the
   JSON diff, and records it in `CHANGELOG.md`; a `BREAKING` line needs a major version.
2. CI runs it as the `api-surface` job (with a MariaDB service, so the table section is compared too) and `tests/Unit/ApiSurfaceGuardTest.php`
   proves the guard fails on a removed method, a changed signature, a new type, a new event id and a vanished migration.
3. The older `compatibility` job (Roave backward-compatibility-check against the last tag) stays advisory until 1.0: it understands
   PHP semantics the snapshot does not (variance, inherited members), the snapshot understands tables and vocabularies that Roave cannot.
   At 1.0 both are required.

What the guard cannot see: the **shape** of `array` parameters and returns (they are only `array` in the signature; the shapes live in
docblocks), the behaviour of a method with an unchanged signature, and the semantic meaning of a column. Those are covered by tests and
review, and by the open question below about array shapes.

## Open design questions (not decided here)

These need the owner. None blocks the tooling above.

1. **Non-final classes.** `AutomationRuleEvaluator`, `JobRunner`, `ProblemService`, `ChangeService`, `WorkflowService`, `DocxConverter`,
   `WebhookDispatcher`, `RedisMetadataCache`, `CredentialReferenceRenderer`, `DatabaseException` and the KB exceptions are not `final`,
   some on purpose (`JobRunner`, `RedisMetadataCache` are subclassed by editions to pin defaults). Inheritance then becomes API. Which
   stay extensible (document the extension points), which become `final` before 1.0?
2. **Array shapes.** About 70 public methods take or return plain `array`. Annotate with `array{...}` shapes before 1.0 so completion
   works and the guard can compare them, or accept that the shape is documented prose only? (Also in the freeze review, item 2.)
3. **Long positional constructors.** `RedisConnectionConfig` (10 parameters), `WebhookDispatcher` (8), `ToolPipeline` (7): freeze with
   "use named arguments", or introduce options objects (a break for positional callers)?
4. **Logger unions.** `\Closure|LoggerInterface|null` on three MCP classes: drop the closure at 1.0 (recommended in the freeze review)?
5. **Core owner decisions already open:** #45 (ITFlow shims), #46 (Packagist), #47 (webhook signature change), #48 (database support
   promise), #49 (test helpers package). In particular #49 decides where `Testing\DatabaseContractTestCase` and the conformance kit
   live, and whether they are inside the promise.
6. **Are the tables public for reading?** Proposed: yes, columns are Stable, and an edition may query `audit_events` etc. read-only;
   writes go through services. If instead the tables become private, `AuditReader` and friends must cover every edition query first.
   Adding a NOT NULL column without a default is breaking for an edition that inserts directly, so migrations must keep adding
   nullable or defaulted columns only.
7. **`MigrationRunner` lock name.** `rivet_core_migrations` is a server-global `GET_LOCK` name, so two databases on one server serialize
   their migrations (see [SECURITY-REVIEW-2.md](SECURITY-REVIEW-2.md)). Scoping it to the schema changes behaviour but not the
   signature: patch, or a documented minor?
8. **`Provisional` types** (table above): the list needs confirming, and each needs a promote-or-demote decision before `rc.1`.
9. **Webhook event ids as API.** Removing an id from `EventCatalog` is guarded as breaking. Is deprecating (still listed, flagged
   `deprecated`) the only allowed path, and how long does a deprecated id live?
10. **The 0.x constraint.** Editions pin `^0.N`; the guard reports changes per commit, not per tag. Should the release checklist attach
    the guard's diff against the last tag to every release note?
