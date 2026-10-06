# Compliance status

`RivetCore\Compliance`: a self-assessment aid, not a certification. Automatic checks (supplied by the edition) and a manual checklist
are scored against ISO/IEC 27001, SOC 2, PCI DSS, HIPAA and NIST SP 800-171 / CMMC Level 2, saved as snapshots, exported
(CSV, printable HTML) and optionally shared, reduced, with portal users. Retention presets live in `RetentionPolicy`, see
[retention.md](retention.md).

## What it owns

- `compliance_attestations` (migration 0008, `subject_id` from 0010): append-only manual reviews: `item_id`, `reviewed_on`, `next_due_on`, `reviewer_name`, `reviewed_by`, `note`.
- `compliance_snapshots` (0008, 0010): saved assessments: `taken_at`, `taken_by`, `trigger_type`, `app_version`, `summary_json`, `results_json`.
- `compliance_shared_report` (0009): the one snapshot shown to portal users.
- `compliance_subjects` (0010): per customer: chosen `frameworks`, shared snapshot and note.
- `compliance_responsibilities` (0011): who answers for a section or item (`section:<category>` / `item:<id>`).

`subject_id = 0` means the installation itself; a positive id is an MSP's customer.

## You supply

`CheckInterface` implementations (what can be proven automatically), `ManualItem`s (what a person attests), and the UI.
`Check\AuditTrailRecordingCheck` and `Check\RetentionMeetsPresetCheck` are shared checks Core ships.

## Flags

None. Frameworks are chosen per installation or per subject (`SubjectCompliance::setFrameworks`).

## Use it

<!-- run -->
```php
use RivetCore\Compliance\{AttestationStore, CheckInterface, CheckResult, ComplianceAssessor, Framework, ManualItem, ReportRenderer, SnapshotStore};

$check = new class implements CheckInterface {
    public function id(): string { return 'mfa'; }
    public function title(): string { return 'Multi-factor authentication is on'; }
    public function category(): string { return 'Access'; }
    public function why(): string { return 'Stolen passwords alone should not be enough.'; }
    public function controls(): array { return [Framework::SOC2 => ['CC6.1']]; }
    public function run(): CheckResult { return CheckResult::pass('Enabled for all agents'); }
};
$attest = new AttestationStore($db);
$attest->record('policy_review', 7, 'Ada', date('Y-m-d'), null, 'Reviewed');
$items = [new ManualItem('policy_review', 'Security policy reviewed', 'Governance', 'Policies age.', [Framework::SOC2 => ['CC1.1']], 365)];

$assessment = (new ComplianceAssessor([$check], $items, $attest, $clock))->assess();
$id = (new SnapshotStore($db))->save($assessment, 7);
echo 'snapshot ', $id, ', SOC 2 score ', $assessment->summaries[Framework::SOC2]['score'], '%, csv bytes ', strlen((new ReportRenderer())->csv($assessment, Framework::SOC2)), "\n";
```

## How it fails

- A check that throws is isolated: it reports `error` (counted as 0) with a generic message that never carries the exception text.
  A check is never silently reported as `pass`.
- Manual state is derived from the newest attestation: `current`, `due_soon`, `overdue`, `never`. Attestation input is validated (known item id, reviewer name, a review date not in the future, a next-due date not before it, note length).
- `ReportRenderer` escapes everything in HTML and neutralises spreadsheet formulas (`=`, `+`, `-`, `@`) in CSV.
- `SharedReport::view()` exposes only framework scores, manual item titles, state and last-review date, and each automatic check's
  title and result: never details, counts, account names, reviewer names or notes.
