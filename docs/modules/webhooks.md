# Webhooks

`RivetCore\Webhooks`: signed outgoing webhooks. The long reference is [webhooks.md](../webhooks.md) (signing, verification, replay
protection, retries, URL policy) and the per-platform setup guides are [webhook-platforms.md](../webhook-platforms.md).

## What it owns

Table `webhook_deliveries` (migration 0005): `delivery_id`, `webhook_id`, `event_type`, `attempt_number`, `http_status`, `duration_ms`,
`request_payload_json`, `response_body_snippet` (at most 1000 characters), `created_at`. The endpoints themselves are the edition's
(`WebhookSubscriptionsInterface`).

## You supply

`WebhookSubscriptionsInterface::forEvent()` (and optionally `WebhookSubscriptionLookupInterface::find()` so one delivery can be retried
on its own through the job queue), the `ClockInterface`, and optionally a `UrlPolicy`.

## Flags

- `requireUrlPolicy` on `WebhookDispatcher`: make a policy mandatory (a default public-addresses-only policy is used when none is injected).
- `UrlPolicy(allowPrivate: false, ..., allowedNetworks: [...])`: private addresses are refused unless inside a listed network
  (`NetworkList::parse()` validates admin input). Loopback, link-local (including the cloud metadata address), multicast, broadcast
  and unspecified addresses are never allowed, even when listed.
- Per-subscription `format`, `method`, `extraHeaders`, `template` options (see [webhooks.md](../webhooks.md)).

## Use it

<!-- run -->
```php
use RivetCore\Webhooks\{UrlPolicy, WebhookDispatcher, WebhookSubscription, WebhookSubscriptionLookupInterface, WebhookSubscriptionsInterface};

$subs = new class implements WebhookSubscriptionsInterface, WebhookSubscriptionLookupInterface {
    public function forEvent(string $eventType): array { return [$this->find(1)]; }
    public function find(int $webhookId): ?WebhookSubscription { return new WebhookSubscription($webhookId, 'https://hooks.example.test/in', 'shared-secret'); }
};
$sent = [];
$transport = function (string $url, string $body, array $headers) use (&$sent): array { $sent = $headers; return ['status' => 204, 'body' => '', 'error' => null]; };
$policy = new UrlPolicy(false, fn (string $host): array => ['93.184.216.34']);   // stub the resolver so the sample needs no DNS

$dispatcher = new WebhookDispatcher($db, $subs, $clock, ['X-Example'], $transport, 10, $policy);
$result = $dispatcher->deliverTo(1, 'ticket.created', ['ticket_id' => 42]);
echo json_encode(['ok' => $result['ok'], 'signature_header' => (bool) preg_grep('/^X-Rivet-Signature-V2: t=\d+,v1=[0-9a-f]{64}$/', $sent)]), "\n";
```

## How it fails

`deliver()` and `deliverTo()` never throw: every attempt is logged in `webhook_deliveries` and returned as
`{webhook_id, http_status, duration_ms, error, ok}`. A URL the policy rejects is never contacted (`error: "endpoint URL not allowed"`);
a bad header, method or format fails the attempt without contacting the endpoint; a deleted endpoint returns `gone: true` so a queued
retry stops. Retries are the caller's job (use [Jobs](jobs.md) and pass `$signedAt` per attempt so the replay window means something);
the body stays byte-identical across retries. Requests are pinned to the vetted addresses (`CURLOPT_RESOLVE`) against DNS rebinding and never use a proxy.
