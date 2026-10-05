# ADR-001: Database strategy

Status: accepted (2026-10-04)

## Decision

RivetIT and RivetMSP stay on MySQL/MariaDB. RivetCore does not depend on mysqli, PDO or any engine. It depends on
`RivetCore\Database\DatabaseInterface`, and each edition supplies an adapter that reuses its existing mysqli connection.

```
RivetCore -> DatabaseInterface -> edition adapter -> mysqli -> MySQL/MariaDB
```

## Reasons

1. Both production systems already use MySQL/mysqli.
2. The Core extraction is a large change; an engine migration at the same time multiplies the risk.
3. Nothing today justifies a different engine.
4. A small contract keeps future options open (a PDO or PostgreSQL adapter would not change Core).

## Rules that follow

- Core never references `mysqli`, `global $mysqli`, `$GLOBALS`, `$_SESSION` or `$_SERVER`, and never includes an edition's bootstrap.
- The contract exposes only `fetchOne`, `fetchAll`, `execute` and `transaction`, plus the Core-owned `ExecutionResult` and `DatabaseException`. Methods are added only when a module needs them.
- Parameters are positional `?` placeholders with plain PHP values; the adapter infers binding types.
- New SQL prefers portable forms. MySQL-specific SQL is isolated behind a repository, not scattered through services.
- Core owns `rivet_core_migrations` and its own tables only. Migrations are additive and idempotent. Edition tables (users, clients, departments, settings) stay with the edition and are reached through contracts.
- Contract tests (`RivetCore\Testing\DatabaseContractTestCase`) run against a scratch database for Core and for each edition adapter.

## Future

PostgreSQL may be evaluated separately if a concrete requirement appears (advanced query needs, JSONB-heavy workloads, large-scale analytics, measured performance, organizational standardization). Newness alone is not a reason.
