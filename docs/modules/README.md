# Modules

One page per module: what it owns (tables), the contracts an edition implements, key classes with runnable examples,
configuration, how it fails, security notes, which edition features use it, and links. For wiring a new edition see
[../adapters.md](../adapters.md) and [../quickstart.md](../quickstart.md); for the compatibility rules see
[ADR-004](../architecture/ADR-004-versioning-and-compatibility.md).

| Module | Page | Namespace | Owns tables | You supply | Fails how |
|---|---|---|---|---|---|
| Audit | [audit.md](audit.md) | `RivetCore\Audit` | `audit_events` | request context | Errors are thrown to the caller; wrap in try/catch if auditing must never block (editions do). The `afterLog` fan-out can never fail the write. |
| Redis, health | [redis.md](redis.md) | `RivetCore\Redis`, `Health` | none | Redis client provider | Fails open: locks report "held" (degraded), rate limits allow, readiness reports Redis but never fails on it |
| Cron | [cron.md](cron.md) | `RivetCore\Cron` | none | job catalog | Refuses unknown or non-allowlisted scripts and unsafe state directories |
| Jobs | [jobs.md](jobs.md) | `RivetCore\Jobs` | `integration_jobs` | job handlers | Retries with backoff, then dead-letter; `PermanentJobFailure` skips retries; unknown types dead-letter |
| Webhooks | [webhooks.md](webhooks.md) | `RivetCore\Webhooks` | `webhook_deliveries` | subscriptions | Never throws from delivery; every attempt is recorded; the caller decides retries (through Jobs). See also [../webhooks.md](../webhooks.md) and [../webhook-platforms.md](../webhook-platforms.md) |
| Automation | [automation.md](automation.md) | `RivetCore\Automation` | `automation_rules` | action handlers | A failing action is recorded and does not stop other rules |
| ITSM | [itsm.md](itsm.md) | `RivetCore\ITSM` | `problems`, `changes` | ticket link | Invalid status transitions and bad input throw `InvalidArgumentException` |
| Workflow | [workflow.md](workflow.md) | `RivetCore\Workflow` | `workflow_*` (4) | subject records | `startRun()` is atomic and throws for an unknown template; task moves are not validated (see the page) |
| Compliance | [compliance.md](compliance.md) | `RivetCore\Compliance` | `compliance_*` | checks, attestation provider | A check that throws is recorded as an error and scores 0; stores throw `InvalidArgumentException` for bad input |
| Retention | [retention.md](retention.md) | `RivetCore\Retention` | none | policy values | A horizon below 1 keeps everything for that table; floors apply only when a compliance profile is passed |
| MCP | [mcp.md](mcp.md) | `RivetCore\Mcp` | `mcp_unlinked_identities` | agent directory, SDK glue, tool queries | Unlinked identities are recorded and denied |
| KB converters | [kb.md](kb.md) | `RivetCore\KB` | none | uploaded file | Throws `DocxConversionException` / `PdfConversionException` on bad or oversized input |
| Knowledge | [knowledge.md](knowledge.md) | `RivetCore\Knowledge` | none | credential lookup / reveal control | Renders a placeholder when the credential is not visible |
| Ui | [ui.md](ui.md) | `RivetCore\Ui` | none | the picker and filter UI | `IconCatalog::normalize()` falls back to the default; `DateRange` falls back to all time for bad input |
| RMM (endpoint agent) | [rmm.md](rmm.md) | `RivetCore\Rmm` | `endpoint_agent_*` (10) | tenancy, assets, bridge, secret box, access policy (+ optional sink, audit, module state) | Off by default; device endpoints answer `{error, code}` (503 `module_disabled` when off); technician and admin operations return an `ActionResult`; a refusal never throws |
| Migration | [migration.md](migration.md) | `RivetCore\Migration` | `rivet_core_migrations` | an updater that calls the runner | Concurrent runs take turns; a runner that waits too long throws; there is no `down()` |

## Page template

Every page has the same sections: Overview, Contracts an edition must implement, Key classes, Configuration, How it fails, Security
notes, Used by (RivetIT and RivetMSP), Links.

## Related

[Upgrading](../../UPGRADING.md) | [Edition checklist](../EDITION_CHECKLIST.md) | [Performance](../PERFORMANCE.md) |
[1.0.0 release gate](../RELEASE_GATE.md) | [API surface](../api-surface.md) | [Decisions](../architecture/)
