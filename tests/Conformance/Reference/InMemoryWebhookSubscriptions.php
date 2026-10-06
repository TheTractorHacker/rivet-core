<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance\Reference;

use RivetCore\Webhooks\WebhookSubscription;
use RivetCore\Webhooks\WebhookSubscriptionLookupInterface;
use RivetCore\Webhooks\WebhookSubscriptionsInterface;

/** Reference WebhookSubscriptionsInterface + lookup over an array. */
final class InMemoryWebhookSubscriptions implements WebhookSubscriptionsInterface, WebhookSubscriptionLookupInterface
{
    /** @var array<int, array{event:string, url:string, secret:string, enabled:bool}> */
    private array $rows = [];

    public function add(int $id, string $event, string $url, string $secret, bool $enabled = true): void
    {
        $this->rows[$id] = ['event' => $event, 'url' => $url, 'secret' => $secret, 'enabled' => $enabled];
    }

    public function forEvent(string $eventType): array
    {
        $out = [];
        foreach ($this->rows as $id => $r) {
            if ($r['enabled'] && $r['event'] === $eventType) {
                $out[] = new WebhookSubscription($id, $r['url'], $r['secret']);
            }
        }

        return $out;
    }

    public function find(int $webhookId): ?WebhookSubscription
    {
        $r = $this->rows[$webhookId] ?? null;

        return $r !== null && $r['enabled'] ? new WebhookSubscription($webhookId, $r['url'], $r['secret']) : null;
    }
}
