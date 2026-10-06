# Modules

| Module | Namespace | Owns tables | You supply | Fails how |
|---|---|---|---|---|
| Audit | `RivetCore\Audit` | `audit_events` | request context | Errors are thrown to the caller; wrap in try/catch if auditing must never block (editions do) |
| Redis, health | `RivetCore\Redis`, `Health` | none | Redis provider | Returns "not acquired"/allows the work when Redis is down |
| Cron | `RivetCore\Cron` | none | job catalog | Refuses unknown or non-allowlisted scripts |
| Jobs | `RivetCore\Jobs` | `integration_jobs` | job handlers | Retries with backoff, then dead-letter; `PermanentJobFailure` skips retries |
| Webhooks | `RivetCore\Webhooks` | `webhook_deliveries` | subscriptions | HMAC-SHA256 signed; attempts recorded; caller decides retry via Jobs; optional `allowedNetworks` (CIDR list, see `NetworkList`) lets webhooks reach the server's own private LAN only (loopback, link-local/metadata, multicast never allowed); `LocalNetworks::detect()` suggests the server's networks |
| Automation | `RivetCore\Automation` | `automation_rules` | action handlers | A failing action is recorded and does not stop other rules |
| ITSM | `RivetCore\ITSM` | `problems`, `changes` | ticket link | Invalid status transitions are refused |
| Workflow | `RivetCore\Workflow` | `workflow_*` | subject records | Task/run state machine refuses illegal moves |
| Compliance | `RivetCore\Compliance` | `compliance_*` | checks, attestations | Unknown checks are reported "unknown", never "pass" |
| Retention | `RivetCore\Retention` | none | policy values | Floors from the chosen preset are enforced |
| MCP | `RivetCore\Mcp` | `mcp_unlinked_identities` | agent directory | Unlinked identities are recorded and denied |
| KB converters | `RivetCore\KB` | none | uploaded file | Throws `DocxConversionException` / `PdfConversionException` on bad input |
| Knowledge | `RivetCore\Knowledge` | none | credential lookup | Renders a placeholder when the credential is not visible |
| UI / IconCatalog | `RivetCore\Ui\IconCatalog` | none | the picker UI (render `toJson()`, store `normalize()` output) | `normalize()` returns the default for empty/invalid input; any valid `fa-xxx` class is accepted even when not curated (`has()` is catalog membership only) |
| UI / DateRange | `RivetCore\Ui\DateRange` | none | the list/report filter (`canned_date`, `dtf`, `dtt`, tz, week start) | Unknown preset or invalid custom dates resolve to all time; reversed dates are swapped; years clamped to 1970..2099; `sqlBounds()` is half-open for sargable queries |

Per-module deep dives are added as each module's API is frozen for 1.0 (see ROADMAP.md).
