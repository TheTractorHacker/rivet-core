<?php

declare(strict_types=1);

namespace RivetCore\Crypto;

/**
 * A credential field of the old vault: iv (16 characters) followed by base64(AES-128-CBC ciphertext), no prefix, key = the vault master
 * key string (functions.php encryptCredentialEntryWithKey). There is no authentication, so this reader is only as good as the key you
 * give it: with the wrong key roughly 1 value in 256 decrypts to garbage instead of failing. supports() is a shape test (16 characters,
 * then base64 of a whole number of AES blocks); use this reader only on a column that holds nothing else.
 *
 * @api
 */
final class LegacyVaultFieldReader implements LegacyReader
{
    public function __construct(#[\SensitiveParameter] private string $masterKey)
    {
    }

    public function name(): string
    {
        return 'vault-cbc';
    }

    public function supports(string $stored): bool
    {
        if (strlen($stored) < 40 || str_starts_with($stored, Envelope::PREFIX)) {
            return false;
        }
        $rest = substr($stored, 16);
        if (preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $rest) !== 1) {
            return false;
        }
        $raw = base64_decode($rest, true);

        return $raw !== false && $raw !== '' && strlen($raw) % 16 === 0;
    }

    public function read(string $stored, string $context): string
    {
        if ($this->masterKey === '') {
            throw new KeyUnavailable('The legacy vault master key is not available.');
        }

        return LegacyCbc::decrypt(substr($stored, 16), $this->masterKey, substr($stored, 0, 16), 0);
    }
}
