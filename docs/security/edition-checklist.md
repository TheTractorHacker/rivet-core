# Edition security checklist

For anyone integrating RivetCore 1.0 into a host application (RivetIT, RivetMSP, or a third edition). Each item is
something Core **assumes** and cannot enforce; the reasoning is in [threat-model.md](threat-model.md) section 6.
"Result" columns record what was verified read-only on 2026-10-06 against `/var/www/mw-itflow.foleyit.com` (RivetIT, Core
pinned at v0.18.1) and `/home/sysadmin/rivetmsp-beta` (RivetMSP, Core v0.21.0). "n/a" means the edition does not use that
Core module. Re-run the greps after every Core upgrade.

Convention: `RI` = RivetIT, `MSP` = RivetMSP; paths are relative to each repo.

## A. Authorisation and tenancy

| # | Check | How to verify | RI | MSP |
|---|---|---|---|---|
| A1 | Every call into an admin-type Core service sits behind an admin check: webhooks CRUD and "Send test", Redis settings/test/clear/memory, MCP settings/link/unlink, cron start/schedule, retention, attestation, publishing the shared report | `grep -rn "RedisAdmin\|JobRunner\|IdentityLinker\|SharedReport\|->publish(\|RetentionService" --include=*.php . \| grep -v vendor`, then confirm each file is reached only after an admin check | OK: admin POST handlers load only when `$session_is_admin` (`admin/post.php:28`); `admin/post/cron.php` additionally calls `enforceUserPermission` | OK: `admin/post/cron.php:14` gated the same way (spot check) |
| A2 | Inject a real `AccessPolicyInterface`; never rely on `AllowAllPolicy` in production | `grep -rn "AccessPolicyInterface\|AllowAllPolicy\|DenyAllPolicy" src includes` | Not used (edition has its own `enforce*Permission` functions); acceptable, Core calls nothing through the contract | Not used; same |
| A3 | Compliance subject / client id for the portal comes from the **session**, never from a request parameter | `grep -rn "->shared(\|SubjectCompliance\|compliance_shared_report" --include=*.php client` | `client/compliance.php:15` reads the single global report (`SharedReport::current()`); every portal user of every client sees the same published report. Confirm this is intended (it is the MSP's own posture) | OK: `client/compliance.php:18` uses `shared((int) $session_client_id)` |
| A4 | Job handlers and automation actions re-check authorisation of the *effect* (a queued job runs with no session) | read each `JobWorker::register` handler | review per handler; `rivetEmitEvent` queues `webhook.deliver` / `automation.action` only | n/a here, review `CoreBridge` consumers |
| A5 | MCP: map the validated `sub` to **one** active agent and fail closed on 0 or >1 matches | `grep -rn "oauth.subject" -A12 mcp_server` | OK: `mcp_server/McpIdentityMiddleware.php` selects `LIMIT 2` and requires exactly one active agent | n/a (no MCP) |

## B. Webhooks and outbound requests

| # | Check | How to verify | RI | MSP |
|---|---|---|---|---|
| B1 | Every `WebhookDispatcher` is built with a `UrlPolicy` (or `$requireUrlPolicy = true`) | `grep -rn "new WebhookDispatcher" -A4 --include=*.php . \| grep -v vendor` | OK: `includes/event_bus.php:66-70` passes `rivetWebhookUrlPolicy()` and `true` | **Issue (Low, SR-15):** `src/Core/CoreBridge.php:198` builds a policy-less dispatcher (`CoreBridge::webhooks()`); only tests call it today. Delete it or pass the policy. The real path `includes/event_bus.php:60-75` is correct |
| B2 | URLs are vetted on save **and** Core re-vets on every attempt; "Send test" uses the same dispatcher | `grep -rn "rivetWebhookUrlPolicy" --include=*.php .` | OK: `admin/post/settings_webhooks.php:24`, `includes/event_bus.php:361-382` | OK: `admin/includes/webhook_form_lib.php:209,417`, `includes/event_bus.php:332-353` |
| B3 | `allowedNetworks` is built only through `NetworkList::parse` (admin input) | `grep -rn "rivetWebhookAllowedNetworks" -A12 includes/event_bus.php` | OK (uses Core's parser; the env flag `RIVETIT_WEBHOOK_ALLOW_PRIVATE=1` disables the range test, keep it unset in production) | same with `RIVETMSP_WEBHOOK_ALLOW_PRIVATE` |
| B4 | Webhook secrets and chat URLs are encrypted at rest and never echoed | `grep -rn "encryptSetting" admin/post/settings_webhooks.php` | OK (`:66`, `:84`, `:143`, `:163`) | OK (`admin/includes/webhook_form_lib.php:505`) |
| B5 | Event names handed to `deliver()/deliverTo()` come from the catalog / a validated pattern | `grep -rn "rivetEmitEvent" includes/event_bus.php` | OK: `preg_match('/^[a-z0-9_.]{1,150}$/')` before use (`event_bus.php:~96`) | check the same function |
| B6 | Delivery runs off the request path (queue) so a slow receiver cannot hold a user request | `grep -rn "webhook.deliver" --include=*.php .` | OK: `JobQueue::enqueue('webhook.deliver', ...)` | verify |
| B7 | `send_webhook` automation handler re-vets the saved URL with `UrlPolicy` at send time (Core only checks the URL's shape) | find the handler registered for `send_webhook` | verify `rivetRunAutomationRule` uses `rivetWebhookDispatcher` | verify |
| B8 | Receivers are told to verify **V2** (timestamp) and to use the raw body | `docs/webhooks.md`, in-app help | in-app help links | verify |

## C. MCP and identities

| # | Check | How to verify | RI | MSP |
|---|---|---|---|---|
| C1 | The JWT library validates signature, issuer, `exp`, `nbf` and pins the algorithm; `TokenClaimsGuard::acceptable` runs after it | `grep -rn "JwtTokenValidator\|TokenClaimsGuard::acceptable" mcp_server` | OK: `mcp_server/index.php:68-71` (`algorithms: ['RS256']`, issuer, audience), guard in `McpIdentityMiddleware.php:25` | n/a |
| C2 | The linked-identity columns are **binary** (`utf8mb4_bin`) and unique per (issuer, subject) | `grep -n "user_oidc_subject" db.sql` | **Issue (Low, SR-14):** `db.sql:5690-5691` are `varchar(255)` with the table's `utf8mb4_general_ci`; OIDC `sub` is case-sensitive. Also affects web OIDC login (`includes/oidc_portal.php:164`). Plan: `ALTER ... COLLATE utf8mb4_bin` on `users.user_oidc_issuer/subject` in the same release as the Core migration | n/a (no OIDC columns in `users`) |
| C3 | `McpDiagnostics::run($cfg, $baseHost)` gets the host from configuration, never from `Host:` | `grep -rn "McpDiagnostics" --include=*.php admin` | OK: `admin/post/settings_mcp.php:41` passes `$config_base_url` | n/a |
| C4 | DNS-rebinding and body-size limits at the MCP endpoint | `mcp_server/index.php` | OK: `DnsRebindingProtectionMiddleware([$host])`, 64 KiB body cap (`:41-46`, `:92`) | n/a |
| C5 | The edition's `ToolPipeline` permission callback is cheap and exact (it fails closed on exceptions since 1.0, but a wrong "true" is still a hole) | `mcp_server/ReadTools.php:81` | uses `itflow_user_access_profile` | n/a |
| C6 | Tool arguments are not echoed into audit beyond what is needed; no secrets in tool argument names | review tool schemas | read-only tools | n/a |

## D. Redis

| # | Check | How to verify | RI | MSP |
|---|---|---|---|---|
| D1 | Redis password stored encrypted, never logged; `RedisConnectionConfig::validate()` runs before saving | `grep -rn "RedisSettings\|RedisConnectionConfig" --include=*.php .` | OK: `src/Redis/RedisSettings.php:43-73` (encrypted column, validate via Core) | verify settings class |
| D2 | TLS to Redis whenever it is not on the loopback/a private socket; `tls_verify` stays on | `grep -rn "TLS_VERIFY\|tls_verify" .` | env flag `RIVETIT_REDIS_TLS_VERIFY` defaults to true (`RedisSettings.php:62`); UI should warn when false | verify |
| D3 | `RedisAdmin` `clearable` patterns are prefixed with the edition's key prefix so "clear cache" cannot delete other tenants'/apps' keys | `grep -rn "new RedisAdmin" -A8 .` | verify the pattern list | verify |
| D4 | Rate-limit bucket names are not attacker-chosen unbounded strings | `grep -rn "RateLimiter\|->hit(" .` | MCP uses `mcp:u<id>`; login/limits use `ITFlow\Redis\RateLimit`, check key sources | verify |
| D5 | Callers of `Lock`/`CronGuard` that need exclusivity check `degraded()` or are idempotent | `grep -rn "CronGuard\|LockManager" .` | `admin/post/cron.php` uses `ITFlow\Redis\RateLimit`; cron scripts: review | verify |

## E. Jobs, cron, retention, audit

| # | Check | How to verify | RI | MSP |
|---|---|---|---|---|
| E1 | `JobRunner` is constructed with the real app root and only ever started from an admin handler; the scripts under `cron/` and `scripts/` are not writable by the web user | `grep -rn "new JobRunner" .` | OK: `admin/cron.php:12`, `admin/post/cron.php:79`; confirm file ownership on deploy | same pattern |
| E2 | Job handlers are idempotent and never put secrets in exception messages (they are shown in the UI and stored 2000 chars) | review each registered handler | review | review |
| E3 | Retention honours the compliance floor (pass the profile to `RetentionService` or compute effective days) | `grep -rn "RetentionService" -B6 cron` | OK: `cron/cron.php:161-175` computes `RetentionPolicy::effectiveDays` before calling `prune` | verify |
| E4 | Audit summaries, delivery snippets and job errors are HTML-escaped in every screen and CSV-escaped in exports | grep the views that print `summary`, `response_body_snippet`, `error` | verify | verify |
| E5 | Do not put secrets in audit metadata under unusual key names; Core redacts known names only | review `AuditService::log` / `AuditService::record` callers | e.g. `admin/post/settings_mcp.php:38` logs the issuer only: fine | verify |
| E6 | Treat `audit_events.ip_address` / `user_agent` and `webhook_deliveries.request_payload_json` as personal data in the retention and privacy statements | policy | n/a | n/a |

## F. Uploads and the KB converters

| # | Check | How to verify | RI | MSP |
|---|---|---|---|---|
| F1 | Converted HTML is purified again before storage and CSP forbids inline script | `grep -rn "DocxConverter::convert\|PdfConverter::convert" -A30 .` | OK: `agent/post/kb_article.php:117` and `:337` convert before anything is created, then the HTMLPurifier pass | verify if the KB import exists |
| F2 | Extracted media is written under **edition-generated** names, outside script-executing locations, served with the sniffed type and `X-Content-Type-Options: nosniff` | same files | OK per code comments (token substitution, random names); confirm the upload directory has no PHP handler in nginx | verify |
| F3 | Upload size limit at the web server is at or below the converter limits (32 MiB PDF, 48 MiB DOCX archive) | nginx `client_max_body_size`, `upload_max_filesize` | verify | verify |
| F4 | poppler runs with resource limits (PHP-FPM `MemoryMax`/cgroup, quota-limited temp dir) and the three binaries are the distro's | `systemctl show php*-fpm -p MemoryMax`, `df /tmp` | verify at deploy | verify at deploy |
| F5 | `CredentialReferenceRenderer`'s badge callback renders only the id-based reference, never the credential value | the closure passed to the constructor | verify | n/a |

## G. Platform and supply chain

| # | Check | How to verify | RI | MSP |
|---|---|---|---|---|
| G1 | Pin Core to a released tag and move to `^1.0` after release; both editions need the 0.19-0.21 fixes and these review fixes | `grep -n "rivet/rivet-core" composer.json composer.lock` | `^0.18` locked at **v0.18.1**: needs the bump (picks up the 0.18.1-to-1.0 fixes, among them the webhook response cap, schema-scoped migration lock and audit fan-out redaction) | `^0.21` locked at v0.21.0: bump to the 1.0 tag |
| G2 | `composer audit` in CI; Dependabot on | `.github/` | verify | verify |
| G3 | Redis, MariaDB and the app share no more network than needed; Redis is not exposed publicly | `ss -tlnp`, firewall | deploy-time | deploy-time |
| G4 | Clock sync (NTP): webhook replay windows (300 s) and JWT lifetimes rely on it | `timedatectl` | deploy-time | deploy-time |
| G5 | `MigrationRunner` is run by one process per deployment, at upgrade time, with the DB user that owns the schema; after the 1.0 migration list changes the edition regenerates `db.sql` | `grep -rn "MigrationRunner" .` | `admin/database_updates.php` | same |

## How to use this list

1. Copy the table into the edition's release checklist; keep the "How to verify" commands in CI where they are greps.
2. Anything marked **Issue** above is an edition-side finding from the 2026-10 review, reported here and not changed.
3. Add a row when the edition starts using another Core module (the module's section in
   [threat-model.md](threat-model.md) lists the assumptions).
