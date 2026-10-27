<?php

declare(strict_types=1);

namespace RivetCore\Crypto;

/**
 * Wrapping of the credentials vault's data-encryption key (DEK, 32 random bytes) so the vault can be opened by each user's password and
 * by the instance KEK, without any of them being the DEK.
 *
 * Password wrap format: `vw3:<kdf>:<params>:<base64 salt>:<base64 nonce||tag||ct>`; the key is derived from the password with Argon2id
 * (or PBKDF2-SHA256 600000+), the parameters are stored in the wrap, and the whole header plus the caller's context (for example
 * "user:42") is authenticated, so a wrap cannot be moved to another user or have its parameters edited.
 *
 * Changing a password is {@see rewrap()}: the DEK stays the same, so no credential is touched.
 *
 * @api
 */
final class VaultKeyWrap
{
    public const PREFIX = 'vw3:';
    private const SALT_BYTES = 16;

    private KdfParams $kdf;

    /** @param (\Closure(int): string)|null $random @internal tests only: returns that many bytes for salts and nonces */
    public function __construct(?KdfParams $kdf = null, private ?\Closure $random = null)
    {
        $this->kdf = $kdf ?? KdfParams::preferred();
    }

    public static function generateDek(): string
    {
        return random_bytes(KeyRing::KEY_BYTES);
    }

    public static function isWrap(string $stored): bool
    {
        return str_starts_with($stored, self::PREFIX);
    }

    /** @throws \InvalidArgumentException empty password or context, or a DEK that is not 32 bytes */
    public function wrap(#[\SensitiveParameter] string $dek, #[\SensitiveParameter] string $password, string $context): string
    {
        self::assertInputs($password, $context);
        KeyRing::assertKey($dek);
        $salt = $this->random !== null ? ($this->random)(self::SALT_BYTES) : random_bytes(self::SALT_BYTES);
        $header = self::PREFIX . $this->kdf->kdf . ':' . $this->kdf->paramString() . ':' . base64_encode($salt);
        $envelope = $this->envelope($this->kdf->derive($password, $salt));

        return $header . ':' . self::payload($envelope->seal($dek, self::aad($header, $context)));
    }

    /**
     * @return string the 32-byte DEK
     * @throws DecryptionFailed wrong password, wrong context, tampered wrap (not distinguishable)
     */
    public function unwrap(string $wrap, #[\SensitiveParameter] string $password, string $context): string
    {
        self::assertInputs($password, $context);
        [$header, $params, $salt, $payload] = $this->parse($wrap);
        $envelope = $this->envelope($params->derive($password, $salt));

        return $envelope->open('v3:w:' . $payload, self::aad($header, $context));
    }

    /** Password change: opens with the old password and wraps the same DEK under the new one (fresh salt, current parameters). */
    public function rewrap(string $wrap, #[\SensitiveParameter] string $oldPassword, #[\SensitiveParameter] string $newPassword, string $context): string
    {
        return $this->wrap($this->unwrap($wrap, $oldPassword, $context), $newPassword, $context);
    }

    /** True when the wrap uses weaker parameters (or a weaker KDF) than this instance would write today; re-wrap at the next login. */
    public function needsUpgrade(string $wrap): bool
    {
        try {
            [, $params] = $this->parse($wrap);
        } catch (CryptoException) {
            return false;
        }

        return $params->weakerThan($this->kdf);
    }

    /** Wraps the DEK under the instance KEK ({@see SettingsVault} or any envelope); the recovery path when a user's password wrap is stale. */
    public function wrapForInstance(#[\SensitiveParameter] string $dek, EnvelopeInterface $kek, string $context): string
    {
        KeyRing::assertKey($dek);

        return $kek->seal($dek, 'vault-dek|' . $context);
    }

    /** @throws CryptoException */
    public function unwrapForInstance(string $stored, EnvelopeInterface $kek, string $context): string
    {
        return $kek->open($stored, 'vault-dek|' . $context);
    }

    /**
     * Opens a legacy `V2:` user wrap and returns the legacy master key it held (a 16-character string) for use as the $legacyDek of
     * {@see VaultCipher::migrate()}.
     *
     * @throws DecryptionFailed
     */
    public static function openLegacyUserKey(string $v2, #[\SensitiveParameter] string $password, bool $acceptV1 = false): string
    {
        $reader = new LegacyUserKeyReader($password, $acceptV1);
        if (!$reader->supports($v2)) {
            throw new DecryptionFailed('The value is not a legacy user key.');
        }

        return $reader->read($v2, '');
    }

    private function envelope(#[\SensitiveParameter] string $key): Envelope
    {
        return new Envelope(KeyRing::single($key, 'w'), [], $this->random !== null ? fn (): string => ($this->random)(12) : null);
    }

    /** @return array{0:string,1:KdfParams,2:string,3:string} header, params, salt, base64 payload */
    private function parse(string $wrap): array
    {
        $parts = explode(':', $wrap);
        if (count($parts) !== 5 || $parts[0] . ':' !== self::PREFIX) {
            throw new DecryptionFailed('The value is not a vault key wrap.');
        }
        try {
            $params = KdfParams::parse($parts[1], $parts[2]);
        } catch (InvalidKeyMaterial) {
            throw new DecryptionFailed('The value is not a readable vault key wrap.');
        }
        $salt = base64_decode($parts[3], true);
        if ($salt === false || strlen($salt) !== self::SALT_BYTES || base64_encode($salt) !== $parts[3]) {
            throw new DecryptionFailed('The value is not a readable vault key wrap.');
        }

        return [implode(':', array_slice($parts, 0, 4)), $params, $salt, $parts[4]];
    }

    private static function payload(string $sealed): string
    {
        return substr($sealed, strlen('v3:w:'));
    }

    private static function aad(string $header, string $context): string
    {
        return 'vault-dek-wrap|' . $header . '|' . $context;
    }

    private static function assertInputs(string $password, string $context): void
    {
        if ($password === '' || $context === '') {
            throw new \InvalidArgumentException('A password and a context (for example "user:42") are required.');
        }
    }
}
