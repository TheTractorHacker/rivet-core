<?php

declare(strict_types=1);

namespace RivetCore\Crypto;

/**
 * Credential fields sealed with the vault DEK in the v3 envelope. The AAD is `credential:<id>[:<field>]`, so a ciphertext copied to
 * another credential (or another field of the same credential) fails to open: a database writer cannot swap passwords between records.
 *
 * @api
 */
final class VaultCipher
{
    private Envelope $envelope;

    /** @param (\Closure(): string)|null $nonceSource @internal tests only */
    public function __construct(#[\SensitiveParameter] string $dek, private string $kid = 'dek', ?\Closure $nonceSource = null)
    {
        $this->envelope = new Envelope(KeyRing::single($dek, $kid), [], $nonceSource);
    }

    /** The AAD for a credential field; use it as the {@see RewrapItem} context. */
    public static function contextFor(string|int $credentialId, string $field = ''): string
    {
        $id = (string) $credentialId;
        if ($id === '') {
            throw new \InvalidArgumentException('A credential id is required.');
        }

        return 'credential:' . $id . ($field === '' ? '' : ':' . $field);
    }

    /** '' stays ''. */
    public function seal(#[\SensitiveParameter] string $plaintext, string|int $credentialId, string $field = ''): string
    {
        return $plaintext === '' ? '' : $this->envelope->seal($plaintext, self::contextFor($credentialId, $field));
    }

    /** @throws CryptoException */
    public function open(string $stored, string|int $credentialId, string $field = ''): string
    {
        return $stored === '' ? '' : $this->envelope->open($stored, self::contextFor($credentialId, $field));
    }

    public function sealWithContext(#[\SensitiveParameter] string $plaintext, string $context): string
    {
        return $this->envelope->seal($plaintext, $context);
    }

    /** @throws CryptoException */
    public function openWithContext(string $stored, string $context): string
    {
        return $this->envelope->open($stored, $context);
    }

    public static function isV3(string $stored): bool
    {
        return Envelope::isV3($stored);
    }

    /** Shape test for the old unprefixed `iv + base64(AES-128-CBC)` field. */
    public static function isLegacy(string $stored): bool
    {
        return $stored !== '' && (new LegacyVaultFieldReader('x'))->supports($stored);
    }

    /**
     * Converts a legacy field to v3 under this DEK, reading it with the old master key. A v3 field is returned unchanged; '' stays ''.
     * Legacy CBC is unauthenticated: a wrong $legacyDek usually fails but can yield garbage, so check one known credential before a bulk run.
     *
     * @throws DecryptionFailed
     */
    public function migrate(string $stored, string|int $credentialId, #[\SensitiveParameter] string $legacyDek, string $field = ''): string
    {
        if ($stored === '' || self::isV3($stored)) {
            return $stored;
        }
        $reader = new LegacyVaultFieldReader($legacyDek);
        if (!$reader->supports($stored)) {
            throw new DecryptionFailed('The value is not a legacy vault field.');
        }

        return $this->seal($reader->read($stored, ''), $credentialId, $field);
    }
}
