<?php

declare(strict_types=1);

namespace RivetCore\Crypto;

/**
 * The per-user wrap of the old vault master key (`user_specific_encryption_ciphertext`): `V2:` + salt(16 characters) + iv(16 characters) +
 * base64(AES-128-CBC), key = PBKDF2-HMAC-SHA256(password, salt, 100000 iterations, 16 raw bytes). With $acceptV1 the unprefixed first
 * generation is read too (same layout, key = the 16 lower-case HEX characters of the same PBKDF2, used as the key string).
 *
 * The result is the legacy master key itself. Because CBC cannot tell a wrong password from a right one reliably, a result that is not
 * printable ASCII (the old keys are 16 characters of base64url) is rejected as DecryptionFailed.
 *
 * @api
 */
final class LegacyUserKeyReader implements LegacyReader
{
    private const ITERATIONS = 100000;

    public function __construct(#[\SensitiveParameter] private string $password, private bool $acceptV1 = false)
    {
    }

    public function name(): string
    {
        return 'V2';
    }

    public function supports(string $stored): bool
    {
        if (str_starts_with($stored, 'V2:')) {
            return true;
        }

        return $this->acceptV1 && strlen($stored) > 32 && !str_starts_with($stored, Envelope::PREFIX);
    }

    public function read(string $stored, string $context): string
    {
        if ($this->password === '') {
            throw new KeyUnavailable('No password was given to open the legacy user key.');
        }
        $v2 = str_starts_with($stored, 'V2:');
        $payload = $v2 ? substr($stored, 3) : $stored;
        if (strlen($payload) <= 32) {
            throw new DecryptionFailed('The value is not in a readable format.');
        }
        $salt = substr($payload, 0, 16);
        $key = $v2
            ? hash_pbkdf2('sha256', $this->password, $salt, self::ITERATIONS, 16, true)
            : hash_pbkdf2('sha256', $this->password, $salt, self::ITERATIONS, 16);
        $plain = LegacyCbc::decrypt(substr($payload, 32), $key, substr($payload, 16, 16), 0);
        if ($plain === '' || preg_match('/^[\x21-\x7e]{1,128}$/', $plain) !== 1) {
            throw new DecryptionFailed('The value could not be decrypted.');
        }

        return $plain;
    }
}
