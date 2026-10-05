# rivet-core

Shared, edition-neutral PHP services used by both **RivetIT** (internal IT) and **RivetMSP** (managed service providers).
Free and open source under GPL-3.0. The code is free; hosting and setup are the paid service.

## Status

Foundation + Audit. See [CHANGELOG.md](CHANGELOG.md), [ADR-001](docs/architecture/ADR-001-database-strategy.md) and the open issues.

## Rules

1. A file moves here only if it has no client or department assumptions, or if those sit behind an interface.
2. Core owns its own migrations, tracked separately from either edition's database version.
3. Each edition pins a semver tag and never tracks `main`.
4. Every adopted module is off by default and enabled per instance.
5. Namespace is `RivetCore\`; editions keep thin `ITFlow\` shims for existing callers.

## Pilot extraction order

1. Audit, Redis (lock, rate limit, health), Cron/JobRunner, Jobs queue, MCP pipeline
2. ITSM (Problem, Change), KB, Knowledge
3. Stays in each edition: UI shell, billing, client/department model, Training, Odoo

## Using it from an edition

```json
{
  "repositories": [{ "type": "vcs", "url": "https://github.com/TheTractorHacker/rivet-core" }],
  "require": { "rivet/rivet-core": "^0.1" }
}
```

The edition writes a `DatabaseInterface` adapter over its existing connection, then calls
`MigrationRunner` from its updater. Pin a tag; never track `main`.

## Tests

`composer test`. Integration tests need a scratch MySQL/MariaDB: set `RIVETCORE_TEST_DB_NAME`,
`RIVETCORE_TEST_DB_USER`, `RIVETCORE_TEST_DB_PASS` (and `_HOST`). They skip when unset. Never use production data.
