# Upgrading RivetCore

For edition maintainers (RivetIT, RivetMSP, or any other application that embeds Core). Read [ADR-004](docs/architecture/ADR-004-versioning-and-compatibility.md)
for the rules this guide follows, and [docs/EDITION_CHECKLIST.md](docs/EDITION_CHECKLIST.md) for the per-release routine.

Status of this document: written against `v0.21.0`. The "Between 0.21.0 and 1.0.0" section is completed from `CHANGELOG.md` when
`v1.0.0-rc.1` is tagged. Every item in the 0.7 to 0.21 tables below comes from the changelog; "breaking" means a correct caller of the
older release can need a change.

## Upgrading from 0.x to 1.0

### 1. Constraint

| Where you are | Change |
|---|---|
| `"rivet/rivet-core": "^0.18"` (RivetIT today) or `"^0.21"` (RivetMSP today) | `"^1.0"` once `v1.0.0` is tagged; before that, the exact release candidate (`"1.0.0-rc.1"`) |
| Any `0.x` older than 0.21 | Move to 0.21.0 first, run your tests and your updater, then go to 1.0. The tables below tell you what each step changes; do not skip the changelog. |

In `0.x` a caret accepts only the same minor (`^0.18` never installs `0.19`), which is why both editions drifted apart. From 1.0 a
`^1.0` constraint accepts every 1.x and the lock file is what pins the running version.

The repository entry stays (Core is not on Packagist, [ADR-006](docs/architecture/ADR-006-packagist.md)):

```json
{
  "repositories": [{ "type": "vcs", "url": "https://github.com/TheTractorHacker/rivet-core.git", "no-api": true }],
  "require": { "rivet/rivet-core": "^1.0" }
}
```

Dependencies Core requires: `php >=8.2`, `guzzlehttp/guzzle ^7.0 || ^8.0`, `predis/predis ^3.5`, `psr/log ^3`, `psr/simple-cache ^3.0`.
`psr/log` arrived in 0.17.0 and `predis/predis` in 0.2.0; an edition that vendors its dependencies in git must commit the new vendor files.

### 2. Breaking and behaviour changes since 0.7

None of these is a rename or a removal: Core has not removed a public method since 0.7. What changed is behaviour that a correct caller could
notice. Check each against your edition.

| Since | Change | Who is affected | What to do |
|---|---|---|---|
| 0.16.0 | `MigrationRunner::run()` takes a server-side lock (`GET_LOCK`); a second runner that waits longer than `$lockWaitSeconds` (default 60) throws `\RuntimeException`. New optional fourth constructor argument. | Updaters that run migrations from a web request and CLI at once | Catch `\RuntimeException` (the tree after 0.21.0 throws its subclass `Migration\MigrationInProgressException`) around `run()` and report "another update is running". |
| 0.17.0 | New required dependency `psr/log ^3`. Services accept a PSR-3 logger in place of calling `error_log()`; `Support\ErrorLogLogger` is the default and keeps the old behaviour. | Editions that vendor dependencies | `composer update`, commit vendor if tracked. Optionally inject your logger. |
| 0.17.0 | Every webhook request carries two extra headers, `X-Rivet-Timestamp` and `X-Rivet-Signature-V2`. Legacy headers are byte-identical. | Receivers with strict header allow-lists | Allow the new headers; move receivers to V2 ([ADR-007](docs/architecture/ADR-007-webhook-signatures.md)). |
| 0.17.0 | `Webhooks\UrlPolicy` and the opt-in or required switch on `WebhookDispatcher`. | Editions that do their own URL checks | Pass a policy and use `required` once your own check is retired. |
| 0.17.0 | Redis passwords containing a line break or NUL are rejected by `RedisConnectionConfig`. | Installations with such a password | Rotate the password. |
| 0.17.0 | Retention gains separate horizons for webhook deliveries and finished jobs (7-day floor; 30 days under a preset), batched deletes and a dry-run `plan()`. `prune()` stays backward compatible. | Callers that relied on one horizon for all tables | Pass the horizons explicitly if you want different values. |
| 0.17.0 | Migration `0012` (`integration_jobs.heartbeat_at`, nullable). Before it is applied everything falls back to `started_at`. | Every edition | Run `MigrationRunner::run()`; add the id to `db.sql`. |
| 0.18.0 | `UrlPolicy` accepts an optional `allowedNetworks` list; the old constructor signature is unchanged. | None | Optional. |
| 0.18.1 | `UrlPolicy` rejects more special-purpose ranges (6to4, Teredo, NAT64 local-use, benchmarking, multicast and others). | Webhooks to hosts that resolve into those ranges | List real private LAN ranges through `allowedNetworks`; the special ranges can never be allowed. |
| 0.18.1 | Webhook requests are sent to the vetted host spelling and never use a proxy. | Installations that reached webhook targets through an HTTP proxy | Webhooks no longer go through a proxy; reach the target directly or allow its network. |
| 0.18.1 | `JobQueue::markCompleted()` and `markFailed()` only write while the job is `running` and return `bool` (they returned nothing before). `requeueStale()` dead-letters jobs that used all their attempts. | Workers that ignored the return value are unaffected; workers that depended on a late write overwriting a reclaimed job | Handlers must be idempotent; pass the claimed attempt to the fence argument (`JobWorker` does). |
| 0.18.1 | `JobRunner`: the default state directory is per user (`rivetcore-jobs-<uid>`); it must be a real directory owned by the current user and not group or world writable, otherwise `start()` refuses. | Cron Manager "Run now" in editions | Create the directory with mode 0700 for the web user, or pass an explicit directory. |
| 0.18.1 | `AuditService` clamps every field to its column width, replaces unencodable metadata with a marker and stores values under common secret keys as `[redacted]`. | Pages that showed full-length values or relied on a secret appearing in metadata | None normally; do not put data you must read back under keys such as `token` or `password`. |
| 0.18.1 | `RetentionService` takes an optional compliance profile that raises every horizon to the preset floor. | None unless you pass it | Pass it when a compliance framework is enabled. |
| 0.18.1 | `CredentialReferenceRenderer` only substitutes the badge in text; a token inside a tag or attribute is removed. | KB articles that placed a credential token in an attribute | Move the token into text. |
| 0.20.0, 0.19.0, 0.21.0 | `Ui\DateRange`, `Ui\IconCatalog`, webhook destinations, formats, templates, authentication and the event catalog. | Additive | The default `json` webhook body is byte-identical to before. |
| 0.8.0 to 0.14.0 | Compliance status engine and its tables (migrations 0008 to 0011). Migration 0010 adds `subject_id int NOT NULL DEFAULT 0` and an index to the two existing compliance tables. | Editions that adopt Compliance | Run the migrations; see "Migration order". |
| 0.7.1 | `JobQueue::markFailed()` crash on the fifth attempt fixed; `PdfConverter` on PHP 8.2 fixed. | None | None. |

### 3. Deprecations and their replacements

Deprecated APIs keep working for all of 1.x and are removed in 2.0 ([ADR-004](docs/architecture/ADR-004-versioning-and-compatibility.md)).

| Deprecated | Replacement | Removed |
|---|---|---|
| Webhook legacy headers `<prefix>-Signature` and companions (signature V1) | `X-Rivet-Signature-V2` plus `X-Rivet-Timestamp`; verify with a 5 minute tolerance ([ADR-007](docs/architecture/ADR-007-webhook-signatures.md), [docs/webhooks.md](docs/webhooks.md)) | 2.0 |
| The `\Closure` form of the logger argument on `Mcp\ToolPipeline`, `Mcp\IdentityLinker` and `Mcp\UnlinkedIdentityStore` | Pass a PSR-3 `Psr\Log\LoggerInterface` | 2.0 |
| The edition shims in `ITFlow\...` (and the equivalent RivetMSP namespaces) | Call `RivetCore\...` directly ([ADR-005](docs/architecture/ADR-005-itflow-shims-stay-for-1x.md)) | 2.0, in the editions |

The list above is the one `grep -rn "@deprecated" src` shows at the time of writing; the 1.0 changelog repeats it with the final set.

### 4. Between 0.21.0 and 1.0.0

To be completed from `CHANGELOG.md` when `v1.0.0-rc.1` is tagged. Work in flight when this was written, none of it breaking by design:
the public API freeze (docblock `@api`/`@internal` and array-shape annotations, `docs/api-freeze-review.md`), the security review of 2026-10
(`docs/security/`), and the adapter conformance kit under `RivetCore\Testing` ([ADR-009](docs/architecture/ADR-009-test-helpers-in-package.md)).
If a release candidate breaks something that this guide did not warn about, that is a bug in the release candidate: report it.

## Adopting the RMM module (unreleased)

For an edition that wants the endpoint agent (RivetIT, then RivetMSP). The module is off by default and adds no required constructor argument to any existing type, but its three migrations are part of `CoreMigrations::all()` (there is no opt-in `RmmMigrations` list; the design draft proposed one and the owner chose otherwise, ADR-010 decision 9). **So an edition gets the ten `endpoint_agent_*` tables, and the migration ledger rows, as soon as it runs `MigrationRunner::run()` with `CoreMigrations::all()`, whether or not it ever enables the module** (RivetMSP included). The tables are additive and empty until a device enrolls, and the master switch defaults to off.

1. **Migrations.** `CoreMigrations::all()` now ends with `0014_endpoint_agent_core`, `0015_endpoint_agent_converge`, `0016_rmm_module_switches`. On an install that already has the ten `endpoint_agent_*` tables (RivetIT at DB 2.6.146) 0014 and 0015 are no-ops that only record themselves and 0016 adds five columns with defaults that reproduce today's behaviour; a fresh `db.sql` must contain the tables and the three ledger rows together (or none of them).
2. **Adapters.** Implement `RmmTenancyInterface`, `RmmAssetsInterface`, `RmmBridgeInterface` and `SecretBoxInterface` (the ciphertext format of your existing `encryptSetting()` stays, so stored keys keep decrypting), optionally `RmmMetricSinkInterface`, `RmmAuditInterface` and `RmmModuleStateInterface`, and map the nine `rmm.*` abilities in your `AccessPolicyInterface`. Run `Testing\Rmm*ConformanceTestCase` over them. See [docs/modules/rmm.md](docs/modules/rmm.md).
3. **Bridges.** Replace each device REST file with the five-line bridge (build an `RmmRequest`, call `RmmModule::deviceApi()`, emit the `RmmResponse`); keep TLS and proxy trust, CORS headers, user-token authentication and rate limiting in the edition. `api/v1/endpoint_devices.php` becomes `RmmModule::technicianApi()->handle($request, new RmmPrincipal($userId, $name))`.
4. **Pages.** Render from `RmmReadModel` and call `RmmAdmin` / `TechnicianActions` (they return an `ActionResult`: show `message`, answer `http`). Delete your raw SQL against `endpoint_agent_*`.
5. **Verify.** Replay the golden transcripts against your bridges (`scripts/rmm-golden/`, adapter and router are the pattern), run your old endpoint suites unchanged, and diff `information_schema` before and after your updater step.

6. **Choices the module leaves to the edition** (all optional, all with a safe default):
   - *What a switched-off module answers to devices.* `RmmModule::deviceApi($rateLimit, true, null, null, 'compat')` (or `DeviceApi::DISABLED_COMPAT`) keeps RivetIT's legacy `403 forbidden` for enroll, installer and a valid device credential; the default `uniform` answers `503 module_disabled` with `Retry-After: 3600`. The older `$withModuleState = false` argument still means `compat`. In both modes a missing or damaged state file is re-created lazily on the next device request.
   - *Terminology.* Module option `client_label` (default `client`; RivetIT passes `department`) is used in the messages a user reads (enrollment, installer, transfer, approval reasons, the out-of-scope denial, the default installer name `Department 12`).
   - *Denial texts.* Module option `denial_reasons` (`['rmm.job.run_script' => '...']`) replaces the generic reason of that ability.
   - *Device list extras.* `RmmReadModel::listDevices()` summaries now carry `asset_name` and `update_state` (decoded `update_state_json`, `failed_versions` included). `asset_name` needs an assets adapter that also implements the new optional `Contracts\RmmAssetNamesInterface` (one batched lookup per page); without it the value is null. The technician REST list is unchanged (its JSON is frozen).
   - *Validating a binary without the module.* `Binaries\BinaryInspector::detect()` and `::inspect()` are static and need no database.
7. **Deliberate differences from RivetIT's pre-extraction behaviour** (each documented, none changes the wire protocol):
   - *Interval floors.* `check_in_interval_s` is clamped to 60 to 3600 s and `collect_interval_s` to 30 to 3600 s when settings are saved (RivetIT accepted 30 to 3600 and 10 to 3600). The floors are the design's capacity controls (design 13.3). Stored values below the floor keep working until the settings are saved again. The MeshCentral token lifetime is 60 to 3600 s in both `RmmSettings::update()` and `RmmAdmin::saveMesh()` (`update()` used to accept 30).
   - *Transfer and alerts.* `RmmBridgeInterface::reassignAlerts()` moves only the **open** alerts of the asset and integration, as its contract and the conformance kit say; RivetIT's original `UPDATE rmm_alerts` also moved resolved alerts, which therefore stay under the previous client now. An adapter that keeps the old behaviour fails `RmmBridgeConformanceTestCase`; if historic alerts must follow the device, that is a contract change to decide, not an adapter choice.
   - *Gate and the technician API.* The pre-bootstrap gate no longer answers `endpoint_devices` at all (it used to answer 404 `disabled` before authentication, which told an anonymous caller whether the module is on). `TechnicianApi` answers 401 first, then 404 `disabled`, as RivetIT did. A device endpoint still answers `503 module_disabled` before authentication in `uniform` mode, on purpose: agents must back off without a database (use `compat` to keep the legacy order 401 then 403).

## Adopting RMM Phase 1 (1.0.0-rc.9)

For an edition that already runs the RMM module. Everything is additive: an edition that changes nothing keeps working, and the golden transcripts of the old agents replay identically. The module gains new optional constructor arguments only at the end, and no existing interface gets a method (the new contracts are companions, as ADR-004 asks).

1. **Migrations.** `CoreMigrations::all()` now ends with `0018_rmm_inventory_foundation`: eleven new tables, `CREATE TABLE IF NOT EXISTS`, `utf8mb4_general_ci`, nothing existing altered. If your `db.sql` mirrors Core's tables, add them (the DDL is `Rmm\Migration\RmmSchema::phase1Tables()`); do not run `RmmSchema::tables()` against them. The tables stay empty and cost nothing until the matching feature is used. **Run the migration before, or immediately after, deploying the new Core code**: the check evaluator writes the check history on every check-in that carries checks, so until `0018` has run those check-ins answer `500 internal` (the agents retry the same body and recover on their own once the tables exist).
2. **Events (optional).** Implement `Rmm\Contracts\RmmEventsInterface` on your event bus (webhooks, automation rules) and pass it as the **last** argument of `RmmModule`. The nine `rmm.*` ids are listed in `RmmEvent` and in `Webhooks\EventCatalog` (group `rmm`); a webhook subscription to `rmm.*` or `*` matches them. Without it the module does not even track presence. Call `RmmModule::housekeeping()->run()` from cron as before: it now also announces offline devices once per offline period.
3. **Metric history for an edition without a metrics subsystem (RivetMSP).** Pass `new Rmm\Support\DatabaseMetricSink($database, $clock)` as the metric sink. Housekeeping prunes it. RivetIT keeps its own Metrics subsystem; if it wants the network bar against the 24 hour peak from the module, implement `Rmm\Contracts\RmmMetricReaderInterface` on its sink adapter (the conformance case is `Testing\RmmMetricReaderConformanceTestCase`).
4. **Software inventory.** Roll out an agent of this release (it announces `software_inventory`), then switch on the `inventory_software` sub-switch. Nothing is collected by agents until the server offers it, so the order is safe both ways. Switching it off makes the agents stop sending within one check-in.
5. **Pages and routes.** The technician REST routes are served by `TechnicianApi` without any change to your bridge (they are new path segments under `endpoint_devices`); make sure your front controller passes all segments through. Render the new `RmmReadModel` methods on the device and fleet pages (software tab, tags, groups, check trend, network bar, the live document). `TechnicianActions` is unchanged; tags, groups and the software refresh live in `RmmModule::inventory()`.
6. **Limits.** `check_history_days`, `check_history_gap_s` and `software_history_days` are valid `limits_json` keys now; add them to your settings page if you want them editable (defaults 7, 3600, 365).
7. **Differences to know.** `RmmReadModel::listDevices()` summaries gain a `tags` list in the extras mode (the technician REST list is unchanged). `InMemoryRmmMetricSink` implements the reader (it is `@internal`). `Housekeeping::run()` returns three more counters (`pruned_check_history`, `pruned_software_history`, `pruned_software_removed`) and, when applicable, `pruned_metrics` and `offline_events`.

## Migration order

Core owns its migrations and tracks them in `rivet_core_migrations`, independent of the edition's database version. Migrations are
forward-only, additive and idempotent; running the whole list on a current database is a no-op.

| Id | Class (`@internal`) | Creates or changes | Since |
|---|---|---|---|
| `0001_audit_events` | `Audit\Migration\Migration0001AuditEvents` | `audit_events` | 0.1.0 |
| `0002_integration_jobs` | `Jobs\Migration\Migration0002IntegrationJobs` | `integration_jobs` | 0.3.0 |
| `0003_mcp_unlinked_identities` | `Mcp\Migration\Migration0003McpUnlinkedIdentities` | `mcp_unlinked_identities` | 0.4.0 |
| `0004_problems_and_changes` | `ITSM\Migration\Migration0004ProblemsAndChanges` | `problems`, `changes` | 0.5.0 |
| `0005_webhook_deliveries` | `Webhooks\Migration\Migration0005WebhookDeliveries` | `webhook_deliveries` | 0.6.0 |
| `0006_automation_rules` | `Automation\Migration\Migration0006AutomationRules` | `automation_rules` | 0.6.0 |
| `0007_workflow_tables` | `Workflow\Migration\Migration0007WorkflowTables` | four `workflow_*` tables | 0.6.0 |
| `0008_compliance` | `Compliance\Migration\Migration0008Compliance` | `compliance_attestations`, `compliance_snapshots` | 0.9.0 |
| `0009_compliance_shared_report` | `Compliance\Migration\Migration0009SharedReport` | `compliance_shared_report` | 0.10.0 |
| `0010_compliance_subjects` | `Compliance\Migration\Migration0010Subjects` | `compliance_subjects`; `subject_id` on the two compliance tables | 0.11.0 |
| `0011_compliance_responsibilities` | `Compliance\Migration\Migration0011Responsibilities` | `compliance_responsibilities` | 0.14.0 |
| `0012_job_heartbeat` | `Jobs\Migration\Migration0012JobHeartbeat` | `integration_jobs.heartbeat_at` (nullable) | 0.17.0 |
| `0013_retention_indexes` | `Retention\Migration\Migration0013RetentionIndexes` | indexes on `created_at` for `audit_events` and `webhook_deliveries`, and `(status, created_at)` on `integration_jobs` | unreleased (in the working tree after 0.21.0; confirm in the 1.0 changelog) |
| `0014_endpoint_agent_core` | `Rmm\Migration\Migration0014EndpointAgent` | the ten `endpoint_agent_*` tables (`IF NOT EXISTS`) | 1.0.0-rc.5 |
| `0015_endpoint_agent_converge` | `Rmm\Migration\Migration0015EndpointAgentConverge` | brings a RivetIT 2.6.145 install to 2.6.146 | 1.0.0-rc.5 |
| `0016_rmm_module_switches` | `Rmm\Migration\Migration0016ModuleSwitches` | five columns on `endpoint_agent_settings` | 1.0.0-rc.5 |
| `0017_mcp_identity_binary_collation` | `Mcp\Migration\Migration0017McpIdentityBinaryCollation` | `mcp_unlinked_identities.issuer`/`subject` become `utf8mb4_bin` | 1.0.0-rc.7 |
| `0018_rmm_inventory_foundation` | `Rmm\Migration\Migration0018InventoryFoundation` | eleven new tables: `rmm_device_state`, `rmm_device_software`, `rmm_software_history`, `rmm_tags`, `rmm_device_tags`, `rmm_groups`, `rmm_group_devices`, `rmm_group_tags`, `endpoint_agent_check_history`, `rmm_metric_latest`, `rmm_metric_hourly` (all `IF NOT EXISTS`, nothing existing altered) | 1.0.0-rc.9 |

The authoritative list is `RivetCore\Migration\CoreMigrations::all()`; the exact ids (`$m->id()`) are what `rivet_core_migrations`
stores. Print them with `php -r 'require "vendor/autoload.php"; foreach (RivetCore\Migration\CoreMigrations::all() as $m) echo $m->id(), "\n";'`.
New migrations are only ever appended, so an edition that applies the list in order never meets a gap.

Always pass `CoreMigrations::all()` rather than a hand-picked subset. RivetIT's updater did select migrations per step in 0.x
(each edition database version guarded one Core step); that is safe because the runner skips applied ids, but a subset means a
database can end up with, say, `0012` unapplied while the code expects it (the queue falls back to `started_at`, which is
correct but coarse).

### How an edition's updater calls the runner

```php
// Inside your updater, after your own migrations for this database version, guarded so a missing package retries later.
if (class_exists(\RivetCore\Migration\MigrationRunner::class)) {
    $runner = new \RivetCore\Migration\MigrationRunner(
        new \YourEdition\Core\Adapter\Database\MysqliDatabaseAdapter($mysqli),   // implements RivetCore\Database\DatabaseInterface
        \RivetCore\Migration\CoreMigrations::all(),
        new \RivetCore\Support\SystemClock()
    );
    $applied = $runner->run();       // list of ids applied by this call; [] when already current
    // $runner->status() lists every migration with its applied time; $runner->pending() lists the rest (read-only).
    // Only now advance the edition's own database version.
}
```

Both editions already do this (`admin/database_updates.php` in each), advancing their own version only after the runner returns.

## Rollback

- **There is no `down()`.** A Core migration is not reversed. Roll back by restoring the database backup taken before the update
  (`mysqldump` or the edition's backup) and re-pinning the previous Core version (`composer require rivet/rivet-core:<previous>` or
  restoring the previous `composer.lock` and vendor tree) together. Restoring the database without the pin, or the reverse, leaves the
  code and the schema out of step.
- Because migrations are additive (new tables, new nullable or defaulted columns), an older Core against a newer schema works: it
  ignores what it does not know. The one exception to test is a restore of the database to before a migration while the newer code is
  still deployed: it will not re-run until `MigrationRunner::run()` is called, and `rivet_core_migrations` must be restored with
  the rest of the database (it is part of the backup).
- Keep the rollback pointer a branch or the previous lock file, not a tag on a commit that production runs.

## Edition checklist for the 1.0 upgrade

1. Read `CHANGELOG.md` from your current pin to the target and tick each row of the tables above.
2. Take a database backup and record the current pin and `composer.lock`.
3. Change the constraint to `^1.0` (or the exact release candidate), `composer update rivet/rivet-core --with-all-dependencies` only if
   a dependency conflict demands it, commit `composer.lock` and, where tracked, the vendor files.
4. Run `MigrationRunner::run()` from your updater on a scratch copy first; check `status()` shows every id applied.
5. Add any new migration ids to the edition's `db.sql` (the `rivet_core_migrations` rows) so a fresh install matches an upgraded one.
6. Run the adapter conformance kit (`RivetCore\Testing\DatabaseContractTestCase` and any further cases the release ships) in your CI.
7. Replace uses of deprecated APIs from section 3 in your own code.
8. Move webhook receivers you control to V2 verification.
9. Run your regression scripts against a scratch database built from `db.sql` and one built from a schema-only copy of the live database.
10. Update on the target, check the health endpoint, sign in, and exercise one flow per module you use; watch logs for one cron cycle.

The recurring version of this list for every later release is [docs/EDITION_CHECKLIST.md](docs/EDITION_CHECKLIST.md).
