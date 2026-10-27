<?php

declare(strict_types=1);

namespace RivetCore\Crypto;

/**
 * Immutable set of 32-byte keys by key id (`kid`), with at most one active key. The active key seals; every key in the ring opens.
 * A ring with no active key is valid (decrypt-only, or "not configured yet") and makes sealing throw {@see KeyUnavailable}.
 *
 * @api
 */
final class KeyRing
{
    public const KEY_BYTES = 32;
    public const KID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_.-]{0,31}$/';

    /**
     * @param array<string,string> $keys
     * @param array<string,string> $created
     */
    private function __construct(
        private array $keys,
        private ?string $active,
        private array $created,
    ) {
    }

    public static function empty(): self
    {
        return new self([], null, []);
    }

    /** A ring with one active key. */
    public static function single(#[\SensitiveParameter] string $key, string $kid = 'k1'): self
    {
        return self::fromKeys([$kid => $key], $kid);
    }

    /**
     * @param array<string,string> $keys kid => 32 raw bytes
     * @param array<string,string> $created kid => ISO-8601 date (informational, shown on the rotation panel)
     * @throws InvalidKeyMaterial
     */
    public static function fromKeys(#[\SensitiveParameter] array $keys, ?string $activeKid, array $created = []): self
    {
        $seen = [];
        $clean = [];
        foreach ($keys as $kid => $key) {
            $kid = (string) $kid;
            self::assertKid($kid);
            self::assertKey($key);
            if (isset($seen[$key])) {
                throw new InvalidKeyMaterial('Two key ids share the same key bytes; a rotation must introduce a new key.');
            }
            $seen[$key] = true;
            $clean[$kid] = $key;
        }
        if ($activeKid !== null && !isset($clean[$activeKid])) {
            throw new InvalidKeyMaterial('The active key id is not in the ring.');
        }
        $meta = [];
        foreach ($created as $kid => $when) {
            if (isset($clean[(string) $kid])) {
                $meta[(string) $kid] = (string) $when;
            }
        }

        return new self($clean, $activeKid, $meta);
    }

    /** @throws InvalidKeyMaterial */
    public static function assertKid(string $kid): void
    {
        if (preg_match(self::KID_PATTERN, $kid) !== 1) {
            throw new InvalidKeyMaterial('A key id is 1-32 characters of [A-Za-z0-9_.-] and starts with a letter or digit (no colon).');
        }
    }

    /**
     * Refuses anything that is not 32 bytes, and keys with fewer than 8 distinct byte values (all zeros, repeated patterns, a short
     * ASCII word padded to length). A key from random_bytes(32) has about 28 distinct values, so the odds of a false refusal are nil.
     *
     * @throws InvalidKeyMaterial
     */
    public static function assertKey(#[\SensitiveParameter] string $key): void
    {
        if (strlen($key) !== self::KEY_BYTES) {
            throw new InvalidKeyMaterial('A key must be exactly 32 raw bytes.');
        }
        if (strlen(count_chars($key, 3)) < 8) {
            throw new InvalidKeyMaterial('Refusing a low-entropy key; generate keys with KeyGenerator::key() (random_bytes).');
        }
    }

    public function activeKid(): ?string
    {
        return $this->active;
    }

    public function hasActive(): bool
    {
        return $this->active !== null;
    }

    public function isEmpty(): bool
    {
        return $this->keys === [];
    }

    /** @return list<string> */
    public function kids(): array
    {
        return array_map('strval', array_keys($this->keys));
    }

    public function has(string $kid): bool
    {
        return isset($this->keys[$kid]);
    }

    /** @throws UnknownKeyId */
    public function key(string $kid): string
    {
        return $this->keys[$kid] ?? throw new UnknownKeyId($kid);
    }

    /** @throws KeyUnavailable */
    public function activeKey(): string
    {
        if ($this->active === null) {
            throw new KeyUnavailable('No active encryption key is configured; refusing to continue without one.');
        }

        return $this->keys[$this->active];
    }

    public function createdAt(string $kid): ?string
    {
        return $this->created[$kid] ?? null;
    }

    /** Short public identifier of a key for backup manifests and the rotation panel: {@see KeyGenerator::fingerprint()} of its hex form. */
    public function fingerprint(string $kid): string
    {
        return KeyGenerator::fingerprint(bin2hex($this->key($kid)));
    }

    /** @throws InvalidKeyMaterial */
    public function withKey(#[\SensitiveParameter] string $key, string $kid, ?string $created = null, bool $makeActive = false): self
    {
        $keys = $this->keys;
        $keys[$kid] = $key;
        $meta = $this->created;
        if ($created !== null) {
            $meta[$kid] = $created;
        }

        return self::fromKeys($keys, $makeActive ? $kid : $this->active, $meta);
    }

    /** @throws InvalidKeyMaterial */
    public function withActive(string $kid): self
    {
        return self::fromKeys($this->keys, $kid, $this->created);
    }

    /** Removes a retired key. The active key cannot be removed. @throws InvalidKeyMaterial */
    public function without(string $kid): self
    {
        if ($kid === $this->active) {
            throw new InvalidKeyMaterial('The active key cannot be removed; activate another key first.');
        }
        $keys = $this->keys;
        unset($keys[$kid]);

        return self::fromKeys($keys, $this->active, $this->created);
    }

    /** The same kids with HKDF-derived keys for one purpose label. */
    public function derive(string $purpose): self
    {
        $keys = [];
        foreach ($this->keys as $kid => $key) {
            $keys[$kid] = KeyPurpose::derive($key, $purpose);
        }

        return new self($keys, $this->active, $this->created);
    }

    /** @return array<string,mixed> */
    public function __debugInfo(): array
    {
        return ['kids' => $this->kids(), 'active' => $this->active];
    }
}
