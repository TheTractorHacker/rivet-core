<?php

declare(strict_types=1);

namespace RivetCore\Crypto;

use Psr\Log\LoggerInterface;

/**
 * Named secrets (SMTP passwords, OAuth secrets, TOTP seeds, ...) sealed under HKDF subkeys of the master KEK.
 *
 * - Context binding: the AAD is `<purpose>|<name>`, so a value copied to another setting does not open.
 * - Fail closed: no active key means {@see KeyUnavailable} on write and on reading any encrypted value; cleartext is never stored and
 *   an unreadable value is never turned into '' (unlike the pre-v3 editions, which returned '' and let callers carry on).
 * - Lazy re-wrap: reading a legacy or retired-kid value yields the replacement ciphertext; {@see decrypt()} hands it to a callback.
 * - One master KEK, one subkey per purpose label ({@see KeyPurpose}); {@see subkey()} derives raw keys (HMAC keys for media tokens or
 *   training PINs) from the same root.
 *
 * @api
 */
final class SettingsVault
{
    private Envelope $envelope;

    /**
     * @param list<LegacyReader> $legacyReaders pre-v3 formats this vault may read ({@see LegacyReaders})
     * @param (\Closure(): string)|null $nonceSource @internal tests only
     */
    public function __construct(
        private KeyRing $kek,
        array $legacyReaders = [],
        private string $purpose = KeyPurpose::SETTINGS,
        private ?LoggerInterface $logger = null,
        ?\Closure $nonceSource = null,
    ) {
        KeyPurpose::assertValid($purpose);
        $this->envelope = new Envelope($kek->derive($purpose), $legacyReaders, $nonceSource);
    }

    public function purpose(): string
    {
        return $this->purpose;
    }

    public function envelope(): EnvelopeInterface
    {
        return $this->envelope;
    }

    /** '' is not a secret and stays ''. @throws KeyUnavailable */
    public function encrypt(string $name, #[\SensitiveParameter] string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }

        return $this->envelope->seal($plaintext, $this->context($name));
    }

    /**
     * Opens the value; when it needs re-wrapping and $persist is given, calls `$persist($newCiphertext, $previousCiphertext)` so the
     * caller can run `UPDATE ... SET col = new WHERE col = previous`. A failing callback is logged and never fails the read.
     *
     * @param (\Closure(string,string):void)|null $persist
     * @throws CryptoException
     */
    public function decrypt(string $name, string $stored, ?\Closure $persist = null): string
    {
        $read = $this->read($name, $stored);
        if ($read->rewrapped !== null && $persist !== null) {
            try {
                $persist($read->rewrapped, $stored);
            } catch (\Throwable $e) {
                $this->logger?->warning('Settings vault: could not persist a re-wrapped value', ['setting' => $name, 'error' => get_class($e)]);
            }
        }

        return $read->plaintext;
    }

    /** @throws CryptoException */
    public function read(string $name, string $stored): SettingRead
    {
        if ($stored === '') {
            return new SettingRead('', null, 'empty');
        }
        $context = $this->context($name);
        $plain = $this->envelope->open($stored, $context);
        $rewrapped = null;
        if ($this->envelope->needsRewrap($stored)) {
            try {
                $rewrapped = $this->envelope->seal($plain, $context);
            } catch (KeyUnavailable) {
                $rewrapped = null; // read-only ring: reading still works
            }
        }

        return new SettingRead($plain, $rewrapped, $this->envelope->label($stored));
    }

    public function needsRewrap(string $stored): bool
    {
        return $this->envelope->needsRewrap($stored);
    }

    public function isEncrypted(string $stored): bool
    {
        return Envelope::isV3($stored);
    }

    /**
     * Raw key material for another purpose of the same master KEK (an HMAC key for media tokens, training PINs, ...), derived with HKDF
     * from the active key, or from $kid when given (so a verifier can still check what an older key made).
     *
     * @throws KeyUnavailable|UnknownKeyId
     */
    public function subkey(string $purpose, int $length = 32, ?string $kid = null): string
    {
        $key = $kid === null ? $this->kek->activeKey() : $this->kek->key($kid);

        return KeyPurpose::derive($key, $purpose, $length);
    }

    private function context(string $name): string
    {
        if ($name === '') {
            throw new \InvalidArgumentException('A setting name is required; it is bound into the ciphertext.');
        }

        return $this->purpose . '|' . $name;
    }
}
