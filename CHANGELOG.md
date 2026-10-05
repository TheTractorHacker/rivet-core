# Changelog

All notable changes to RivetCore. Semantic versioning.

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
