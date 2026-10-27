<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Mesh;

/**
 * MeshCentral node ids: one identity, three spellings, one canonical stored form.
 *
 * A MeshCentral node id is the SHA-384 of the agent's certificate key, so always 48 raw bytes. MeshCentral spells it
 *  - in its database and in the web UI as `node/<domain>/<id>`, with `<domain>` empty for the default domain (`node//<id>`);
 *  - as `<id>` = base64 of the 48 bytes with `+` -> `@` and `/` -> `$` and no padding: exactly 64 characters. That is the value of
 *    `gotonode=` in the address bar of a device page (`https://mesh.example/?viewmode=11&gotonode=<id>`), WITHOUT the `node//` prefix;
 *  - the MeshAgent can also print the same 48 bytes as 96 hex characters, which is what some agents report.
 * MeshCentral only finds a device when `gotonode` carries the base64 spelling, so the stored (canonical) form is `node/<domain>/<64-character id>`
 * (`node//<id>` for the default domain) and the other spellings are converted here, once, wherever an id enters the system or leaves it in a URL.
 *
 * Pure: no I/O. A pasted MeshCentral address is only parsed for its `gotonode` value, never fetched.
 *
 * @api
 */
final class MeshNodeId
{
    /** The length of the URL form: base64 of 48 bytes without padding. */
    public const URL_LENGTH = 64;
    public const HEX_LENGTH = 96;
    /** The longest input looked at (a pasted address can be long, a hostile one is cut off here). */
    public const MAX_INPUT = 2048;

    /** What an edition stored before this class existed: `node//` and 16 to 100 id characters, whatever they were. Still accepted, never rewritten blindly. */
    private const LEGACY = '#^node//[A-Za-z0-9@$_-]{16,100}\z#';

    /**
     * Anything a person or an agent may hand over, to the canonical stored form `node/<domain>/<64-char id>`, or null when it is not a node id:
     * a whole pasted MeshCentral address (its `gotonode`), `node/<domain>/<id>`, a bare 64-character id, 96 hex characters with or without the prefix.
     * Surrounding whitespace is ignored; anything else is strict (exactly 48 bytes, the alphabets, no control characters inside).
     */
    public static function normalize(string $in): ?string
    {
        $in = trim($in, " \t\r\n");
        if ($in === '' || strlen($in) > self::MAX_INPUT || preg_match('/[\x00-\x1f\x7f\s]/', $in) === 1) {
            return null;
        }
        if (preg_match('#^[A-Za-z][A-Za-z0-9+.-]*://#', $in) === 1 || $in[0] === '?') {
            $in = self::fromAddress($in);
            if ($in === null) {
                return null;
            }
        } elseif (str_contains($in, '%')) {
            $in = rawurldecode($in);   // a percent-encoded value copied out of an address
            if (preg_match('/[\x00-\x1f\x7f\s]/', $in) === 1) {
                return null;
            }
        }
        $domain = '';
        if (str_starts_with($in, 'node/')) {
            if (preg_match('#^node/([A-Za-z0-9_-]{0,32})/(.+)\z#', $in, $m) !== 1) {
                return null;
            }
            [$domain, $in] = [$m[1], $m[2]];
        }
        $raw = self::decode($in);

        return $raw === null ? null : 'node/' . $domain . '/' . self::encode($raw);
    }

    /**
     * What to store for an id that arrives from outside: the canonical form when it parses, else the id itself when it is a legacy-valid one (so a
     * database that holds an odd old value keeps working), else null.
     */
    public static function canonical(string $in): ?string
    {
        return self::normalize($in) ?? (preg_match(self::LEGACY, $in) === 1 ? $in : null);
    }

    /** True for what canonical() accepts. */
    public static function valid(string $in): bool
    {
        return self::canonical($in) !== null;
    }

    /** The 64-character value for `gotonode=`, `@` and `$` exactly as MeshCentral prints them (no prefix), or null when $in is not an id. */
    public static function toUrlForm(string $in): ?string
    {
        $n = self::normalize($in);

        return $n === null ? null : substr($n, (int) strrpos($n, '/') + 1);
    }

    /** The 96-character lower-case hex of the same 48 bytes, or null. */
    public static function toHex(string $in): ?string
    {
        $url = self::toUrlForm($in);
        $raw = $url === null ? null : self::decode($url);

        return $raw === null ? null : bin2hex($raw);
    }

    /** The MeshCentral domain id inside `node/<domain>/<id>` ('' for the default domain), or null when $in is not an id. */
    public static function domain(string $in): ?string
    {
        $n = self::normalize($in);

        return $n === null ? null : substr($n, 5, (int) strrpos($n, '/') - 5);
    }

    /**
     * Every spelling a stored row may carry for the same node (canonical, and the hex in both cases), for an `IN (...)` lookup that must find
     * rows written before the ids were normalised. Empty when $in is not a parseable id.
     *
     * @return list<string>
     */
    public static function spellings(string $in): array
    {
        $n = self::normalize($in);
        $hex = self::toHex($in);
        if ($n === null || $hex === null) {
            return [];
        }
        $prefix = substr($n, 0, (int) strrpos($n, '/') + 1);

        return [$n, $prefix . $hex, $prefix . strtoupper($hex), $prefix . self::toUrlForm($n)];
    }

    /** The value for `&gotonode=` in a launch URL: the URL form of a parseable id, else the legacy id without its prefix; anything outside [A-Za-z0-9@$_-] is percent-encoded. */
    public static function forLaunch(string $stored): string
    {
        $v = self::toUrlForm($stored) ?? (string) preg_replace('#^node/[^/]*/#', '', $stored);

        return (string) preg_replace_callback('/[^A-Za-z0-9@$_-]/', static fn (array $m): string => rawurlencode($m[0]), $v);
    }

    /** The `gotonode` value of a pasted address (http or https, or just a query string), or null. Never fetched. */
    private static function fromAddress(string $addr): ?string
    {
        if (str_contains($addr, '://')) {
            if (preg_match('#^https?://#i', $addr) !== 1) {
                return null;
            }
            $p = parse_url($addr);
            if ($p === false || empty($p['host'])) {
                return null;
            }
            $query = $p['query'] ?? '';
            // MeshCentral also keeps parameters after a '#': https://mesh/#viewmode=11 is not its shape, but accept a fragment query as a fallback
            if ($query === '' && isset($p['fragment'])) {
                $query = ltrim($p['fragment'], '?');
            }
        } else {
            $query = substr($addr, (int) strpos($addr, '?') + 1);
            $query = explode('#', $query, 2)[0];
        }
        foreach (explode('&', $query) as $pair) {
            $kv = explode('=', $pair, 2);
            if (rawurldecode($kv[0]) === 'gotonode' && isset($kv[1])) {
                $v = rawurldecode($kv[1]);
                if (preg_match('/[\x00-\x1f\x7f\s]/', $v) === 1) {
                    return null;
                }

                return $v;
            }
        }

        return null;
    }

    /** The 48 raw bytes behind an id (no prefix): 96 hex characters, or 64 base64 characters in MeshCentral's alphabet (standard and URL-safe base64 are tolerated). */
    private static function decode(string $id): ?string
    {
        if (strlen($id) === self::HEX_LENGTH && ctype_xdigit($id)) {
            $raw = hex2bin($id);

            return $raw === false ? null : $raw;
        }
        if (strlen($id) !== self::URL_LENGTH || preg_match('#^[A-Za-z0-9@$+/_-]{64}\z#', $id) !== 1) {
            return null;
        }
        $raw = base64_decode(strtr($id, '@$-_', '+/+/'), true);

        return $raw !== false && strlen($raw) === 48 ? $raw : null;
    }

    private static function encode(string $raw): string
    {
        return strtr(rtrim(base64_encode($raw), '='), '+/', '@$');
    }
}
