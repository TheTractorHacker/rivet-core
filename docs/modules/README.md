# Modules

| Module | Namespace | Owns tables | You supply | Fails how |
|---|---|---|---|---|
| Audit | `RivetCore\Audit` | `audit_events` | request context | Errors are thrown to the caller; wrap in try/catch if auditing must never block (editions do) |
| Redis, health | `RivetCore\Redis`, `Health` | none | Redis provider | Returns "not acquired"/allows the work when Redis is down |
| Cron | `RivetCore\Cron` | none | job catalog | Refuses unknown or non-allowlisted scripts |
| Jobs | `RivetCore\Jobs` | `integration_jobs` | job handlers | Retries with backoff, then dead-letter; `PermanentJobFailure` skips retries |
| Webhooks | `RivetCore\Webhooks` | `webhook_deliveries` | subscriptions | HMAC-SHA256 signed; attempts recorded; caller decides retry via Jobs; optional `allowedNetworks` (CIDR list, see `NetworkList`) lets webhooks reach the server's own private LAN only (loopback, link-local/metadata, multicast never allowed); `LocalNetworks::detect()` suggests the server's networks; `EventCatalog` (searchable grouped events, `ticket.*` patterns), `Destinations` (24 presets: n8n, Slack, ntfy, ...), `PayloadFormatter`/`PayloadTemplate` (per-platform bodies, safe `{{}}` templates), `Authentication` (bearer/basic/header); per-subscription `format`/`method`/`extraHeaders` options on `WebhookDispatcher::deliverTo()`; see [webhooks.md](../webhooks.md) |
| Automation | `RivetCore\Automation` | `automation_rules` | action handlers | A failing action is recorded and does not stop other rules |
| ITSM | `RivetCore\ITSM` | `problems`, `changes` | ticket link | Invalid status transitions are refused |
| Workflow | `RivetCore\Workflow` | `workflow_*` | subject records | Run status is derived from its tasks and a cancelled run is never revived; task methods do not validate (unknown ids are silent no-ops), see [workflow.md](workflow.md) |
| Compliance | `RivetCore\Compliance` | `compliance_*` | checks, attestations | A check that throws is reported as `error` (scored 0, generic message), never as `pass` |
| Retention | `RivetCore\Retention` | none | policy values | Floors from the chosen preset are enforced |
| MCP | `RivetCore\Mcp` | `mcp_unlinked_identities` | agent directory | Unlinked identities are recorded and denied |
| KB converters | `RivetCore\KB` | none | uploaded file | Throws `DocxConversionException` / `PdfConversionException` on bad input |
| Knowledge | `RivetCore\Knowledge` | none | credential lookup | Renders a placeholder when the credential is not visible |
| UI / IconCatalog | `RivetCore\Ui\IconCatalog` | none | the picker UI (render `toJson()`, store `normalize()` output) | `normalize()` returns the default for empty/invalid input; any valid `fa-xxx` class is accepted even when not curated (`has()` is catalog membership only) |
| UI / DateRange | `RivetCore\Ui\DateRange` | none | the list/report filter (`canned_date`, `dtf`, `dtt`, tz, week start) | Unknown preset or invalid custom dates resolve to all time; reversed dates are swapped; years clamped to 1970..2099; `sqlBounds()` is half-open for sargable queries |

## One page per module

[Audit](audit.md) | [Redis and health](redis.md) | [Cron](cron.md) | [Jobs](jobs.md) | [Webhooks](webhooks.md) | [Automation](automation.md) |
[ITSM](itsm.md) | [Workflow](workflow.md) | [Compliance](compliance.md) | [Retention](retention.md) | [MCP](mcp.md) | [KB converters](kb.md) |
[Knowledge](knowledge.md) | [UI helpers](ui.md)

Each page says what the module owns (tables and columns), what the edition supplies, which flags it reads, a sample, and how it fails.
Every sample on those pages is executed by `php scripts/check-doc-samples.php` (and by `tests/Integration/DocSamplesTest.php`), so they cannot rot.
The full public surface is in [PUBLIC-API.md](../PUBLIC-API.md).
