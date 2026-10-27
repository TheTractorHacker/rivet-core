<?php

declare(strict_types=1);

namespace RivetCore\Crypto;

/**
 * AES-256-GCM envelope with key ids and caller-supplied AAD (ADR-011).
 *
 * Wire format: `v3:<kid>:<base64>` where the base64 text (standard alphabet, padded, canonical) encodes `nonce(12) || tag(16) || ciphertext`.
 * The nonce is random per call (96 bits; keep one key under 2^32 messages, rotation and per-purpose HKDF subkeys keep it far below).
 * Authentication is done by OpenSSL's GCM implementation (constant-time tag check); this class never compares secrets itself and never
 * hands back data from a failed decryption.
 *
 * @api
 */
final class Envelope implements EnvelopeInterface
{
    public const PREFIX = 'v3:';
    public const MAX_STORED_BYTES = 33554432;
    private const NONCE = 12;
    private const TAG = 16;

    /** @var list<LegacyReader> */
    private array $legacy;
    /** @var (\Closure(): string)|null */
    private ?\Closure $nonceSource;

    /**
     * @param list<LegacyReader> $legacyReaders tried in order for values that are not v3
     * @param (\Closure(): string)|null $nonceSource @internal test injection point (known-answer vectors); must return 12 bytes. Never set in production.
     */
    public function __construct(private KeyRing $ring, array $legacyReaders = [], ?\Closure $nonceSource = null)
    {
        $this->legacy = $legacyReaders;
        $this->nonceSource = $nonceSource;
    }

    public function ring(): KeyRing
    {
        return $this->ring;
    }

    public static function isV3(string $stored): bool
    {
        return str_starts_with($stored, self::PREFIX);
    }

    /** The kid named by a v3 value, or null when the value is not a well-formed v3 header. Does not check the key ring. */
    public static function kidOf(string $stored): ?string
    {
        if (!self::isV3($stored)) {
            return null;
        }
        $parts = explode(':', $stored, 3);

        return count($parts) === 3 && preg_match(KeyRing::KID_PATTERN, $parts[1]) === 1 ? $parts[1] : null;
    }

    public function seal(#[\SensitiveParameter] string $plaintext, string $context): string
    {
        self::assertContext($context);
        $kid = $this->ring->activeKid();
        $key = $this->ring->activeKey();
        $nonce = $this->nonceSource !== null ? ($this->nonceSource)() : random_bytes(self::NONCE);
        if (strlen($nonce) !== self::NONCE) {
            throw new \LogicException('The nonce source must return 12 bytes.');
        }
        $tag = '';
        $ct = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $context, self::TAG);
        if ($ct === false || strlen($tag) !== self::TAG) {
            throw new KeyUnavailable('The cipher refused to seal; nothing was stored in cleartext.');
        }

        return self::PREFIX . $kid . ':' . base64_encode($nonce . $tag . $ct);
    }

    public function open(string $stored, string $context): string
    {
        self::assertContext($context);
        if (!self::isV3($stored)) {
            foreach ($this->legacy as $reader) {
                if ($reader->supports($stored)) {
                    return $reader->read($stored, $context);
                }
            }
            throw new DecryptionFailed('The value is not in a readable format.');
        }
        if ($this->ring->isEmpty()) {
            throw new KeyUnavailable('No encryption key is configured; cannot open an encrypted value.');
        }
        $kid = self::kidOf($stored);
        if ($kid === null || strlen($stored) > self::MAX_STORED_BYTES) {
            throw new DecryptionFailed('The value is not a well-formed v3 envelope.');
        }
        $key = $this->ring->key($kid);
        $b64 = substr($stored, strlen(self::PREFIX) + strlen($kid) + 1);
        $raw = base64_decode($b64, true);
        // Canonical form only: a second spelling of the same bytes would be a malleability the tag cannot see.
        if ($raw === false || strlen($raw) < self::NONCE + self::TAG || base64_encode($raw) !== $b64) {
            throw new DecryptionFailed('The value is not a well-formed v3 envelope.');
        }
        $plain = openssl_decrypt(
            substr($raw, self::NONCE + self::TAG),
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            substr($raw, 0, self::NONCE),
            substr($raw, self::NONCE, self::TAG),
            $context
        );
        if ($plain === false) {
            throw new DecryptionFailed('The value could not be authenticated.');
        }

        return $plain;
    }

    public function needsRewrap(string $stored): bool
    {
        if ($stored === '') {
            return false;
        }
        $active = $this->ring->activeKid();

        return !(self::isV3($stored) && $active !== null && self::kidOf($stored) === $active);
    }

    public function rewrap(string $stored, string $context): string
    {
        $plain = $this->open($stored, $context);
        $new = $this->seal($plain, $context);
        if (!hash_equals($plain, $this->open($new, $context))) {
            throw new DecryptionFailed('The re-sealed value did not verify; the original was kept.');
        }

        return $new;
    }

    public function label(string $stored): string
    {
        if ($stored === '') {
            return 'empty';
        }
        if (self::isV3($stored)) {
            $kid = self::kidOf($stored);

            return $kid === null ? 'unknown' : 'v3:' . $kid;
        }
        foreach ($this->legacy as $reader) {
            if ($reader->supports($stored)) {
                return 'legacy:' . $reader->name();
            }
        }

        return 'unknown';
    }

    private static function assertContext(string $context): void
    {
        if ($context === '') {
            throw new \InvalidArgumentException('A context (record type and id) is required; it is the AAD that stops ciphertext being moved between records.');
        }
    }
}
