# Inbound monitoring webhooks

Namespace `RivetCore\Monitoring\Inbound`. Core owns the shared logic; **tickets belong to the editions**, so Core ships no table, no
migration and no endpoint file. An edition adds a tiny public endpoint, a settings page and three adapters (ticket gateway, incident
store, monitor-to-client map). Ready-to-paste prompts: [../design/edition-prompts-inbound-monitoring.md](../design/edition-prompts-inbound-monitoring.md).

Uptime Kuma is the first source. Another monitor is added by implementing `PayloadParserInterface` (parse a body into an `InboundEvent`); the engine,
authentication and handler are source-neutral.

## What it does

| Delivery | Result |
|---|---|
| DOWN | After `minConsecutiveDown` DOWN deliveries (default 1) or `minDownSeconds` of outage (default off), opens one ticket and remembers it |
| DOWN again, ticket open | At most one internal note per `noteIntervalSeconds` (default 1800) |
| UP, ticket open | Resolves the ticket with a note: recovery time and outage duration |
| UP, ticket below the threshold or none | Resets the counter, nothing else |
| PENDING (2), MAINTENANCE (3) | Ignored. A monitor in maintenance never opens or resolves a ticket |
| Test notification (no heartbeat) | `200 {"ok":true,"action":"test"}`, nothing touched |
| A person already resolved or closed the ticket | Never reopened; the incident closes. The next DOWN after that opens a **new** ticket |
| Same delivery twice (same heartbeat time and status) | `duplicate`, no effect |
| A delivery older than the newest one handled for that monitor | `stale`, no effect: a late DOWN after an UP cannot reopen |
| More than `maxOpensPerHour` (default 6) opens in an hour for one monitor | Flap guard: no new ticket, notes on the latest ticket only while it is still open |

One incident per `(source, monitor id)`. The monitor's row is kept after recovery (state `healthy`) so stale deliveries are recognised. Heartbeat time
(UTC) is the ordering key; a time more than 5 minutes in the future is replaced by the arrival time so a wrong sender clock cannot freeze a monitor.
A DOWN that discovers a human-closed ticket only closes the incident (outcome `closed_by_human`): with Uptime Kuma's default "important heartbeats
only" delivery that means the monitor stays quiet until it recovers and fails again, which is the intent.

## Pieces

- `UptimeKumaPayload` (implements `PayloadParserInterface`): strict parse, returns `InboundEvent` or `PayloadRejection` (a fixed code and sentence; never an
  exception, never any part of the body). Body at most 64 KB, nesting at most 6, status must be the integer 0..3, monitor id a positive integer, strings stripped
  of control and bidi characters and cut (name 200, message 1000, url 500, hostname 255, type 32, 20 tags of 100). Null `heartbeat`/`monitor` is a test event.
- `IncidentEngine` + `EngineConfig`: the state machine above. `process(InboundEvent): EngineOutcome`. A gateway or store exception propagates and the incident is not changed (the retry repeats the step).
- `InboundAuthenticator`: secret from `X-Webhook-Secret` or `Authorization: Bearer`, SHA-256 digests compared with `hash_equals`. **Fails closed**: no secret,
  an empty one or one shorter than 16 characters authenticates nobody. Several secrets may be accepted at once for rotation.
- `InboundHandler::handle(array $headers, string $rawBody, string $remoteIp): InboundResult`: rate limit hook (429) > auth (401, one answer for wrong, missing
  and unconfigured) > size (413) > parse (400) > engine (200, or 500 `internal`). Bodies hold fixed tokens and a ticket id only. The raw body is never logged; the optional PSR logger gets source, monitor key and exception class.
- Contracts the edition implements: `TicketGatewayInterface`, `IncidentStoreInterface`, `MonitorClientMapInterface`, optional `RateLimiterInterface`.
  Reference implementations and conformance cases are in `src/Testing`: `InMemoryTicketGateway` + `TicketGatewayConformanceTestCase`,
  `InMemoryIncidentStore` + `IncidentStoreConformanceTestCase`, `InMemoryMonitorClientMap` (the ambiguity rule).

## Tenant isolation: unmapped monitors are never guessed

`MonitorClientMapInterface::resolve()` returns exactly one `MonitorTarget` (a client and optionally an asset) or `null`. Lookup is by exact monitor id, then by exact tag
(`name` or `name:value`); the monitor name, URL and hostname are never used. If two matches point at different clients the map is ambiguous and must return `null`. On
`null` the engine uses `EngineConfig::$defaultTarget` (a client the admin chose), else `MonitorTarget::unassigned()`, which the edition files under an
internal/unassigned bucket and never under a customer. The ticket keeps its client even if the map changes during the incident.

## Wiring (edition endpoint)

```php
$auth = new InboundAuthenticator([$settings['secret_current'], $settings['secret_previous']]);   // previous only during rotation
$engine = new IncidentEngine($store, $gateway, $map, new EngineConfig(minConsecutiveDown: 1, defaultTarget: $default));
$handler = new InboundHandler($auth, new UptimeKumaPayload(), $engine, $limiter, $logger);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
$r = $handler->handle(getallheaders() ?: [], (string) file_get_contents('php://input', false, null, 0, 70000), $_SERVER['REMOTE_ADDR'] ?? '');
http_response_code($r->status);
foreach ($r->headers as $k => $v) { header("$k: $v"); }
header('Content-Type: application/json');
echo $r->json();
```

## Configure Uptime Kuma

1. In the edition, generate the secret (Settings > Integrations > Monitoring) and copy the endpoint URL, e.g. `https://itflow.example.com/inbound_monitor.php`.
2. Uptime Kuma: **Settings > Notifications > Setup Notification**, type **Webhook**.
3. **Post URL**: the endpoint URL. **Request Body**: **Preset - application/json** (the default body that this parser reads), or **Custom Body** with the same shape.
   The parser needs `heartbeat.status`, `heartbeat.monitorID` (or `monitor.id`) and `heartbeat.time`; `monitor.name`, `url`, `tags` and `msg` are optional.
4. **Additional Headers**: `{"X-Webhook-Secret": "<the secret>"}` (or `{"Authorization": "Bearer <the secret>"}`).
5. **Test** sends a body with a null heartbeat: expect a success toast and, in the edition, a "test received" line. A 401 means the secret or header is wrong.
6. Attach the notification to the monitors (or "Apply on all existing monitors"). Uptime Kuma sends on **important** heartbeats only (DOWN after retries, UP after DOWN),
   which is what the engine expects; "Resend notification if down X times" repeats DOWN, which becomes notes at most once per interval.
7. To route by tag, add a tag to the monitor (e.g. `client` = `acme`) and map `client:acme` to a client in the edition.

Maintenance windows in Uptime Kuma send status 3, which is ignored.

## Failure behaviour

| Situation | Answer |
|---|---|
| Wrong, missing, or unconfigured secret | 401 `unauthorized` |
| Rate limited | 429 + `Retry-After: 60` |
| Body over 64 KB | 413 `too_large` |
| Not JSON, not an object, bad status/id/time/field | 400 with a fixed `error` token |
| Edition ticket system or store throws | 500 `internal` (state unchanged) |

Security notes: the secret travels in a header, so serve the endpoint over HTTPS only; do not log request bodies or headers at the web server or WAF for this path;
the endpoint is unauthenticated apart from the secret, so keep it rate limited and exclude it from any login redirect.

No migration: the incident table belongs to the edition (SQL in the edition prompts).
