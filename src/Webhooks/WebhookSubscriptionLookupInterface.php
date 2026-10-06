<?php

declare(strict_types=1);

namespace RivetCore\Webhooks;

/** Optional companion to WebhookSubscriptionsInterface: look one endpoint up by id, so a single delivery can be retried on its own.
 *
 * @api
 */
interface WebhookSubscriptionLookupInterface
{
    public function find(int $webhookId): ?WebhookSubscription;
}
