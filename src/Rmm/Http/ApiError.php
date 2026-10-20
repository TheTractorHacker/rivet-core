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
    /** The 422 for a timestamp outside the window: carries server_time (RFC 3339 UTC) and skew_s (device time minus server time) so an agent can self-correct and a human can see why. */
    public static function badTime(string $message, int $deviceTs, int $now): self
    {
        return new self(422, 'invalid', $message, [], ['server_time' => gmdate('Y-m-d\TH:i:s\Z', $now), 'skew_s' => $deviceTs - $now]);
    }

    /** @param array<string,string> $headers */
    public function __construct(
        public readonly int $http,
        public readonly string $errCode,
        string $message,
        public readonly array $headers = [],
        /** @var array<string,mixed> additive JSON fields beside error and code (server_time and skew_s on an invalid-time 422) */
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }
}
