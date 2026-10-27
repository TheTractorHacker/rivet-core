<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Http;

/**
 * A response body streamed from a file: an exact length, an optional SHA-256 to verify before any byte is sent, and optional
 * trailer bytes appended after the file (the installer stamp).
 *
 * @api
 */
final class RmmFileBody
{
    public function __construct(
        public readonly string $path,
        public readonly int $length,
        public readonly ?string $trailer = null,
        public readonly ?string $sha256 = null,
    ) {
    }

    /** Total bytes on the wire. */
    public function totalLength(): int
    {
        return $this->length + strlen((string) $this->trailer);
    }
}
