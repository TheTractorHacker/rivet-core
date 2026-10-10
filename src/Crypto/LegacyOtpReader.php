<?php

declare(strict_types=1);

namespace RivetCore\Crypto;

/**
 * `enc:` + iv(16 characters) + base64(ct): a TOTP seed encrypted with the vault master key (functions.php encryptOtpSecret),
 * AES-128-CBC with the master key string as the key. Lower-case prefix, not to be confused with the settings `ENC:` format.
 *
 * @api
 */
final class LegacyOtpReader implements LegacyReader
{
    public function __construct(#[\SensitiveParameter] private string $masterKey)
    {
    }

    public function name(): string
    {
        return 'enc';
    }

    public function supports(string $stored): bool
    {
        return str_starts_with($stored, 'enc:');
    }

    public function read(string $stored, string $context): string
    {
        if ($this->masterKey === '') {
            throw new KeyUnavailable('The legacy vault master key is not available.');
        }
        $payload = substr($stored, 4);
        if (strlen($payload) <= 16) {
            throw new DecryptionFailed('The value is not in a readable format.');
        }

        return LegacyCbc::decrypt(substr($payload, 16), $this->masterKey, substr($payload, 0, 16), 0);
    }
}
