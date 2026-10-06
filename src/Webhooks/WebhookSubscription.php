<?php

declare(strict_types=1);

namespace RivetCore\Webhooks;

/** One endpoint subscribed to an event. The secret is already decrypted by the edition.
 *
 * @api
 */
final readonly class WebhookSubscription
{
    public function __construct(
        public int $webhookId,
        public string $url,
        public string $secret,
        /**
         * Optional per-subscription delivery options (see WebhookDispatcher::deliverTo()): format, method, extraHeaders,
         * template, template_encoding, format_options.
         *
         * @var array<string,mixed>
         */
        public array $options = [],
    ) {
    }
}
