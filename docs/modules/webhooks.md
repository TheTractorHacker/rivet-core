# Webhooks

Namespace `RivetCore\Webhooks` (plus `RivetCore\Support\LocalNetworks`). This page is the module reference. Signing,
verification, replay protection and retry guidance for receivers are in [../webhooks.md](../webhooks.md); per-platform
setup guides are in [../webhook-platforms.md](../webhook-platforms.md). Neither is repeated here.

## Overview

The module owns one table, `webhook_deliveries` (migration `0005_webhook_deliveries`): one row per delivery attempt with
`webhook_id`, `event_type`, `http_status`, `duration_ms`, `attempt_number`, `request_payload_json` (the exact bytes sent)
and `response_body_snippet` (first 1000 characters of the response, or the error text). The edition-owned `webhooks`
table (endpoints, secrets) is not touched by Core.

Everything else is code and data: the delivery engine (`WebhookDispatcher`), the SSRF guard (`UrlPolicy`, `NetworkList`,
`Support\LocalNetworks`), body formats (`PayloadFormatter`, `EventSummary`, `PayloadTemplate`), receiver authentication
(`Authentication`), and two catalogs for the settings UI (`EventCatalog`, `Destinations`).

## Contracts an edition must implement

`WebhookSubscriptionsInterface` (required):

- `forEvent(string $eventType): array` returns `list<WebhookSubscription>`: only enabled endpoints subscribed to that
  event. Expand wildcard subscriptions (`ticket.*`, see `EventCatalog::matchPattern()`) in your own query.

`WebhookSubscriptionLookupInterface` (optional companion, required for `deliverTo()`):

- `find(int $webhookId): ?WebhookSubscription` returns the endpoint, or `null` when it was deleted or disabled. Retried
  jobs use this, so a deleted endpoint stops being retried.

`WebhookSubscription` is a readonly value object: `webhookId`, `url`, `secret` (already decrypted by the edition; it is
the HMAC key), and `options` (`array<string,mixed>`, may hold `format`, `format_options`, `template`,
`template_encoding`, `method`, `extraHeaders`). Build `extraHeaders` from `Authentication::headers()` plus any
`Destination::$headers`.

The edition also supplies a `DatabaseInterface`, a `ClockInterface`, and decides which header prefixes it sends
(`['X-ITFlow', 'X-RivetIT']`), so existing receivers keep verifying.

## Key classes

### WebhookDispatcher

Constructor: `(DatabaseInterface, WebhookSubscriptionsInterface, ClockInterface, array $headerPrefixes = ['X-RivetCore'],
?\Closure $transport = null, int $timeoutSeconds = 10, ?UrlPolicy $urlPolicy = null, bool $requireUrlPolicy = false)`.

- `deliver($eventType, $payload)` fires at every subscriber from `forEvent()`, one attempt each, in the request.
- `deliverTo($webhookId, $eventType, $payload, $attempt = 1, $emittedAt = null, $signedAt = null, $options = null)`
  delivers to one endpoint (for a retryable job). Pass the first attempt's `$emittedAt` (ISO-8601 UTC) on every retry so
  the body stays byte-identical; pass `$signedAt = time()` so the V2 timestamp is fresh on each retry. Returns
  `{webhook_id, http_status, duration_ms, error, ok}`; when the endpoint no longer exists it also returns `gone => true`.
- `pinnedUrl($url, $target)` (`@internal`, not part of the semver promise; public so tests can assert pinning) rewrites the URL host to the vetted host so a trailing-dot spelling cannot bypass the DNS pin.
- `curlOptions(...)` (`@internal`, public only for tests) returns the curl options (pinning, no redirects, TLS verify, no proxy when pinned); do not
  call it from edition code.
- `signatureV2($timestamp, $body, $secret)` returns the `X-Rivet-Signature-V2` value.

```php
use RivetCore\Support\SystemClock;
use RivetCore\Webhooks\{WebhookDispatcher, WebhookSubscription, WebhookSubscriptionsInterface, WebhookSubscriptionLookupInterface, UrlPolicy};

final class MySubs implements WebhookSubscriptionsInterface, WebhookSubscriptionLookupInterface
{
    public function forEvent(string $eventType): array { return [$this->find(7)]; }
    public function find(int $id): ?WebhookSubscription
    {
        return $id === 7 ? new WebhookSubscription(7, 'https://hooks.example.com/in', 'secret', ['format' => 'slack']) : null;
    }
}

$dispatcher = new WebhookDispatcher($db, new MySubs(), new SystemClock(), ['X-RivetIT'], null, 10, new UrlPolicy(), true);
$r = $dispatcher->deliverTo(7, 'ticket.created', ['ticket_id' => 1, 'ticket_subject' => 'Printer'], 1, '2026-01-02T03:04:05Z', time());
// $r['ok'] === true on a 2xx answer
```

Every request carries `Content-Type`, `X-Rivet-Timestamp`, `X-Rivet-Signature-V2` (`t=<ts>,v1=<hmac-sha256 of "<ts>.<body>">`),
and for each configured prefix `<prefix>-Signature` (legacy V1, `sha256=` HMAC of the body only, no timestamp) and
`<prefix>-Event`. Both signatures cover the exact bytes sent, including for non-JSON formats. A `{txn}` placeholder in a
PUT URL is replaced with a per-message id (hash of event and body), so Matrix retries are idempotent.

Without `format`, `method` or `extraHeaders` the body is the legacy envelope `{"event","timestamp","data"}`, byte-identical
to earlier versions. With any of them, the body comes from `PayloadFormatter`. Methods other than POST/PUT, a format that
cannot render, and bad extra headers fail the attempt without contacting the endpoint.

### UrlPolicy, NetworkList, LocalNetworks

`UrlPolicy::vet($url)` returns `{host, port, ips}` or `null`. It allows only http/https, no userinfo, no control
characters, and a host whose every resolved address is public. Constructor:
`(bool $allowPrivate = false, ?\Closure $resolver = null, array $allowedNetworks = [])`. With `allowedNetworks`, a private
address passes only when inside a listed CIDR; loopback, unspecified, link-local (cloud metadata), multicast and
broadcast/reserved addresses are never allowed, even if listed. `isSafe()` and static `isPublicIp()` are helpers.

`NetworkList::parse($textOrList)` normalises admin input and returns `{networks, errors}`: only private space (10/8,
172.16/12, 192.168/16, 100.64/10, fc00::/7), no wider than /8 (IPv4) or /48 (IPv6), at most 16 entries
(`MAX_ENTRIES`). `NetworkList::contains($cidrs, $ip)` is the matcher. `Support\LocalNetworks::detect()` suggests the
server's own private IPv4 subnets (`[{interface, cidr, address}]`), skipping loopback, down interfaces and container bridges.

```php
use RivetCore\Webhooks\{UrlPolicy, NetworkList};

$allowed = NetworkList::parse('192.168.1.0/24, 8.8.8.0/24');
// $allowed['networks'] === ['192.168.1.0/24']; 'errors' explains the rejected 8.8.8.0/24
$policy = new UrlPolicy(false, null, $allowed['networks']);
$policy->vet('http://192.168.1.10:8080/hook'); // ['host' => '192.168.1.10', 'port' => 8080, 'ips' => [...]]
$policy->vet('http://127.0.0.1/');             // null, loopback is never allowed
```

### PayloadFormatter, EventSummary, FormattedPayload

`PayloadFormatter::format($format, ['event' => ..., 'timestamp' => ..., 'data' => [...]], $options): FormattedPayload`
(`body`, `contentType`, extra `headers`, such as ntfy's Title and Priority). `PayloadFormatter::FORMATS`:
`json`, `form`, `slack`, `slack_attachments`, `teams`, `discord`, `ntfy`, `gotify`, `telegram`, `matrix`,
`matrix_hookshot`, `apprise`, `template`. `formats()` and `isFormat()` are helpers. Options include `app_name`,
`link_url`, `link_label`, `test`, `chat_id` (telegram, required), and `template` (required for the `template` format).
It throws `InvalidArgumentException` for an unknown format or missing required option. `EventSummary::fromEvent($type,
$data)` turns any event into `{type, title, summary, url, severity, actor, client, fields}`, dropping secret-looking keys
and unsafe URLs; chat formats escape text for their platform so event data cannot ping a channel or inject markup.

### PayloadTemplate

A placeholder engine with no code execution: `{{path|default:"x"|json|upper|truncate:80}}`. Filters:
`default`, `json`, `upper`, `lower`, `trim`, `truncate`. Encodings: `json`, `text`, `form` (values are escaped for the
chosen encoding). Limits: 8192 template bytes, 65536 rendered bytes, 100 placeholders.

```php
use RivetCore\Webhooks\PayloadTemplate;

PayloadTemplate::render('{"t":"{{data.ticket_subject|upper}}"}', ['data' => ['ticket_subject' => 'hi']]); // {"t":"HI"}
PayloadTemplate::validate('{"t":');   // list of human-readable problems; [] when usable
PayloadTemplate::placeholders($tpl);  // data paths read, for a "fields used" preview
PayloadTemplate::sampleContext('ticket.created'); // realistic preview data
```

### Authentication

Modes (`Authentication::MODES`): `none`, `bearer`, `basic`, `header`, `hmac` (the last adds no headers; signing is always on).
`validate($config)` returns a list of problems, `headers($config)` returns the header map (throws
`InvalidArgumentException` when invalid), `redact($config)` masks secrets for display or logs.
`isValidHeaderName()` and `isForbiddenHeaderName()` (framing and hop-by-hop headers such as Host and Content-Length) are public helpers.

```php
use RivetCore\Webhooks\Authentication;

Authentication::headers(['mode' => 'bearer', 'token' => 'abc']);  // ['Authorization' => 'Bearer abc']
Authentication::redact(['mode' => 'bearer', 'token' => 'abc']);   // token becomes '********'
```

### EventCatalog, EventDefinition

`EventCatalog` is static data: 113 events in 14 groups. `all()`, `groups()`, `byGroup()`, `get($id)`, `has($id)`,
`search($q, $group = null, $limit = 200)` (id exact, id/label prefix, contains, tags, then description; every term must
match), `matchPattern('ticket.*')` (expands `*` to the matching ids), `isPattern()`, `unknown($ids)` (ids matching
nothing), `toArray()` and `toJson()` for a client-side picker. `EventDefinition` holds `id`, `group`, `groupLabel`,
`label`, `description`, `severity` (`info|warning|critical`), `payloadFields`, `since` (`null`, or `'planned'` for
events not emitted everywhere yet) and `tags`.

### Destinations, Destination, DestinationField

`Destinations` holds 24 presets (`all()`, `get($id)`, `has($id)`, `byCategory()`, `categoryLabels()`, `toArray()`,
`toJson()`). A `Destination` carries `format`, `method`, `urlHint`, `urlPattern`, `authModes`, `defaultAuth`, `headers`,
`extraFields`, `setupSteps`, `sampleCurl`, `verifySnippets` and `notes`; `urlMatches($url)` checks a saved URL and
`buildUrl($values)` fills `{placeholders}` from `target = 'url'` fields. A `DestinationField` (`name`, `label`, `type`,
`required`, `help`, `target` of `url` or `option`) describes an extra input such as an ntfy topic.

```php
use RivetCore\Webhooks\Destinations;

$ntfy = Destinations::get('ntfy');   // format 'ntfy', urlHint 'https://ntfy.sh/{topic}'
$ntfy->buildUrl(['topic' => 'alerts']); // 'https://ntfy.sh/alerts'
```

## Configuration

Core has no settings of its own; the edition decides:

- header prefixes, timeout (default 10 s), and whether a `UrlPolicy` is mandatory (`$requireUrlPolicy = true`);
- the allowed internal networks (stored by the edition, validated with `NetworkList::parse()`);
- an `$allowPrivate = true` escape hatch on `UrlPolicy` (both editions read an environment variable for it) that skips
  only the address-range test;
- retention of `webhook_deliveries`, via `Retention\RetentionService` (separate delivery horizon, 7-day floor).

## How it fails

- The dispatcher never throws. A transport exception, a rejected URL or a logging failure is turned into a result row
  (`error` set, `ok` false). A `forEvent()` exception yields an empty result list.
- A URL the policy rejects is never contacted; the attempt is logged with `endpoint URL not allowed`. Other fixed errors:
  `unsupported HTTP method`, `invalid extra headers`, `invalid extra header`, `payload format failed`,
  `transport failed`, `endpoint no longer exists`.
- Success is a 2xx answer; anything else is `ok = false` with `HTTP <status>`. Core does not retry by itself: run
  `deliverTo()` from a `Jobs\JobWorker` handler and throw `PermanentJobFailure` when `gone` is true.
- With no `UrlPolicy` and `$requireUrlPolicy = false` there is no SSRF check at all (the edition vets URLs on save).
- Parsing helpers fail with messages instead of exceptions (`NetworkList::parse`, `PayloadTemplate::validate`,
  `Authentication::validate`); `render()`, `format()` and `headers()` throw `InvalidArgumentException`.

## Security notes

- Always pass a `UrlPolicy` with `$requireUrlPolicy = true`. The vetted addresses are pinned with `CURLOPT_RESOLVE`
  (DNS rebinding), redirects are not followed, only http/https are spoken, TLS is verified, and pinned requests bypass proxies.
- Allowed networks cannot admit loopback, link-local or multicast, and `NetworkList` refuses public or very wide ranges.
- Verify receivers with V2 (timestamp plus HMAC) and reject old timestamps; V1 has no replay protection.
- Extra headers cannot override framing headers or the signature, timestamp and event headers.
- Templates cannot execute code or read outside the context array; chat formats neutralise mentions and markup.
- `Authentication::redact()` before logging; the `secret` in `WebhookSubscription` must be stored encrypted by the edition.
- `request_payload_json` stores full bodies, so include only data the receiver may see and prune it with Retention.

## Used by

- RivetMSP (`/home/sysadmin/rivetmsp-beta`, locked to v0.21.0): `includes/event_bus.php` builds the dispatcher (policy
  required, allowed networks from settings); `src/Core/Adapter/Webhooks/WebhooksTableSubscriptions.php` implements both
  subscription interfaces and builds options from `Destinations` and `Authentication`; `admin/webhook_form.php`,
  `admin/includes/webhook_form_lib.php`, `admin/webhook_tools.php`, `admin/webhook_guides.php`,
  `admin/webhook_url_check.php` and `admin/settings_webhooks.php` use `Destinations`, `PayloadFormatter`,
  `PayloadTemplate`, `Authentication`, `EventCatalog` and `NetworkList`; `includes/event_picker.php` renders the
  `EventCatalog` picker; `includes/event_rules_lib.php`, `admin/event_rules.php` and `src/Automation/RuleRecipes.php` use the
  catalog for rules.
- RivetIT (`/var/www/mw-itflow.foleyit.com`, currently pinned `^0.18`, locked v0.18.1): `includes/event_bus.php` builds
  the dispatcher and queues `webhook.deliver` jobs that call `deliverTo()` with `time()` as `$signedAt`;
  `src/Core/Adapter/Webhooks/WebhooksTableSubscriptions.php` implements the interfaces; `src/Webhooks/WebhookDispatcher.php`
  is a compatibility shim (`X-ITFlow-*` prefixes); `admin/post/settings_webhooks.php` uses `NetworkList`. RivetIT does
  not yet use `Destinations`, `EventCatalog`, `PayloadFormatter`, `PayloadTemplate`, `Authentication` or `LocalNetworks`,
  which arrived after v0.18.1.

## Links

- CHANGELOG: 0.6.0 (dispatcher, migration 0005), 0.15.0 (`deliverTo`, lookup interface), 0.17.0 (`UrlPolicy`, V2
  signature), 0.18.0 (`allowedNetworks`, `NetworkList`, `LocalNetworks`), 0.18.1 (pinning hardening, `pinnedUrl`),
  0.21.0 (destinations, formats, templates, authentication, event catalog); see [../../CHANGELOG.md](../../CHANGELOG.md).
- [../webhooks.md](../webhooks.md), [../webhook-platforms.md](../webhook-platforms.md), [../adapters.md](../adapters.md).
- Related modules: Jobs (retries), Retention (log pruning), Automation (`send_webhook` action).
- Tests: `tests/Unit/WebhookFormatsDispatcherTest.php`, `WebhookHardeningTest.php`, `UrlPolicyTest.php`,
  `UrlPolicyAllowedNetworksTest.php`, `PayloadFormatterTest.php`, `PayloadTemplateTest.php`, `AuthenticationTest.php`,
  `EventCatalogTest.php`, `DestinationsTest.php`, `WebhookDocsTest.php`; `tests/Integration/WebhookAutomationWorkflowTest.php`.
