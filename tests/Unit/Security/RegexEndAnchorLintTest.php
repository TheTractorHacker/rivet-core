<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;

/**
 * CORE-DATA-3 (red team 2026-11-21): without the D modifier `$` also matches before a final newline, so `KeyRing::KID_PATTERN` accepted "k1\n" as a key id different from
 * "k1". Every pattern in src/ that is anchored at both ends (`^...$`) is a validator or a parser of a whole value, and must say what it means: `$` with D (or `\z`) for
 * "the value ends here". This lint reads every PHP string literal in src/ that is a regex anchored `^...$`, and fails on one that has neither D nor m (with m, `$` is about
 * lines and D does nothing).
 *
 * The exceptions keep the plain `$` ON PURPOSE, each with its reason. Mostly deny lists: there `$` matching before a final newline denies MORE (a key named "password\n"
 * is still redacted, a file named "id_rsa\n" is still a secret file), and D would let the newline variant through.
 *
 * Public pattern constants of @api types (FROZEN) keep their values: editions may use them and a changed value is a BREAKING line in the API surface guard (the CORE-10
 * decision, docs/architecture/ADR-004). Core itself never uses one bare: every preg_match on a frozen constant appends the modifier at the call site
 * (`preg_match(KeyRing::KID_PATTERN . 'D', $kid)`) or goes through RmmProtocol::matches(), and the second test enforces that.
 *
 * Limits: a pattern assembled at run time (`'/^' . $x . '$/'`) or interpolated in a double-quoted string is not a single literal and is not seen; review those by hand.
 */
final class RegexEndAnchorLintTest extends TestCase
{
    /** @var array<string,array<string,string>> path => [pattern literal => why it keeps the plain `$`] */
    private const EXCEPTIONS = [
        'src/Audit/AuditService.php' => [
            '/^(password|passwd|pwd|secret|client_secret|token|access_token|refresh_token|id_token|api_key|apikey|authorization|private_key)$/i'
                => 'deny list of context keys whose values are redacted: a key "password\n" must still be redacted',
        ],
        'src/Rmm/Change/ChangeWatchPolicy.php' => [
            '/^(?:id_(?:rsa|dsa|ecdsa|ed25519)(?:_sk)?|\.netrc|\.pgpass|\.htpasswd|\.git-credentials|credentials|secrets?(?:\..*)?|tokens?|api_?keys?|kubeconfig|admin\.conf|[^\/]*\.token)$/'
                => 'deny list of secret file names: "id_rsa\n" must still count as a secret file',
        ],
        'src/Rmm/Remote/PathPolicy.php' => [
            '/^(id_rsa.*|id_ed25519.*|id_ecdsa.*|id_dsa.*|.*\.(pem|key|p12|pfx|kdbx|keystore|jks)|ssh_host_.*|ntds\.dit|pagefile\.sys|hiberfil\.sys|swapfile\.sys|rivetit-agent(\.exe)?(\.(new|prev|failed))?|meshagent\.msh|meshagent\.db.*)$/i'
                => 'deny list of transfer file names, the agent\'s own files included since CORE-AGENT-6 (the newline variant must still be denied)',
        ],
        'src/Crypto/KeyGenerator.php' => [
            '/^[0-9a-fA-F]{64}$/'
                => 'decides how an EXISTING legacy settings key string becomes key bytes (hex or SHA-256); with D a hex key with a trailing newline would turn from a loud '
                . 'hex2bin failure (InvalidKeyMaterial) into a silently different SHA-256 key',
        ],
        'src/KB/PdfConverter.php' => [
            '/^\s*(\d{1,3})[.)]\s+(.*)$/u' => 'a layout heuristic on extracted PDF text (is this line a numbered list item), not an input validator',
            '/^\s*[\x{2022}\x{2023}\x{25E6}\x{2043}\x{2219}\x{00B7}\x{25AA}\x{25CF}*-]\s+(.*)$/u' => 'a layout heuristic on extracted PDF text (is this line a bullet item), not an input validator',
        ],
    ];

    /** @var array<string,list<string>> path => public pattern constants whose value is frozen (see the class comment) */
    private const FROZEN = [
        'src/Crypto/KeyRing.php' => ['KID_PATTERN'],
        'src/Panel/PanelProtocol.php' => ['DEVICE_TOKEN_BEARER_RE', 'ENROLL_TOKEN_SECRET_RE', 'ENROLL_TOKEN_SELECTOR_RE', 'INSTALL_ID_RE'],
        'src/Rmm/Remote/RemoteProtocol.php' => ['PROCESS_NAME_RE', 'SERVICE_NAME_RE', 'TRANSFER_TOKEN_RE'],
        'src/Rmm/RmmProtocol.php' => ['AGENT_VERSION_RE', 'BINARY_STORAGE_NAME_RE', 'CHECK_KEY_RE', 'DEVICE_TOKEN_BEARER_RE', 'ENROLL_TOKEN_SECRET_RE', 'ENROLL_TOKEN_SELECTOR_RE', 'INSTALL_ID_RE',
            'MACHINE_GUID_RE', 'MAC_RE'],
        'src/Rmm/Starter/StarterPack.php' => ['ID_RE', 'PACK_ID_RE'],
    ];

    /** @var array<string,string> "path:line" => why that call site may use a frozen constant without D */
    private const BARE_USE_EXCEPTIONS = [];

    public function testEveryPatternAnchoredAtBothEndsSaysWhereTheValueEnds(): void
    {
        $root = dirname(__DIR__, 3);
        $offenders = [];
        foreach (self::anchoredWithoutD($root . '/src') as [$path, $line, $pattern, $const]) {
            $rel = substr($path, strlen($root) + 1);
            if (isset(self::EXCEPTIONS[$rel][$pattern]) || in_array($const, self::FROZEN[$rel] ?? [], true)) {
                continue;
            }
            $offenders[] = "$rel:$line $pattern";
        }
        $this->assertSame([], $offenders, "add the D modifier (or end with \\z), or list the pattern in EXCEPTIONS with the reason it must keep matching before a final newline");
    }

    public function testEveryExceptionStillExists(): void
    {
        $root = dirname(__DIR__, 3);
        $found = [];
        foreach (self::anchoredWithoutD($root . '/src') as [$path, , $pattern]) {
            $found[substr($path, strlen($root) + 1)][$pattern] = true;
        }
        foreach (self::EXCEPTIONS as $rel => $patterns) {
            foreach ($patterns as $pattern => $why) {
                $this->assertGreaterThan(20, strlen($why));
                $this->assertArrayHasKey($pattern, $found[$rel] ?? [], "stale exception in $rel: remove it");
            }
        }
    }

    public function testFrozenPublicPatternsAreUsedWithD(): void
    {
        $root = dirname(__DIR__, 3);
        $names = array_unique(array_merge(...array_values(self::FROZEN)));
        $bare = '/preg_(?:match|match_all|replace|replace_callback|split)\(\s*(?:self|static|[A-Za-z_\\\\][A-Za-z0-9_\\\\]*)::(?:' . implode('|', $names) . ')\s*(?!\.\s*\'D\')[,)]/';
        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $rel = substr($file->getPathname(), strlen($root) + 1);
            foreach (file($file->getPathname()) ?: [] as $i => $line) {
                if (preg_match($bare, $line) === 1 && !isset(self::BARE_USE_EXCEPTIONS[$rel . ':' . ($i + 1)])) {
                    $offenders[] = $rel . ':' . ($i + 1);
                }
            }
        }
        $this->assertSame([], $offenders, "append . 'D' to the frozen constant at the call site (or use RmmProtocol::matches())");
        // every frozen constant still exists, still has no D (else it belongs in the main scan), and every call-site exception still points at a bare use
        foreach (self::FROZEN as $rel => $consts) {
            $src = (string) file_get_contents($root . '/' . $rel);
            foreach ($consts as $c) {
                $this->assertMatchesRegularExpression('/public const ' . $c . ' = \'[^\']*\$[\/#~][a-zA-Z]*\';/', $src, "$rel $c");
            }
        }
        foreach (self::BARE_USE_EXCEPTIONS as $at => $why) {
            [$rel, $line] = explode(':', $at);
            $this->assertMatchesRegularExpression($bare, (string) (file($root . '/' . $rel) ?: [])[(int) $line - 1], "stale call-site exception $at");
            $this->assertGreaterThan(20, strlen($why));
        }
    }

    /** The scanner itself: it must find the shapes it is meant to find, and nothing else. */
    public function testTheScannerSeesWhatItShould(): void
    {
        $dir = sys_get_temp_dir() . '/regex_lint_' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/Sample.php', <<<'PHP'
            <?php
            const A = '/^[a-z]+$/';
            const B = '/^[a-z]+$/D';
            const C = '/^[a-z]+$/m';
            const D = "#^/dev/[a-z]+$#i";
            const E = '/[a-z]+$/';
            const F = '/^[a-z]+\$/';
            const G = 'not a regex $';
            const H = '~^x(?:y|z)$~u';
            const I = '/^[a-z]+\z/';
            PHP);
        try {
            $found = self::anchoredWithoutD($dir);
            $hits = array_map(static fn (array $h): string => $h[2], $found);
            $consts = array_map(static fn (array $h): string => $h[3], $found);
        } finally {
            unlink($dir . '/Sample.php');
            rmdir($dir);
        }
        $this->assertSame(['/^[a-z]+$/', '#^/dev/[a-z]+$#i', '~^x(?:y|z)$~u'], $hits);
        $this->assertSame(['A', 'D', 'H'], $consts);
    }

    /**
     * @return list<array{string,int,string,string}> path, line, the pattern as PHP sees it, the name of the constant it is the value of ('' when it is not one)
     */
    private static function anchoredWithoutD(string $dir): array
    {
        $out = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $const = '';
            $afterConst = false;
            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $t) {
                if (is_array($t) && $t[0] === T_CONST) {
                    $afterConst = true;
                } elseif ($afterConst && is_array($t) && $t[0] === T_STRING) {
                    $const = $t[1];
                    $afterConst = false;
                } elseif ($t === ';') {
                    $const = '';
                }
                if (!is_array($t) || $t[0] !== T_CONSTANT_ENCAPSED_STRING) {
                    continue;
                }
                $v = substr($t[1], 1, -1);
                $v = $t[1][0] === "'" ? str_replace(['\\\\', "\\'"], ['\\', "'"], $v) : stripcslashes($v);
                if (strlen($v) < 4 || !in_array($v[0], ['/', '#', '~', '!', '@', '%'], true)) {
                    continue;
                }
                $end = strrpos($v, $v[0]);
                $flags = $end === false || $end === 0 ? '' : substr($v, $end + 1);
                if ($end === false || $end === 0 || preg_match('/^[a-zA-Z]*$/D', $flags) !== 1) {
                    continue;
                }
                $body = substr($v, 1, $end - 1);
                if (!str_starts_with($body, '^') || !str_ends_with($body, '$') || str_ends_with($body, '\\$')
                    || str_contains($flags, 'D') || str_contains($flags, 'm')) {
                    continue;
                }
                $out[] = [$file->getPathname(), $t[2], $v, $const];
            }
        }
        usort($out, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return $out;
    }
}
