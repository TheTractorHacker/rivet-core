# rivet-core

Shared, edition-neutral PHP services used by both **RivetIT** (internal IT) and **RivetMSP** (managed service providers).
Free and open source under GPL-3.0. The code is free; hosting and setup are the paid service.

## Status

Audit, Redis, Cron, Jobs, MCP, ITSM, Webhooks, Automation, Workflow, KB converters, Knowledge. See [CHANGELOG.md](CHANGELOG.md), [ADR-001](docs/architecture/ADR-001-database-strategy.md), the [quickstart](docs/quickstart.md), [writing an edition adapter](docs/adapters.md), the [module reference](docs/modules/README.md), the [public API](docs/PUBLIC-API.md), the [upgrade guide](docs/UPGRADING.md), [Redis configuration](docs/REDIS.md), [performance baselines](docs/PERFORMANCE.md), the [second security review](docs/SECURITY-REVIEW-2.md), the [installer test plan](docs/testing/installers.md) and the open issues.

## Rules

1. A file moves here only if it has no client or department assumptions, or if those sit behind an interface.
2. Core owns its own migrations, tracked separately from either edition's database version.
3. Each edition pins a semver tag and never tracks `main`.
4. Every adopted module is off by default and enabled per instance.
5. Namespace is `RivetCore\`; editions keep thin `ITFlow\` shims for existing callers.

## Modules

| Module | Namespace | Core-owned tables | Edition supplies |
|---|---|---|---|
| Audit | `RivetCore\Audit` | `audit_events` | request context |
| Redis, health | `RivetCore\Redis`, `Health` | - | Redis client provider |
| Cron | `RivetCore\Cron` | - | job list |
| Jobs | `RivetCore\Jobs` | `integration_jobs` | - |
| MCP | `RivetCore\Mcp` | `mcp_unlinked_identities` | agent directory, SDK glue, tool queries |
| ITSM | `RivetCore\ITSM` | `problems`, `changes` | ticket link |
| Webhooks | `RivetCore\Webhooks` | `webhook_deliveries` | subscriptions |
| Automation | `RivetCore\Automation` | `automation_rules` | rule execution |
| Workflow | `RivetCore\Workflow` | `workflow_*` (4) | subject records |
| KB, Knowledge | `RivetCore\KB`, `Knowledge` | - | media, import, reveal control |

What stays in the editions, and why: [ADR-002](docs/architecture/ADR-002-modules-that-stay-in-editions.md).

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
