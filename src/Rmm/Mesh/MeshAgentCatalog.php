<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Mesh;

/**
 * The facts about the optional MeshCentral agent install that are plain data: the agent-id table, the install arguments, the download address and
 * the validation of the values an administrator may set (pure functions, no I/O, no database).
 *
 * PARTLY VERIFIED against a real MeshCentral (1.0.0-rc.15): the download address shape `<mesh>/meshagents?id=4&meshid=<64-character URL form>&installflags=0`
 * (id 4 = Windows x64, installflags 0) came from an owner's server. STILL UNVERIFIED: the Linux ids, the install arguments and the node-id locations,
 * which are written from the MeshCentral documentation and source as remembered and exercised only against mock servers. They are carried in the
 * stamped installer, and an administrator can correct them on the server (MeshAgentSettings) without a new agent. See docs/rmm/REMOTE_AND_LOGS.md, "Install the MeshCentral agent with the
 * RivetIT agent".
 *
 * @api
 */
final class MeshAgentCatalog
{
    /**
     * `GOOS/GOARCH` => the MeshCentral agent id served by `<mesh>/meshagents?id=<N>`. The same table is compiled into the Go agent
     * (internal/mesh DefaultAgentIDs); a test pins both to endpoint-agent/testdata/vectors/agent_installer_mesh_vectors.json.
     * VERIFY AGAINST YOUR MESH VERSION: 3 Windows x86-32 service, 4 Windows x86-64 service, 5 Linux x86-32, 6 Linux x86-64, 26 Linux ARM 64-bit.
     * The Windows ARM64 id (36) is the least certain.
     */
    public const DEFAULT_AGENT_IDS = [
        'linux/386' => 5,
        'linux/amd64' => 6,
        'linux/arm64' => 26,
        'windows/386' => 3,
        'windows/amd64' => 4,
        'windows/arm64' => 36,
    ];

    /** Arguments passed to the downloaded installer, by operating system. Windows: `MeshAgent.exe -fullinstall`. Linux: `./meshagent -install`. */
    public const DEFAULT_INSTALL_ARGS = [
        'linux' => ['-install'],
        'windows' => ['-fullinstall'],
    ];

    public const GROUP_HEX_MIN = 32;
    public const GROUP_HEX_MAX = 128;
    public const MAX_AGENT_IDS = 16;
    public const MAX_ARGS = 8;
    public const MAX_ARG_LEN = 100;
    private const ARG_RE = '/^-{0,2}[A-Za-z0-9][A-Za-z0-9=_.\/:@-]*\z/';
    private const OS_ARCH_RE = '/^(windows|linux)\/[a-z0-9]{2,10}\z/';

    /** Length of MeshCentral's URL form of a device group id: base64 of the 48-byte hash, 64 characters, `@` for `+` and `$` for `/`. */
    public const GROUP_URL_LEN = 64;
    private const GROUP_URL_RE = '/^[A-Za-z0-9@$_-]{64}\z/';
    private const GROUP_HOSTILE_RE = '/[\s\x00-\x1f\x7f"\'`\\\\<>]/';

    /**
     * The device group id, canonical form: lower-case hex. Accepts everything {@see parseGroup()} accepts and drops the rest of its answer.
     * Returns null for anything else.
     */
    public static function normalizeGroupId(string $in): ?string
    {
        return self::parseGroup($in)['hex'] ?? null;
    }

    /**
     * Parse what an administrator may paste into the "device group id" field. Accepted, after trimming surrounding whitespace:
     *  - the whole download address MeshCentral shows (`https://host/meshagents?id=4&meshid=<64 characters>&installflags=0`): only the `meshid`
     *    is taken, and `id` (when present) must be a number from 1 to 255. NOTHING is fetched. A host that is not the configured Mesh host is a
     *    warning, not an error (the address may be pasted before the Mesh address is saved);
     *  - the bare 64-character URL form (`[A-Za-z0-9@$_-]`, 48 raw bytes), exactly as the address carries it;
     *  - MeshCentral's `mesh//<64 characters>` form;
     *  - lower- or upper-case hex (32 to 128 characters, even length).
     * The result holds the canonical hex, the URL form when the input GAVE one (kept verbatim, so what MeshCentral printed is what is sent back) or
     * null, the host and agent id found in a pasted address, and the warnings.
     *
     * @return array{hex:string,url:?string,host:?string,agent_id:?int,warnings:list<string>}|null null when the input is not a usable group id
     */
    public static function parseGroup(string $in, ?string $meshUrl = null): ?array
    {
        $in = trim($in);
        if ($in === '' || strlen($in) > 2000 || preg_match(self::GROUP_HOSTILE_RE, $in) === 1) {
            return null;
        }
        $host = null;
        $agentId = null;
        $warnings = [];
        if (preg_match('~^[A-Za-z][A-Za-z0-9+.-]*://~', $in) === 1) {
            $p = parse_url($in);
            if ($p === false || !in_array(strtolower((string) ($p['scheme'] ?? '')), ['https', 'http'], true) || ($p['host'] ?? '') === '' || isset($p['user']) || isset($p['pass'])) {
                return null;
            }
            $host = strtolower((string) $p['host']);
            $q = [];
            parse_str((string) ($p['query'] ?? ''), $q);
            if (!is_string($q['meshid'] ?? null)) {
                return null;
            }
            if (array_key_exists('id', $q)) {
                if (!is_string($q['id']) || preg_match('/^[0-9]{1,3}\z/', $q['id']) !== 1 || (int) $q['id'] < 1 || (int) $q['id'] > 255) {
                    return null;
                }
                $agentId = (int) $q['id'];
            }
            $in = $q['meshid'];
            if ($meshUrl !== null && trim($meshUrl) !== '') {
                $want = parse_url(trim($meshUrl), PHP_URL_HOST);
                if (is_string($want) && $want !== '' && strtolower($want) !== $host) {
                    $warnings[] = "The pasted address is for the host $host, but the MeshCentral address is set to " . strtolower($want) . '. Check that both belong to the same MeshCentral server.';
                }
            }
            if ($in === '' || preg_match('~^[A-Za-z][A-Za-z0-9+.-]*://~', $in) === 1) {
                return null;
            }
        }
        $r = ['hex' => '', 'url' => null, 'host' => $host, 'agent_id' => $agentId, 'warnings' => $warnings];
        if (str_starts_with($in, 'mesh//')) {
            $in = substr($in, 6);
            $hex = preg_match(self::GROUP_URL_RE, $in) === 1 ? self::urlToHex($in) : null;
            if ($hex === null) {
                return null;
            }

            return ['hex' => $hex, 'url' => $in] + $r;
        }
        $n = strlen($in);
        if ($n >= self::GROUP_HEX_MIN && $n <= self::GROUP_HEX_MAX && $n % 2 === 0 && preg_match('/^[0-9a-fA-F]+\z/', $in) === 1) {
            return ['hex' => strtolower($in)] + ['url' => null] + $r;   // a 64-character all-hex string is hex, not the URL form
        }
        if (preg_match(self::GROUP_URL_RE, $in) === 1) {
            $hex = self::urlToHex($in);

            return $hex === null ? null : ['hex' => $hex, 'url' => $in] + $r;
        }

        return null;
    }

    /** The 96 hex characters behind MeshCentral's 64-character URL form, or null when it does not decode to exactly 48 bytes. */
    public static function urlToHex(string $url): ?string
    {
        if (preg_match(self::GROUP_URL_RE, $url) !== 1) {
            return null;
        }
        $raw = base64_decode(strtr($url, ['@' => '+', '$' => '/', '-' => '+', '_' => '/']), true);

        return $raw === false || strlen($raw) !== 48 ? null : bin2hex($raw);
    }

    /** MeshCentral's URL form of a 96-hex group id (base64 of the 48 bytes, `+` written `@` and `/` written `$`), or null for any other length. */
    public static function hexToUrl(string $hex): ?string
    {
        if (strlen($hex) !== 96 || preg_match('/^[0-9a-fA-F]+\z/', $hex) !== 1) {
            return null;
        }
        $raw = hex2bin($hex);

        return $raw === false ? null : strtr(base64_encode($raw), ['+' => '@', '/' => '$']);
    }

    /**
     * What the installer payload carries as `mesh.meshid` and the download address as `meshid=`: the URL form (the verbatim one when known, else the
     * one derived from the hex), or the hex itself for the rare group id that is not 48 bytes.
     */
    public static function wireGroupId(string $hex, ?string $url = null): string
    {
        if ($url !== null && $url !== '' && self::urlToHex($url) === $hex) {
            return $url;
        }

        return self::hexToUrl($hex) ?? $hex;
    }

    /** A certificate pin: the SHA-384 (96 hex) or SHA-256 (64 hex) of the server's certificate, colons and case ignored. Empty input is valid (system roots). */
    public static function normalizePin(string $in): ?string
    {
        $p = strtolower(str_replace([':', ' '], '', trim($in)));
        if ($p === '') {
            return '';
        }

        return (strlen($p) === 64 || strlen($p) === 96) && preg_match('/^[0-9a-f]+\z/', $p) === 1 ? $p : null;
    }

    /**
     * Validate the corrections to the agent-id table: `GOOS/GOARCH` => 1..255.
     *
     * @return array<string,int>|null null when invalid
     */
    public static function cleanAgentIds(mixed $in): ?array
    {
        if (!is_array($in) || ($in !== [] && array_is_list($in)) || count($in) > self::MAX_AGENT_IDS) {
            return null;
        }
        $out = [];
        foreach ($in as $k => $v) {
            if (!is_string($k) || preg_match(self::OS_ARCH_RE, $k) !== 1 || !is_int($v) || $v < 1 || $v > 255) {
                return null;
            }
            $out[$k] = $v;
        }
        ksort($out);

        return $out;
    }

    /**
     * Validate the corrections to the install arguments: `windows`/`linux` => list of at most 8 arguments of letters, digits and `=_.:/@-`.
     *
     * @return array<string,list<string>>|null null when invalid
     */
    public static function cleanInstallArgs(mixed $in): ?array
    {
        if (!is_array($in) || ($in !== [] && array_is_list($in))) {
            return null;
        }
        $out = [];
        foreach ($in as $os => $args) {
            if (($os !== 'windows' && $os !== 'linux') || !is_array($args) || !array_is_list($args) || count($args) > self::MAX_ARGS) {
                return null;
            }
            $clean = [];
            foreach ($args as $a) {
                if (!is_string($a) || strlen($a) > self::MAX_ARG_LEN || preg_match(self::ARG_RE, $a) !== 1) {
                    return null;
                }
                $clean[] = $a;
            }
            $out[$os] = $clean;
        }
        ksort($out);

        return $out;
    }

    /**
     * The full table the installer carries: the built-in one with the administrator's corrections on top.
     *
     * @param array<string,int> $overrides
     * @return array<string,int>
     */
    public static function mergedAgentIds(array $overrides): array
    {
        $t = $overrides + self::DEFAULT_AGENT_IDS;
        ksort($t);

        return $t;
    }

    /**
     * @param array<string,list<string>> $overrides
     * @return array<string,list<string>>
     */
    public static function mergedInstallArgs(array $overrides): array
    {
        $t = [];
        foreach (self::DEFAULT_INSTALL_ARGS as $os => $args) {
            $t[$os] = isset($overrides[$os]) && $overrides[$os] !== [] ? $overrides[$os] : $args;
        }

        return $t;
    }

    /** The address the agent downloads from (the Go side builds the same string). `$group` is hex or the URL form; the URL form goes on the wire. */
    public static function downloadUrl(string $base, int $agentId, string $group, int $installFlags = 0): string
    {
        $g = self::parseGroup($group);

        return rtrim($base, '/') . '/meshagents?id=' . $agentId . '&meshid=' . ($g === null ? $group : self::wireGroupId($g['hex'], $g['url'])) . '&installflags=' . $installFlags;
    }
}
