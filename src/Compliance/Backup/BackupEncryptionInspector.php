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

    /**
     * CORE-DS-29: a binary OpenPGP message starts with an encrypted-data packet whose header parses (old or new format, a plausible length) and whose first body byte is the version
     * that kind of packet has: PKESK 3 or 6, SKESK 4, 5 or 6, SEIPD 1 or 2. A first byte that merely has the high bit set (UTF-8 text starting with a Latin-1 letter, MessagePack, other
     * binary formats) fails this. The legacy symmetrically-encrypted-data packet (9) has no version and is not recognised here: use an armored message or the suffix hint.
     */
    private static function looksLikeBinaryPgpEncrypted(string $head): bool
    {
        $n = strlen($head);
        $b = ord($head[0]);
        if (($b & 0x80) === 0) {
            return false;
        }
        if (($b & 0x40) !== 0) {
            $tag = $b & 0x3f;
            if ($n < 2) {
                return false;
            }
            $l = ord($head[1]);
            if ($l < 192) {
                $len = $l;
                $at = 2;
            } elseif ($l < 224) {
                if ($n < 3) {
                    return false;
                }
                $len = (($l - 192) << 8) + ord($head[2]) + 192;
                $at = 3;
            } elseif ($l === 255) {
                if ($n < 6) {
                    return false;
                }
                $len = (ord($head[2]) << 24) | (ord($head[3]) << 16) | (ord($head[4]) << 8) | ord($head[5]);
                $at = 6;
            } else {
                // partial body length: only a data packet may use it
                $len = null;
                $at = 2;
                if ($tag !== 18) {
                    return false;
                }
            }
        } else {
            $tag = ($b >> 2) & 0x0f;
            $lenType = $b & 0x03;
            $width = [1, 2, 4][$lenType] ?? 0;
            if ($width === 0 || $n < 1 + $width) {
                return false;   // indeterminate length: only for data packets, which have no version we can check
            }
            $len = 0;
            for ($k = 1; $k <= $width; $k++) {
                $len = ($len << 8) | ord($head[$k]);
            }
            $at = 1 + $width;
        }
        $versions = [1 => [3, 6], 3 => [4, 5, 6], 18 => [1, 2]][$tag] ?? null;
        if ($versions === null || $at >= $n || ($len !== null && $len < 2)) {
            return false;
        }

        return in_array(ord($head[$at]), $versions, true);
    }
}
