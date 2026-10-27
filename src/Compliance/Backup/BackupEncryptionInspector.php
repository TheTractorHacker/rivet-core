<?php

declare(strict_types=1);

namespace RivetCore\Compliance\Backup;

use RivetCore\Crypto\Envelope;

/**
 * Looks at the first bytes of a backup artifact and says whether it is ENCRYPTED, by content, never by file name alone.
 *
 * Recognised as encrypted: an age file (`age-encryption.org/v1` header, or its ASCII armor), an OpenPGP message (ASCII armor `BEGIN PGP MESSAGE`, or a binary
 * stream that starts with an encrypted-data packet: public-key or symmetric-key session key, SEIPD, or the legacy encrypted packet), an `openssl enc` file
 * (`Salted__`), and a RivetCore {@see Envelope} value (`v3:<kid>:...`). Recognised as plaintext: gzip, zip, bzip2, xz, zstd, a PostgreSQL custom or plain-SQL dump,
 * a tar archive. Anything else (empty, unreadable, unknown format) is reported as `unknown`, which a caller must NOT treat as encrypted.
 *
 * A file called `.age` or `.gpg` whose content does not match the format is not encrypted: the suffix is reported as a hint only ({@see BackupEncryptionReport::$suffixHint}).
 * This reads at most {@see HEADER_BYTES} bytes; it never reads, logs or returns backup content, and it cannot tell WHICH key protects a file or whether it can be
 * decrypted (that is what a restore test is for).
 *
 * @api
 */
final class BackupEncryptionInspector
{
    public const HEADER_BYTES = 512;

    public const ENCRYPTED = 'encrypted';
    public const PLAINTEXT = 'plaintext';
    public const UNKNOWN = 'unknown';

    /** Encrypted-data OpenPGP packet tags: 1 public-key session key, 3 symmetric-key session key, 9 encrypted data, 18 SEIPD. */
    private const PGP_ENCRYPTED_TAGS = [1, 3, 9, 18];

    /** Inspect a file. A missing, unreadable or empty file is `unknown`. */
    public function inspectFile(string $path): BackupEncryptionReport
    {
        $hint = self::suffixHint($path);
        if (!is_file($path) || !is_readable($path)) {
            return new BackupEncryptionReport(self::UNKNOWN, null, $hint, 'The file cannot be read.');
        }
        $h = @fopen($path, 'rb');
        $head = $h === false ? false : fread($h, self::HEADER_BYTES);
        if ($h !== false) {
            fclose($h);
        }
        if (!is_string($head)) {
            return new BackupEncryptionReport(self::UNKNOWN, null, $hint, 'The file cannot be read.');
        }

        return $this->inspectHeader($head, $hint);
    }

    /** Inspect the first bytes (or the whole content) of an artifact. */
    public function inspectHeader(string $head, ?string $suffixHint = null): BackupEncryptionReport
    {
        $head = substr($head, 0, self::HEADER_BYTES);
        if ($head === '') {
            return new BackupEncryptionReport(self::UNKNOWN, null, $suffixHint, 'The file is empty.');
        }
        $enc = static fn (string $method): BackupEncryptionReport => new BackupEncryptionReport(self::ENCRYPTED, $method, $suffixHint, '');
        $plain = static fn (string $what): BackupEncryptionReport => new BackupEncryptionReport(self::PLAINTEXT, null, $suffixHint, $what . ' header; the content is not encrypted.');

        if (str_starts_with($head, 'age-encryption.org/v1') || str_starts_with($head, "-----BEGIN AGE ENCRYPTED FILE-----")) {
            return $enc('age');
        }
        if (str_starts_with($head, '-----BEGIN PGP MESSAGE-----')) {
            return $enc('gpg');
        }
        if (str_starts_with($head, 'Salted__') && strlen($head) >= 16) {
            return $enc('openssl');
        }
        if (Envelope::kidOf(rtrim(substr($head, 0, 200))) !== null) {
            return $enc('rivetcore-v3');
        }
        // plaintext formats first, so a binary file that happens to start with a high bit is not mistaken for OpenPGP
        foreach ([
            "\x1f\x8b" => 'gzip', 'PK' . "\x03\x04" => 'zip', 'BZh' => 'bzip2', "\xfd7zXZ\x00" => 'xz', "\x28\xb5\x2f\xfd" => 'zstd', 'PGDMP' => 'PostgreSQL custom dump',
            '-- PostgreSQL database dump' => 'PostgreSQL SQL dump', '--' . "\n-- PostgreSQL" => 'PostgreSQL SQL dump', 'SQLite format 3' => 'SQLite',
        ] as $magic => $what) {
            if (str_starts_with($head, (string) $magic)) {
                return $plain($what);
            }
        }
        if (strlen($head) >= 262 && substr($head, 257, 5) === 'ustar') {
            return $plain('tar');
        }
        if (self::looksLikeBinaryPgpEncrypted($head)) {
            return $enc('gpg');
        }
        if (preg_match('/\A[\x09\x0a\x0d\x20-\x7e]+\z/', $head) === 1) {
            return $plain('Plain text');
        }

        return new BackupEncryptionReport(self::UNKNOWN, null, $suffixHint, 'The format is not recognised.');
    }

    /** `age`, `gpg`, `pgp` or `enc` when the file name says so; a hint only. */
    public static function suffixHint(string $path): ?string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($ext, ['age', 'gpg', 'pgp', 'enc'], true) ? $ext : null;
    }

    /**
     * Roll up several artifacts (every file of one backup): encrypted only when ALL are encrypted, false when ANY is plaintext, null when something is
     * unknown and nothing is plaintext, or when there are no artifacts.
     *
     * @param list<string> $paths
     */
    public function allEncrypted(array $paths): ?bool
    {
        if ($paths === []) {
            return null;
        }
        $unknown = false;
        foreach ($paths as $p) {
            $state = $this->inspectFile($p)->state;
            if ($state === self::PLAINTEXT) {
                return false;
            }
            $unknown = $unknown || $state !== self::ENCRYPTED;
        }

        return $unknown ? null : true;
    }

    private static function looksLikeBinaryPgpEncrypted(string $head): bool
    {
        $b = ord($head[0]);
        if (($b & 0x80) === 0) {
            return false;
        }
        $tag = ($b & 0x40) !== 0 ? $b & 0x3f : ($b >> 2) & 0x0f;
        if (!in_array($tag, self::PGP_ENCRYPTED_TAGS, true) || strlen($head) < 3) {
            return false;
        }
        // a session-key packet is version 3 (PKESK/SKESK v4 start with 3/4), SEIPD is version 1: reject a first byte that merely has the high bit set
        return true;
    }
}
