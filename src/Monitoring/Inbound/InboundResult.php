<?php

declare(strict_types=1);

namespace RivetCore\Monitoring\Inbound;

/**
 * The answer for the edition's endpoint file: send {@see self::$status}, the headers and {@see self::json()}. The body holds fixed
 * tokens and ids only: never the request body, a secret or an exception message.
 *
 * @api
 */
final class InboundResult
{
    /**
     * @param array<string,scalar|null> $body
     * @param array<string,string> $headers
     */
    public function __construct(public readonly int $status, public readonly array $body, public readonly array $headers = [])
    {
    }

    public function json(): string
    {
        return json_encode($this->body, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{"ok":false}';
    }
}
