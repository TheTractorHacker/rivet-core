# rivet-core

Shared, edition-neutral PHP services used by both **RivetIT** (internal IT) and **RivetMSP** (managed service providers).
Free and open source under GPL-3.0. The code is free; hosting and setup are the paid service.

## Status

Current release: **0.21.0**. The roadmap to **1.0.0** is in [ROADMAP.md](ROADMAP.md); what is left, with the evidence for each gate item, is in
[docs/RELEASE_GATE.md](docs/RELEASE_GATE.md). 1.0.0 will be a promise of a stable public API, so before it the API can still change in a minor
(always listed in [CHANGELOG.md](CHANGELOG.md)).

Modules: Audit, Redis and health, Cron, Jobs, Webhooks, Automation, ITSM, Workflow, Compliance, Retention, MCP, KB converters, Knowledge, Ui, Migration.

## Documentation

| For | Read |
|---|---|
| Getting started | [Quickstart](docs/quickstart.md), [Writing an edition adapter](docs/adapters.md) |
| What each module does | [Module reference](docs/modules/README.md) (one page per module: tables, contracts, examples, failure behaviour) |
| Public API | [API surface](docs/api-surface.md), [API freeze review](docs/api-freeze-review.md) |
| Decisions | [ADR-001 database](docs/architecture/ADR-001-database-strategy.md), [ADR-002 what stays in editions](docs/architecture/ADR-002-modules-that-stay-in-editions.md), [ADR-003 authorization](docs/architecture/ADR-003-authorization-contract.md), [ADR-004 versioning and compatibility](docs/architecture/ADR-004-versioning-and-compatibility.md), [ADR-005 `ITFlow\` shims](docs/architecture/ADR-005-itflow-shims-stay-for-1x.md), [ADR-006 Packagist](docs/architecture/ADR-006-packagist.md), [ADR-007 webhook signatures](docs/architecture/ADR-007-webhook-signatures.md), [ADR-008 database support](docs/architecture/ADR-008-database-support.md), [ADR-009 test helpers](docs/architecture/ADR-009-test-helpers-in-package.md) |
| Upgrading | [UPGRADING.md](UPGRADING.md), [Edition checklist for every release](docs/EDITION_CHECKLIST.md) |
| Webhooks | [Signing, verification, retries, URL policy](docs/webhooks.md), [24 platform guides](docs/webhook-platforms.md) |
| Performance | [Baselines and budgets](docs/PERFORMANCE.md) (`php scripts/bench.php`) |
| Release | [1.0.0 release gate](docs/RELEASE_GATE.md), [ROADMAP.md](ROADMAP.md), [CHANGELOG.md](CHANGELOG.md) |
| Security | [SECURITY.md](SECURITY.md), [threat model](docs/security/threat-model.md), [2026-10 security review](docs/security/review-2026-10.md) (these two are being written alongside the 1.0 work) |
| Contributing | [CONTRIBUTING.md](CONTRIBUTING.md), [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md) |

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
| Compliance | `RivetCore\Compliance` | `compliance_*` (5) | checks, attestation provider |
| Retention | `RivetCore\Retention` | - | horizons (days) |
| KB, Knowledge | `RivetCore\KB`, `Knowledge` | - | media, import, reveal control |
| Ui | `RivetCore\Ui` | - | the picker and filter UI |
| Migration | `RivetCore\Migration` | `rivet_core_migrations` | an updater that runs it |

What stays in the editions, and why: [ADR-002](docs/architecture/ADR-002-modules-that-stay-in-editions.md).

## Using it from an edition

```json
{
  "repositories": [{ "type": "vcs", "url": "https://github.com/TheTractorHacker/rivet-core" }],
  "require": { "rivet/rivet-core": "^0.21" }
}
```

The edition writes a `DatabaseInterface` adapter over its existing connection, then calls
`MigrationRunner` from its updater. Pin a tag; never track `main`. In 0.x a caret accepts only the same minor, so bump it each release; from 1.0 use `^1.0` ([ADR-004](docs/architecture/ADR-004-versioning-and-compatibility.md)). Core is not on Packagist yet ([ADR-006](docs/architecture/ADR-006-packagist.md)), hence the repository entry.

## Tests

`composer test`. Integration tests need a scratch MySQL/MariaDB: set `RIVETCORE_TEST_DB_NAME`,
`RIVETCORE_TEST_DB_USER`, `RIVETCORE_TEST_DB_PASS` (and `_HOST`; `RIVETCORE_TEST_REDIS_PORT` for the Redis tests). They skip when unset. Never use production data. `php scripts/bench.php` runs the [performance baselines](docs/PERFORMANCE.md) the same way.
