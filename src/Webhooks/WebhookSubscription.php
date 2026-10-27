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
         * template, template_encoding, format_options, and `allow_http` (true = this destination may be plain http://, see WebhookDispatcher's `requireHttps`).
         *
         * @var array<string,mixed>
         */
        public array $options = [],
    ) {
    }

    /** The destination's own, explicit choice to be reached over plain http://. Only this subscription's stored options count, never per-job options. */
    public function allowsHttp(): bool
    {
        return ($this->options['allow_http'] ?? false) === true;
    }
}
