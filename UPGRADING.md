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

## Adopting Crypto (unreleased)

For RivetIT and RivetMSP. The code replaces `encryptSetting`/`decryptSetting`, `encryptOtpSecret`/`decryptOtpSecret` and the `V2:` / credential-field functions of `functions.php`. Nothing is forced: v3 is written as soon as you call it, legacy values stay readable, and each step can ship on its own. No Core migration. Background: [ADR-011](docs/architecture/ADR-011-envelope-and-key-standard.md), API and examples: [docs/modules/crypto.md](docs/modules/crypto.md).

**0. Prepare.** Update Core. `ext-openssl` and `ext-sodium` are required (Docker images and the editions' installers already have them). Run the two conformance kits in the edition's CI (`EnvelopeConformanceTestCase`; `RewrapSourceConformanceTestCase` once step 4 exists). Freeze the context strings you choose in step 2 in a constant table: changing one later makes the data unreadable.

**1. The key file (settings and everything derived).**
   1. Setup and the updater create `/etc/<product>/keys.json` (outside the web root, `root:www-data`, mode 0640) with `KeyFile::write($path, KeyGenerator::ringFromLegacySettingsKey($config_settings_enc_key))`. When the key is the usual 64 hex characters its bytes become kid `k1`, so v3 values and existing `ENC2:` values share one root key and the backup fingerprint does not change. A fresh install (or RivetMSP before its settings-cipher port) uses `KeyGenerator::newRing()`.
   2. At bootstrap: `$ring = (new KeyRingLoader($logger))->load($path)->ring;` (show `->warnings` on the Security settings page). Keep `$config_settings_enc_key` in `config.php` **only** until step 2 has drained every `ENC:`/`ENC2:` value, because `LegacyReaders::settings($legacyKey)` needs it.
   3. Back the file up offline, separately from the database dump and the backup passphrase; record `$ring->fingerprint($kid)` in the backup manifest (replacing `backup_settings_key_fingerprint()`; same formula).

**2. Settings secrets.** Build `new SettingsVault($ring, LegacyReaders::settings($legacyKey))` once (add `LegacyReaders::plaintext()` last only for the columns that still hold cleartext). `encryptSetting($v)` becomes `$vault->encrypt($name, $v)` and `decryptSetting($v)` becomes `$vault->decrypt($name, $v, $persist)` where `$name` identifies the setting (`smtp_password`, `oauth.google.client_secret`, `rmm.api_key`; for per-row secrets `<table>.<column>:<id>`) and `$persist` runs `UPDATE ... SET col = :new WHERE col = :previous` (lazy re-wrap). **Behaviour change:** failures throw instead of returning `''`; catch `CryptoException` at the call sites that treated `''` as "not configured" and show a configuration error. Check the column widths: a v3 value is the plaintext + 28 bytes, base64, + `v3:<kid>:`; use `TEXT`/`varchar(512)` or wider for columns that were `varchar(255)`. Drain the rest with step 4.

**3. TOTP seeds, media tokens, training PINs (MFA item 4 and friends).** TOTP: `new SettingsVault($ring, LegacyReaders::settingsAndOtp($legacyKey, $canonicalVaultKey), KeyPurpose::TOTP)`, name `user:<id>`. `$canonicalVaultKey` is `getCanonicalVaultKey()` (RivetIT stores the vault master key in settings), which is what lets a CLI rewrap read the old `enc:` seeds that otherwise need a logged-in vault session; unprefixed seeds are cleartext, read them with `LegacyReaders::plaintext()` once and rewrap. Media tokens and training PINs: derive the HMAC key with `$vault->subkey(KeyPurpose::MEDIA_TOKEN)` / `TRAINING_PIN` instead of storing a second secret; `subkey(..., kid: $oldKid)` verifies tokens issued before a rotation.

**4. The rewrap tool (`bin/rewrap`, also the rotation panel).** One `RewrapSource` per encrypted column (`name()` like `settings.smtp_password`, `next()` ordered by primary key after the cursor, `replace()` as `UPDATE ... WHERE id = ? AND col = ?` returning `affected_rows === 1`, `context` = the same string used by the read path). Run `(new Rewrapper(new FileRewrapStateStore($dir), $auditService))->run($source, new EnvelopeReencryptor($vault->envelope()), new RewrapOptions(dryRun: true))` first, then without `dryRun`, and repeat until `rewrapped` is 0 and `isClean()`. `RotationPlan::build($ring, RotationPlan::inventory($columnValues, $envelope))` feeds the panel (per key: how many values, pending, retirable). Rotate with `KeyGenerator::rotate($ring)` + `KeyFile::write($path, $new, replace: true)` + rewrap; keep the retired key for one backup cycle.

**5. The credentials vault (item 19), behind a flag `vault_v3`.**
   1. Generate the DEK once: `$dek = VaultKeyWrap::generateDek()`; store `$wrap->wrapForInstance($dek, $vault->envelope(), 'vault')` in settings (the KEK copy; recovery path).
   2. Convert credential fields with the Rewrapper: `new VaultFieldReencryptor(new VaultCipher($dek), legacyDek: $canonicalVaultKey)`, one source per credential column (password, username, OTP columns of `credentials`, asset and website logins), item context `VaultCipher::contextFor($id, 'password')`. Dry-run first and spot-check one known credential (legacy CBC is unauthenticated, a wrong key can produce garbage).
   3. Per-user wraps: on the next login, when the user's stored wrap is legacy (`V2:`/unprefixed), verify with `VaultKeyWrap::openLegacyUserKey($stored, $password)` (or just the login itself), then store `$wrap->wrap($dek, $password, 'user:' . $id)` from the instance-wrapped DEK. API keys that carry a wrap (`api_key_decrypt_hash`) get the same treatment with context `apikey:<id>`. Password change: `$wrap->rewrap($stored, $old, $new, $ctx)`; no credential is touched. `needsUpgrade()` tells you to re-wrap at login when the KDF parameters are raised.
   4. Read path: `VaultCipher::open()` for v3 values and the legacy functions for anything `VaultCipher::isLegacy()` until the rewrap reports clean; then flip `vault_v3` for writes (before the flip new credentials are written in the legacy format, so the drain is not chasing new rows) and retire the canonical key.
   5. A forgotten password is an admin re-issue from the KEK wrap (`unwrapForInstance` then `wrap()`), not a weaker KDF.

**6. Done when:** `RotationPlan` is complete for every column, the legacy keys are gone from `config.php` and the settings table, a restore drill with only the offline key file opens a sample secret of each kind, and both kits are green in the edition's CI.

## Adopting RMM Phase 1 (1.0.0-rc.9)

For an edition that already runs the RMM module. Everything is additive: an edition that changes nothing keeps working, and the golden transcripts of the old agents replay identically. The module gains new optional constructor arguments only at the end, and no existing interface gets a method (the new contracts are companions, as ADR-004 asks).

1. **Migrations.** `CoreMigrations::all()` now ends with `0018_rmm_inventory_foundation`: eleven new tables, `CREATE TABLE IF NOT EXISTS`, `utf8mb4_general_ci`, nothing existing altered. If your `db.sql` mirrors Core's tables, add them (the DDL is `Rmm\Migration\RmmSchema::phase1Tables()`); do not run `RmmSchema::tables()` against them. The tables stay empty and cost nothing until the matching feature is used. **Run the migration before, or immediately after, deploying the new Core code**: the check evaluator writes the check history on every check-in that carries checks, so until `0018` has run those check-ins answer `500 internal` (the agents retry the same body and recover on their own once the tables exist).
2. **Events (optional).** Implement `Rmm\Contracts\RmmEventsInterface` on your event bus (webhooks, automation rules) and pass it as the **last** argument of `RmmModule`. The nine `rmm.*` ids are listed in `RmmEvent` and in `Webhooks\EventCatalog` (group `rmm`); a webhook subscription to `rmm.*` or `*` matches them. Without it the module does not even track presence. Call `RmmModule::housekeeping()->run()` from cron as before: it now also announces offline devices once per offline period.
3. **Metric history for an edition without a metrics subsystem (RivetMSP).** Pass `new Rmm\Support\DatabaseMetricSink($database, $clock)` as the metric sink. Housekeeping prunes it. RivetIT keeps its own Metrics subsystem; if it wants the network bar against the 24 hour peak from the module, implement `Rmm\Contracts\RmmMetricReaderInterface` on its sink adapter (the conformance case is `Testing\RmmMetricReaderConformanceTestCase`).
4. **Software inventory.** Roll out an agent of this release (it announces `software_inventory`), then switch on the `inventory_software` sub-switch. Nothing is collected by agents until the server offers it, so the order is safe both ways. Switching it off makes the agents stop sending within one check-in.
5. **Pages and routes.** The technician REST routes are served by `TechnicianApi` without any change to your bridge (they are new path segments under `endpoint_devices`); make sure your front controller passes all segments through. Render the new `RmmReadModel` methods on the device and fleet pages (software tab, tags, groups, check trend, network bar, the live document). `TechnicianActions` is unchanged; tags, groups and the software refresh live in `RmmModule::inventory()`.
6. **Limits.** `check_history_days`, `check_history_gap_s` and `software_history_days` are valid `limits_json` keys now; add them to your settings page if you want them editable (defaults 7, 3600, 365).
7. **Differences to know.** `RmmReadModel::listDevices()` summaries gain a `tags` list in the extras mode (the technician REST list is unchanged). `InMemoryRmmMetricSink` implements the reader (it is `@internal`). `Housekeeping::run()` returns three more counters (`pruned_check_history`, `pruned_software_history`, `pruned_software_removed`) and, when applicable, `pruned_metrics` and `offline_events`.

## Adopting RMM Phase 2 (1.0.0-rc.10)

For an edition that already runs the RMM module. Everything is additive and off until switched on: an edition that changes nothing keeps working and the golden transcripts of the old agents replay identically.

1. **Migrations.** `CoreMigrations::all()` now ends with `0019_rmm_policies_and_scripts`: eleven new tables, `CREATE TABLE IF NOT EXISTS`, `utf8mb4_general_ci`, nothing existing altered. If your `db.sql` mirrors Core's tables, add them (the DDL is `Rmm\Migration\RmmSchema::phase2Tables()`). Run it before, or immediately after, deploying the new code: the job list reads `rmm_job_extra` (it tolerates a missing table), but every other Phase 2 path needs the tables. A fresh install gets them from the migration runner.
2. **The new ability.** Map `rmm.job.approve` in your `AccessPolicyInterface` (RivetIT: a role setting of its own is best; do not hand it to every role that has `rmm.job.run_saved`, the point is a second person). An unknown ability is denied, so until you map it nobody can approve and a request simply waits and expires.
3. **Sub-switches.** `policies` and `scripts` are off. Switch them on (`RmmSettings::update(['features_json' => ...])`) once agents of this release are rolled out. Old agents get the policy's values in the fields they already read, without the new keys.
4. **Job types.** `JobTypeRegistry::withDefaults()` now also registers `shell` and `python`, and `reboot` and `collect` list Linux. `JobService::create()` now refuses a type whose platforms do not include the device's operating system (`powershell` on a Linux device); a custom registry is unaffected unless it lists platforms.
5. **Constructor arguments.** `RmmModule` builds everything; an edition that constructs `JobService`, `CheckinService`, `Housekeeping`, `TechnicianActions`, `TechnicianApi` or `RmmReadModel` itself passes the new optional trailing arguments or switches to `RmmModule`.
6. **Pages and routes.** The new REST routes are served by `TechnicianApi` without a change to your bridge. Pages come from `readModel()->automation()` and `policyActions()`, `scriptActions()`, `fieldActions()`. Optional: `RmmModule::setScheduleGate()` with a maintenance-window gate.
7. **Limits.** `approval_bulk_threshold` (0 = never), `approval_expiry_h`, `schedule_batch`, `bulk_run_max` are valid `limits_json` keys now.
8. **Differences to know.** A job list entry for a library job carries a `library` member; every other job keeps its shape. The `rmm.*` event catalog has four more ids (13 in all); a webhook subscribed to `rmm.*` starts receiving them when the features are used.

## Adopting RMM Phase 3 (alerting maturity)

For an edition that already runs the RMM module. Everything is additive and dormant: until the `alerting` sub-switch is turned on the check evaluator is the Phase 0 code, no new table is read, and a check-in costs the same number of statements. The golden transcripts of old agents replay identically. `RmmBridgeInterface` did not change.

1. **Migration.** `CoreMigrations::all()` gains `0020_rmm_alerting_maturity` (id 0019 belongs to RMM Phase 2): eight new tables (`rmm_alerting_settings`, `rmm_check_eval`, `rmm_alert_meta`, `rmm_storm_summaries`, `rmm_maintenance_windows`, `rmm_device_parents`, `rmm_escalation_policies`, `rmm_escalation_steps`), all `CREATE TABLE IF NOT EXISTS`, `utf8mb4_general_ci`, nothing existing altered. The DDL is `Rmm\Migration\AlertingSchema::tables()` if your `db.sql` mirrors Core's tables.
2. **Escalation delivery (optional).** Implement `Rmm\Contracts\RmmEscalationInterface` (`notify`, `acknowledgeAlert`, `raiseAlertSeverity`) with whatever you deliver notices with, run `Testing\RmmEscalationConformanceTestCase` against it, and pass it as the `escalation` option of `RmmModule`. Without it escalation steps stay due, are retried (5 times per step, a minute apart) and then skipped; nothing is ever recorded as sent that was not.
3. **Ability.** Map `rmm.alert.manage` in your `AccessPolicyInterface` (acknowledge and resolve alerts, maintenance windows of one client or device, a device's parent). Windows over all devices, a site, a group or a tag, escalation policies and the storm limits need `rmm.admin`; per-device threshold overrides need `rmm.device.manage`.
4. **Scheduled scripts.** While `alerting` is on, `RmmModule` installs `Alerting\MaintenanceScheduleGate` as the schedule gate unless you called `setScheduleGate()` yourself: a scheduled script waits (`defer`) while a `suppress` maintenance window is open for the device. A `mute` window never holds a script back, and with the switch off every script runs as before.
5. **Your own alert screens.** If users acknowledge or resolve agent alerts in your UI, call `$module->alerts()->acknowledge($alertId, $userId)` (and `resolveExternal($alertId)` when you resolved it yourself) so Core's record and the escalation clock agree. Alerts that were open before the switch was turned on are adopted by the next `Housekeeping::run()`.
6. **Maintenance mode.** Your own `rmm_maintenance_mode` flag is not read by Core. Maintenance windows replace it for agent alerts; to keep one source of truth, stop offering the flag for agent alerts when you adopt windows.
7. **Events.** Six new ids in `RmmEvent` and `Webhooks\EventCatalog`: `rmm.alert.opened`, `.escalated`, `.acknowledged`, `.resolved`, `rmm.maintenance.started`, `.ended`. The maintenance events are not about one device (the same convention as the Phase 2 events): `device_id` is 0, `hostname` is empty and `client_id` is the scope's client; a device-scoped window carries the device in `scope_id`.
8. **Switch on.** Roll out an agent of this release (it announces `check:<type>` for the twelve new check types; an older agent keeps getting the four original types only), then enable the sub-switch with `RmmSettings::update(['features_json' => [...your list..., 'alerting' => true]])`. Thresholds, flap and the other rules live in the `params` of the check definitions (see `Checks\CheckCatalog`, `GET endpoint_devices/check_types`).
9. **Differences to know.** `ChecksValidator::TYPES` has sixteen entries and its refusal message lists them; the params of the four original types are still not schema-checked, but `flap` is validated and `thresholds` is refused on them. `RmmSettings::signedChecks()` takes an optional capability list (null = the original types only). `Housekeeping::run()` returns more counters while the switch is on. `CheckEvaluator`, `Housekeeping`, `TechnicianApi` and `RmmEventPublisher` gained optional trailing constructor arguments / one method.

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
| `0019_rmm_policies_and_scripts` | `Rmm\Migration\Migration0019PoliciesAndScripts` | eleven new tables: `rmm_policies`, `rmm_policy_versions`, `rmm_policy_assignments`, `rmm_scripts_v2`, `rmm_script_versions`, `rmm_job_extra`, `rmm_schedules`, `rmm_schedule_runs`, `rmm_approvals`, `rmm_custom_fields`, `rmm_custom_field_values` (all `IF NOT EXISTS`, nothing existing altered) | 1.0.0-rc.10 |

| `0020_rmm_alerting_maturity` | `Rmm\Migration\Migration0020AlertingMaturity` | eight new tables: `rmm_alerting_settings`, `rmm_check_eval`, `rmm_alert_meta`, `rmm_storm_summaries`, `rmm_maintenance_windows`, `rmm_device_parents`, `rmm_escalation_policies`, `rmm_escalation_steps` (all `IF NOT EXISTS`, nothing existing altered) | after 1.0.0-rc.9 |

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
