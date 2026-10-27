<?php

declare(strict_types=1);

namespace RivetCore\Crypto;

/**
 * Labels for the HKDF-derived subkeys. One master KEK feeds every purpose; a value sealed under one label cannot be opened under
 * another because each label yields a different key.
 *
 * @api
 */
final class KeyPurpose
{
    public const SETTINGS = 'settings';
    public const TOTP = 'totp';
    public const MEDIA_TOKEN = 'media-token';
    public const TRAINING_PIN = 'training-pin';
    public const VAULT_WRAP = 'vault-wrap';
    public const SIGNING_SEAL = 'signing-seal';

    private const HKDF_PREFIX = 'rivetcore-crypto|v1|';

    /** @throws InvalidKeyMaterial */
    public static function assertValid(string $purpose): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,47}$/', $purpose) !== 1) {
            throw new InvalidKeyMaterial('A key purpose is 1-48 characters of [a-z0-9._-].');
        }
    }

    /** HKDF-SHA256 of a 32-byte KEK, with the purpose in the info string. */
    public static function derive(string $kek, string $purpose, int $length = 32): string
    {
        self::assertValid($purpose);
        if ($length < 16 || $length > 255 * 32) {
            throw new \InvalidArgumentException('Derived key length out of range.');
        }

        return hash_hkdf('sha256', $kek, $length, self::HKDF_PREFIX . $purpose, '');
    }
}
