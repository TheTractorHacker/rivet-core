<?php

declare(strict_types=1);

namespace RivetCore\Webhooks;

/** One endpoint subscribed to an event. The secret is already decrypted by the edition. */
final readonly class WebhookSubscription
{
    public function __construct(
        public int $webhookId,
        public string $url,
        public string $secret,
    ) {
    }
}
