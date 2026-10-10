<?php

declare(strict_types=1);

namespace RivetCore\Crypto;

/**
 * Password-hardening parameters stored inside every vault key wrap, so they can be raised later and old wraps still open.
 * Argon2id (libsodium) when available, else PBKDF2-HMAC-SHA256 with at least 600000 iterations (OWASP 2023 and later).
 *
 * @api
 */
final class KdfParams
{
    public const ARGON2ID = 'argon2id';
    public const PBKDF2 = 'pbkdf2-sha256';
    public const MIN_ARGON_MEMORY_KIB = 19456;
    public const MIN_ARGON_OPS = 2;
    public const MAX_ARGON_MEMORY_KIB = 1048576;
    public const MAX_ARGON_OPS = 20;
    public const MIN_PBKDF2_ITERATIONS = 600000;
    public const MAX_PBKDF2_ITERATIONS = 10000000;

    private function __construct(
        public readonly string $kdf,
        public readonly int $memoryKib,
        public readonly int $ops,
        public readonly int $iterations,
    ) {
    }

    public static function argon2idAvailable(): bool
    {
        return function_exists('sodium_crypto_pwhash') && defined('SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13');
    }

    /** Argon2id when libsodium has it, else PBKDF2 at 600000 iterations. */
    public static function preferred(): self
    {
        return self::argon2idAvailable() ? self::argon2id() : self::pbkdf2();
    }

    /** @throws InvalidKeyMaterial below the minimum or above the sanity cap */
    public static function argon2id(int $memoryKib = 65536, int $ops = 3): self
    {
        if (!self::argon2idAvailable()) {
            throw new InvalidKeyMaterial('Argon2id is not available in this PHP; use PBKDF2.');
        }

        return self::checked(new self(self::ARGON2ID, $memoryKib, $ops, 0));
    }

    /** @throws InvalidKeyMaterial */
    public static function pbkdf2(int $iterations = self::MIN_PBKDF2_ITERATIONS): self
    {
        return self::checked(new self(self::PBKDF2, 0, 0, $iterations));
    }

    /** Parses the `<kdf>:<params>` part of a wrap. @throws InvalidKeyMaterial */
    public static function parse(string $kdf, string $params): self
    {
        if ($kdf === self::ARGON2ID && preg_match('/^m=(\d{1,8}),t=(\d{1,3})$/', $params, $m) === 1) {
            return self::checked(new self(self::ARGON2ID, (int) $m[1], (int) $m[2], 0));
        }
        if ($kdf === self::PBKDF2 && preg_match('/^i=(\d{1,9})$/', $params, $m) === 1) {
            return self::checked(new self(self::PBKDF2, 0, 0, (int) $m[1]));
        }
        throw new InvalidKeyMaterial('Unknown or malformed key-derivation parameters.');
    }

    public function paramString(): string
    {
        return $this->kdf === self::ARGON2ID ? 'm=' . $this->memoryKib . ',t=' . $this->ops : 'i=' . $this->iterations;
    }

    /** True when $other is stronger in any dimension, or a different (preferred) KDF. */
    public function weakerThan(KdfParams $other): bool
    {
        if ($this->kdf !== $other->kdf) {
            return $other->kdf === self::ARGON2ID;
        }

        return $this->memoryKib < $other->memoryKib || $this->ops < $other->ops || $this->iterations < $other->iterations;
    }

    /** 32 key bytes from the password. */
    public function derive(#[\SensitiveParameter] string $password, string $salt): string
    {
        if ($this->kdf === self::ARGON2ID) {
            if (!self::argon2idAvailable()) {
                throw new KeyUnavailable('This wrap needs Argon2id, which this PHP lacks.');
            }

            return sodium_crypto_pwhash(32, $password, $salt, $this->ops, $this->memoryKib * 1024, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
        }

        return hash_pbkdf2('sha256', $password, $salt, $this->iterations, 32, true);
    }

    private static function checked(self $p): self
    {
        $ok = $p->kdf === self::ARGON2ID
            ? $p->memoryKib >= self::MIN_ARGON_MEMORY_KIB && $p->memoryKib <= self::MAX_ARGON_MEMORY_KIB && $p->ops >= self::MIN_ARGON_OPS && $p->ops <= self::MAX_ARGON_OPS
            : $p->iterations >= self::MIN_PBKDF2_ITERATIONS && $p->iterations <= self::MAX_PBKDF2_ITERATIONS;
        if (!$ok) {
            throw new InvalidKeyMaterial('Key-derivation parameters are outside the allowed range (Argon2id: 19 MiB-1 GiB, 2-20 passes; PBKDF2: 600000-10000000 iterations).');
        }

        return $p;
    }
}
