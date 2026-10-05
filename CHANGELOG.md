# Changelog

All notable changes to RivetCore. Semantic versioning.

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
