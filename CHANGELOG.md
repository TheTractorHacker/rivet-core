# Changelog

All notable changes to RivetCore. Semantic versioning.

## 0.17.2
Security hardening from the 2026-10 review (all low severity, backward compatible).
- **UrlPolicy:** also rejects 6to4 (2002::/16), Teredo (2001::/32), local-use NAT64 (64:ff9b:1::/48), 100::/64, documentation, benchmarking (198.18/15), IETF-protocol (192.0.0/24), 192.88.99/24 and multicast ranges.
- **Webhooks:** pinned requests are sent to the vetted host spelling (a trailing-dot host can no longer skip the DNS pin) and never use a proxy (`CURLOPT_PROXY` empty, `CURLOPT_NOPROXY` `*`). New `WebhookDispatcher::pinnedUrl()`.
- **Jobs:** `requeueStale()` dead-letters jobs that used all their attempts instead of looping them forever. `markCompleted()`/`markFailed()` only write while the job is `running` and take an optional claimed-attempt fence (the worker passes it); both now return bool. Handlers should be idempotent.
- **JobRunner:** default state directory is per user (`rivetcore-jobs-<uid>`); the directory must be a real directory owned by the current user and not group/world writable, otherwise `start()` refuses. The log is created exclusively after removing any symlink at that path.
- **AuditService:** every field is clamped to its column width; metadata that cannot be encoded is replaced by a marker instead of throwing; values under common secret keys (password, token, secret, authorization, api_key ...) are stored as `[redacted]`.
- **RetentionService:** optional second constructor argument (compliance profile) raises every horizon to the preset floor inside `prune()`/`plan()`.
- **CredentialReferenceRenderer:** the badge is only substituted in text; a token inside a tag or attribute is removed.
- CI: `permissions: contents: read`. `SECURITY.md` no longer lists a non-existent `migrations/` directory and names the UrlPolicy, signature V2 and Redis TLS surfaces.
- Known, not changed: the pending-identity table (migration 0003) uses a case-insensitive collation for OIDC issuer/subject; fixing it needs a schema migration and the matching edition column, so it is planned for a later release.

## 0.18.0
- **Webhooks to the local network only.** `Webhooks\UrlPolicy` takes an optional `allowedNetworks` list (CIDR). A private address is allowed only when it lies inside a listed network; loopback, link-local (including the cloud metadata address), multicast, broadcast and unspecified addresses are never allowed, even if listed. Every address a hostname resolves to must pass, and the vetted target is still pinned by the dispatcher. New `Webhooks\NetworkList` (`parse()` normalises and validates admin input: private ranges only, not wider than /8 (IPv4) or /48 (IPv6), at most 16 entries; `contains()`), and `Support\LocalNetworks::detect()` suggests the server's own private subnet(s) from its network interfaces (IPv4; container bridges and public addresses skipped). The old constructor signature is unchanged.

## 0.17.1
CI only: the backward-compatibility checker needs PHP 8.4+ and was a dev dependency, so `composer install` failed on PHP 8.2 and 8.3 (and the lowest-dependencies job). It is now installed inside the compatibility job only. No library change.

## 0.17.0
API freeze preparation (milestone v0.9.0) plus the pieces editions were missing.
- **Public API marked:** every type is tagged `@api` or `@internal` (migration classes and the PHPUnit-based contract test case are internal). `docs/api-surface.md` is generated from the tags (`scripts/api-surface.php`); `docs/api-freeze-review.md` lists what is settled and what is left before 1.0. CI gains an advisory backward-compatibility check against the last tag (`roave/backward-compatibility-check`).
- **Logging:** services accept a PSR-3 logger instead of calling `error_log()`; `Support\ErrorLogLogger` is the default and keeps the old behaviour. The MCP classes still accept the old closure. `PdfConverter::convert()` takes an optional logger. New dependency: `psr/log` ^3.
- **Authorization contract (ADR-003):** `Contracts\AccessPolicyInterface`, `Contracts\AccessDenied`, `Support\AllowAllPolicy`, `Support\DenyAllPolicy`. Opt-in; no existing behaviour changes.
- **Audit read side:** `Audit\AuditReader` (filters, pagination, grouped counts, chunked export) and `Audit\AuditPage`, so editions stop querying `audit_events` themselves.
- **Webhooks:** `Webhooks\UrlPolicy` (public addresses only, no userinfo, pinned connection against DNS rebinding) with an opt-in/required switch on `WebhookDispatcher`; every request now also carries `X-Rivet-Timestamp` and `X-Rivet-Signature-V2` (`t=<ts>,v1=<hmac of "<ts>.<body>">`); the legacy headers are byte-identical. Pass `$signedAt = time()` on each retry for a useful replay window.
- **Jobs:** per-type timeouts (cooperative, with a `JobContext` passed to handlers), a heartbeat so only really-dead jobs are reclaimed, `release()`, handler introspection (`has()`), clearer unknown-type dead-lettering, and proof that claiming is atomic. **Migration 0012** adds the nullable `integration_jobs.heartbeat_at`; before an edition applies it everything falls back to `started_at`.
- **Redis:** `RedisConnectionConfig` (host, port, db, password, ACL user, TLS with CA/client cert) and `RedisAdmin::client()/test()` taking it, with distinct auth / TLS / unreachable results that never echo the password. A password containing a line break or NUL is now rejected.
- **Retention:** separate horizons for webhook deliveries and finished jobs (7-day floor, 30 days under any framework preset), a dry-run `plan()` and batched deletes; `prune()` stays backward compatible.

## 0.16.0
Quality gates and hygiene (milestone v0.8.0); no new features.
- `MigrationRunner`: concurrent runs now take turns through a server-side lock (`GET_LOCK`) instead of racing; a second runner that waits longer than `$lockWaitSeconds` (default 60) throws a `RuntimeException`. New read-only `status()` lists every migration with its applied time. Run-twice and lock tests added.
- CI: PHP 8.2 to 8.5 on MariaDB 11, plus MariaDB 10.11 and MySQL 8.0 / 8.4 on PHP 8.4; a `--prefer-lowest` job; PHPStan and `composer audit`; a coverage job with an 85% gate outside the DOCX/PDF converters (`scripts/coverage-gate.php`); GitHub Releases are cut from tags with the changelog excerpt.
- Static analysis: PHPStan level 6 is required and clean (`phpstan.neon`; the two converters keep a documented baseline). Fixes with no behavior change: Redis `eval()` arguments, `CONFIG SET` via `executeRaw`, docblock types in Automation/Compliance, an unreachable statement in `DatabaseContractTestCase`. `declare(strict_types=1)` in the four converter files.
- Converter corpus: 34 new DOCX/PDF tests (zip bombs, traversal, XXE, billion laughs, script/HTML escaping, JS/Launch actions in PDFs, oversize files); no vulnerabilities found.
- Dependabot for Composer and GitHub Actions; `SECURITY.md`, `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md`, issue and pull request templates, `.gitattributes`.
- Docs: quickstart, "writing an edition adapter", module reference.

## 0.15.1

### Added
- `AuditService` takes an optional `afterLog` callback, called after each recorded event, so an edition can fan audit events out to webhooks and automation rules. It cannot fail or slow the audit write.

## 0.15.0

### Added
- `Jobs\JobWorker` (job-type handler registry, claim loop with time/size limits, retry with backoff, `PermanentJobFailure` for no-retry failures, unknown types dead-lettered) and `JobQueue::requeueStale`, `stats`, `recent`, `retry`, `purgeCompleted`.
- `Webhooks\WebhookDispatcher::deliverTo()`: one endpoint, one attempt, byte-identical body across retries (so signatures and receiver de-duplication hold); `WebhookSubscriptionLookupInterface` (optional companion of the subscriptions interface); results gain `ok`; the attempt number is logged.
- Event automation: `Automation\EventContext::flatten`, `AutomationRuleStore` (validated rules for create_ticket / send_webhook / notify_user), `AutomationExecutor` (runs one rule's action through edition handlers; `{placeholders}` from the event, never in URLs or ids).

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
