<?php

declare(strict_types=1);

namespace RivetCore\Crypto;

/**
 * Moves values to the active key of an {@see EnvelopeInterface}; values already there are left alone (null).
 *
 * @api
 */
final class EnvelopeReencryptor implements Reencryptor
{
    public function __construct(private EnvelopeInterface $envelope)
    {
    }

    public function reencrypt(string $stored, string $context): ?string
    {
        return $this->envelope->needsRewrap($stored) ? $this->envelope->rewrap($stored, $context) : null;
    }
}
