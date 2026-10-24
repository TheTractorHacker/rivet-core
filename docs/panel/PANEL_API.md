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
   single use by default, 24 h by default (72 h at most), stored as a SHA-256 in `endpoint_agent_enrollment_tokens` with `kind = 'panel'` and the `contact_id`.
   For an RMM mass deployment a capped multi-use **fleet token** can be minted (`$maxUses` > 1, up to `PanelLimits::$enrollMaxUses` devices). Since 1.0.0-rc.38 a fleet token
   is **client-level only** (a contact together with `$maxUses` > 1 throws `InvalidArgumentException`: every machine would be bound to the same person, and a leaked token
   would open that person's tickets), lives **72 h at most whatever `enrollMaxTtlH` is**, and the `Panel Enrolled` audit row of each machine says `fleet token: device N of M`.
2. The panel calls `POST /panel_enroll` and receives a **device credential** `rvpd_<64 hex>`, shown once. Only its SHA-256 is stored, in
   `endpoint_agent_devices` with `kind = 'panel'`, `link_state = 'panel'`, no asset, and the token's client and contact. Valid 365 days.
3. Every other call sends `Authorization: Bearer rvpd_...`. The server looks the credential up, requires `kind = 'panel'`, refuses revoked/retired/expired
   devices, re-checks that the client still exists (403 `client_invalid` when the edition's `clientName()` is null: deleted or archived) and, for a device with a contact,
   re-checks through the gateway that the contact is still an active contact of that client.

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

**Ownership is the pair (device, contact)** (1.0.0-rc.38). A ticket a device created is recorded with the contact the device was bound to at that moment, and it is visible
to that device only while the device is bound to that same contact (or, for a client-level ticket, while it is client-level). A PC that is re-enrolled for another person
(same `install_id`, a token for another contact), or between client level and a contact, does **not** see the previous person's tickets or chat, and cannot post into
them; the list is empty and the ticket answers 404 `not_found`. Nothing is deleted: the ledger rows stay, and re-enrolling for the original contact brings the tickets back.
The daily ticket count stays per device, so changing identity does not reset it. A `client_request_id` used under the old identity answers 409 `conflict` to the new one
(an id is never replayed across identities).

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

`limit` is clamped to 1..50 (default 20); `offset` to 0..100000; a non-integer is 422. `mine` is true for a ticket this device created. The device-created set is the device's 500 most recent tickets (`PanelLimits::$ownedWindow`); a device with a contact also sees every ticket of its contact, whatever its age.

### POST /panel_tickets

```json
{"subject": "Printer jammed", "body": "Tray 2 shows an error", "category_id": 1001, "display_name": "jsmith",
 "client_request_id": "11111111-2222-4333-8444-555555555555",
 "attachments": [{"filename": "screen.png", "content_type": "image/png", "data_base64": "iVBORw0KGgo..."}]}
```

201 `{"ticket_id": 1002, "replayed": false, "server_time": "..."}`. `category_id` must be one of this client's categories. Limits: subject 200 characters, body 20000,
`display_name` 100, 50 tickets per device per 24 h, 10 per minute.

**Idempotent creation (`client_request_id`).** Send a fresh UUID per user action and reuse it for every retry of that action. Core makes the guarantee itself, whatever the
edition's gateway does: before the gateway is called it reserves `(device, client_request_id)` in `panel_ticket_owner` under a unique key, so of any number of
concurrent or repeated requests with the same id at most one reaches the gateway and at most one ticket exists. What a caller sees:

| Situation | Answer |
|---|---|
| First request | 201, `replayed: false` |
| Retry after the first succeeded | **200**, the first ticket, `replayed: true` (no gateway call, still allowed when the daily cap is reached) |
| Retry while the first is still running | **409** `request_in_progress`, `Retry-After: 2`; retry shortly and you get the 200 above |
| First request failed (gateway error, 5xx) | the reservation is released; the retry is a fresh attempt and creates the ticket |
| First request died after reserving (process killed) | the retry gets 409 `request_in_progress` for up to 300 s, then takes the reservation over and creates the ticket |
| Same id from another device | independent: ids are scoped per device |
| No `client_request_id` | every request creates a ticket; nothing is merged |

Residual case: a gateway call that takes longer than 300 s can be overtaken by a retry and produce two tickets; Core records both for the device and writes a
`Panel Ticket Duplicate` audit entry. Reservations count towards the 50-per-day cap while they exist.

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
20 per minute, and a **daily budget per device** (1.0.0-rc.38, `PanelLimits::$chatPostsPerDayMax` = 500 posts and `$chatBytesPerDayMax` = 50 MiB of text plus decoded attachments, per UTC day).
The call that would pass either gets 429 `rate_limited` with `Retry-After` set to the seconds until the next UTC midnight, **before** the gateway is called; a post that fails
(validation, closed ticket, gateway error) does not spend any of it. The first refusal of each day writes one `Panel Chat Limit Reached` audit row. A closed ticket is 409 `ticket_closed` (it can still be read).

## Attachments

At most 3 per request, 5 MiB each, 10 MiB together (configurable through `PanelLimits`, with hard ceilings). Sent as base64 inside the JSON body, so the request
body cap is derived from those limits (`PanelLimits::postBodyMax()`, about 14 MiB by default): make sure the edition's web server and PHP allow it for the panel routes.
Every check is on the server and none trusts the client:

- the extension must be on the allow-list (default `png jpg jpeg gif webp pdf txt log csv`; configuration can only narrow the built-in table) **and** no later dot-separated
  part of the name may be an executable, script or markup extension (`report.exe.png` is refused),
- the declared content type must be the one that extension maps to,
- the **magic bytes** must match (PNG, JPEG, GIF, WebP, PDF); text files (`txt`, `log`, `csv`) must be valid UTF-8 without NUL or control characters, must not start with `#!`, and must not
  contain HTML/script markup **anywhere** (1.0.0-rc.38; it used to be the first 8 KiB),
- a text file must not **begin with a spreadsheet formula character** (`=`, `+`, `-`, `@`, a tab or a carriage return, after an optional byte-order mark), and a CSV must not
  have a cell that starts with `=` or looks like a function or DDE call (`+cmd|' /C calc'!A0`, `@SUM(`) (1.0.0-rc.38; a cell that merely contains `=` or `|`, or a negative number, is fine),
- executables are recognised by content under any name (MZ, ELF, Mach-O, shebang), and ZIP and OLE containers (so Office documents, jar, apk, msi) are not accepted,
- the stored filename is a sanitised basename (letters, digits, `. - _ ( ) [ ]` and space, at most 100 characters); the original name is never used as a path. A Windows
  device name (`CON`, `PRN`, `AUX`, `NUL`, `COM1`..`COM9`, `LPT1`..`LPT9`, with or without an extension) and a name that starts with `-` are refused (1.0.0-rc.38).

A rejected attachment answers 422 `attachment_rejected` (or 413 `too_large`) and creates nothing.

### Serving attachments (what the gateway must do)

Core never writes or serves a file; the gateway stores the bytes. Core checks the type but cannot look inside a PNG, GIF or PDF for active content (`GIF89a<script>`, a PDF with
`/OpenAction /JavaScript` pass the magic-byte check). So the gateway must (1) store the bytes **outside the web root**, under a name it generates, (2) serve them only to an
authorized staff user, as a **download**: `Content-Disposition: attachment`, `Content-Type` from its own table (never from the upload), `X-Content-Type-Options: nosniff`, and
ideally from a cookie-less origin, never inline from the application origin, and (3) remember that a CSV opened in a spreadsheet is the remaining risk Core reduces but cannot remove.

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
| `panel_chat` | 20 per 60 s (and 500 posts / 50 MiB per UTC day, `PanelLimits::$chatPostsPerDayMax` / `$chatBytesPerDayMax`, kept in `rmm_daily_budget`) | chat post |
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
| 403 | `client_invalid` | the client of this device no longer exists for the edition (deleted or archived) |
| 403 | `client_level_disabled` | a client-level device while the client's setting is off |
| 404 | `not_found` | unknown route, or a ticket that is missing **or outside this device's scope** (same answer) |
| 405 | `method_not_allowed` | `Allow` lists the right methods |
| 409 | `conflict` | the install id belongs to an RMM agent or another client |
| 409 | `ticket_closed` | posting to a closed ticket |
| 409 | `request_in_progress` | a create with the same `client_request_id` is still running; `Retry-After: 2` |
| 413 | `too_large` | body or attachment over the cap |
| 422 | `invalid` | a field is missing, mistyped, too long or not UTF-8 |
| 422 | `attachment_rejected` | extension, type, magic bytes, executable content or count not accepted |
| 426 | `tls_required` | plain HTTP |
| 429 | `rate_limited` | over a budget; `Retry-After` |
| 500 | `internal` | generic; the detail goes to the PHP log only |
| 503 | `module_disabled` | the edition switched the panel service off; `Retry-After: 3600` |

## Audit

Through the existing `RmmAuditInterface` (an edition maps it to its audit trail / `AuditChain`): `Panel Enrollment Token Created`, `Panel Enrollment Token Revoked`,
`Panel Enrolled`, `Panel Enrollment Failed`, `Panel Ticket Created` (client, ticket id, device, contact or client-level, attachment count), `Panel Chat Posted`, `Panel Chat Limit Reached` (once per device and day), `Panel Ticket Duplicate` (rare: a create outlived its 300 s reservation),
`Panel Device Revoked` (also written by the RMM revoke action when the row is a panel device), `Panel Setting Changed`. Ticket text, tokens and secrets are never put in an audit description.

## Threat model

| Threat | Control |
|---|---|
| Stolen or guessed enrollment token | 160-bit secret, SHA-256 at rest, single use (atomic `UPDATE ... use_count < max_uses`), short TTL, revocable, constant-time compare, per-address attempt limit |
| Stolen device credential | 256 bits, SHA-256 at rest, scoped to ONE client and at most one contact, no admin/technician right, revocable at once, expires after 365 d, per-device rate limits |
| Client or contact spoofing | identity is read from the credential only; body/query values ignored |
| Cross-client or cross-contact ticket access | every ticket is checked against the device's client and (contact match OR created by this device **as the contact it is bound to now**) in Core, after the gateway answers; a missing and a forbidden ticket are indistinguishable |
| A PC handed to another person (re-enrolled with the same install id) | the ledger is keyed to (device, contact): the new person sees none of the previous person's tickets or chat |
| One enrolled device filling storage or the audit log through chat | per-minute limit, a per-device daily budget in posts and bytes charged before the gateway is called, one audit row per day for the refusals |
| A leaked fleet token | client-level only, 72 h at most, capped uses, each device individually revocable and audited with its position in the count |
| Using a panel token against the RMM API (command execution, jobs) or the reverse | different token shapes, `kind` checked on both sides, install ids and enrollment tokens not interchangeable |
| Enumeration / brute force | 256-bit credentials, failure rate limit per address, uniform errors |
| Malicious attachment | allow-list, magic bytes, executable and archive refusal, size and count caps, sanitised filename, never executed or served by Core |
| Stored/reflected XSS, bidi spoofing | input stripped of invisible/bidi characters, output stripped of tags, `<` `>` entity-encoded |
| Flooding / ticket spam | per-minute and per-day caps per device (tickets, and chat posts and bytes), body caps, pagination caps; an administrator can revoke the device |
| Replay of a create after a dropped connection, or two concurrent retries | `client_request_id` reserved under a unique key before the gateway call: one ticket, however the retries interleave |
| Leaver or moved contact | contact re-validated on every request (`contact_invalid`) |
| Clock-skewed PC | no timestamps are accepted; `server_time` is returned |
| Information leak in errors | generic 500, no stack traces, no internal notes, no other clients' names |

Not covered here (edition responsibility): TLS termination and the trusted-proxy decision behind `RmmRequest::$secureTransport`, the web-server body-size limit for the
panel routes, how the gateway escapes plain text into its own HTML, retention of the stored attachments, and malware scanning of attachments if wanted.

## Time stamps

Every `created_at` / `updated_at` the gateway returns must be **UTC**, written `Y-m-d H:i:s` or as ISO 8601 ending in `Z`; Core turns them into RFC 3339 `Z` for the panel.
A gateway that stores local time and labels it UTC, or applies the UTC offset twice, makes the panel show tickets hours in the future or the past. Since 1.0.0-rc.38 the
conformance kit checks it: a ticket or message created a moment ago must be within 120 s of `PanelTicketGatewayConformanceTestCase::conformanceNow()` (the real clock unless the edition
overrides it with its test clock).

## What an edition implements

See [UPGRADING.md](../../UPGRADING.md) ("Adopting the RivetPanel API"): the `PanelTicketGatewayInterface` adapter (and the `PanelTicketGatewayConformanceTestCase` kit),
the thin `api/v1/panel_*.php` wrappers, migrations 0040 and 0041 and the mirrored DDL (`Panel\Migration\PanelSchema`, `Rmm\Migration\BudgetSchema`), the Deploy page that mints the enrollment token with an
optional contact, and the per-client "allow tickets from client-level devices" setting.
