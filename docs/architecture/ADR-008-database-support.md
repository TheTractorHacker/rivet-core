# ADR-008: Database support promise

Status: accepted (2026-10-06). Decision #48 in the ROADMAP. Builds on ADR-001.

## Context

ADR-001 keeps Core engine-neutral at the interface (`DatabaseInterface`) but both editions run on MySQL or MariaDB, and Core's
migrations and several queries use MySQL-family SQL (`ENGINE=InnoDB`, `GET_LOCK`, `INSERT ... ON DUPLICATE KEY`, `GREATEST`, `NOW()`,
`SUBSTRING_INDEX`, `LIMIT` with offsets, JSON stored as text). The CI matrix runs the full suite against MariaDB 10.11 and 11 and MySQL 8.0 and
8.4. Issue #55 holds the investigation of a PostgreSQL adapter.

## Decision

1. **Tested and supported in 1.x:** MariaDB 10.11 and 11, MySQL 8.0 and 8.4. A change is not merged unless the whole matrix is green;
   a regression on any of them is a bug.
2. **Best effort:** other MariaDB 10.x/11.x and MySQL 8.x versions. Core uses no feature newer than the oldest tested version, so
   they are expected to work, but a failure there is fixed only when it is reproducible on a tested version or the fix is trivial.
   MySQL 5.7 and MariaDB older than 10.11 are not supported (the migrations rely on `utf8mb4` defaults and `IF NOT EXISTS`
   forms those servers handle differently).
3. **Dropping a version:** only after the vendor ends support, announced one minor ahead (ADR-004). MySQL 8.0 reached upstream end of life in
   2026 and stays in the matrix while an edition's production runs it; its removal from the matrix is a candidate for a 1.x minor.
4. **PostgreSQL is out of scope for 1.x.** Nothing in the editions can use it. See issue #55 for the investigation; any adapter
   would be a new `DatabaseInterface` implementation plus a portable rewrite of the migrations and of the handful of
   vendor-specific statements, tracked as a post-1.0 backlog item. Core's own SQL stays in the services behind the interface
   (ADR-001), so this is additive later.
5. **SQLite and others** are not supported, including for tests: the contract tests and integration tests run on a real MySQL-family server.
6. Edition adapters must pass the `DatabaseContractTestCase` conformance kit (ADR-009); that is how "supported" is enforced for an
   adapter.

## Consequences

- The matrix stays four databases wide; CI cost is accepted.
- New SQL is reviewed for MariaDB/MySQL divergence (for example `GREATEST`, JSON functions, generated columns) and tested on both families.
- Hosting-provider databases that are MySQL-compatible but not listed (Aurora, PlanetScale, Percona) are best effort.

## Reversal cost

Narrowing the promise (dropping a version) is cheap and follows ADR-004. Widening it to PostgreSQL is expensive: an adapter, a portable
dialect for 12 migrations and the raw SQL in every service, a new CI leg and a second conformance run in both editions. Starting the
investigation costs only time because Core never leaks the engine through its public API.
