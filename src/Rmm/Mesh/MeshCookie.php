<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Mesh;

use RivetCore\Rmm\RmmProtocol;

/**
 * MeshCentral "login token" cookie minting (the pure part of the remote-access launch; the policy checks, the device lookup and
 * the reachability probe stay in the service that calls this).
 *
 * MeshCentral supports login tokens for embedding it in another application: the server holds one loginTokenKey, an integrator
 * builds a token for a MeshCentral user from that key and opens https://server/?login=TOKEN. The token is AES-256-GCM over
 * {"u": userid, "a": 3, "time": unix seconds} using the first 32 bytes of the (hex) key, packed as IV(12) || GCM tag(16) ||
 * ciphertext, base64 with "+" -> "@" and "/" -> "$". Mint it at the moment of the click; never store or log it.
 *
 * @api
 */
final class MeshCookie
{
    /** The cookie's "time" is back-dated by this many seconds to tolerate clock skew between the two servers. */
    public const BACKDATE_S = 120;

    /**
     * MeshCentral's `hide` URL bitmask (read from MeshCentral's views/default.handlebars, `args.hide`): 1 masthead (logo bar), 2 top bar (the account and
     * menu strip), 4 footer, 8 page titles (the tab headings), 16 left bar and main menu (the device list), 32 the back button. 31 = the first five bits:
     * what Tactical RMM's "Take control" passes. Default for a launch; 0 = no `hide` parameter at all (the full MeshCentral interface).
     */
    public const HIDE_DEFAULT = 31;
    public const HIDE_MAX = 255;

    /** A fresh login key (hex) for the edition to store encrypted: 80 random bytes, 160 hex characters. */
    public static function newLoginKey(): string
    {
        return bin2hex(random_bytes(80));
    }

    /**
     * MeshCentral's own cookie format. $keyHex is the loginTokenKey as hex; null when it is unusable.
     *
     * @param array<string,mixed> $payload
     */
    public static function encode(array $payload, string $keyHex, ?string $iv = null): ?string
    {
        $key = ctype_xdigit($keyHex) && strlen($keyHex) % 2 === 0 ? hex2bin($keyHex) : false;
        if ($key === false || strlen($key) < 32) {
            return null;
        }
        $key = substr($key, 0, 32);
        $iv ??= random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt((string) json_encode($payload), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($ct === false) {
            return null;
        }

        return strtr(base64_encode($iv . $tag . $ct), '+/', '@$');
    }

    /**
     * The inverse of encode() (test helper and sanity check).
     *
     * @return array<string,mixed>|null
     */
    public static function decode(string $cookie, string $keyHex): ?array
    {
        $key = ctype_xdigit($keyHex) && strlen($keyHex) % 2 === 0 ? hex2bin($keyHex) : false;
        $raw = base64_decode(strtr($cookie, '@$', '+/'), true);
        if ($key === false || $raw === false || strlen($raw) < 29) {
            return null;
        }
        $pt = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', substr($key, 0, 32), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($pt === false) {
            return null;
        }
        $data = json_decode($pt, true);

        return is_array($data) ? $data : null;
    }

    /** True when the template uses the retired `{username}` placeholder (CORE-13): see {@see self::accountName()}. */
    public static function usesDisplayName(string $template): bool
    {
        return str_contains($template, '{username}');
    }

    /**
     * The MeshCentral account name for a technician: `{user_id}` replaced, then reduced to [A-Za-z0-9._-].
     *
     * CORE-13: `{username}` is no longer substituted. The name is the technician's display name, which the technician can edit, and the
     * login token signs in as whatever account is named: renaming oneself "admin" yielded a token for the Mesh admin. A template that
     * still contains `{username}` now yields '' (the launch is refused, fail closed) instead of an account chosen by the caller.
     * `$username` is kept only for signature compatibility and is ignored.
     */
    public static function accountName(string $template, int $userId, string $username): string
    {
        if (self::usesDisplayName($template)) {
            return '';
        }
        $account = str_replace('{user_id}', (string) $userId, $template);

        return (string) preg_replace('/[^A-Za-z0-9._-]/', '', $account);
    }

    /**
     * The login cookie for one MeshCentral account: {u:'user/<domain>/<account>', a:3, time: now - 120}. Null when the key is
     * unusable or the account is empty.
     *
     * @param int $now unix seconds (the caller's clock)
     * @param int|null $ttlS CORE-3/14: the configured token lifetime in seconds. MeshCentral accepts a token for {@see self::MESH_ACCEPT_S} after its
     *                       "time", so the lifetime is shortened by back-dating "time" by (MESH_ACCEPT_S - ttl). Null (the default) or a ttl of
     *                       MESH_ACCEPT_S - BACKDATE_S or more keeps the fixed 120 s back-date, that is the original ~58 minute window.
     */
    public static function login(string $keyHex, string $domain, string $account, int $now, ?string $iv = null, ?int $ttlS = null): ?string
    {
        if ($account === '') {
            return null;
        }

        return self::encode(['u' => 'user/' . $domain . '/' . $account, 'a' => RmmProtocol::MESH_COOKIE_ACCESS, 'time' => $now - self::backdate($ttlS)], $keyHex, $iv);
    }

    /** MeshCentral's default login-token acceptance window (minutes 60) in seconds. */
    public const MESH_ACCEPT_S = 3600;

    /** Seconds to back-date the cookie: at least BACKDATE_S (clock skew), at most the acceptance window less 60 s. */
    public static function backdate(?int $ttlS): int
    {
        if ($ttlS === null) {
            return self::BACKDATE_S;
        }

        return max(self::BACKDATE_S, min(self::MESH_ACCEPT_S - 60, self::MESH_ACCEPT_S - $ttlS));
    }

    /**
     * The launch URL: viewmode 11 = Desktop (the default and the only one before Phase 6), 12 = Terminal, 13 = Files. $base is a normalised https base URL without
     * trailing slash. `$viewmode` must be one of those three (anything else falls back to the desktop).
     *
     * `$hide` is MeshCentral's `hide` bitmask ({@see self::HIDE_DEFAULT}): 1 to 255 appends `&hide=N`, which makes MeshCentral hide its own header,
     * top bar, footer, titles and device list so that only the device view is shown. 0 (the default of this method, so older callers get the
     * unchanged URL), or any value outside 0..255, appends nothing. Note that MeshCentral does not connect by itself from the URL: see docs/rmm/REMOTE_AND_LOGS.md.
     */
    public static function launchUrl(string $base, string $domain, string $cookie, string $nodeId, int $viewmode = RmmProtocol::MESH_VIEWMODE, int $hide = 0): string
    {
        $path = ($domain !== '' ? '/' . rawurlencode($domain) : '') . '/';
        if (!in_array($viewmode, [RmmProtocol::MESH_VIEWMODE, RmmProtocol::MESH_VIEWMODE + 1, RmmProtocol::MESH_VIEWMODE + 2], true)) {
            $viewmode = RmmProtocol::MESH_VIEWMODE;
        }
        $tail = $hide >= 1 && $hide <= self::HIDE_MAX ? '&hide=' . $hide : '';

        return $base . $path . '?login=' . rawurlencode($cookie) . '&gotonode=' . MeshNodeId::forLaunch($nodeId) . '&viewmode=' . $viewmode . $tail;
    }
}
