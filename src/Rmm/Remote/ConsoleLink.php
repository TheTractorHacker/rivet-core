<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Remote;

/**
 * The deep link that opens the Rivet Console (the optional desktop app): `rivetconsole://connect?v=1&origin=<percent-encoded https origin>&code=<code>`. Only those three
 * parameters. The `origin` is Core's own `console_public_url` setting (never a request header); the `code` is 32 random bytes in base64url (43 characters), valid 60 seconds
 * and usable once, which the console redeems at `POST <origin>/api/v1/endpoint_console/redeem`. The link holds no long-lived secret and no login token: see
 * {@see ConsoleRedeemService}. The kind and the node are NOT in the link, Core stores them with the code.
 *
 * Pure (no I/O) apart from the random bytes of {@see newCode()}.
 *
 * @api
 */
final class ConsoleLink
{
    public const SCHEME = 'rivetconsole';
    public const VERSION = 1;
    /** desktop, a command-line terminal, a PowerShell terminal and the file manager. Background and chat are never issued. */
    public const KINDS = ['desktop', 'terminal', 'terminal-ps', 'files'];
    /** `node/<domain>/<id>`: an empty domain is `node//<id>`. */
    public const NODE_RE = '/^node\/([A-Za-z0-9_-]{0,32})\/[A-Za-z0-9@$_-]{20,100}$/D';
    public const CODE_RE = '/^[A-Za-z0-9_-]{43}$/D';
    /** Seconds a code is valid for redeeming. */
    public const CODE_TTL_S = 60;

    /** The kind a Mesh `view` maps to when the caller does not name one. */
    private const VIEW_KIND = ['desktop' => 'desktop', 'terminal' => 'terminal', 'files' => 'files'];

    public static function validKind(string $kind): bool
    {
        return in_array($kind, self::KINDS, true);
    }

    public static function validNode(string $node): bool
    {
        return preg_match(self::NODE_RE, $node) === 1;
    }

    public static function validCode(string $code): bool
    {
        return preg_match(self::CODE_RE, $code) === 1;
    }

    /** A fresh code: 32 random bytes, base64url without padding (43 characters). */
    public static function newCode(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /** The only form a code is stored in. */
    public static function hashCode(string $code): string
    {
        return hash('sha256', $code);
    }

    /**
     * The console kind for a Mesh view, or null when the request is inconsistent: `$requested` (when given) must be an allowed kind and fit the view
     * (`terminal-ps` is a terminal; the other kinds equal their view).
     */
    public static function kindFor(string $view, ?string $requested = null): ?string
    {
        $default = self::VIEW_KIND[$view] ?? null;
        if ($default === null) {
            return null;
        }
        if ($requested === null || $requested === '') {
            return $default;
        }
        if (!self::validKind($requested)) {
            return null;
        }

        return ($requested === $default || ($requested === 'terminal-ps' && $view === 'terminal')) ? $requested : null;
    }

    /**
     * A bare origin (`https://host[:port]`, lower-case host) or null: https only (http only with `$allowInsecureHttp`, loopback test servers), no userinfo, path, query or
     * fragment (one trailing slash is dropped), a plain ASCII host name or an IPv4 address, at most 255 characters.
     */
    public static function normalizeOrigin(string $url, bool $allowInsecureHttp = false): ?string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 255 || preg_match('/[\x00-\x20"<>\\\\^`{|}?#@%]/', $url) === 1) {
            return null;
        }
        if (str_ends_with($url, '/')) {
            $url = substr($url, 0, -1);
        }
        $p = parse_url($url);
        if ($p === false || !isset($p['scheme'], $p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment']) || isset($p['path'])) {
            return null;
        }
        $scheme = strtolower($p['scheme']);
        if ($scheme !== 'https' && !($scheme === 'http' && $allowInsecureHttp)) {
            return null;
        }
        $host = strtolower($p['host']);
        if (preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $host) !== 1 || str_contains($host, '..')) {
            return null;
        }
        $port = $p['port'] ?? null;
        if ($port !== null && $port < 1) {
            return null;
        }

        return $scheme . '://' . $host . ($port === null ? '' : ':' . $port);
    }

    /** The deep link, or null when the origin is not a bare origin or the code is not a well-formed code. */
    public static function build(string $origin, string $code, bool $allowInsecureHttp = false): ?string
    {
        if (self::normalizeOrigin($origin, $allowInsecureHttp) !== $origin || !self::validCode($code)) {
            return null;
        }

        return self::SCHEME . '://connect?v=' . self::VERSION . '&origin=' . rawurlencode($origin) . '&code=' . $code;
    }

    /**
     * The `console` object `launchRemote()` returns beside the stock URL when the `remote_console` switch is on: `{url: <deep link>, fallback_url: <stock MeshCentral URL>}`.
     * Null when no valid link can be built.
     *
     * @return array{url:string,fallback_url:string}|null
     */
    public static function descriptor(string $origin, string $code, string $fallbackUrl, bool $allowInsecureHttp = false): ?array
    {
        $link = self::build($origin, $code, $allowInsecureHttp);

        return $link === null ? null : ['url' => $link, 'fallback_url' => $fallbackUrl];
    }
}
