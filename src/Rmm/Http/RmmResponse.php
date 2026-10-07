<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Http;

/**
 * A framework-neutral response. Never emitted by Core itself: the edition (or {@see SapiEmitter}) sends it.
 *
 * @api
 */
final class RmmResponse
{
    /** The JSON flags of every device/technician response (frozen wire format). */
    public const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION;

    /** @param array<string,string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly ?string $body,
        public readonly ?RmmFileBody $file = null,
    ) {
    }

    /**
     * JSON response with Content-Type and Cache-Control: no-store.
     *
     * @param array<mixed> $data
     * @param array<string,string> $headers
     */
    public static function json(int $status, array $data, array $headers = []): self
    {
        return new self($status, ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'] + $headers, (string) json_encode($data, self::JSON_FLAGS));
    }

    /** {"error": message, "code": code}, as ApiError produces. */
    public static function error(ApiError $e): self
    {
        return self::json($e->http, ['error' => $e->getMessage(), 'code' => $e->errCode], $e->headers);
    }
}
