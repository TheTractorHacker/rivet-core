# Retention

## Overview

`RivetCore\Retention\RetentionService` prunes the Core-owned log tables so they cannot grow without bound. The edition chooses the horizons (in days) and when to call it, typically from an hourly cron.

- **Owns no tables and no migration.** It deletes from tables owned by other modules:
  - `audit_events` (migration 0001, see [audit.md](audit.md)), by `created_at`;
  - `webhook_deliveries` (migration 0005), by `created_at`;
  - `integration_jobs` (migrations 0002/0012, see [jobs.md](jobs.md)), only `completed` and `dead_letter` rows, by `created_at`. `pending` and `running` jobs are never touched.
- Fixed SQL only; no identifier comes from the caller.
- The edition's own tables (activity logs, auth logs) are pruned by the edition, not here.

## Contracts an edition must implement

- `Database\DatabaseInterface` ([../adapters.md](../adapters.md)). The cutoff is computed from the database clock (`SELECT NOW()`), so it agrees with the timestamps the database wrote.
- **Policy values**: the edition stores the horizons (its log-retention and audit-retention settings) and passes numbers in.
- Optionally a **compliance profile id** (a key of `Compliance\RetentionPolicy::PROFILES`) from its compliance settings.

## Key classes

```php
use RivetCore\Retention\RetentionService;

$retention = new RetentionService($database);              // no compliance profile
$deleted = $retention->prune(90, 365);                      // 90 days for deliveries and jobs, 365 for the audit trail
// ['audit_events' => 120, 'webhook_deliveries' => 4000, 'integration_jobs' => 35]

// Separate horizons, smaller batches, and a dry run first:
$plan = $retention->plan(days: 90, auditDays: 365, deliveryDays: 30, jobDays: 30);
$retention->prune(90, 365, 30, 30, batchSize: 1000);
```

- `prune($days, $auditDays = null, $deliveryDays = null, $jobDays = null, $batchSize = 5000)`: each null falls back to `$days`. Deletes in `LIMIT $batchSize` statements, repeating until a table is clear, so no statement holds a long lock. Returns rows deleted per table; a table whose horizon is below 1 is skipped and not listed.
- `plan(...)` takes the same horizons and returns how many rows `prune()` would delete, without deleting.
- A horizon of 0 or below means "keep everything" for that table. If every horizon is below 1, nothing happens.

### Compliance profile and RetentionPolicy presets

With a profile, `new RetentionService($database, 'soc2')`, every horizon is raised to that preset's floor for its kind inside `prune()` and `plan()`, so a caller cannot delete audit rows younger than the floor. A 0 (keep forever) stays 0.

`Compliance\RetentionPolicy` provides the presets and the arithmetic:

| Preset | Audit floor (days) |
|---|---|
| `none` | 0 |
| `iso27001`, `soc2`, `pci`, `nist171` | 365 |
| `hipaa` | 2190 |

Deliveries and finished jobs use a shorter floor: 7 days with no preset (`OPERATIONAL_MIN_DAYS`), 30 days under any framework preset (`OPERATIONAL_REGULATED_DAYS`, never above the preset's own floor). Helpers: `isValidProfile()`, `floorDays()`, `floorDaysFor($profile, $kind)`, `effectiveDays()`, `effectiveDaysFor()`, `isBelowFloor()`, `describeDays()`, and the kind constants `KIND_AUDIT`, `KIND_DELIVERIES`, `KIND_JOBS`. A preset is a floor, not a compliance claim.

```php
use RivetCore\Compliance\RetentionPolicy;

RetentionPolicy::effectiveDays('soc2', 90);   // 365
RetentionPolicy::effectiveDays('soc2', 0);    // 0 (keep forever)
```

Note that `RetentionService` without a profile applies no floor at all (not even the 7-day operational minimum); the edition is expected to apply `effectiveDays()` first, or pass the profile.

## Configuration

- Constructor: `(DatabaseInterface $database, ?string $profile = null)`.
- `RetentionService::DEFAULT_BATCH = 5000`.
- Settings keys are the edition's. RivetIT and RivetMSP read `config_log_retention`, `config_audit_retention_days` and the compliance profile from their settings tables in `cron/cron.php`.

## How it fails

- Database errors propagate as `DatabaseException`; there is no internal catch. Editions wrap the call so the rest of the cron job still runs (both log `RivetCore retention skipped: ...` with `error_log`).
- An unknown profile id means no preset floor (`floorDays()` returns 0).
- A table that does not exist throws on its statement; pruning stops there.

## Security notes

- Deletion is limited to three fixed tables and fixed `WHERE` clauses with bound cutoffs; caller input is only integers.
- Passing the compliance profile is the way to guarantee a regulated installation cannot configure retention below its floor, even through a stale setting or direct call.
- Pruning the audit trail is irreversible; run `plan()` first when changing horizons, and keep backups for the horizon you need.

## Used by

- **RivetIT:** `cron/cron.php` calls `RetentionService::prune($log_retention_days, $audit_retention_days)` (no profile argument; horizons are first passed through `RetentionPolicy::effectiveDays()`). `admin/settings_compliance.php`, `admin/post/settings_compliance.php` and `admin/post/settings_security.php` use `RetentionPolicy` for the settings UI and to enforce the floor on save.
- **RivetMSP:** `cron/cron.php` does the same (`RetentionService::prune()` inside try/catch). The same three admin settings files use `RetentionPolicy`.
- Core: `Compliance\Check\RetentionMeetsPresetCheck` uses `RetentionPolicy`.

## Links

- CHANGELOG: 0.7.0 (`RetentionService`), 0.8.0 (separate audit horizon, `RetentionPolicy::effectiveDays`), 0.17.0 (delivery/job horizons, `plan()`, batched deletes), 0.18.1 (compliance profile argument). See [../../CHANGELOG.md](../../CHANGELOG.md).
- Related: [audit.md](audit.md), [jobs.md](jobs.md), [../webhooks.md](../webhooks.md).
- Tests: `tests/Integration/RetentionTest.php`, `tests/Unit/RetentionPolicyTest.php`.
