# Changelog

All notable changes to RivetCore. Semantic versioning.

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
