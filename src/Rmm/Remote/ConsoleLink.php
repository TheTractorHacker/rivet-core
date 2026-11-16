<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Remote;

/**
 * The deep link that opens the Rivet Console (the optional desktop app): `rivetconsole://connect?kind=<kind>&node=<mesh node id>`.
 *
 * The link only NAMES a device. It never carries a credential, token or password: the console uses its own signed-in session and the server
 * enforces the technician's rights on every tunnel. Pure (no I/O); the kind comes from an allow-list and the node id must match
 * {@see NODE_RE}, anything else yields null and no link is built.
 *
 * @api
 */
final class ConsoleLink
{
    public const SCHEME = 'rivetconsole';
    /** desktop, a command-line terminal, a PowerShell terminal and the file manager. */
    public const KINDS = ['desktop', 'terminal', 'terminal-ps', 'files'];
    public const NODE_RE = '/^node\/\/[A-Za-z0-9@$_-]{20,100}$/D';

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

    /** The deep link, or null when the kind or the node id is not acceptable. */
    public static function build(string $kind, string $nodeId): ?string
    {
        if (!self::validKind($kind) || !self::validNode($nodeId)) {
            return null;
        }

        return self::SCHEME . '://connect?kind=' . rawurlencode($kind) . '&node=' . rawurlencode($nodeId);
    }

    /**
     * The launch descriptor `launchRemote()` returns beside the stock URL when the `remote_console` switch is on:
     * `{kind, url: <deep link>, fallback_url: <stock MeshCentral URL>}`. Null when no valid link can be built (the caller then offers the stock URL only).
     *
     * @return array{kind:string,url:string,fallback_url:string}|null
     */
    public static function descriptor(string $kind, ?string $nodeId, string $fallbackUrl): ?array
    {
        $link = $nodeId === null ? null : self::build($kind, $nodeId);

        return $link === null ? null : ['kind' => $kind, 'url' => $link, 'fallback_url' => $fallbackUrl];
    }
}
