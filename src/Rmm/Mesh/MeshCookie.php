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

    /** The MeshCentral account name for a technician: template placeholders replaced, then reduced to [A-Za-z0-9._-]. */
    public static function accountName(string $template, int $userId, string $username): string
    {
        $account = str_replace(['{user_id}', '{username}'], [(string) $userId, (string) preg_replace('/[^A-Za-z0-9._-]/', '', $username)], $template);

        return (string) preg_replace('/[^A-Za-z0-9._-]/', '', $account);
    }

    /**
     * The login cookie for one MeshCentral account: {u:'user/<domain>/<account>', a:3, time: now - 120}. Null when the key is
     * unusable or the account is empty.
     *
     * @param int $now unix seconds (the caller's clock)
     */
    public static function login(string $keyHex, string $domain, string $account, int $now, ?string $iv = null): ?string
    {
        if ($account === '') {
            return null;
        }

        return self::encode(['u' => 'user/' . $domain . '/' . $account, 'a' => RmmProtocol::MESH_COOKIE_ACCESS, 'time' => $now - self::BACKDATE_S], $keyHex, $iv);
    }

    /** The launch URL: viewmode 11 = Desktop. $base is a normalised https base URL without trailing slash. */
    public static function launchUrl(string $base, string $domain, string $cookie, string $nodeId): string
    {
        $path = ($domain !== '' ? '/' . rawurlencode($domain) : '') . '/';

        return $base . $path . '?login=' . rawurlencode($cookie) . '&gotonode=' . rawurlencode($nodeId) . '&viewmode=' . RmmProtocol::MESH_VIEWMODE;
    }
}
