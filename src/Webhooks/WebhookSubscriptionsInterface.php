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
    /**
     * Enabled endpoints subscribed to $eventType, with their secret decrypted. Disabled and deleted endpoints are never returned.
     * An event matches when it is listed exactly or covered by a stored pattern ("*" = every event, "ticket.*" = every event of
     * that group, see EventCatalog); anything else, including an empty or hostile $eventType, matches nothing and does not throw.
     * Checked by Testing\WebhookSubscriptionsConformanceTestCase.
     *
     * @return list<WebhookSubscription>
     */
    public function forEvent(string $eventType): array;
}
