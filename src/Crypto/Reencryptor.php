<?php

declare(strict_types=1);

namespace RivetCore\Crypto;

/**
 * Decides how one stored value moves to the current format/key.
 *
 * @api
 */
interface Reencryptor
{
    /**
     * @return string|null the replacement ciphertext, or null when the value is already current (so a re-run changes nothing)
     * @throws CryptoException the value cannot be read; the Rewrapper counts it as failed and leaves it untouched
     */
    public function reencrypt(string $stored, string $context): ?string;
}
