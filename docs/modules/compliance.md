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

```php
$renderer = new \RivetCore\Compliance\ReportRenderer();
$csv = $renderer->csv($assessment, \RivetCore\Compliance\Framework::PCI);
$html = $renderer->html($assessment, 'Example Org', null, '1.2.3');
```

### SharedReport, SubjectCompliance

`SharedReport` is the one report an administrator publishes to portal users: `publish($snapshotId, $note, $userId)` (throws `\InvalidArgumentException` if the snapshot does not exist), `unpublish()`, `isPublished()`, `current()`. `SharedReport::view(Assessment)` is public and pure: it returns only framework scores, manual item titles/state/last-review date, and automatic check titles/results with framework tags. Counts, details, account names, reviewer names, notes and fix links are never included. Notes are limited to `SharedReport::NOTE_MAX` (1000).

`SubjectCompliance($database, $clock)` is the same for a subject other than the installation (an MSP customer), with no automatic checks (Core cannot see a customer's systems):

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

- [CHANGELOG](../../CHANGELOG.md): 0.8.0 (`RetentionPolicy`), 0.9.0 (engine, migration 0008), 0.10.0 (`SharedReport`), 0.11.0 (`SubjectCompliance`, `ClientChecklist`), 0.12.0 (NIST 800-171, `Nist171Map`), 0.13.0 (`nist171` retention preset), 0.14.0 (`ResponsibilityStore`), 0.18.1 (`RetentionService` floors).
- [Writing an edition adapter](../adapters.md), [modules overview](README.md), [ROADMAP](../../ROADMAP.md).
- Tests: `tests/Unit/ComplianceEngineTest.php`, `tests/Unit/Nist171Test.php`, `tests/Unit/RetentionPolicyTest.php`, `tests/Integration/ComplianceStoreTest.php`, `tests/Integration/SubjectComplianceTest.php`.
