# Edition prompts: RivetCore 1.0.0-rc.67 (inbound monitoring webhooks: Uptime Kuma)

Core now ships `RivetCore\Monitoring\Inbound`: parser, incident engine, shared-secret authenticator and `InboundHandler::handle()`. Read `docs/modules/inbound-monitoring.md` and the rc.67 CHANGELOG entry first. **No Core migration**: the editions own the incident table (SQL below, numbered by each edition's own scheme). Tickets, categories, priorities and statuses are the edition's, so everything that touches them is in the adapter.

What Core already does, so the prompts stay short: validates and sanitises the body; authenticates (fail closed); decides open, note, resolve, ignore, duplicate, stale, human-closed and flapping; picks a target client from the map. What the edition does: expose the endpoint, store the secret and settings, persist incidents, create/note/resolve tickets, show it in the admin UI.

## Shared design (both editions)

**Incident table** (`utf8mb4`; adapt the engine/collation to the edition's db.sql conventions and number the update to match its updater):

```sql
CREATE TABLE `inbound_monitor_incidents` (
  `incident_source` varchar(32) NOT NULL,
  `incident_monitor_key` varchar(32) NOT NULL,
  `incident_monitor_name` varchar(200) NOT NULL DEFAULT '',
  `incident_state` enum('healthy','pending','open') NOT NULL DEFAULT 'healthy',
  `incident_ticket_id` int(11) DEFAULT NULL,
  `incident_last_ticket_id` int(11) DEFAULT NULL,
  `incident_consecutive_down` int(11) NOT NULL DEFAULT 0,
  `incident_down_since` int(11) DEFAULT NULL,
  `incident_last_event_at` int(11) DEFAULT NULL,
  `incident_last_status` tinyint(4) DEFAULT NULL,
  `incident_last_note_at` int(11) DEFAULT NULL,
  `incident_opens` varchar(400) NOT NULL DEFAULT '[]',
  `incident_client_id` int(11) DEFAULT NULL,
  `incident_asset_id` int(11) DEFAULT NULL,
  `incident_updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`incident_source`,`incident_monitor_key`),
  KEY `incident_state` (`incident_state`),
  KEY `incident_ticket_id` (`incident_ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `inbound_monitor_map` (
  `map_id` int(11) NOT NULL AUTO_INCREMENT,
  `map_source` varchar(32) NOT NULL DEFAULT 'uptime-kuma',
  `map_kind` enum('monitor','tag') NOT NULL,
  `map_value` varchar(120) NOT NULL,          -- monitor id, or tag as "name" / "name:value", stored lower-case for tags
  `map_client_id` int(11) NOT NULL,
  `map_asset_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`map_id`),
  UNIQUE KEY `map_unique` (`map_source`,`map_kind`,`map_value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Settings (in the edition's settings table, DB-backed, not a config file): `inbound_monitoring_enabled`, `inbound_monitoring_secret` (store encrypted with the edition's secret box, or as a hash plus show-once; see below), `inbound_monitoring_secret_previous` (rotation), `..._default_client_id` (0 = unassigned), `..._min_consecutive_down` (1), `..._min_down_seconds` (0), `..._note_interval_minutes` (30), `..._max_opens_per_hour` (6), `..._priority_name` ('High'), `..._category_name` ('' or e.g. 'Monitoring'), `..._sla_exempt` / `..._billable` (below).

`IncidentStoreInterface` adapter: `get()` selects by the two key columns and builds `Incident::fromArray()` (`incident_opens` is a JSON list of ints); `put()` is `INSERT ... ON DUPLICATE KEY UPDATE` from `Incident::toArray()`; `locked()` takes `GET_LOCK(sha1(source|monitor), 10)` (throw if not acquired), runs the closure, always `RELEASE_LOCK` in `finally`. Do not commit the engine's decision before the ticket exists: the engine already calls `put()` only after the gateway call succeeded. Prove it with `IncidentStoreConformanceTestCase` against the scratch DB.

`MonitorClientMapInterface` adapter (tenant isolation): load `inbound_monitor_map`; collect the monitor-id entry and every tag entry matching `$event->tags` (case-insensitive, exact); if there are none return `null`; if the matches name more than one distinct client return `null` (ambiguous: never pick one); otherwise `MonitorTarget::client($clientId, $assetId)` after re-checking the client still exists and is not archived (else `null`). Never look at the monitor name, URL or hostname. The settings page must refuse to save a second row for the same monitor id and warn when a tag row and a monitor row point at different clients.

`TicketGatewayInterface` adapter: see per edition. Common rules: the `TicketRequest` text is plain and sender-controlled, so escape it exactly like the edition does for other external text (RivetIT: `sanitizeInput()` before SQL, and render escaped); never build HTML from it; resolve the priority and category **by name** (`TicketRequest::$priority`, `::$category`), falling back to the edition's default when the name is not found; status by the edition's creation-status resolver, resolved status through the same alias the edition uses for "Resolved"; no literal status ids anywhere (ids are per install).

Endpoint file: `inbound_monitor.php` (public, no session, no CSRF, POST only; 405 otherwise). It builds `InboundAuthenticator` from the stored secrets, `UptimeKumaPayload`, `IncidentEngine` (with `EngineConfig` from the settings and the clock default), `InboundHandler`, reads `getallheaders()`, `file_get_contents('php://input', false, null, 0, 70000)` and `REMOTE_ADDR`, calls `handle()` and emits `$result->status`, `$result->headers` and `$result->json()` as `application/json`. If the feature is disabled answer 404 with an empty body. Wrap everything so a PHP error still answers a generic 500 JSON (no stack trace). Behind a reverse proxy take the client address from the edition's existing trusted-proxy helper, never from a raw `X-Forwarded-For`. Rate limiter: implement `RateLimiterInterface` over the edition's existing limiter (e.g. 60 requests/minute/IP); fail open if the limiter store is down.

Settings page (admin only, CSRF, audit-logged): enable switch; the endpoint URL with a copy button; **secret**: "Generate" creates 32 random bytes as 64 hex characters (`bin2hex(random_bytes(32))`), shows it once with a copy button, stores it encrypted; "Rotate" moves the current secret to `secret_previous` and generates a new one; "Finish rotation" clears the previous one; never print a stored secret again and never write it to logs or the audit trail (log "secret generated/rotated" only); default client (or "Unassigned"); the map editor (monitor id or tag -> client, optional asset) with the validation above; thresholds (consecutive DOWNs, minimum duration, note interval, flap limit) with the defaults and one-line explanations; priority and category pickers (store the names); a "Test webhook" button (below); a short "How to configure Uptime Kuma" block copied from `docs/modules/inbound-monitoring.md` (Settings > Notifications > Webhook, JSON body, the additional header with the secret, Test).

"Test webhook" button: the server posts a synthetic body to its own endpoint and shows the result. Prefer calling the handler in-process with the stored secret and a **test** body (`{"heartbeat":null,"monitor":null,"msg":"Testing"}`) so it exercises authentication and parsing without creating a ticket; add a second button "Send a sample DOWN then UP" that uses a clearly named monitor id (`999999`, name "RivetIT test monitor", mapped to the default client) and resolves the ticket it opened. Show the status code and `action` only.

Admin view "Monitoring incidents": table of `inbound_monitor_incidents` (monitor, state, client, ticket link, down since, last event, opens in the last hour), filter open/pending, a "Close incident" action that only deletes the row's ticket link (state `healthy`, `incident_ticket_id` NULL) and leaves the ticket alone, and a count in the settings page header. Show the last 20 delivery outcomes if the edition already keeps an integration log (outcome token and ticket id only, never the body).

WAF / logging notes (write them into the settings page help and the README): allow `POST` to the endpoint from the Uptime Kuma host; do not enable request-body or header logging for this path at nginx, the WAF or APM (the header carries the secret and the body carries monitor names and URLs); exclude the path from any "must be logged in" redirect, CSRF middleware and bot-protection challenge; keep HTTPS-only; the body limit is 64 KB, so `client_max_body_size 64k;` is enough for this location.

Tests (both editions): `TicketGatewayConformanceTestCase` and `IncidentStoreConformanceTestCase` run against a scratch database; an end-to-end test through the real endpoint file with a loopback request: no secret, wrong secret, empty configured secret (all 401, nothing created), test body (200, nothing created), DOWN (ticket created under the mapped client), DOWN again (no second ticket), UP (ticket resolved with the recovery note), a technician resolves the ticket by hand then DOWN (no reopen, no note), later DOWN (new ticket), unmapped monitor (default client, or unassigned when none), ambiguous tags (default/unassigned, never one of the two clients), maintenance status (nothing), the body with 70 KB (413), a secret never present in any response or log line. Run the edition's full suite against a schema-only scratch copy; do not deploy to production.

## RivetIT chat

```
Adopt RivetCore 1.0.0-rc.67 and add "Monitoring webhooks (Uptime Kuma)" to RivetIT. Worktree, one PR, beta first, dump the database before the migration, do not deploy to production. Read docs/modules/inbound-monitoring.md and docs/design/edition-prompts-inbound-monitoring.md in rivet-core (the "Shared design" section is part of this task).

0. Pin rivet/rivet-core to the rc.67 tag. Add the two tables in the shared design to db.sql and a DB update with the next number in includes/database_updates.php (bump LATEST_DATABASE_VERSION, idempotent CREATE TABLE IF NOT EXISTS). Add the settings columns the shared design lists (config_inbound_monitoring_*), with the secret stored through the edition's existing encrypted-settings helper.

1. Public endpoint inbound_monitor.php at the web root, modelled on comet_webhook.php (same fail-closed behaviour: an unconfigured secret rejects everything) but all logic delegated to RivetCore\Monitoring\Inbound\InboundHandler. Output buffering around the includes as comet_webhook.php does so notices never reach the response. Use hash_equals through InboundAuthenticator, not a hand-written compare. Add the route to the five nginx vhost files only if /etc/nginx/snippets/itflow-locations.conf needs it (a public PHP file at the web root usually does not; verify, do not edit live nginx: describe the change instead).

2. Ticket gateway adapter (includes/inbound_monitoring.php, class ItflowMonitoringTicketGateway implements TicketGatewayInterface), mirroring comet_create_alert_ticket() and comet_process_job():
   - openTicket(): atomic next-number via the settings UPDATE ... LAST_INSERT_ID() pattern; ticket_source = the request's sourceLabel ('Uptime Kuma'); subject/details = sanitizeInput() of the request text; ticket_priority = the priority NAME as the tickets table stores it (it is a name column: validate against the allowed names, else 'High'); ticket_category resolved BY NAME from ticket_categories (resolveTicketCategory() semantics: unknown name = the default category, never a literal id); ticket_client_id = target client, or for an unassigned target the edition's internal/"no client" convention (client_id 0), never a customer; ticket_asset_id = the target asset only when it belongs to that client; ticket_assigned_to = resolveTicketAssignee(0); ticket_status = resolveTicketCreationStatus(...) (by name, per install); ticket_created_by = 0; ticket_billable = 0 by default for monitoring tickets (setting 'inbound_monitoring_billable' can turn it on; do NOT use config_ticket_default_billable silently) ; if the category is SLA-exempt (ticket_counts_sla = 0) leave it, otherwise the SLA clock starts as for any ticket; a setting 'inbound_monitoring_sla_exempt' may select an SLA-exempt category by name. Return the new ticket_id. Call appNotify() like Comet does. Wrap the number increment and insert in one transaction.
   - isStillOpen(): ticket exists AND ticket_resolved_at IS NULL AND ticket_closed_at IS NULL AND ticket_archived_at IS NULL (use the edition's real column names; check how the ticket list defines "open"). What about a ticket in a "parked"/waiting status (waiting on customer, scheduled, on hold)? It is still open: the engine must keep noting and resolving it. If the edition has a status that means "parked until a date" (snoozed), a DOWN note must NOT wake it up by itself: add the note as an internal reply with no status change and no reopen, and only the UP resolves it.
   - addNote(): INSERT ticket_replies (type 'Internal', ticket_reply_by = 0), sanitizeInput() on the text, no status change, no notification to the contact; no-op if not open.
   - resolveTicket(): the Comet resolve path: internal reply, then status = resolveTicketStatusId(4) (Resolved is an alias for Closed in this app), ticket_resolved_at/ticket_closed_at = NOW(), slaStampResponseIfMissing(), no contact email (monitoring tickets have no contact); no-op if not open.
   Tests with a scratch DB: TicketGatewayConformanceTestCase with real tickets (closeTicketAsPerson uses the edition's own close path).

3. Settings page agent/admin/settings_monitoring_webhooks.php (menu: Settings > Integrations > Monitoring webhooks, next to Comet): everything in the shared design's "Settings page" and "Monitoring incidents" sections, in the app shell with the edition's page components, CSRF on every POST, audit rows through logAction() (never the secret). Store the secret encrypted and show it once. Map editor on the same page with inline validation. Add a "Test webhook" button as described. Add a dashboard-free "Monitoring incidents" admin table (open/pending, link to the ticket).

4. Docs: a short section in the in-app help and the README on the Uptime Kuma setup (copy from rivet-core docs), the WAF/body-logging notes, and CHANGELOG. Verify end to end on a throwaway copy: a real Uptime Kuma test notification (or curl with the same JSON) against the loopback endpoint, as in the tests list of the shared design.
```

## RivetMSP chat

```
Adopt RivetCore 1.0.0-rc.67 and add "Monitoring webhooks (Uptime Kuma)" to RivetMSP, same Core calls and shared design as RivetIT (docs/design/edition-prompts-inbound-monitoring.md "Shared design"). Worktree, one PR, beta first, dump the database before the migration, do not deploy to production.

Differences from RivetIT, all about TENANCY:
- Tickets belong to a client (an MSP customer). A monitor maps to EXACTLY ONE client. The map table's unique key (source, kind, value) plus the adapter's rule (two matches for different clients = ambiguous = null) are the guarantee; add a database-level test that saving a second mapping for the same monitor id is refused, and a test that a monitor tagged for client A and client B opens the ticket under neither (default client or unassigned).
- "Unassigned" must not be a real customer. Create (once, by name, idempotently) an internal client/queue "Monitoring - unassigned" flagged internal/non-billable, or use the edition's existing internal client if it has one; the gateway files tickets for MonitorTarget::unassigned() there and nowhere else. Never fall back to "the first client" or to the client whose name looks similar. The admin settings page shows a banner with the number of open incidents sitting in unassigned.
- The map editor and the default-client picker only list clients, assets and departments the admin may manage. An asset in the map must belong to the mapped client (re-check in the adapter, on every openTicket: an asset that moved to another client is ignored, the ticket is still filed under the mapped client).
- Per-client visibility: the ticket is filed like any other ticket under that client, so it follows the existing client/department access rules; the Monitoring incidents admin table is visible to users with the settings/integrations permission only and shows client names only to users who may see that client (others see "restricted"). Module-only logins (client-portal/limited roles) must not see any of it.
- The synthetic Test-webhook sample uses the default client only, and is refused when no default client is set (never an arbitrary client).
- Billable and SLA: monitoring tickets default to non-billable and to the client's SLA policy only if the chosen category is not SLA-exempt (by category name, settings pickers); a ticket opened for an unassigned target is never billable. Parked/waiting statuses are treated as open (notes still added, resolve still works), a snoozed ticket is not woken by a DOWN note.
- Resolve by name everywhere: priority, category and the statuses (per install). No literal ids.
- Everything else (endpoint file, hash_equals via InboundAuthenticator, rotation, rate limit, tests including the end-to-end list and the conformance cases) is as in the RivetIT prompt.
```
