<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Change;

/**
 * The names, caps and small pure rules of change monitoring (wire protocol 3.2.7 and 3.9, docs/rmm/CHANGE_MONITOR.md). Everything here is shared by the signed
 * configuration the server hands out, the ingest validation and the REST routes, so the two sides cannot drift apart.
 *
 * @api
 */
final class ChangeProtocol
{
    /** What an agent announces when it can watch the registry, configuration files, local groups and scheduled tasks (and what the server offers back). */
    public const CAP = 'change:monitor';
    /** What an agent announces when it can aggregate and filter Security log events by source (`aggregate`, level `always` in a log source); see {@see \RivetCore\Rmm\Logs\SecurityLogPresets}. */
    public const CAP_LOG_PRESETS = 'logs:presets';
    /** The sub-switch (RmmSettings::FEATURES). */
    public const FEATURE = 'change_monitor';

    public const BATCH_MAX = 100;
    public const BODY_MAX = 262144;
    /** agent_changes: requests per window (count, seconds) per device. */
    public const RATE = [30, 60];
    public const CONFIG_VERSION = 1;
    /** The signature of a change configuration covers this line, a line feed, then the canonical JSON of the object without its signature: a job or a check can never be replayed as one. */
    public const SIGN_DOMAIN = 'rivetit-change-config-v1';

    public const KINDS = ['registry', 'file', 'group', 'task'];
    /** Wire name => stored number. */
    public const CHANGE_TYPES = ['added' => 1, 'modified' => 2, 'removed' => 3];
    public const WATCH_ID_RE = '/^(?:win|lnx|custom)\.[a-z0-9_]{1,40}$/D';

    public const TARGET_MAX = 300;
    public const ITEM_MAX = 200;
    public const VALUE_MAX = 512;
    public const SUMMARY_MAX = 200;
    public const ACTOR_MAX = 120;
    public const PROCESS_MAX = 200;

    /** Record flags (stored in `flags`). */
    public const FLAG_REDACTED = 1;
    public const FLAG_TRUNCATED = 2;

    public const CHECK_PREFIX = 'change.rule_';
    public const MAX_CUSTOM_WATCHES = 30;
    public const MAX_PATHS_PER_WATCH = 10;
    /** The most bytes the signed configuration may take (it rides in the check-in response). */
    public const CONFIG_MAX_BYTES = 49152;

    public const MASK = \RivetCore\Rmm\RmmProtocol::REDACTION_MASK;

    public static function changeNumber(string $name): ?int
    {
        return self::CHANGE_TYPES[$name] ?? null;
    }

    public static function changeName(int $n): string
    {
        return (string) (array_search($n, self::CHANGE_TYPES, true) ?: 'modified');
    }

    /**
     * Value names whose content is never shown, stored or sent: they usually hold a secret. The agent applies the same rule before it reads anything, and the server
     * applies it again to what arrives.
     */
    public static function secretName(string $name): bool
    {
        return preg_match('/(pass(word|wd|phrase)?|pwd|secret|token|api[_-]?key|apikey|private[_-]?key|credential|connectionstring|authkey|accesskey|sharedkey|sas[_-]?token|bearer|cookie)/i', $name) === 1;
    }

    /**
     * The shared redactor applied to the text of a change record. In a SUDOERS file the tags `NOPASSWD:` and `PASSWD:` are not secrets (the redactor would take
     * "NOPASSWD: ALL" for a password and hide the one thing a sudoers change is about), so they are kept out of its sight; in any other record they are masked like any key.
     */
    public static function redact(string $s, bool $sudoers = false): string
    {
        if ($sudoers) {
            $s = preg_replace('/\b(NOPASSWD|PASSWD):/', "$1\u{E000}", $s) ?? $s;   // word boundary: "NOPASSWD:" and "PASSWD:" only, not "xPASSWD:"
        }
        $s = \RivetCore\Rmm\Crypto\Redactor::redact($s);
        $s = self::harden($s);
        // command-line shapes that carry a password after a switch (best effort: a secret in free text with no marker stays visible)
        foreach ([
            '/(\s-u\s+[^\s:]+:)\S+/i' => '$1' . self::MASK,
            '/(\bmysql\w*\b[^\n]*?\s-p)[^\s=]\S*/i' => '$1' . self::MASK,
            '/(\/user:\S+\s+)\S+/i' => '$1' . self::MASK,
            '/(\s\/RP\s+)\S+/i' => '$1' . self::MASK,
            '/(\s\/p(?:assword)?:)\S+/i' => '$1' . self::MASK,
            '/(\bsshpass\b[^\n]*?\s-p\s*)\S+/i' => '$1' . self::MASK,
            '#(://[^/\s:@]+:)[^/\s@]+(@)#' => '$1' . self::MASK . '$2',
        ] as $re => $to) {
            $s = preg_replace($re, $to, $s) ?? $s;
        }

        return $sudoers ? str_replace("\u{E000}", ':', $s) : $s;
    }

    /** Environment variable names whose values are shown; every other Environment= / export value is masked. Mirrors the agent (redact.go envHarmless). */
    private const ENV_HARMLESS = ['PATH', 'LANG', 'LANGUAGE', 'TZ', 'HOME', 'USER', 'LOGNAME', 'SHELL', 'PWD', 'TERM', 'EDITOR', 'VISUAL', 'PAGER', 'MAILTO', 'NODE_ENV', 'PYTHONUNBUFFERED',
        'PYTHONPATH', 'LD_LIBRARY_PATH', 'LD_PRELOAD', 'JAVA_HOME', 'GOPATH', 'GOROOT', 'DISPLAY', 'HTTP_PROXY', 'HTTPS_PROXY', 'NO_PROXY'];

    private static function envHarmless(string $name): bool
    {
        $n = strtoupper($name);
        if (in_array($n, self::ENV_HARMLESS, true) || str_starts_with($n, 'LC_') || str_starts_with($n, 'XDG_')) {
            return true;
        }
        foreach (['_HOME', '_DIR', '_PATH', '_PORT', '_HOST', '_LEVEL', '_ENV', '_MODE', '_USER', '_USERNAME'] as $suf) {
            if (str_ends_with($n, $suf)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The rc.31 redaction rules (security review F2), the same as the agent's `hardenRedact` in endpoint-agent/internal/change/redact.go and checked against the same
     * vectors: PASS / PW tokens and _KEY / CamelCase Key names, a secret in the next word of a switch, `-p <word>` for tools that take it that way, the value of any
     * Environment= / export assignment not on the harmless list, and long hex / base64 runs (not in an SSH public key line).
     *
     * @internal called by {@see redact()}; public so the shared vectors can reach it
     */
    public static function harden(string $s): string
    {
        $m = self::MASK;
        $s = preg_replace('/\b((?:[A-Za-z0-9]+[_.-])*(?:pass|pw)(?:[_.-][A-Za-z0-9_.-]*)?|(?:[A-Za-z0-9]+[_.-])+key)(["\']?\s*[:=]\s*["\']?)[^\s"\',;]+/i', '$1$2' . $m, $s) ?? $s;
        $s = preg_replace('/\b([A-Za-z0-9_.-]*[a-z0-9](?:Key|Pass|Pw))(["\']?\s*[:=]\s*["\']?)[^\s"\',;]+/', '$1$2' . $m, $s) ?? $s;
        $s = preg_replace('/(\s--?(?:pass(?:word|wd|phrase)?|pwd|pw|secret|token|api-?key|apikey|auth(?:-?token)?|bearer|client-?secret|access-?key|private-?key|credential|key)\s+)(?:"[^"]*"|\'[^\']*\'|\S+)/i', '$1' . $m, $s) ?? $s;
        $s = preg_replace('/(\b(?:mysql\w*|mariadb\w*|sshpass|mongo\w*)\b[^\n]*?\s-p\s+)\S+/i', '$1' . $m, $s) ?? $s;
        $assign = '(?:"[A-Za-z_][A-Za-z0-9_]*=[^"]*"|\'[A-Za-z_][A-Za-z0-9_]*=[^\']*\'|[A-Za-z_][A-Za-z0-9_]*=\S*)';
        $s = preg_replace_callback('/(\bEnvironment\s*=\s*|\bexport\s+)(' . $assign . '(?:\s+' . $assign . ')*)/', static function (array $k) use ($m): string {
            return $k[1] . preg_replace_callback('/"([A-Za-z_][A-Za-z0-9_]*)=([^"]*)"|\'([A-Za-z_][A-Za-z0-9_]*)=([^\']*)\'|([A-Za-z_][A-Za-z0-9_]*)=(\S*)/', static function (array $a) use ($m): string {
                if ($a[1] !== '' && !self::envHarmless($a[1])) {
                    return '"' . $a[1] . '=' . $m . '"';
                }
                if (($a[3] ?? '') !== '' && !self::envHarmless($a[3])) {
                    return "'" . $a[3] . '=' . $m . "'";
                }
                if (($a[5] ?? '') !== '' && !self::envHarmless($a[5])) {
                    return $a[5] . '=' . $m;
                }

                return $a[0];
            }, $k[2]);
        }, $s) ?? $s;
        if (preg_match('/\b(?:ssh-(?:rsa|ed25519|dss)|ecdsa-sha2-\S+|sk-(?:ssh|ecdsa)\S*)\s/i', $s) !== 1) {
            $s = preg_replace('/\b[0-9A-Fa-f]{32,}\b/', $m, $s) ?? $s;
            $s = preg_replace('/(^|[\s"\'=:])[A-Za-z0-9+]{40,}={0,2}($|[\s"\',;])/', '$1' . $m . '$2', $s) ?? $s;
        }

        return $s;
    }

    /** Control characters, bidi overrides, zero-width marks and line separators become a space, invalid UTF-8 is dropped, the text is trimmed and cut to a character budget. */
    public static function text(string $s, int $max): string
    {
        $s = (string) @iconv('UTF-8', 'UTF-8//IGNORE', $s);
        $s = (string) preg_replace('/[\x00-\x1f\x7f\x{80}-\x{9f}\x{200b}-\x{200f}\x{2028}-\x{202e}\x{2066}-\x{2069}\x{feff}]+/u', ' ', $s);

        return trim(mb_substr($s, 0, $max));
    }

    /** The same text made safe for a sentence in an alert or a webhook (HTML, Markdown, chat): angle brackets, quotes and backticks go too (backslashes stay: registry paths need them). Stored values keep everything. */
    public static function alertText(string $s, int $max = 200): string
    {
        return trim(mb_substr((string) preg_replace('/[<>`"\']+/u', ' ', self::text($s, $max * 2)), 0, $max));
    }
}
