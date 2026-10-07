<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Installer;

use RivetCore\Rmm\RmmProtocol;

/**
 * The stamped-installer file format shared with the Go agent (pure functions, no I/O, no database).
 *
 *   stamped_exe = <original exe bytes> || payload || footer
 *   payload     = UTF-8 JSON object, at most 16384 bytes
 *   footer      = 52 bytes: uint32 big-endian payload length || 32-byte raw SHA-256 of the payload || 16 ASCII bytes "RIVETIT-EMBED-v1"
 *
 * The agent reads the LAST 52 bytes of its own executable, checks the magic, the length bound and the SHA-256, then parses the
 * payload. The SHA-256 is an integrity check against truncation and corruption, not authentication: whoever can read the payload
 * already holds the enrollment token it carries, which is the secret. endpoint-agent/testdata/vectors/agent_installer_trailer_vectors.json
 * pins the bytes.
 *
 * @api
 */
final class InstallerStamp
{
    public const MAGIC = RmmProtocol::EMBED_MAGIC;
    public const FOOTER_LEN = RmmProtocol::EMBED_FOOTER_LEN;
    public const MAX_PAYLOAD = RmmProtocol::EMBED_MAX_PAYLOAD;
    public const PAYLOAD_VERSION = RmmProtocol::EMBED_PAYLOAD_VERSION;

    /**
     * @param array<string,mixed> $fields installer_id, server_url, enrollment_token, department, created_at, expires_at, optional ca_pem
     * @throws \LengthException when the payload exceeds MAX_PAYLOAD
     */
    public static function buildPayload(array $fields): string
    {
        $p = [
            'version' => self::PAYLOAD_VERSION,
            'installer_id' => self::text($fields['installer_id']),
            'server_url' => self::text($fields['server_url']),
            'enrollment_token' => self::text($fields['enrollment_token']),
            'department' => self::text($fields['department']),
            'ca_pem' => isset($fields['ca_pem']) && $fields['ca_pem'] !== '' ? self::text($fields['ca_pem']) : null,
            'created_at' => self::text($fields['created_at']),
            'expires_at' => self::text($fields['expires_at']),
        ];
        $json = json_encode($p, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($json) > self::MAX_PAYLOAD) {
            throw new \LengthException('installer payload is larger than ' . self::MAX_PAYLOAD . ' bytes');
        }

        return $json;
    }

    /** The 52-byte footer for a payload. @throws \LengthException when the payload is empty or over MAX_PAYLOAD */
    public static function footer(string $payload): string
    {
        $n = strlen($payload);
        if ($n === 0 || $n > self::MAX_PAYLOAD) {
            throw new \LengthException('installer payload must be 1 to ' . self::MAX_PAYLOAD . ' bytes');
        }

        return pack('N', $n) . hash('sha256', $payload, true) . self::MAGIC;
    }

    /** payload || footer: what is appended to the unstamped exe (also what a streaming download writes after the file). */
    public static function trailer(string $payload): string
    {
        return $payload . self::footer($payload);
    }

    public static function stamp(string $exe, string $payload): string
    {
        return $exe . self::trailer($payload);
    }

    /** True when the last 16 bytes are the magic (a pre-stamped file). The magic string inside the body is NOT a footer. */
    public static function hasFooter(string $tail): bool
    {
        return strlen($tail) >= 16 && substr($tail, -16) === self::MAGIC;
    }

    /**
     * Read a stamped file the way the agent does. Returns the decoded payload, or null when any check fails.
     *
     * @return array{payload:string,exe_length:int,data:array<string,mixed>}|null
     */
    public static function read(string $stamped): ?array
    {
        $total = strlen($stamped);
        if ($total < self::FOOTER_LEN || substr($stamped, -16) !== self::MAGIC) {
            return null;
        }
        $footer = substr($stamped, -self::FOOTER_LEN);
        $unpacked = unpack('N', substr($footer, 0, 4));
        $len = is_array($unpacked) ? (int) $unpacked[1] : 0;
        if ($len < 1 || $len > self::MAX_PAYLOAD || $len > $total - self::FOOTER_LEN) {
            return null;
        }
        $payload = substr($stamped, $total - self::FOOTER_LEN - $len, $len);
        if (!hash_equals(substr($footer, 4, 32), hash('sha256', $payload, true))) {
            return null;
        }
        if (!is_object(json_decode($payload))) {
            return null;   // must be a JSON object (an empty {} is one; a list or scalar is not)
        }
        /** @var array<string,mixed> $data */
        $data = (array) json_decode($payload, true);

        return ['payload' => $payload, 'exe_length' => $total - self::FOOTER_LEN - $len, 'data' => $data];
    }

    private static function text(mixed $v): string
    {
        return is_scalar($v) || $v instanceof \Stringable ? (string) $v : '';
    }
}
