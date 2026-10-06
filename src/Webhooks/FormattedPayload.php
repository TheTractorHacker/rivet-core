<?php

declare(strict_types=1);

namespace RivetCore\Webhooks;

/**
 * A request body ready to send: the exact bytes, their Content-Type and any extra headers the format needs
 * (for example ntfy's Title/Priority/Tags). The signature is computed over $body by the dispatcher.
 *
 * @api
 */
final readonly class FormattedPayload
{
    /** @param array<string,string> $headers */
    public function __construct(
        public string $body,
        public string $contentType,
        public array $headers = [],
    ) {
    }
}
