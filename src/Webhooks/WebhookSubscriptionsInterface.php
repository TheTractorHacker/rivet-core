<?php

declare(strict_types=1);

namespace RivetCore\Webhooks;

/**
 * Where an edition keeps its webhook endpoints (the `webhooks` table, how events are listed, how secrets are
 * encrypted). Core only needs "who is subscribed to this event, and with what secret".
 *
 * @api
 */
interface WebhookSubscriptionsInterface
{
    /** @return list<WebhookSubscription> enabled endpoints subscribed to $eventType */
    public function forEvent(string $eventType): array;
}
