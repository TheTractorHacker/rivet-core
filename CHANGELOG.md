# Changelog

All notable changes to RivetCore. Semantic versioning.

## 0.14.0

### Added
- `Compliance\ResponsibilityStore`: assign a section of compliance (or a single item) to a managed service provider, for an organization that outsources part of it. No assignment means its own staff. Migration `0011_compliance_responsibilities`.
- `ComplianceAssessor` takes the assignments and puts `responsible` on every automatic and manual row; `ReportRenderer` adds a "Responsible" column only when something is assigned; `SharedReport::view` includes it.

## 0.13.0

### Added
- Retention preset `nist171` (NIST SP 800-171 / CMMC): a 365-day minimum. 800-171 requires audit logs to be retained but sets no number, so this is the common organization-defined value, stated as such in the preset's note. Every compliance framework now has a retention preset, and a test enforces that the two lists stay in step.

## 0.12.0

### Added
- NIST SP 800-171 Rev 2 / CMMC Level 2 as a fifth compliance framework (`Framework::NIST171`). `Nist171Map` supplies requirement numbers for every existing check and checklist item, so both editions pick them up with no code change; the customer checklist gains CUI scope, SSP/POA&M and CMMC self-assessment items. No schema change. NIST 800-171 has no fixed retention period, so there is no retention preset for it.

## 0.11.0

### Added
- `Compliance\SubjectCompliance` and `ClientChecklist`: compliance for a subject other than the installation itself (an MSP's customer): chosen frameworks, a 20-item manual checklist with sign-off, snapshots, one shared snapshot (reduced view), and an overview across subjects. Migration `0010_compliance_subjects` adds `subject_id` (0 = the installation) to `compliance_attestations` and `compliance_snapshots` and the `compliance_subjects` table.
- `AttestationStore` and `SnapshotStore` take an optional `$subjectId` (default 0, unchanged behaviour).

## 0.10.0

### Added
- `Compliance\SharedReport`: an administrator publishes one saved snapshot for portal users. Only a reduced view is exposed (framework scores, manual checklist titles/state/last-review date, automatic check titles and results); details, counts, account names, reviewer names and notes are never included. Migration `0009_compliance_shared_report`.

## 0.9.0

### Added
- Compliance status engine in `RivetCore\Compliance`: `CheckInterface`/`CheckResult`/`Status`, `ManualItem`, `ComplianceAssessor` (isolates failing checks, scores ISO/IEC 27001, SOC 2, PCI DSS and HIPAA), `Assessment`.
- `AttestationStore` (append-only manual reviews with validation), `SnapshotStore` (saved assessments), `ReportRenderer` (CSV with formula neutralisation and escaped, self-contained printable HTML).
- Shared checks `AuditTrailRecordingCheck` and `RetentionMeetsPresetCheck`.
- Migration `0008_compliance`: tables `compliance_attestations` and `compliance_snapshots`.
- The status view is a self-assessment aid, not a certification; control references are indicative.

## 0.8.0

### Added
- `Compliance\RetentionPolicy`: minimum-retention presets (ISO/IEC 27001, SOC 2, PCI DSS, HIPAA) and `effectiveDays()`, which raises a stored retention to the preset's floor and treats 0 (and negative values) as "keep forever". A preset is a floor, not a compliance claim.
- `RetentionService::prune($days, $auditDays = null)`: the audit trail can have its own horizon, independent of the delivery log and finished jobs. A horizon below 1 skips that table. Existing one-argument calls behave as before.

## 0.7.1

### Fixed
- `Jobs\JobQueue::markFailed()` crashed with a fatal error on the **fifth** failed attempt (the default `max_attempts`) instead of marking the job `dead_letter`: PHP cannot pass a class constant to `end()` by reference. Attempts past the backoff table now reuse the longest wait.
- `KB\PdfConverter` rejected every PDF on PHP 8.2 ("not a readable PDF"): before PHP 8.3 the exit code is only reported by the first `proc_get_status()` call that sees the process finished, and `proc_close()` then returns -1. The converter now keeps that exit code. PHP 8.2 is Core's declared minimum, and the full suite now passes on 8.2, 8.4 and 8.5.

## 0.7.0

### Added
- `Retention\RetentionService`: prunes `audit_events`, `webhook_deliveries` and finished `integration_jobs` older than a horizon the edition supplies (its log-retention setting). A horizon below 1 keeps everything; pending and running jobs are never deleted.

### Changed
- CI runs on PHP 8.3 and 8.4.

## 0.6.0

### Added
- Webhooks: `WebhookDispatcher` (synchronous delivery, HMAC-SHA256 body signing, configurable header prefixes, per-attempt log, never throws) with the edition supplying subscribers through `WebhookSubscriptionsInterface`; migration `0005_webhook_deliveries`.
- Automation: `AutomationRuleEvaluator` (evaluation-only rule matching) and migration `0006_automation_rules`.
- Workflow: `WorkflowService` (template snapshot, complete/skip/reopen/cancel, derived run status) and migration `0007_workflow_tables`.
- KB: `DocxConverter` and `PdfConverter` with their exceptions, moved unchanged.
- Knowledge: `CredentialReferenceRenderer` with an edition-supplied reveal control.

### Changed
- `WorkflowService::startRun` is now atomic (a failure mid-way leaves no half-created run).

## 0.5.0

### Added
- ITSM: `ProblemService` and `ChangeService` (status machines, validation, resolve timestamps) on the Core-owned `problems` and `changes` tables (migration `0004_problems_and_changes`). The edition attaches tickets to problems through `TicketProblemLinkInterface`; Core never touches the tickets table. Status and transition tables are public constants so UIs can render from them instead of mirroring them by hand.

## 0.4.1

### Fixed
- Accept Guzzle 8 (`^7.0 || ^8.0`); both editions already run 8.2.0. Tests pass on 8.2.0.

## 0.4.0

### Added
- MCP: `McpConfig::resolve` (settings + environment, kill switch), `TokenClaimsGuard`, `ToolPipeline` (rate limit, permission, scoped read, audit row, standard envelope), `UnlinkedIdentityStore` + `IdentityLinker` behind an edition-supplied `AgentDirectoryInterface`, `McpDiagnostics`, `RedisMetadataCache` (PSR-16), migration `0003_mcp_unlinked_identities`.
- Dependencies: guzzlehttp/guzzle ^7, psr/simple-cache ^3. Core does not require mcp/sdk: the editions own the SDK glue and the tool bodies.

## 0.3.0

### Added
- Cron: `JobRunner` (allowlisted background job runner for admin "Run now"; non-final so an edition can pin its default state directory) and `JobCatalog` (an edition supplies its job entries).
- Jobs: `JobQueue` on the Core-owned `integration_jobs` table, with migration `0002_integration_jobs`.

### Fixed
- `JobQueue::claim()` is now safe for concurrent workers (conditional UPDATE per candidate) and returns the claimed state (status `running`, incremented `attempts`). The old worker passed a pre-claim snapshot to `markFailed()`, so the attempt count and backoff step were off by one.
- A job that exits without a trailing newline no longer hides its `[exit N]` marker.

## 0.2.0

### Added
- Redis module: `LockManager`/`Lock` (fail-open mutex), `RateLimiter`, `CronGuard`, `RedisAdmin` (validate/test/stats/clear/memory), `RedisClientProviderInterface`.
- `Health\ReadinessChecker` (database + schema, Redis reported but never fatal).
- Dependency: predis/predis ^3.5.

## 0.1.0

### Added
- Foundation: `DatabaseInterface`, `ExecutionResult`, `DatabaseException`, `ClockInterface`, `SettingsInterface`, `RequestContextInterface`.
- Migration runner with its own `rivet_core_migrations` state, independent of edition database versions.
- `Audit\AuditService` and migration `0001_audit_events`.
- `Testing\DatabaseContractTestCase` for adapter tests; PHPUnit suite; CI.
- ADR-001 database strategy.
