<?php

declare(strict_types=1);

namespace RivetCore\Webhooks;

/** Optional companion to WebhookSubscriptionsInterface: look one endpoint up by id, so a single delivery can be retried on its own.
 *
 * @api
 */
interface WebhookSubscriptionLookupInterface
{
    /** The endpoint with this id, as {@see WebhookSubscriptionsInterface::forEvent()} would return it (secret decrypted), or null when it does not exist, is disabled or was deleted. */
    public function find(int $webhookId): ?WebhookSubscription;
}
