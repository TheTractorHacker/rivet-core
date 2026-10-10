<?php

declare(strict_types=1);

namespace RivetCore\Crypto;

/**
 * Creates keys. Everything comes from random_bytes; nothing here derives a key from a passphrase.
 *
 * @api
 */
final class KeyGenerator
{
    /** The constant the editions use for backup manifests; it is part of the on-disk format, do not change it. */
    public const FINGERPRINT_PREFIX = 'rivetit-settings-key-fingerprint|v1|';

    public static function key(): string
    {
        return random_bytes(KeyRing::KEY_BYTES);
    }

    /** A new, unique key id such as "k20261010-3fa9": sortable by creation date. */
    public static function kid(?\DateTimeInterface $now = null): string
    {
        return 'k' . ($now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Ymd') . '-' . bin2hex(random_bytes(2));
    }

    /** A ring holding one fresh active key. */
    public static function newRing(?\DateTimeInterface $now = null): KeyRing
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $kid = self::kid($now);

        return KeyRing::fromKeys([$kid => self::key()], $kid, [$kid => $now->format('c')]);
    }

    /** A rotation step: the same ring plus a new key that becomes active; the previous keys stay decrypt-only. */
    public static function rotate(KeyRing $ring, ?\DateTimeInterface $now = null): KeyRing
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $ring->withKey(self::key(), self::kid($now), $now->format('c'), true);
    }

    /**
     * 16 hex characters identifying a key without revealing it: `substr(sha256('rivetit-settings-key-fingerprint|v1|' . $key), 0, 16)`,
     * the same formula as RivetIT's backup manifest and deploy/backup.sh. '' for ''. $key is the key as the edition configures it (the
     * hex string); {@see KeyRing::fingerprint()} passes the hex form of a raw key.
     */
    public static function fingerprint(#[\SensitiveParameter] string $key): string
    {
        return $key === '' ? '' : substr(hash('sha256', self::FINGERPRINT_PREFIX . $key), 0, 16);
    }

    /**
     * A ring from an existing `$config_settings_enc_key`: when it is 64 hex characters its bytes ARE the key (so v3 values and the
     * legacy `ENC2:` values share one root and the fingerprint is unchanged); any other string is hashed with SHA-256 (the legacy rule).
     */
    public static function ringFromLegacySettingsKey(#[\SensitiveParameter] string $legacy, string $kid = 'k1'): KeyRing
    {
        $raw = preg_match('/^[0-9a-fA-F]{64}$/', $legacy) === 1 ? (string) hex2bin($legacy) : hash('sha256', $legacy, true);

        return KeyRing::single($raw, $kid);
    }
}
