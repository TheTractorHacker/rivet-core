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
| More than `maxOpensPerHourPerClient` (default 300) opens in an hour for one client, or `maxOpensPerHourAll` (default 600) in all | Ticket cap: no ticket of its own (`capped`). The first one opens ONE summary ticket (that client's; unassigned for the overall cap), the next ones are notes on it (at most 50 an hour, the rest counted). The monitor stays pending, so its next DOWN after the hour opens its own ticket |
| A sender limited to some clients reports a monitor the map sends to another client | `403 {"ok":false,"error":"out_of_scope"}`, nothing opened (per-sender secrets only, below) |

One incident per `(source, monitor id)` in single-secret mode, per `(source, sender, monitor id)` with per-sender secrets. The monitor's row is kept after
recovery (state `healthy`) so stale deliveries are recognised. Heartbeat time (UTC) is the ordering key; a time in the future is replaced by the arrival
time (no slack since the 2026-11-21 red team, NEW-1), so a delivery can make another one stale only when that one's heartbeat is older than the first
one's arrival: the delivery delay, plus however far the real sender's clock lags. It can no longer silence the real deliveries of the next minutes.
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
  and unconfigured) > size (413) > parse (400) > engine (200 `{"ok":true,"action":"<token>"}`, 403 `out_of_scope`, or 500 `internal`). Bodies hold fixed tokens
  only, never a ticket id (a sender learns nothing about tickets). The raw body is never logged; the optional PSR logger gets source, sender id, monitor key and exception class.
- Ticket text: everything the engine hands the gateway (subject, body, notes) is plain text without markup: tag-shaped runs are removed and a stray `<` or `>`
  becomes `‹` or `›`. The edition still escapes on output; wording a sender chose (a phishing line in a monitor name) is still the sender's text.
- Contracts the edition implements: `TicketGatewayInterface`, `IncidentStoreInterface`, `MonitorClientMapInterface`, optional `RateLimiterInterface`.
  Reference implementations and conformance cases are in `src/Testing`: `InMemoryTicketGateway` + `TicketGatewayConformanceTestCase`,
  `InMemoryIncidentStore` + `IncidentStoreConformanceTestCase`, `InMemoryMonitorClientMap` (the ambiguity rule).

## Senders: one shared secret, or one secret per sender

**Single-secret mode** (`new InboundAuthenticator([$secret])`, unchanged) trusts every holder of the secret for every monitor and every client: any holder can
resolve or silence any monitor's incident, burn its flap budget, and route a new ticket to any client the map knows by sending that client's tag. Use it only
when every Uptime Kuma instance is operated by the MSP itself, never hand that secret to a client.

**Per-sender secrets** (optional extension point) give each sender its own secret and, optionally, the clients it may report for:

<!-- skip: fragment of an edition-side example, needs a configured edition or live service -->
```php
$auth = new InboundAuthenticator([$settings['secret_current']], [
    'msp-kuma'  => ['secrets' => [$mspSecret]],                          // any client, but only its own incidents
    'acme-kuma' => ['secrets' => [$acmeSecret], 'clients' => [3]],     // a Kuma the client Acme runs: Acme only
]);
```

`InboundAuthenticator::identify()` answers the `InboundSender` (id `[a-z0-9-]`, at most 12 characters) and `InboundHandler` hands it to the engine. A named
sender's incidents are keyed `"<sender>/<monitor id>"`, so it cannot see, resolve, silence or flap another sender's monitors even when the monitor ids are the
same. With a client list it may only open tickets for those clients: a monitor the map (by id or by tag) sends to another client is refused (403
`out_of_scope`); an unmapped one goes to its only client, else to the default target when that is one of its clients, else unassigned. A secret configured for
two senders (or as a shared and a per-sender secret) authenticates nobody. `InboundEvent::$sender` carries the id to the map, so a map may keep per-sender
monitor ids.

## Ticket cap

`EngineConfig::$maxOpensPerHourPerClient` (default 300; unassigned counts as one client) and `$maxOpensPerHourAll` (default 600) bound the tickets opened
in an hour across every monitor and sender (CORE-API-5: 2000 DOWNs on fresh monitor ids used to open 2000 tickets). Past a cap nothing is dropped silently:
the first held-back DOWN opens one summary ticket ("Monitoring: ticket limit reached"), the next ones are listed on it (50 notes an hour, the rest counted),
and the summary is reused while nobody closed it. The counters are rows of the incident store with source `_cap` (keys `client:<id>`, `unassigned`,
`all`; label or hide them in an admin view); the engine takes their locks while it holds a monitor's lock, so `IncidentStoreInterface::locked()` must allow
nested locks on different keys (GET_LOCK does; `IncidentStoreConformanceTestCase` checks it). 0 turns a cap off.

## Tenant isolation: unmapped monitors are never guessed

`MonitorClientMapInterface::resolve()` returns exactly one `MonitorTarget` (a client and optionally an asset) or `null`. Lookup is by exact monitor id, then by exact tag
(`name` or `name:value`); the monitor name, URL and hostname are never used. If two matches point at different clients the map is ambiguous and must return `null`. On
`null` the engine uses `EngineConfig::$defaultTarget` (a client the admin chose), else `MonitorTarget::unassigned()`, which the edition files under an
internal/unassigned bucket and never under a customer. The ticket keeps its client even if the map changes during the incident.

## Wiring (edition endpoint)

<!-- skip: fragment of an edition-side example, needs a configured edition or live service -->
```php
$auth = new InboundAuthenticator([$settings['secret_current'], $settings['secret_previous']]);   // previous only during rotation; per-sender secrets: see "Senders"
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
| Sender limited to some clients reports another client's monitor | 403 `out_of_scope` |
| Body over 64 KB | 413 `too_large` |
| Not JSON, not an object, bad status/id/time/field | 400 with a fixed `error` token |
| Edition ticket system or store throws | 500 `internal` (state unchanged) |

Security notes: the secret travels in a header, so serve the endpoint over HTTPS only; do not log request bodies or headers at the web server or WAF for this path;
the endpoint is unauthenticated apart from the secret, so keep it rate limited and exclude it from any login redirect. A shared secret trusts its holder with
every client (see "Senders"): give a client-operated monitor a per-sender secret limited to that client.

No migration: the incident table belongs to the edition (SQL in the edition prompts).
