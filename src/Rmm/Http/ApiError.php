<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Http;

/**
 * An error the device-facing endpoints turn into {"error": "...", "code": "..."} with an HTTP status.
 *
 * @api
 */
final class ApiError extends \RuntimeException
{
    /** @param array<string,string> $headers */
    public function __construct(
        public readonly int $http,
        public readonly string $errCode,
        string $message,
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }
}
