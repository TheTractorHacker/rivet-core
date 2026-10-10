<?php

declare(strict_types=1);

namespace RivetCore\Crypto;

/**
 * Returns a stored value that carries none of the known encryption prefixes as it is. This reproduces the editions' "legacy plaintext
 * must keep working" rule for columns that were once written in the clear, and is therefore OFF unless you register it. Register it
 * last, only for columns known to have held cleartext, and treat every value it reads as a candidate for re-sealing (the Rewrapper does).
 *
 * @api
 */
final class PlaintextPassthroughReader implements LegacyReader
{
    private const KNOWN = ['v3:', 'ENC2:', 'ENC:', 'enc:', 'V2:'];

    public function name(): string
    {
        return 'plaintext';
    }

    public function supports(string $stored): bool
    {
        foreach (self::KNOWN as $prefix) {
            if (str_starts_with($stored, $prefix)) {
                return false;
            }
        }

        return true;
    }

    public function read(string $stored, string $context): string
    {
        return $stored;
    }
}
