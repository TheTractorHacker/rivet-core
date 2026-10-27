<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Crypto;

use RivetCore\Rmm\RmmProtocol;

/**
 * Strips credentials from job output before it is stored or shown. Best effort: patterns, not a guarantee. The pattern set and its
 * order are those of RivetIT src/EndpointAgent/Redactor.php, ported, then hardened in rc.38 (quoted values with spaces, `psk`, `passw0rd`, the product's own tokens).
 *
 * @api
 */
final class Redactor
{
    public const MASK = RmmProtocol::REDACTION_MASK;

    /**
     * What follows `name=` / `name:`. A double- or single-quoted value is masked up to its closing quote (spaces included, a backslash escapes, never past the end of the
     * line), an unquoted one up to the next space, quote, comma or semicolon. Capture groups, counted from the name group that precedes it: 2 the separator, 3 and 5 an
     * opening quote, 4 and 6 its closing quote; the replacement is `$1$2$3$5[REDACTED]$4$6`, so `pass="a b c"` becomes `pass="[REDACTED]"`.
     *
     * @internal shared with {@see \RivetCore\Rmm\Change\ChangeProtocol::harden()}; the Go agent has the same expression (valueRE in internal/change/redact.go)
     */
    public const VALUE = '(["\']?\s*[:=]\s*)(?:(")(?:[^"\\\\\r\n]|\\\\.)*("?)|(\')(?:[^\'\\\\\r\n]|\\\\.)*(\'?)|[^\s"\',;]+)';

    /**
     * {@see VALUE} with the quoted-value loops made possessive. The text matched is the same (a loop that stops at the closing quote or the end of the line
     * never has to give characters back), but PCRE no longer keeps a backtracking frame per character, which exhausted the JIT stack for a quoted value of
     * about 8 KB and made the whole rule fail (CORE-06).
     */
    private const VALUE_LINEAR = '(["\']?\s*[:=]\s*)(?:(")(?:[^"\\\\\r\n]++|\\\\.)*+("?)|(\')(?:[^\'\\\\\r\n]++|\\\\.)*+(\'?)|[^\s"\',;]++)';

    public static function redact(string $s): string
    {
        $m = self::MASK;
        $rules = [
            // PEM private key blocks.
            '/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?(-----END [A-Z ]*PRIVATE KEY-----|$)/s' => $m,
            // Authorization headers and bearer strings.
            '/\bBearer\s+[A-Za-z0-9._~+\/=-]{8,}/i' => 'Bearer ' . $m,
            '/\b(Authorization|Proxy-Authorization)\s*[:=]\s*\S+(\s+\S+)?/i' => '$1: ' . $m,
            // JSON Web Tokens.
            '/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]*/' => $m,
            // key=value / key: value with secret-looking names.
            // Starts at the keyword (the text before it is kept as it was) and the rest of the name is bounded: an unanchored name prefix made this rule quadratic.
            '/((?:password|passw[o0]rd|passwd|pwd|passphrase|secret|token|api[_-]?key|apikey|access[_-]?key|private[_-]?key|client[_-]?secret|credential|psk)[A-Za-z0-9_.-]{0,128}+)' . self::VALUE_LINEAR . '/i' => '$1$2$3$5' . $m . '$4$6',
            // Names the change monitor already masks (rc.31): DB_PASS, SMTP_PW, APP_KEY, api.key, camelCase licenseKey (CORE-06, parity with ChangeProtocol::harden).
            '/\b((?:[A-Za-z0-9]{1,40}[_.-]){0,6}(?:pass|pw|psk)(?:[_.-][A-Za-z0-9_.-]{0,40}+)?|(?:[A-Za-z0-9]{1,40}[_.-]){1,6}key)' . self::VALUE_LINEAR . '/i' => '$1$2$3$5' . $m . '$4$6',
            '/\b([A-Za-z0-9_.-]{0,80}[a-z0-9](?:Key|Pass|Pw))' . self::VALUE_LINEAR . '/' => '$1$2$3$5' . $m . '$4$6',
            // curl -u user:secret, mysql -pSECRET / -p SECRET, sshpass -p SECRET.
            '/(\bcurl\b[^\n]{0,200}?\s-u\s+[^\s:]{1,100}:)\S+/i' => '$1' . $m,
            '/(\b(?:mysql\w*|mariadb\w*|sshpass|mongo\w*)\b[^\n]{0,200}?\s-p\s?)(?!assword)\S+/i' => '$1' . $m,
            // PowerShell -Password / -Token style switches and ConvertTo-SecureString -String.
            '/(-(?:password|token|secret|apikey|key)\s+)(["\']?)[^\s"\']+\2/i' => '$1' . $m,
            '/(ConvertTo-SecureString\s+(?:-String\s+)?)(["\'])[^"\']*\2/i' => '$1' . $m,
            // Provider key shapes.
            '/\bAKIA[0-9A-Z]{16}\b/' => $m,
            '/\bgh[pousr]_[A-Za-z0-9]{30,}\b/' => $m,
            '/\bxox[abprs]-[A-Za-z0-9-]{10,}\b/' => $m,
            // This application's own credential formats: the agent and panel enrollment tokens and the RivetPanel device credential (rc.38: an underscore is a word
            // character, so the generic 64-hex rule below can never see the hex of `rvpd_<hex>`).
            '/\brvte1\.[0-9A-Za-z]{6,}\.[0-9A-Za-z_-]{8,}/' => $m,
            '/\brvtp1\.[0-9A-Za-z]{6,}\.[0-9A-Za-z_-]{8,}/' => $m,
            '/\brvpd_[0-9A-Za-z]{16,}/' => $m,
            '/\b[0-9a-f]{64}\b/' => $m,
        ];
        foreach ($rules as $re => $to) {
            $r = preg_replace($re, $to, $s);
            if ($r === null) {
                // A PCRE failure (JIT stack, backtrack limit) must never hand the text back unredacted.
                return $m;
            }
            $s = $r;
        }

        return $s;
    }
}
