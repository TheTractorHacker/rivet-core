# Upgrading RivetCore (0.x to 0.x, and to 1.0)

For an edition that pins an older tag. Derived from [CHANGELOG.md](../CHANGELOG.md); the schema claims are verified by
`scripts/verify-upgrade.php` (results at the end). Policy: [ADR-004](architecture/ADR-004-versioning-policy.md).

## Why every 0.x release needs a deliberate bump

In `0.x`, Composer's caret accepts only the same minor: `^0.17` allows `0.17.0` and `0.17.1` but **not** `0.18.0`. So every minor release
needs an explicit change to the edition's `composer.json` (and a minor may break an API; the changelog says so). A patch (`0.17.1`) is
picked up by `composer update`. The caret becomes useful at 1.0, where `^1.0` accepts every `1.x`.

```json
{
  "repositories": [{ "type": "vcs", "url": "https://github.com/TheTractorHacker/rivet-core", "no-api": true }],
  "require": { "rivet/rivet-core": "^0.21" }
}
```

You can jump several minors in one step (the migrations are cumulative and idempotent; see the verification), but read every row below
between your current version and the target for behaviour that may matter to you.

## The procedure

1. **Read** the rows below from your version to the target, and the changelog entries they point at.
2. **Back up the database** and record the rollback pin (the tag you are on, and a branch in your repository that is not the tag a live
   site is running). A rollback is that backup plus the old pin, nothing else (see "Rolling back").
3. **Bump the constraint** in the edition's `composer.json` to the target minor and run `composer update rivet/rivet-core
   --with-dependencies`. Editions that track `vendor/` in Git regenerate the committed vendor files and commit them with the bump.
   Check `composer.json` of Core for dependency changes (table below) and make sure your PHP is at least 8.2.
4. **Run Core's migration runner** from your updater, after your own migrations, then bump your own database version:
   ```php
   $applied = (new RivetCore\Migration\MigrationRunner($db, RivetCore\Migration\CoreMigrations::all(), new RivetCore\Support\SystemClock()))->run();
   ```
   `run()` returns the ids it applied (empty when current), takes a server-side lock so two updaters cannot race (since 0.16.0), and is
   safe to run repeatedly. `status()` lists every migration with its applied time and `pending()` the ones still to run.
   Append the new migration ids to your install SQL (`db.sql`) snapshot so a fresh install matches an upgraded one.
5. **Verify.** `ReadinessChecker::check()` reports ready; run your own smoke test; check one flow per module you use; watch the logs and
   one cycle of your cron. In particular after 0.17.0 and 0.18.1 check the job worker and any webhook receivers (rows below).
6. **Record** the new pin and the migration ids that were applied.

### Checklist

- [ ] Current version and target version written down; every row between them read.
- [ ] Database backup taken and restorable (test the restore at least once per release train).
- [ ] Rollback branch and old pin recorded.
- [ ] Constraint bumped; `composer update` ran; vendor files regenerated and committed if tracked.
- [ ] PHP >= 8.2; `ext-mysqli` (your adapter), `ext-zip` and `ext-dom` for DOCX, `poppler-utils` for PDF, `ext-curl` for webhooks.
- [ ] `MigrationRunner::run()` executed; returned ids match the table below; `pending()` is empty.
- [ ] Your install SQL snapshot includes the new migration ids and tables.
- [ ] `ReadinessChecker` green; smoke tests green; cron ran one cycle with no errors.
- [ ] Webhook receivers still verify signatures (new headers are additive; legacy headers unchanged).
- [ ] Changelog line written in the edition's own release notes.

## Migrations by version

Core's migrations are numbered `0001` to `0012` and tracked in `rivet_core_migrations`, independent of any edition's database
version. Only the versions below add migrations; every other release has none.

| Added in | Migration | What it does |
|---|---|---|
| 0.1.0 | `0001_audit_events` | table `audit_events` |
| 0.3.0 | `0002_integration_jobs` | table `integration_jobs` |
| 0.4.0 | `0003_mcp_unlinked_identities` | table `mcp_unlinked_identities` |
| 0.5.0 | `0004_problems_and_changes` | tables `problems`, `changes` |
| 0.6.0 | `0005_webhook_deliveries`, `0006_automation_rules`, `0007_workflow_tables` | `webhook_deliveries`, `automation_rules`, four `workflow_*` tables |
| 0.9.0 | `0008_compliance` | `compliance_attestations`, `compliance_snapshots` |
| 0.10.0 | `0009_compliance_shared_report` | `compliance_shared_report` |
| 0.11.0 | `0010_compliance_subjects` | `compliance_subjects`; adds `subject_id` (default 0) to attestations and snapshots |
| 0.14.0 | `0011_compliance_responsibilities` | `compliance_responsibilities` |
| 0.17.0 | `0012_job_heartbeat` | adds nullable `integration_jobs.heartbeat_at` |

All migrations are additive and idempotent (`CREATE TABLE IF NOT EXISTS`, guarded `ADD COLUMN`), touch only Core-owned tables, and are
never edited after release. No migration in the 0.x line drops, renames or rewrites data (checked by reading every `src/*/Migration` file: only `CREATE TABLE IF NOT EXISTS` and two guarded `ADD COLUMN`/`ADD INDEX` statements).

## Dependencies

| From | Change |
|---|---|
| all | `php >= 8.2` |
| 0.2.0 | `predis/predis ^3.5` |
| 0.4.0 | `guzzlehttp/guzzle ^7.0`, `psr/simple-cache ^3.0` |
| 0.4.1 | Guzzle `^7.0 \|\| ^8.0` (both editions already run Guzzle 8) |
| 0.17.0 | `psr/log ^3` |

Dev only: `phpunit ^11.5`, `phpstan ^2`.

## Version by version: what may need your attention

Rows list contract changes (an API changed or behaviour an edition may have relied on changed). Additive releases with nothing to do are
summarised at the end. "Breaking" means a minor that can break a caller; nothing in 0.x is guaranteed compatible.

| To | Constraint | What changed | What to check |
|---|---|---|---|
| 0.3.0 | `^0.3` | `JobQueue::claim()` is safe for concurrent workers and **returns the claimed state** (status `running`, attempts incremented) | a worker that passed a pre-claim snapshot to `markFailed()` had an off-by-one attempt count; pass the returned rows |
| 0.4.0 | `^0.4` | new dependencies (Guzzle, PSR-16) | `composer update` must be able to resolve them |
| 0.6.0 | `^0.6` | `WorkflowService::startRun()` is atomic | none; a failure now leaves no half-created run |
| 0.7.1 | `^0.7.1` | fix: `markFailed()` crashed on the fifth failed attempt; PDF import failed on PHP 8.2 | take this patch before any other 0.7 |
| 0.8.0 | `^0.8` | `RetentionService::prune($days, $auditDays = null)`; `RetentionPolicy` presets | existing one-argument calls behave as before |
| 0.9.0 | `^0.9` | compliance status engine, migration `0008` | run the migration runner |
| 0.11.0 | `^0.11` | `AttestationStore`/`SnapshotStore` take an optional `$subjectId`; migration `0010` adds `subject_id` | default 0 keeps old behaviour |
| 0.14.0 | `^0.14` | `ComplianceAssessor` takes an optional fifth argument (responsibilities); rows and snapshots gain `responsible`; migration `0011` | an edition that renders rows should expect the extra key |
| 0.15.0 | `^0.15` | `deliverTo()` and `JobWorker` added; webhook results gain `ok` | code that compared a result array exactly should use the keys |
| 0.16.0 | `^0.16` | `MigrationRunner` takes a server-side lock; a runner that waits longer than `$lockWaitSeconds` (60) throws `RuntimeException`; new `status()` | run migrations from one place (the updater); catch the exception and tell the admin to retry |
| 0.17.0 | `^0.17` | **largest change.** Services take a PSR-3 logger instead of calling `error_log()` (`Support\ErrorLogLogger` is the default; the three MCP classes still accept the old closure); `psr/log` added; every webhook request now also carries `X-Rivet-Timestamp` and `X-Rivet-Signature-V2` (legacy headers byte-identical); `UrlPolicy` and pinned connections; `JobQueue` heartbeat and migration `0012` (until applied, everything falls back to `started_at`); `RedisConnectionConfig` and `RedisAdmin::client()/test()` accept it (the array form still works); `RetentionService` separate horizons for deliveries and jobs (7-day floor, 30 under a preset) and a dry-run `plan()`; `AuditReader`; `AccessPolicyInterface` (opt-in) | run `0012`; pass a logger if you want logs somewhere other than `error_log`; receivers may start verifying V2 |
| 0.18.0 | `^0.18` | `UrlPolicy` optional `allowedNetworks` (constructor signature unchanged), `NetworkList`, `LocalNetworks` | none |
| 0.18.1 | `^0.18.1` | security hardening, all backward compatible but behaviour visible: `JobQueue::markCompleted()`/`markFailed()` **now return `bool`** and only write while the job is `running` (take the claimed attempt as a fence; `JobWorker` passes it); `requeueStale()` dead-letters jobs that used all attempts; `JobRunner` default state directory is per user (`rivetcore-jobs-<uid>`) and refuses a directory not owned by the user or group/world writable (`start()` returns `ok: false`); `AuditService` clamps every field to its column and stores values under secret-looking keys as `[redacted]`; `UrlPolicy` rejects more ranges; webhook requests never use a proxy; `CredentialReferenceRenderer` only substitutes tokens in text | custom callers of `markCompleted()`/`markFailed()` should read the new return value; pin a state directory in a `JobRunner` subclass if you relied on the old location; handlers must be idempotent |
| 0.19.0 to 0.21.0 | `^0.21` | additive: `IconCatalog`, `DateRange`, webhook destinations, formats, templates, authentication and the event catalog; `deliverTo()` options | none |

Additive and no action beyond the bump: 0.1.0 to 0.2.0, 0.4.1, 0.5.0, 0.7.0, 0.10.0, 0.12.0, 0.13.0, 0.15.1 (`AuditService` optional
`afterLog` callback), 0.17.1 (CI only).

### Known behaviour to design around (all versions)

- Redis helpers fail open: with Redis down, locks report `degraded()` and cron jobs may overlap; make jobs idempotent ([REDIS.md](REDIS.md)).
- The migration lock name is global to the database server (two databases on one server serialize their migration runs); see
  [PUBLIC-API.md](PUBLIC-API.md), open question 7.

## Rolling back

Migrations are forward-only and additive; there is no down migration (DDL auto-commits on MySQL and MariaDB, so one cannot be made safe).

- **Code rollback only** (the schema stays upgraded): pin the previous tag and `composer update`. The verification below shows that an old
  tag's migration runner, pointed at the upgraded schema, applies nothing and does not fail. Services of the old tag ignore columns and
  tables they do not know (additive schema), so this is the supported way back for a bad release. It was verified for the runner and is
  covered for the services by the additive-only rule, not by running every old service against every newer schema.
- **Full rollback** (restore the data as well): restore the database backup taken in step 2 and pin the old tag; the restored
  `rivet_core_migrations` table then reflects the old schema, and the migrations are re-applied when you upgrade again.
- Never edit a migration or delete rows from `rivet_core_migrations` to "redo" one; add a new migration instead.

## Upgrading to 1.0

When 1.0 ships: change the constraint to `^1.0` (the last change of this kind), read the 1.0 changelog for anything that left
`Provisional` status ([PUBLIC-API.md](PUBLIC-API.md)), decide the open questions the owner settles by then (#45 to #49), and from then on
`composer update` is safe within `1.x`. The `ITFlow\` shims in the editions stay supported through 1.x (decision #45, pending).

## Verification: the migration runner from old schemas

`php scripts/verify-upgrade.php <scratch-db-base> [tag ...]` extracts each old tag's `src/` with `git archive`, runs **that tag's own**
migration runner into an empty scratch database (the schema an edition on that tag has), inserts an audit row, runs **this tree's** runner,
and checks that it applied exactly the missing migrations, that the schema equals a fresh install (tables, columns, types, nullability,
defaults, indexes, engine, collation; column order is reported separately because an upgraded table gets new columns last), that the row
survived, that a second run applies nothing, and that the old tag's runner on the upgraded schema applies nothing.

<!-- upgrade-results -->
