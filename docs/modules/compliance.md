# Compliance

Namespace `RivetCore\Compliance`. Status reporting for ISO/IEC 27001, SOC 2, PCI DSS, HIPAA and NIST SP 800-171 / CMMC Level 2: automatic checks, a manual checklist with sign-off, scoring, saved snapshots, reports, a reduced shareable view, and retention presets. It measures how many listed controls are evidenced. It is a self-assessment, not a certification, and every control reference is indicative.

## Overview

What it owns (migrations `0008` to `0011`, run through `Migration\MigrationRunner`):

| Migration | Tables / changes |
|---|---|
| `0008_compliance` | `compliance_attestations` (append-only manual reviews), `compliance_snapshots` (saved assessments) |
| `0009_compliance_shared_report` | `compliance_shared_report` (one row, `shared_id = 1`: the published snapshot) |
| `0010_compliance_subjects` | adds `subject_id` (default 0) to the two tables above; creates `compliance_subjects` (frameworks and shared snapshot per subject) |
| `0011_compliance_responsibilities` | `compliance_responsibilities` (who owns a section or an item) |
| `0046_compliance_framework_profiles` | `compliance_profiles`, `compliance_profile_versions`, `compliance_profile_requirements`, `compliance_profile_selection`, `compliance_profile_selection_log`, `compliance_restore_tests` (customizable frameworks, below) |

`subject_id = 0` means the installation itself; any other value is a subject the edition defines, such as an MSP customer id. `AttestationStore` and `SnapshotStore` always filter on `subject_id`, so every installation needs migration `0010`, not only multi-subject ones.

Core also ships two reference checks (`Check\AuditTrailRecordingCheck`, `Check\RetentionMeetsPresetCheck`) and the retention presets (`RetentionPolicy`). Every other check is the edition's.

## Contracts an edition must implement

**`CheckInterface`**: one automatic, read-only check. It must never change anything.

- `id(): string`: stable id such as `mfa_coverage`. Stored in snapshots; never rename or reuse.
- `title()`, `category()` (grouping heading), `why()`: plain strings for the report.
- `controls(): array<string, list<string>>`: indicative references keyed by `Framework` constant, for example `[Framework::PCI => ['8.4.2']]`. Unknown framework keys are dropped by the assessor.
- `run(): CheckResult`: build with `CheckResult::pass()`, `warn()`, `fail()`, `notApplicable()` or `error()`. `fail` and `warn` take an optional `$fixPath` (a link to the admin page that fixes it); `pass`, `warn` and `fail` take a `$metrics` array of scalars shown in reports.

**`AttestationProviderInterface`**: `latestPerItem(): array`, the newest review per item id, each `['reviewed_on' => 'Y-m-d', 'next_due_on' => ?string, 'reviewer_name' => string, 'note' => ?string]`. `AttestationStore` implements it on top of the database; an edition only needs its own implementation to read attestations from somewhere else.

**`ManualItem`** is a value object, not an interface: `new ManualItem($id, $title, $category, $why, $controls, $intervalDays = 365)`. The edition supplies its list; `ClientChecklist::items()` supplies the 24-item customer list.

The edition also supplies a `DatabaseInterface` (see [adapters](../adapters.md)) and a `ClockInterface` (for `ComplianceAssessor` and `SubjectCompliance`).

## Key classes

### ComplianceAssessor, Assessment, Status

`new ComplianceAssessor(list<CheckInterface> $checks, list<ManualItem> $manualItems, AttestationProviderInterface $attestations, ClockInterface $clock, array $responsible = [])`, then `assess(): Assessment`.

Scoring: pass = 1, warn = 0.5, fail or error = 0, not applicable is left out. A manual item is 1 when `current`, 0.5 when `due_soon` (inside the last quarter of its interval, at most 30 days), 0 when `overdue` or `never`. The score per framework (and `all`) is the average as a percentage, or `null` when nothing is scored. `ComplianceAssessor::summarize()` is public and static.

<!-- skip: fragment of an edition-side example, needs a configured edition or live service -->
```php
use RivetCore\Compliance\{ComplianceAssessor, CheckInterface, CheckResult, Framework, ManualItem, AttestationProviderInterface};
use RivetCore\Support\SystemClock;

final class HttpsOnlyCheck implements CheckInterface
{
    public function id(): string { return 'https_only'; }
    public function title(): string { return 'HTTPS is enforced'; }
    public function category(): string { return 'Cryptography'; }
    public function why(): string { return 'Traffic must not travel in clear text.'; }
    public function controls(): array { return [Framework::PCI => ['4.2.1']]; }
    public function run(): CheckResult { return CheckResult::pass('All requests are redirected to HTTPS.'); }
}

$assessor = new ComplianceAssessor(
    [new HttpsOnlyCheck()],
    [new ManualItem('policy_review', 'Security policy reviewed', 'Governance', 'Sets direction.', [Framework::ISO27001 => ['A.5.1']])],
    $attestations,                                  // an AttestationProviderInterface, e.g. new AttestationStore($db)
    new SystemClock(),
    ['section:Governance' => 'Acme MSP'],           // optional: ResponsibilityStore::names()
);
$assessment = $assessor->assess();
echo $assessment->summaries['all']['score'];        // percentage, or null
```

`Assessment` is plain arrays (`generatedAt`, `automatic`, `manual`, `summaries`); `toArray()` and `fromArray()` round-trip it through JSON. Each automatic row carries `id, title, category, why, controls, status, status_label, summary, detail, fix_path, metrics, responsible`; each manual row carries `id, title, category, why, controls, interval_days, state, reviewed_on, next_due_on, reviewer_name, note, responsible`.

`Nist171Map::apply()` runs on every row: items without NIST 800-171 references get them from `Nist171Map::MAP` (Rev 2 requirement numbers such as `3.5.3`), so an edition's checks pick up the fifth framework with no code change. Ids not in the map are left alone.

### AttestationStore

Append-only record of manual reviews; the newest row per item decides the state. `new AttestationStore($database, $subjectId = 0)`.

<!-- skip: fragment of an edition-side example, needs a configured edition or live service -->
```php
use RivetCore\Compliance\AttestationStore;

$attest = new AttestationStore($db);
$id = $attest->record('access_review', $userId, 'A. Admin', '2026-09-30', null, 'Quarterly review done');
$latest = $attest->latestPerItem();                 // keyed by item id
$history = $attest->history('access_review', 20);   // newest first, limit 1..200
```

`record()` throws `\InvalidArgumentException` (messages safe to show) for an item id outside `[a-z0-9_]{1,64}`, an empty reviewer, a malformed date, a review date more than a day in the future, or a next-due date before the review. Notes are trimmed to `AttestationStore::NOTE_MAX` (2000) characters, reviewer names to 200.

### SnapshotStore

Saved assessments, so posture over time can be shown. Snapshots are evidence and are never pruned by retention.

<!-- skip: fragment of an edition-side example, needs a configured edition or live service -->
```php
use RivetCore\Compliance\SnapshotStore;

$snaps = new SnapshotStore($db);
$id = $snaps->save($assessment, $userId, 'scheduled', '1.2.3');   // trigger: 'manual' (default) or 'scheduled'
$list = $snaps->list(24);                           // newest first, summaries decoded, no full results
$one = $snaps->get($id);                            // ['assessment' => Assessment, 'taken_at' => ..., ...] or null
```

An unknown trigger is stored as `manual`; `get()` returns `null` for a missing id, another subject's id, or unreadable JSON.

### ReportRenderer

`rows(Assessment, ?string $framework)`, `csv(...)` and `html(Assessment, string $orgName, ?string $framework, ?string $appVersion)`. The optional framework filters to items tagged to it. A "Responsible" column is added only when some item has a responsible party; unassigned rows then read "Internal". The HTML is self-contained, escapes every value, is marked `noindex`, and includes `ReportRenderer::DISCLAIMER`. CSV starts with a UTF-8 BOM and neutralizes spreadsheet formulas by prefixing a quote to cells beginning with `= + - @` or a tab (`csvCell()`).

<!-- skip: fragment of an edition-side example, needs a configured edition or live service -->
```php
$renderer = new \RivetCore\Compliance\ReportRenderer();
$csv = $renderer->csv($assessment, \RivetCore\Compliance\Framework::PCI);
$html = $renderer->html($assessment, 'Example Org', null, '1.2.3');
```

### SharedReport, SubjectCompliance

`SharedReport` is the one report an administrator publishes to portal users: `publish($snapshotId, $note, $userId)` (throws `\InvalidArgumentException` if the snapshot does not exist), `unpublish()`, `isPublished()`, `current()`. `SharedReport::view(Assessment)` is public and pure: it returns only framework scores, manual item titles/state/last-review date, and automatic check titles/results with framework tags. Counts, details, account names, reviewer names, notes and fix links are never included. Notes are limited to `SharedReport::NOTE_MAX` (1000).

`SubjectCompliance($database, $clock)` is the same for a subject other than the installation (an MSP customer), with no automatic checks (Core cannot see a customer's systems):

<!-- skip: fragment of an edition-side example, needs a configured edition or live service -->
```php
use RivetCore\Compliance\{SubjectCompliance, Framework};

$subjects = new SubjectCompliance($db, $clock);
$subjects->setFrameworks(15, [Framework::PCI, Framework::ISO27001]);   // unknown values dropped, stored in canonical order
$subjects->record(15, 'access_review', $userId, 'A. Admin', '2026-09-30', null, null);
$assessment = $subjects->assess(15);                // only checklist items tied to the chosen frameworks
$snapshotId = $subjects->snapshot(15, $userId);
$subjects->share(15, $snapshotId, 'Q3 review', $userId);
$view = $subjects->shared(15);                      // reduced view or null
$rows = $subjects->overview();                      // one row per subject that has frameworks
```

`record()` refuses an item that is not in `ClientChecklist::ids()`. `ClientChecklist::forFrameworks($frameworks)` returns only the items that help evidence a chosen framework, with their references trimmed to those frameworks.

### ResponsibilityStore

Assign a section (`ResponsibilityStore::sectionKey($category)`) or one item (`itemKey($id)`) to a party, for an organization that outsources part of compliance. An item assignment overrides its section; no assignment means the organization's own staff.

<!-- skip: fragment of an edition-side example, needs a configured edition or live service -->
```php
use RivetCore\Compliance\ResponsibilityStore;

$resp = new ResponsibilityStore($db);
$resp->assign(ResponsibilityStore::sectionKey('Resilience'), 7, 'Acme MSP', $userId);
$names = $resp->names();                            // pass to ComplianceAssessor
ResponsibilityStore::resolve($names, 'backups', 'Resilience');   // 'Acme MSP'
```

The party name is stored with the assignment so old reports stay readable if the party is renamed or removed. `assign()` and `clear()` throw `\InvalidArgumentException` for a key that is not `section:` or `item:` plus 1 to 100 printable characters, or an empty name.

### RetentionPolicy

Minimum-retention presets, keyed like `Framework`: `none`, `iso27001`, `soc2`, `pci` (365 days each), `hipaa` (2190), `nist171` (365). A preset is a floor, not a promise of compliance.

<!-- skip: fragment of an edition-side example, needs a configured edition or live service -->
```php
use RivetCore\Compliance\RetentionPolicy;

RetentionPolicy::effectiveDays('hipaa', 90);        // 2190: raised to the floor
RetentionPolicy::effectiveDays('pci', 0);           // 0: keep forever is always allowed
RetentionPolicy::isBelowFloor('pci', 90);           // true: tell the admin why it was raised
RetentionPolicy::floorDaysFor('soc2', RetentionPolicy::KIND_JOBS);   // 30: deliveries and jobs use a shorter floor
```

`floorDaysFor()` and `effectiveDaysFor()` take a kind: `KIND_AUDIT` uses the preset floor; `KIND_DELIVERIES` and `KIND_JOBS` use 7 days with no preset and `min(30, floor)` under any preset. An unknown preset means no minimum. `Retention\RetentionService` accepts a profile and applies these floors inside `plan()` and `prune()`.

### Shared checks

`Check\AuditTrailRecordingCheck($database, bool $recordingEnabled, string $settingsPath)` (id `audit_trail_recording`): fails when recording is off, warns when `audit_events` has no row in the last 30 days, otherwise passes. `Check\RetentionMeetsPresetCheck($profile, $logDays, $auditDays, $settingsPath)` (id `log_retention`): warns when no preset is chosen, fails when either horizon is below the floor.

## Customizable frameworks (1.0.0-rc.44; migration 0046; opt-in)

A framework is data: a list of requirements an administrator can create, copy from a built-in, edit (every change is a new version), export and import. Core checks each requirement with evidence, enforces the retention ones in the RMM settings, and reports through the assessor, snapshots and reports above. Nothing changes until an edition selects a profile. Design, data model, JSON schema and decisions: [docs/design/compliance-frameworks.md](../design/compliance-frameworks.md). Edition prompts: [docs/design/compliance-edition-prompts.md](../design/compliance-edition-prompts.md).

### Profiles: `Compliance\Profile`

| Class | What it is |
|---|---|
| `DataClass` | ids of the data classes: `audit_events`, `rmm_logs`, `change_records`, `check_history`, `software_history`, `patch_history`, `deployment_history`, `metrics_hourly`, `metrics_daily`, `backups` |
| `RequirementType` | `retention_min` (days, per data class), `backup_rpo_max` (hours), `backup_covers_all_stores`, `backup_offsite`, `backup_encrypted` (yes/no), `restore_test_max_age` (days), `tamper_evidence` (yes/no, per class; reported "not available" until phase 3), `log_presets_baseline` (yes/no; the Windows security event-log presets of the compliance baseline are collected; judged by `security_log_presets`, with the audit-policy caveat; not in the built-ins, see `BuiltInProfiles::suggestedExtras()`) |
| `Requirement`, `FrameworkProfile` | immutable, always valid; the constructor refuses what the type does not allow |
| `BuiltInProfiles` | the five built-ins, derived on every call from `Framework::LABELS` and `RetentionPolicy::PROFILES` (one requirement: `retention_min` on `audit_events`); read-only |
| `FrameworkProfileStore` | custom profiles in the database: `list`, `get($key, $version)`, `create`, `copy`, `update` (new version only when the content changed; `expectedVersion` refuses a stale edit), `revertTo`, `archive`, `unarchive`, `delete` (only a never-selected profile), `history`, `diff`, `select`/`selectedProfile`/`selectedKey`/`selectionLog`, `retentionFloors`, `export`, `import` |
| `ProfileCodec` | the strict JSON codec behind export/import (unknown keys, types, bounds, sizes, secrets) |
| `RetentionFloors` | the selected profile's retention minimums for the RMM settings |

<!-- skip: fragment of an edition-side example, needs a configured edition or live service -->
```php
use RivetCore\Compliance\Profile\{FrameworkProfileStore, Requirement, RequirementType};

$profiles = new FrameworkProfileStore($db, $clock, fn (string $action, array $details) => $audit->log(/* ... */));
$profiles->copy('hipaa', 'hipaa_plus', 'HIPAA plus backups', $userId, $userName);
$profiles->update('hipaa_plus', 'HIPAA plus backups', '', [
    new Requirement(RequirementType::RETENTION_MIN, 'audit_events', 2190),
    new Requirement(RequirementType::BACKUP_ENCRYPTED, null, 1),
    new Requirement(RequirementType::RESTORE_TEST_MAX_AGE, null, 365),
], $userId, $userName, 'Added backup requirements');
$profiles->select('hipaa_plus', $userId, $userName);       // null clears the selection
$json = $profiles->export('hipaa_plus');                    // share it
$profiles->import($json, $userId, $userName, 'hipaa_plus_copy');   // throws ProfileValidationException with every problem found
```

Every writing method needs a user name (attribution) and throws `ProfileValidationException` (an `\InvalidArgumentException`; `errors()` lists all messages, safe to show) for a built-in, an unknown profile, an existing key, a stale edit, a selected profile that cannot be archived or deleted, or invalid content. **Core has no permissions: the edition must gate every call behind its highest administrative permission.**

### Checks and facts

`Check\ProfileChecks` builds the checks the selected profile asks for (none when no profile is selected). Add them to the assessor and pass the store as the last argument so snapshots record the profile name and version:

<!-- skip: fragment of an edition-side example, needs a configured edition or live service -->
```php
use RivetCore\Compliance\Check\ProfileChecks;

$checks = (new ProfileChecks($profiles, $clock, $backupFacts, $restoreTests, fn () => $module->settings()->dataStore(),
    $module->dataStores(), $module->settings(), $module->remoteSettings(), $module->changeSettings(), fn () => $auditDays, '/admin/compliance'))->checks();
$assessor = new ComplianceAssessor([...$editionChecks, ...$checks], $manualItems, $attestations, $clock, $responsible, $profiles);
```

| Check id | Requirement | Fails when |
|---|---|---|
| `backup_coverage` | `backup_covers_all_stores` | the newest successful backup lacks the control database, or the PostgreSQL RMM store while `data_store` is `postgres` |
| `backup_recency` | `backup_rpo_max` | the newest successful backup is older than the limit (late by less than 10 %, at least an hour, is a warning) |
| `backup_encrypted` | `backup_encrypted` | the newest backup is not encrypted (unknown is a warning) |
| `backup_offsite` | `backup_offsite` | no off-site copy arrived, or it is older than the RPO |
| `backup_retention` | `retention_min` on `backups` | backup sets are kept fewer days |
| `restore_test_recency` | `restore_test_max_age` | no test, a failed newest test, or one too old; warns for a test without matching row counts or without the PostgreSQL store |
| `data_store_retention` | `retention_min` on an RMM class | an RMM history of the ACTIVE store is kept fewer days; warns for a nearly full row cap, unreadable store, or minimums not enforced by the settings |
| `audit_retention` | `retention_min` on `audit_events` | audit events are kept fewer days |
| `integrity_chain` | `tamper_evidence` | always, with "Not available: not implemented in this release" (phase 3) |

Results carry evidence lines (`Met:`, `Not met:`, `Evidence:`, `Caveat:`) in `detail`. With no profile, or a profile that does not ask for a check, the check is "not applicable" and left out of the score.

**`Backup\BackupFactsProviderInterface`** (`@api`, the edition implements it): `backupFacts(): BackupFacts` reports what the newest successful backup did: `lastSuccessAt`, `includedStores` (`BackupStore::CONTROL_DB`, `RMM_POSTGRES`), `destinations` (`local`, `offsite`), `lastOffsiteSuccessAt`, `encrypted`, `encryptionMethod`, `retentionDays`, and optionally `lastRestoreTest`. Read it from the backup's own record (a log row, a manifest, the last exit status), never from configuration; never throw (return `BackupFacts::unavailable()`); never include a secret. `Backup\UnavailableBackupFactsProvider` is the honest default. Run `Testing\BackupFactsProviderConformanceTestCase` in the edition's tests.

### Restore tests

<!-- skip: fragment of an edition-side example, needs a configured edition or live service -->
```php
use RivetCore\Compliance\Backup\{BackupStore, RestoreTestStore, RowCounts};

$tests = new RestoreTestStore($db, $clock);
$before = RowCounts::control($db, ['audit_events', 'compliance_snapshots']);          // on the source, around the backup
// ... restore into a scratch copy, then count again there:
$after = RowCounts::control($scratch, ['audit_events', 'compliance_snapshots']);
$verify = RestoreTestStore::verifySummary(file_get_contents('rmm-store-verify.json')); // output of scripts/rmm-store-verify.php --json
$tests->record(new \DateTimeImmutable('now'), true, [BackupStore::CONTROL_DB, BackupStore::RMM_POSTGRES], 'postgres', $backupTakenAt,
    [...RestoreTestStore::pairCounts('control_db', $before, $after), ...RestoreTestStore::pairCounts('rmm_postgres', $pgBefore, $pgAfter)], $verify, 420, 'Restored on a scratch VM', $userId, $userName);
```

`record()` refuses a passed test whose row counts differ, a count for a store that was not tested, a future date, an invalid table name (counts only cover tables Core knows) and a note with a secret. Records are append-only.

### Retention enforcement

Give the module the option `retention_floors` (`RetentionFloors::fromStore($profiles)`). Then `RmmSettings::update()`, `RemoteSettings::update()` and `ChangeSettings::update()` refuse a retention below the selected framework's minimum with a message that names the framework and the requirement, `limits()` never answers less than the minimum, and the housekeeping (including the metric tiers, `RmmSettings::effectiveExplicitLimits()`) never deletes younger rows. For the audit trail call `RetentionFloors::effectiveDays('audit_events', $days)` before `RetentionService::prune()`. Without the option nothing changes. A minimum above a setting's own maximum applies over it (design note 4.4).

## Configuration

Core has no configuration keys for this module. The edition decides which frameworks to offer, which checks and manual items exist, how often snapshots are taken (`trigger` `scheduled` is for cron), and where the "fix" links point. `ManualItem::$intervalDays` is per item. The retention preset is an edition setting passed to `RetentionPolicy`/`RetentionService`.

## How it fails

- A check that throws is caught per check and recorded as `error` ("This check could not run."). The exception message is deliberately not copied into the report. A failing check scores 0, so it lowers the score instead of hiding.
- An item with no recorded review is `never` and scores 0; nothing is ever reported as passing for lack of data.
- Stores validate input and throw `\InvalidArgumentException` with a message safe to show; database errors propagate as `DatabaseException`. Edition code decides whether to wrap them.
- `SnapshotStore::get()` and `SharedReport::current()` return `null` (not an exception) for a missing or unreadable snapshot.
- Nothing is logged by this module.

## Security notes

- Checks must be read-only; Core cannot enforce that, so review edition checks accordingly.
- `SharedReport::view()` and `SubjectCompliance::shared()` are the only data a portal user should see; do not hand portal pages an `Assessment`.
- HTML reports escape all values; CSV neutralizes formula injection. Pass the output through your normal authorization, since a full report lists accounts and settings details.
- Snapshots store full results JSON, including check details; protect and retain them like audit evidence.
- Reviewer name is free text typed by the person recording the review; `reviewed_by` is the authenticated user id supplied by the edition.

## Used by

RivetIT (`/var/www/mw-itflow.foleyit.com`):

- `src/Compliance/ComplianceService.php` wires `ComplianceAssessor`, `AttestationStore`, `SnapshotStore`, `SharedReport` and `ResponsibilityStore` to the installation; `src/Compliance/ComplianceCatalog.php` supplies the checks and manual items (using `AuditTrailRecordingCheck`, `RetentionMeetsPresetCheck` and `CallbackCheck`, an edition class).
- `admin/compliance_status.php`, `admin/compliance_report.php` (`ReportRenderer`), `client/compliance.php` (shared view), `admin/settings_compliance.php` and `admin/post/settings_compliance.php`, `admin/post/settings_security.php` (`RetentionPolicy`), `cron/cron.php` (`RetentionPolicy::effectiveDays`), and `admin/database_updates.php` (runs migrations 0008 to 0011).
- No `SubjectCompliance` or `ClientChecklist` use.

RivetMSP (`/home/sysadmin/rivetmsp-beta`):

- `src/Compliance/ComplianceService.php` wires the same stores plus `SubjectCompliance`; `ComplianceCatalog.php` and `CallbackCheck.php` as above.
- `admin/compliance_status.php`, `admin/compliance_report.php`, `client/compliance.php`, retention settings and cron as in RivetIT, plus the per-customer pages `agent/compliance_clients.php`, `agent/client_compliance.php` (`ClientChecklist`, `Framework`), `agent/client_compliance_report.php` (`ReportRenderer`) and `agent/post/client_compliance.php`.
- `ResponsibilityStore` is not referenced in RivetMSP.

## Links

- [CHANGELOG](../../CHANGELOG.md): 0.8.0 (`RetentionPolicy`), 0.9.0 (engine, migration 0008), 0.10.0 (`SharedReport`), 0.11.0 (`SubjectCompliance`, `ClientChecklist`), 0.12.0 (NIST 800-171, `Nist171Map`), 0.13.0 (`nist171` retention preset), 0.14.0 (`ResponsibilityStore`), 0.18.1 (`RetentionService` floors), 1.0.0-rc.44 (customizable frameworks, migration 0046).
- [Writing an edition adapter](../adapters.md), [modules overview](README.md), [ROADMAP](../../ROADMAP.md).
- Tests: `tests/Unit/Compliance/` (profiles, codec, built-in golden, backup checks), `tests/Integration/Compliance/` (store, restore tests, retention enforcement, MariaDB retention check, assessor/snapshot/report), `tests/Integration/Rmm/Postgres/DataStoreRetentionPostgresTest.php`, `tests/Conformance/BackupFactsKitTest.php`, `tests/Unit/ComplianceEngineTest.php`, `tests/Unit/Nist171Test.php`, `tests/Unit/RetentionPolicyTest.php`, `tests/Integration/ComplianceStoreTest.php`, `tests/Integration/SubjectComplianceTest.php`.
