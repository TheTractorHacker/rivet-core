<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Mesh;

/**
 * The facts about the optional MeshCentral agent install that are plain data: the agent-id table, the install arguments, the download address and
 * the validation of the values an administrator may set (pure functions, no I/O, no database).
 *
 * UNVERIFIED against a real MeshCentral server. The agent ids, the download address and the install arguments are written from the MeshCentral
 * documentation and source as remembered and exercised only against mock servers. They are carried in the stamped installer, and an administrator
 * can correct them on the server (MeshAgentSettings) without a new agent. See docs/rmm/REMOTE_AND_LOGS.md, "Install the MeshCentral agent with the
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

    /**
     * The device group id as the installer download address wants it: lower-case hex. Accepts the hex itself (32 to 128 characters, even length)
     * or MeshCentral's own `mesh//<64 characters>` form (base64 of the 48-byte hash with `@` for `+` and `$` for `/`), which is converted.
     * Returns null for anything else.
     */
    public static function normalizeGroupId(string $in): ?string
    {
        $in = trim($in);
        if ($in === '') {
            return null;
        }
        if (str_starts_with($in, 'mesh//')) {
            $b64 = strtr(substr($in, 6), ['@' => '+', '$' => '/']);
            if (preg_match('/^[A-Za-z0-9+\/]{64}\z/', $b64) !== 1) {
                return null;
            }
            $raw = base64_decode($b64, true);

            return $raw === false || strlen($raw) !== 48 ? null : bin2hex($raw);
        }
        $hex = strtolower($in);
        $n = strlen($hex);

        return $n >= self::GROUP_HEX_MIN && $n <= self::GROUP_HEX_MAX && $n % 2 === 0 && preg_match('/^[0-9a-f]+\z/', $hex) === 1 ? $hex : null;
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

    /** The address the agent downloads from (the Go side builds the same string). */
    public static function downloadUrl(string $base, int $agentId, string $groupHex, int $installFlags = 0): string
    {
        return rtrim($base, '/') . '/meshagents?id=' . $agentId . '&meshid=' . $groupHex . '&installflags=' . $installFlags;
    }
}
