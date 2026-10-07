# Migration

## Overview

`RivetCore\Migration` applies Core's own schema steps. Its state lives in the `rivet_core_migrations` table (`migration_id`, `applied_at`), independent of RivetIT and RivetMSP database versions. Each edition's updater calls the runner after its own migrations.

- **Owns:** `rivet_core_migrations`, created by the runner itself on first `run()`.
- `CoreMigrations::all()` returns the ordered, append-only list of every Core-owned migration.

| Id | Class | Creates or changes |
|---|---|---|
| `0001_audit_events` | `Audit\Migration\Migration0001AuditEvents` | `audit_events` |
| `0002_integration_jobs` | `Jobs\Migration\Migration0002IntegrationJobs` | `integration_jobs` |
| `0003_mcp_unlinked_identities` | `Mcp\Migration\Migration0003McpUnlinkedIdentities` | `mcp_unlinked_identities` |
| `0004_problems_and_changes` | `ITSM\Migration\Migration0004ProblemsAndChanges` | `problems`, `changes` |
| `0005_webhook_deliveries` | `Webhooks\Migration\Migration0005WebhookDeliveries` | `webhook_deliveries` |
| `0006_automation_rules` | `Automation\Migration\Migration0006AutomationRules` | `automation_rules` |
| `0007_workflow_tables` | `Workflow\Migration\Migration0007WorkflowTables` | `workflow_*` |
| `0008_compliance` | `Compliance\Migration\Migration0008Compliance` | `compliance_attestations`, `compliance_snapshots` |
| `0009_compliance_shared_report` | `Compliance\Migration\Migration0009SharedReport` | shared report |
| `0010_compliance_subjects` | `Compliance\Migration\Migration0010Subjects` | `compliance_subjects`, `subject_id` columns |
| `0011_compliance_responsibilities` | `Compliance\Migration\Migration0011Responsibilities` | responsibilities |
| `0012_job_heartbeat` | `Jobs\Migration\Migration0012JobHeartbeat` | `integration_jobs.heartbeat_at` |
| `0013_retention_indexes` | `Retention\Migration\Migration0013RetentionIndexes` | retention indexes |
| `0014_endpoint_agent_core` | `Rmm\Migration\Migration0014EndpointAgent` | the ten `endpoint_agent_*` tables, settings row |
| `0015_endpoint_agent_converge` | `Rmm\Migration\Migration0015EndpointAgentConverge` | `ca_pem`, release `arch`/`binary_id` and key (installs stopped at RivetIT 2.6.145) |
| `0016_rmm_module_switches` | `Rmm\Migration\Migration0016ModuleSwitches` | `features_json`, `limits_json`, `shed_level`, `ingest_mode`, `max_devices` |

## Contracts an edition must implement

- `Database\DatabaseInterface` ([../adapters.md](../adapters.md)), whose connection can run DDL.
- `Contracts\ClockInterface` (`now(): DateTimeImmutable`); `Support\SystemClock` is provided. The time is stored as `applied_at`.
- `MigrationInterface` is implemented by Core's migrations. An edition does not add to `CoreMigrations`; it only adds its own schema in its own updater.

### Writing a Core migration (Core contributors)

```php
use RivetCore\Database\DatabaseInterface;
use RivetCore\Migration\MigrationInterface;

final class Migration0013Example implements MigrationInterface
{
    public function id(): string { return '0013_example'; }   // stable, sortable, never changed once released

    public function up(DatabaseInterface $database): void
    {
        $database->execute('CREATE TABLE IF NOT EXISTS `example` (`id` int(11) NOT NULL AUTO_INCREMENT, PRIMARY KEY (`id`))');
    }
}
```

Rules: additive and idempotent (`CREATE TABLE IF NOT EXISTS`, add a column only after checking `information_schema`); never drop, rename or truncate; touch Core-owned tables only; append to `CoreMigrations::all()`. Keep a table identical to the edition's `db.sql` definition when the edition already created it.

## Key classes

```php
use RivetCore\Migration\{MigrationRunner, CoreMigrations};
use RivetCore\Support\SystemClock;

$runner = new MigrationRunner($database, CoreMigrations::all(), new SystemClock(), lockWaitSeconds: 60);

$runner->pending();   // list<string> ids not yet applied (read only)
$runner->status();    // list<array{id, applied_at}> in id order, applied_at null when pending (read only)
$applied = $runner->run();   // list<string> ids applied by this call; [] when already current
```

How an edition's updater calls it (the actual pattern in both editions' `admin/database_updates.php`):

```php
// Skipped (version NOT advanced, so it retries) if rivet/rivet-core is not installed yet.
if (class_exists(\RivetCore\Migration\MigrationRunner::class)) {
    (new \RivetCore\Migration\MigrationRunner(
        new MysqliDatabaseAdapter($mysqli),
        \RivetCore\Migration\CoreMigrations::all(),
        new \RivetCore\Support\SystemClock()
    ))->run();
    mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.124'");
}
```

`run()` creates the state table, loads applied ids, applies pending migrations in `strcmp` order of id, and records each one after its `up()` succeeds. Because every step is idempotent, an edition may call `run()` from several of its own version steps; only pending migrations execute.

## Configuration

- `$lockWaitSeconds` (default 60): how long a second runner waits for the first.
- The lock is a server-side `GET_LOCK('rivet_core_migrations', wait)` (`MigrationRunner::LOCK_NAME`), released in `finally`.
- The constructor throws `InvalidArgumentException` for duplicate migration ids.

## How it fails

- A second runner that waits longer than the limit throws `RuntimeException` ("Another Core migration run is in progress; try again in a moment.").
- If the database cannot provide a lock (`DatabaseException` or no row), the run continues without it; the migrations are idempotent.
- A failing `up()` propagates its exception. The migration is not recorded, so the next `run()` retries it. DDL auto-commits on MySQL/MariaDB, so there is no transaction and a half-finished step must be safe to repeat.
- `status()` and `pending()` treat a missing state table as "nothing applied".

## Security notes

- Migrations execute DDL with the application's database account; keep that account's privileges in mind and review every migration before releasing.
- Only Core-owned tables may be touched; migrations never drop or truncate, which limits damage from a bad release.
- Rollback: there is no `down()`. Roll back by restoring the database backup taken before the update and pinning `rivet/rivet-core` to the previous version in the edition's `composer.json`. Newer schema left behind is additive and harmless to older code, except that jobs fall back to `started_at` when `heartbeat_at` is missing or ignored.

## Used by

- **RivetIT:** `admin/database_updates.php` runs the runner in the 2.6.124 step (all migrations) and again at the steps that need migrations 0008 to 0012 (for example the `Migration0012JobHeartbeat` step); `health/ready.php` checks that the stored version matches `LATEST_DATABASE_VERSION`.
- **RivetMSP:** `admin/database_updates.php` has the same pattern (2.6.55 for the first run, then later steps for 0008 to 0012).

## Links

- CHANGELOG: 0.1.0 (runner and its own state), 0.16.0 (`GET_LOCK` and `status()`), 0.17.0 (migration 0012). See [../../CHANGELOG.md](../../CHANGELOG.md).
- ADR: [../architecture/ADR-001-database-strategy.md](../architecture/ADR-001-database-strategy.md).
- Related: [audit.md](audit.md), [jobs.md](jobs.md).
- Tests: `tests/Integration/MigrationAndAuditTest.php`.
