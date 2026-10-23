# RivetPanel device-token API

The server side of **RivetPanel**, the desktop helper that lets a person at a managed PC open a ticket and chat with the technician. It replaces the
legacy shared `X-Api-Key` (which acts as the first administrator and trusts a `client_id`/`contact_id` in the body) with a **per-device token** minted
through the RMM enrollment mechanism. Machine-readable form: [openapi-panel.yaml](openapi-panel.yaml).

- Core: `RivetCore\Panel` (`src/Panel`). Framework-agnostic handlers (`Panel\Http\PanelApi`) over the same neutral `Rmm\Http\RmmRequest` / `RmmResponse` the RMM
  device API uses. Editions add thin `api/v1/panel_*.php` wrappers and implement `Panel\Contracts\PanelTicketGatewayInterface`.
- The RMM device protocol is **unchanged**. This API adds nothing to it and an old agent behaves exactly as before.

## Endpoints

All under `/api/v1`, TLS only, JSON in and out, `Cache-Control: no-store`. The panel routes are logical names; an edition maps them to files and may
accept the ticket id as a path segment (`/tickets/{id}/chat`) or as `?ticket_id=`.

| Logical route | `RmmRequest::$endpoint` | Methods | Auth | Purpose |
|---|---|---|---|---|
| `/panel_enroll` | `panel_enroll` | POST | enrollment token in the body | exchange the token for a device credential |
| `/panel_me` | `panel_me` | GET | device | who am I, may I create tickets, limits |
| `/panel_categories` | `panel_categories` | GET | device | categories of my client |
| `/panel_tickets` (`/tickets`) | `panel_tickets` | GET, POST | device | list / create |
| `/panel_ticket_chat` (`/tickets/{id}/chat`) | `panel_ticket_chat` | GET, POST | device | read / post messages of one ticket |

`panel_me` is an addition to the minimum set (tickets, chat, categories): the panel needs it to know, before it shows a form, whether this device may create tickets.

## Authentication and token kinds

1. An administrator mints a **panel enrollment token** for a client and optionally a contact (`PanelEnrollment::createToken`). Shape `rvtp1.<12 hex>.<40 hex>`,
   single use, 24 h by default (72 h at most), stored as a SHA-256 in `endpoint_agent_enrollment_tokens` with `kind = 'panel'` and the `contact_id`.
2. The panel calls `POST /panel_enroll` and receives a **device credential** `rvpd_<64 hex>`, shown once. Only its SHA-256 is stored, in
   `endpoint_agent_devices` with `kind = 'panel'`, `link_state = 'panel'`, no asset, and the token's client and contact. Valid 365 days.
3. Every other call sends `Authorization: Bearer rvpd_...`. The server looks the credential up, requires `kind = 'panel'`, refuses revoked/retired/expired
   devices, and (for a device with a contact) re-checks through the gateway that the contact is still an active contact of that client.

**Kind isolation (both ways).** An RMM credential is 64 alphanumerics, a panel credential is `rvpd_` + 64 hex, so neither parses as the other; independently of the
shape, `DeviceRepository::authenticate()` refuses a row whose kind is not `agent` and `PanelAuthenticator` refuses one whose kind is not `panel`. The same holds for
enrollment tokens: the RMM enrollment (`rvte1.`) and installer download refuse a token whose kind is not `agent`, and `panel_enroll` refuses an RMM token. A panel
install id cannot be taken over by an RMM enrollment and the other way round (409 `conflict`). Panel devices do not count towards the installation's
`max_devices`, are never linked to an asset and are skipped by every RMM automation (those require `link_state = 'linked'`).

**Identity comes only from the credential.** `client_id` and `contact_id` (in the body, the query, or anywhere else) are never read. A device cannot name another
client or another person.

## Who may do what

| Device | Categories | List / create tickets | Read / post chat |
|---|---|---|---|
| with a contact | yes | tickets of that contact **plus** the ones this device created, in its own client | the same tickets |
| client-level (no contact), client setting **on** | yes | only the tickets this device created | only those |
| client-level, client setting **off** (default) | yes | 403 `client_level_disabled` | 403 `client_level_disabled` |

The client setting is "allow tickets from client-level devices" (`PanelSettings`, table `panel_client_settings`, default OFF). A ticket outside the device's scope,
in another client, or that does not exist is the **same** 404 `not_found` (ids cannot be probed). An administrator revokes a panel device with the existing RMM
device actions (revoke, allow re-enrollment, rotate credential, retire) or `PanelAdmin::revokeDevice()`; the next call answers 401 `revoked`.

For a client-level device the OS user name may be sent as `display_name`. It is an **unverified hint** passed to the gateway as `requesterHint`; it never selects a
contact and never grants anything.

## Requests and responses

Every successful answer and every error includes `"server_time"` (RFC 3339 UTC). Nothing the panel sends is a timestamp, so there is no skew rule to violate: the RMM
`collected_at in the future` check does not exist here, and a clock-skewed PC is never refused for being skewed. The panel can compare `server_time` with its own clock.

### POST /panel_enroll

```json
{"enrollment_token": "rvtp1.0a1b2c3d4e5f.<40 hex>",
 "device": {"install_id": "6f1d3c52-9b7e-4c1a-8d2f-0a1b2c3d4e5f", "hostname": "JANE-LAPTOP", "os": "windows", "os_version": "Windows 11 23H2", "app_version": "0.3.1"}}
```

201:

```json
{"device_id": 192, "device_token": "rvpd_<64 hex>",
 "client": {"client_id": 1, "name": "Dept A"},
 "contact": {"contact_id": 1000, "name": "Jane Doe"},
 "can_create_tickets": true,
 "limits": {"subject_max": 200, "ticket_body_max": 20000, "chat_body_max": 5000, "attachments_max": 3, "attachment_bytes_max": 5242880,
            "attachments_total_max": 10485760, "attachment_extensions": ["png","jpg","jpeg","gif","webp","pdf","txt","log","csv"]},
 "server_time": "2026-10-23T17:57:22Z"}
```

`contact` is `null` for a client-level device. Enrolling again with the same `install_id` and a new token keeps `device_id` and rotates the credential (the old one stops
working at once). Failures: 401 `invalid_token` / `revoked` / `expired` (token), 403 `forbidden` (token used up; device revoked or retired until an administrator allows
re-enrollment), 403 `contact_invalid` (the contact the token was issued for is gone; the token is not consumed), 409 `conflict`, 422 `invalid`, 429 `rate_limited`.

### GET /panel_me

Same shape as the enrollment answer without the token, plus `can_view_tickets`.

### GET /panel_categories

```json
{"categories": [{"category_id": 1001, "name": "Hardware"}], "server_time": "2026-10-23T17:57:22Z"}
```

### GET /panel_tickets?limit=20&offset=0

```json
{"tickets": [{"ticket_id": 1002, "subject": "Printer jammed", "status": "Open", "closed": false, "category_id": null,
              "created_at": "2026-10-01T09:00:00Z", "updated_at": "2026-10-01T10:00:00Z", "mine": true}],
 "limit": 20, "offset": 0, "has_more": false, "server_time": "2026-10-23T17:57:22Z"}
```

`limit` is clamped to 1..50 (default 20); `offset` to 0..100000; a non-integer is 422. `mine` is true for a ticket this device created.

### POST /panel_tickets

```json
{"subject": "Printer jammed", "body": "Tray 2 shows an error", "category_id": 1001, "display_name": "jsmith",
 "client_request_id": "11111111-2222-4333-8444-555555555555",
 "attachments": [{"filename": "screen.png", "content_type": "image/png", "data_base64": "iVBORw0KGgo..."}]}
```

201 `{"ticket_id": 1002, "replayed": false, "server_time": "..."}`. A repeat of the same `client_request_id` from the same device answers **200** with the first ticket
and `"replayed": true` (a safe retry after a dropped connection). `category_id` must be one of this client's categories. Limits: subject 200 characters, body 20000,
`display_name` 100, 50 tickets per device per 24 h, 10 per minute.

### GET /panel_ticket_chat?ticket_id=1002&after=0&limit=50

```json
{"ticket": {"ticket_id": 1002, "subject": "Printer jammed", "status": "Open", "closed": false},
 "messages": [{"message_id": 1003, "from": "you", "author": "You", "body": "Tray 2 shows an error", "created_at": "2026-10-01T09:00:00Z", "attachments": []},
              {"message_id": 1004, "from": "staff", "author": "Tom", "body": "Hi Jane, we are on it.", "created_at": "2026-10-01T10:00:00Z", "attachments": []}],
 "next_after": 1004, "server_time": "2026-10-23T17:57:22Z"}
```

Oldest first. Poll with `after` = the last `next_after`. Internal notes are never returned. Attachments are listed as metadata (`filename`, `content_type`, `size`); there is no
download endpoint in this version.

### POST /panel_ticket_chat?ticket_id=1002

`{"body": "Thanks!", "display_name": "jsmith", "attachments": [...]}` -> 201 `{"ticket_id": 1002, "message_id": 1005, "server_time": "..."}`. Body limit 5000 characters;
20 per minute. A closed ticket is 409 `ticket_closed` (it can still be read).

## Attachments

At most 3 per request, 5 MiB each, 10 MiB together (configurable through `PanelLimits`, with hard ceilings). Sent as base64 inside the JSON body, so the request
body cap is derived from those limits (`PanelLimits::postBodyMax()`, about 14 MiB by default): make sure the edition's web server and PHP allow it for the panel routes.
Every check is on the server and none trusts the client:

- the extension must be on the allow-list (default `png jpg jpeg gif webp pdf txt log csv`; configuration can only narrow the built-in table) **and** no later dot-separated
  part of the name may be an executable, script or markup extension (`report.exe.png` is refused),
- the declared content type must be the one that extension maps to,
- the **magic bytes** must match (PNG, JPEG, GIF, WebP, PDF); text files must be valid UTF-8 without NUL or control characters, must not start with `#!`, and must not
  contain HTML/script markup near the start,
- executables are recognised by content under any name (MZ, ELF, Mach-O, shebang), and ZIP and OLE containers (so Office documents, jar, apk, msi) are not accepted,
- the stored filename is a sanitised basename (letters, digits, `. - _ ( ) [ ]` and space, at most 100 characters); the original name is never used as a path.

A rejected attachment answers 422 `attachment_rejected` (or 413 `too_large`) and creates nothing.

## Text handling

Input (subject, body, names, filenames): must be UTF-8; NUL, control characters, bidirectional overrides/isolates/marks, zero-width characters and line/paragraph
separators are removed; the length is checked in characters; markup is kept as typed (stored as plain text; the edition's renderer must escape it).
Output (every string the API returns): entities are decoded once, tags are stripped, the same invisible characters are removed and the two markup characters left are
returned as `&lt;` and `&gt;`. A client renders the value as text, decoding exactly those two entities; even an `innerHTML` mistake is inert.

## Rate limits

Per device, through the edition's limiter closure (the same shape and Redis `RateLimiter` the RMM API uses), buckets `panel_<kind>:<device_id>`:

| Bucket | Limit | Used by |
|---|---|---|
| `panel_read` | 120 per 60 s | me, categories, ticket list, chat read |
| `panel_create` | 10 per 60 s (and 50 per 24 h, `PanelLimits::$ticketsPerDayMax`) | create ticket |
| `panel_chat` | 20 per 60 s | chat post |
| `panel_authfail:<sha1 address>` | 30 per 60 s | failed credential checks per address (a valid call is not counted) |

Enrollment is limited per address from the database (shared attempt log, salt `panel-enroll|`): 10 failures or 60 attempts per 600 s, then 429 with `Retry-After: 600`.
429 always carries `Retry-After`.

## Error codes

`{"error": "...", "code": "...", "server_time": "..."}`; the `code` is the contract.

| HTTP | code | Meaning |
|---|---|---|
| 401 | `invalid_token` | unknown, rotated, malformed, or not a panel credential / enrollment token |
| 401 | `revoked` | the device or enrollment token was revoked (or the device retired) |
| 401 | `expired` | the credential (365 d) or the enrollment token expired |
| 403 | `forbidden` | enrollment token used up; device revoked/retired and not yet allowed to re-enroll |
| 403 | `contact_invalid` | the contact this device belongs to was archived, deleted or moved to another client |
| 403 | `client_level_disabled` | a client-level device while the client's setting is off |
| 404 | `not_found` | unknown route, or a ticket that is missing **or outside this device's scope** (same answer) |
| 405 | `method_not_allowed` | `Allow` lists the right methods |
| 409 | `conflict` | the install id belongs to an RMM agent or another client |
| 409 | `ticket_closed` | posting to a closed ticket |
| 413 | `too_large` | body or attachment over the cap |
| 422 | `invalid` | a field is missing, mistyped, too long or not UTF-8 |
| 422 | `attachment_rejected` | extension, type, magic bytes, executable content or count not accepted |
| 426 | `tls_required` | plain HTTP |
| 429 | `rate_limited` | over a budget; `Retry-After` |
| 500 | `internal` | generic; the detail goes to the PHP log only |
| 503 | `module_disabled` | the edition switched the panel service off; `Retry-After: 3600` |

## Audit

Through the existing `RmmAuditInterface` (an edition maps it to its audit trail / `AuditChain`): `Panel Enrollment Token Created`, `Panel Enrollment Token Revoked`,
`Panel Enrolled`, `Panel Enrollment Failed`, `Panel Ticket Created` (client, ticket id, device, contact or client-level, attachment count), `Panel Chat Posted`,
`Panel Device Revoked` (also written by the RMM revoke action when the row is a panel device), `Panel Setting Changed`. Ticket text, tokens and secrets are never put in an audit description.

## Threat model

| Threat | Control |
|---|---|
| Stolen or guessed enrollment token | 160-bit secret, SHA-256 at rest, single use (atomic `UPDATE ... use_count < max_uses`), short TTL, revocable, constant-time compare, per-address attempt limit |
| Stolen device credential | 256 bits, SHA-256 at rest, scoped to ONE client and at most one contact, no admin/technician right, revocable at once, expires after 365 d, per-device rate limits |
| Client or contact spoofing | identity is read from the credential only; body/query values ignored |
| Cross-client or cross-contact ticket access | every ticket is checked against the device's client and (contact match OR created by this device) in Core, after the gateway answers; a missing and a forbidden ticket are indistinguishable |
| Using a panel token against the RMM API (command execution, jobs) or the reverse | different token shapes, `kind` checked on both sides, install ids and enrollment tokens not interchangeable |
| Enumeration / brute force | 256-bit credentials, failure rate limit per address, uniform errors |
| Malicious attachment | allow-list, magic bytes, executable and archive refusal, size and count caps, sanitised filename, never executed or served by Core |
| Stored/reflected XSS, bidi spoofing | input stripped of invisible/bidi characters, output stripped of tags, `<` `>` entity-encoded |
| Flooding / ticket spam | per-minute and per-day caps per device, body caps, pagination caps; an administrator can revoke the device |
| Replay of a create after a dropped connection | `client_request_id` idempotency per device |
| Leaver or moved contact | contact re-validated on every request (`contact_invalid`) |
| Clock-skewed PC | no timestamps are accepted; `server_time` is returned |
| Information leak in errors | generic 500, no stack traces, no internal notes, no other clients' names |

Not covered here (edition responsibility): TLS termination and the trusted-proxy decision behind `RmmRequest::$secureTransport`, the web-server body-size limit for the
panel routes, how the gateway escapes plain text into its own HTML, retention of the stored attachments, and malware scanning of attachments if wanted.

## What an edition implements

See [UPGRADING.md](../../UPGRADING.md) ("Adopting the RivetPanel API"): the `PanelTicketGatewayInterface` adapter (and the `PanelTicketGatewayConformanceTestCase` kit),
the thin `api/v1/panel_*.php` wrappers, migration 0040 and the mirrored DDL (`Panel\Migration\PanelSchema`), the Deploy page that mints the enrollment token with an
optional contact, and the per-client "allow tickets from client-level devices" setting.
