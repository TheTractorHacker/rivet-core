<?php

declare(strict_types=1);

namespace RivetCore\Crypto;

/**
 * A loaded key ring and where it came from ("file", "env"), plus permission warnings the operator should see (rotation panel, setup
 * checks, log).
 *
 * @api
 */
final class KeyRingLoadResult
{
    /** @param list<string> $warnings */
    public function __construct(
        public readonly KeyRing $ring,
        public readonly string $source,
        public readonly array $warnings = [],
    ) {
    }

    public function __debugInfo(): array
    {
        return ['source' => $this->source, 'ring' => $this->ring, 'warnings' => $this->warnings];
    }
}
