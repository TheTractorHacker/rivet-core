# Webhooks

RivetCore's webhook layer delivers events (a ticket was created, a backup failed, ...) to an HTTP endpoint. Core owns the
shared parts: the **event catalog**, the **destination presets**, the **payload formats**, **authentication headers**,
**signing** and the **URL policy**. The edition (RivetIT, RivetMSP) renders the settings UI and stores the subscriptions.

| Piece | Class | What it does |
|---|---|---|
| Events | `EventCatalog` | Searchable, grouped list of events with descriptions; `ticket.*` style patterns |
| Destinations | `Destinations` / `Destination` | Presets for n8n, Slack, ntfy, ... : format, URL shape, setup steps, sample curl |
| Formats | `PayloadFormatter`, `EventSummary` | Turns an event into the body a platform expects |
| Custom bodies | `PayloadTemplate` | Safe `{{placeholder}}` templates (no code execution) |
| Auth | `Authentication` | Bearer / basic / custom header for the receiver |
| Delivery | `WebhookDispatcher` | Signs, sends, logs every attempt; never throws |
| SSRF | `UrlPolicy`, `NetworkList` | Public addresses only, plus an admin-listed set of internal networks |

Per-platform setup guides are in [webhook-platforms.md](webhook-platforms.md) (generated from the catalog).

## The event envelope

The default format (`json`) is the generic envelope, byte-for-byte what RivetCore has always sent:

```json
{"event":"ticket.created","timestamp":"2026-01-02T03:04:05Z","data":{"ticket_id":1042,"ticket_number":"TCK-1042","ticket_subject":"Printer on floor 2 is offline"}}
```

`timestamp` is the moment the event was emitted (UTC). It stays the same on retries, so receivers can de-duplicate on
`event` + `timestamp` + a field of `data`.

## Choosing events

`EventCatalog::all()` lists every known event with `id`, `group`, `label`, `description`, `severity`, `payloadFields` and
search `tags`; `search()` ranks id and label prefixes first. A subscription may store a pattern: `*` is every event,
`ticket.*` every ticket event, `auth.login_*` the login events (`EventCatalog::matchPattern()` expands it). Events an
install records that are not in the catalog can be listed by the edition as "other events seen on this server"
(`EventCatalog::unknown()`). Events with `since: planned` are reserved names that the editions do not emit everywhere yet.

## Formats

| Format | Content-Type | Used by |
|---|---|---|
| `json` | application/json | n8n, Node-RED, Activepieces, Windmill, Zapier, Make, Pipedream, Home Assistant, Huginn, generic |
| `form` | application/x-www-form-urlencoded | generic form endpoints (keys such as `data.ticket_id`) |
| `slack` | application/json | Slack incoming webhook (Block Kit) |
| `slack_attachments` | application/json | Mattermost, Rocket.Chat (Slack-style attachments) |
| `teams` | application/json | Microsoft Teams Workflows webhook (Adaptive Card) |
| `discord` | application/json | Discord (content/embeds within its limits) |
| `ntfy` | text/plain | ntfy (Title, Priority, Tags, Click headers) |
| `gotify` | application/json | Gotify |
| `telegram` | application/json | Telegram Bot API (HTML parse mode) |
| `matrix`, `matrix_hookshot` | application/json | Matrix client API, matrix-hookshot |
| `apprise` | application/json | Apprise API |
| `template` | json, text or form | Your own body, see below |

All chat/notification formats escape user text for the platform (no mentions, links or markup from ticket subjects),
truncate to the platform's limits and never copy secret-looking keys (`password`, `token`, `api_key`, ...). The `form` and
`template` formats replace the value of such keys with `[redacted]`. The plain `json` envelope is sent as the event
provides it.

### Custom templates

```
{"title":"{{summary.title}}","text":"{{summary.summary|truncate:200}}","ticket":{{data.ticket_id|json}}}
```

- `{{data.ticket_id}}` reads a path (`data.items.0.name` for list items); a missing path is empty.
- Filters: `default:"x"`, `upper`, `lower`, `trim`, `truncate:N`, `json` (the value as a JSON literal; must be last).
- In a JSON template a plain `{{x}}` is escaped for the inside of a string, so put it between quotes; use `|json`
  (without quotes) for numbers, booleans, lists and objects. In `text` it is inserted as is; in `form` it is percent-encoded.
- Available names: `event`, `timestamp`, `data.*` and `summary.*` (`title`, `summary`, `url`, `severity`, `actor`, `client`, `fields`).
- No loops, conditions or function calls; `{{ system("id") }}` is rejected as an invalid placeholder. Limits: template 8 KB,
  output 64 KB, 100 placeholders. `PayloadTemplate::validate()` returns readable errors and renders a sample first.

## Authentication towards the receiver

`Authentication::headers()` builds `Authorization: Bearer ...`, `Authorization: Basic ...` or one custom header from the
destination config; `validate()` rejects reserved names (Host, Content-Length, Content-Type, Transfer-Encoding,
Connection, our `X-Rivet-*` and any `*-Signature` header) and line breaks; `redact()` gives a copy that is safe to show.
Mode `hmac` adds nothing: the signature headers below are sent on every request anyway.

## Signing

Every request carries:

| Header | Value |
|---|---|
| `X-Rivet-Timestamp` | Unix seconds when this **attempt** was signed |
| `X-Rivet-Signature-V2` | `t=<timestamp>,v1=<hex HMAC-SHA256 of "<timestamp>.<raw body>">` |
| `<Prefix>-Signature` | Legacy: `sha256=<hex HMAC-SHA256 of the raw body>`; the prefix is chosen by the edition (e.g. `X-RivetIT`) |
| `<Prefix>-Event` | The event type |

The key is the endpoint's secret. The signatures cover the **exact bytes sent**, whatever the format (so a Slack-shaped body
is signed as that body). Prefer V2: because the timestamp is signed, a captured request cannot be replayed later.

### Verifying a signature

1. Take the **raw** request body (before any JSON parsing).
2. Parse `X-Rivet-Signature-V2` into `t` and `v1`.
3. Reject if `|now - t|` is more than **300 seconds** (replay protection).
4. Compute HMAC-SHA256 over `t + "." + rawBody` with your secret and compare with `v1` in constant time.
5. Keep recent `event` + `timestamp` pairs for a few minutes to also drop exact duplicates.

Examples (the same snippets are in `Destination::$verifySnippets`):

**Node.js**

```js
const crypto = require('crypto');

// rawBody: the request body exactly as received (Buffer or string), NOT re-serialised JSON.
function verifyRivetSignature(rawBody, headers, secret, toleranceSeconds = 300) {
  const header = headers['x-rivet-signature-v2'] || '';
  const m = /^t=(\d+),v1=([0-9a-f]{64})$/.exec(header);
  if (!m) return false;
  if (Math.abs(Date.now() / 1000 - Number(m[1])) > toleranceSeconds) return false; // replay protection
  const signed = Buffer.concat([Buffer.from(m[1] + '.'), Buffer.from(rawBody)]);
  const expected = crypto.createHmac('sha256', secret).update(signed).digest();
  const got = Buffer.from(m[2], 'hex');
  return got.length === expected.length && crypto.timingSafeEqual(got, expected);
}
```

**Python**

```python
import hashlib, hmac, re, time

def verify_rivet_signature(raw_body: bytes, headers: dict, secret: str, tolerance: int = 300) -> bool:
    """raw_body: the request body exactly as received. headers: lower-cased header names."""
    m = re.fullmatch(r"t=(\d+),v1=([0-9a-f]{64})", headers.get("x-rivet-signature-v2", ""))
    if not m:
        return False
    if abs(time.time() - int(m.group(1))) > tolerance:  # replay protection
        return False
    expected = hmac.new(secret.encode(), m.group(1).encode() + b"." + raw_body, hashlib.sha256).hexdigest()
    return hmac.compare_digest(expected, m.group(2))
```

**PHP**

```php
function verifyRivetSignature(string $rawBody, string $header, string $secret, int $tolerance = 300): bool
{
    if (!preg_match('/^t=(\d+),v1=([0-9a-f]{64})$/', $header, $m)) {
        return false;
    }
    if (abs(time() - (int) $m[1]) > $tolerance) { // replay protection
        return false;
    }

    return hash_equals(hash_hmac('sha256', $m[1] . '.' . $rawBody, $secret), $m[2]);
}

// $ok = verifyRivetSignature(file_get_contents('php://input'), getallheaders()['X-Rivet-Signature-V2'] ?? '', $secret);
```

**Bash / openssl** (for testing; the string compare is not constant time)

```bash
#!/usr/bin/env bash
# usage: verify.sh '<X-Rivet-Signature-V2 header value>' body.bin '<secret>'
sig="$1"; file="$2"; secret="$3"
ts=$(sed -n 's/^t=\([0-9][0-9]*\),v1=[0-9a-f]\{64\}$/\1/p' <<<"$sig")
v1=$(sed -n 's/^t=[0-9]*,v1=\([0-9a-f]\{64\}\)$/\1/p' <<<"$sig")
[ -n "$ts" ] && [ -n "$v1" ] || { echo "malformed signature"; exit 1; }
age=$(( $(date +%s) - ts )); [ "${age#-}" -le 300 ] || { echo "stale timestamp"; exit 1; }   # replay protection
expected=$( { printf '%s.' "$ts"; cat "$file"; } | openssl dgst -sha256 -hmac "$secret" -hex | sed 's/^.* //')
[ "$expected" = "$v1" ] && echo ok || { echo "signature mismatch"; exit 1; }
```

**n8n Code node** (after a Webhook node with Options > Raw Body on; the n8n server needs `NODE_FUNCTION_ALLOW_BUILTIN=crypto`;
verify against the n8n docs for your version)

```js
// n8n Code node ("Run Once for All Items"), placed right after the Webhook node.
// Needs: Webhook node > Options > Raw Body (on), and NODE_FUNCTION_ALLOW_BUILTIN=crypto on the n8n server.
const crypto = require('crypto');
const secret = $env.RIVET_WEBHOOK_SECRET; // or paste it here; env access may need N8N_BLOCK_ENV_ACCESS_IN_NODE=false

const item = $input.first();
const header = item.json.headers['x-rivet-signature-v2'] || '';
const m = /^t=(\d+),v1=([0-9a-f]{64})$/.exec(header);
if (!m || Math.abs(Date.now() / 1000 - Number(m[1])) > 300) throw new Error('Rejected: bad or stale signature header');

const raw = await this.helpers.getBinaryDataBuffer(0, 'data'); // the raw request body
const expected = crypto.createHmac('sha256', secret)
  .update(Buffer.concat([Buffer.from(m[1] + '.'), raw])).digest();
const got = Buffer.from(m[2], 'hex');
if (got.length !== expected.length || !crypto.timingSafeEqual(got, expected)) throw new Error('Rejected: signature mismatch');

return $input.all();
```

Hosted platforms that cannot see the raw body (Zapier, Make, IFTTT) and chat services (Slack, Discord, ...) cannot verify
signatures: for those the URL is the secret, so keep it private.

## Delivery, retries and backoff

`WebhookDispatcher` makes one HTTP attempt per call and writes one row per attempt to `webhook_deliveries` (status,
duration, attempt number, a snippet of the response). It never throws: a webhook failure must not break the action that
caused it. Any **2xx** is success. Redirects are never followed, only http/https are spoken and TLS is always verified.
Timeout is 10 seconds.

Retries are the caller's job: editions queue a `webhook.deliver` job per endpoint (`JobQueue`), which retries a failed
attempt with backoff **1, 5, 30, then 120 minutes** and gives up (dead-letters) after the job's attempt limit (5 in the
editions). Retries re-send the **same body** (same legacy signature) with a **fresh timestamp** and signature V2, so
receivers should treat deliveries as at-least-once and de-duplicate. If the endpoint was deleted or disabled in the
meantime the job stops. Answer quickly with 2xx and do the work asynchronously; slow responses count as failures.

Per-endpoint options (`WebhookSubscription::$options`, or the `$options` argument of `deliverTo()`):

| Option | Meaning |
|---|---|
| `format` | One of the formats above (default: the plain envelope) |
| `format_options` | Formatter options: `chat_id`, `username`, `ntfy_priority`, `ntfy_tags`, `app_name`, `link_url`, `test`, `template` ... |
| `template`, `template_encoding` | Shortcut for the `template` format |
| `method` | `POST` (default) or `PUT` |
| `extraHeaders` | Header map, usually `Authentication::headers()` plus the destination's static headers |

Extra headers are validated on every send: a bad name, a CR/LF/NUL in a value, `Host`, `Content-Length`, `Content-Type`,
`Transfer-Encoding`, `Connection` and our own signature/timestamp/event headers are refused and the attempt fails without
contacting the endpoint (logged as "invalid extra header"). A URL containing `{txn}` (Matrix) gets a per-message id derived
from the body, so a retry reuses it.

## URL policy and internal networks

Endpoint URLs are vetted by `UrlPolicy` on every attempt and the connection is pinned to the vetted addresses (defeats
DNS rebinding). By default only **public** addresses are allowed: loopback, private ranges (10/8, 172.16/12, 192.168/16,
fc00::/7), link-local and cloud-metadata (169.254/16), CGNAT, multicast and reserved ranges are refused, and every
address a name resolves to must pass.

Many of the platforms in [webhook-platforms.md](webhook-platforms.md) (n8n, Node-RED, Home Assistant, Gotify, ...) run on
the same LAN as the helpdesk. An administrator can list the specific networks webhooks may reach
(`UrlPolicy`'s `allowedNetworks`, validated by `NetworkList::parse()`; `LocalNetworks::detect()` suggests the server's
own). Only private ranges can be listed (at most 16 entries, no broader than /8 for IPv4 or /48 for IPv6); loopback,
link-local/metadata and multicast can never be allowed, listed or not. Listing a network is a security decision: anything on it
can then be a webhook target.

## Platform contracts we were unsure about

Anything marked "verify" in the platform guides is written from public documentation that changes often (n8n Code-node
crypto access, Activepieces URL shape, Windmill argument mapping, ntfy encoded-word headers and rate limits, Zapier and
Make plan limits, Teams size limits, hookshot's default transformation, Node-RED raw bodies). Check the linked vendor
documentation before relying on them.
