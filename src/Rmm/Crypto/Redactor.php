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

    /**
     * CORE-DATA-2: {@see VALUE_LINEAR} for password-like names, where an unquoted value is masked to the end of its line (`password: correct horse battery staple`). The
     * separator and the white space around it stay on one line, so an empty value never takes the next line with it. Same capture groups.
     */
    private const VALUE_TO_EOL = '(["\']?[ \t]*+[:=][ \t]*+)(?:(")(?:[^"\\\\\r\n]++|\\\\.)*+("?)|(\')(?:[^\'\\\\\r\n]++|\\\\.)*+(\'?)|[^\s"\',;][^\r\n]*+)';

    /**
     * The rules run in order. Their behaviour is pinned by tests/Unit/Rmm/RedactionSharedVectorsTest.php against `redaction-vectors.json` beside this file, the vectors
     * the Go agent's jobs.Redact replays too (CORE-DATA-2 / chain E: one file for both sides).
     *
     * @param bool $passwordToEndOfLine CORE-DATA-2: mask the unquoted value of a password-like name to the end of its line (`password: correct horse battery staple`),
     *        not only to the first space. On by default (job output, logs). The change monitor turns it off ({@see \RivetCore\Rmm\Change\ChangeProtocol::redact()}):
     *        the frozen rc.38 vectors it shares with the Go agent's hardenRedact keep the rest of such a line, and both sides must give the same text.
     */
    public static function redact(string $s, bool $passwordToEndOfLine = true): string
    {
        $m = self::MASK;
        $toEol = '/((?:passw[o0]rd|passwd|passphrase|pwd)[A-Za-z0-9_.-]{0,128}+)' . self::VALUE_TO_EOL . '/i';
        $rules = [
            // PEM private key blocks.
            '/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?(-----END [A-Z ]*PRIVATE KEY-----|$)/s' => $m,
            // Authorization headers and bearer strings.
            '/\bBearer\s+[A-Za-z0-9._~+\/=-]{8,}/i' => 'Bearer ' . $m,
            '/\b(Authorization|Proxy-Authorization)\s*[:=]\s*\S+(\s+\S+)?/i' => '$1: ' . $m,
            // CORE-DATA-2: a Cookie or Set-Cookie header line (also as curl -v prints it, after `<` or `>`): the whole value to the end of the line. Only a line that starts
            // with the header name: "cookie:" in the middle of a sentence is left alone.
            '/^([ \t]*+(?:[<>][ \t]*+)?(?:Set-)?Cookie[ \t]*+:[ \t]*+)[^\r\n]++/im' => '$1' . $m,
            // JSON Web Tokens.
            '/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]*/' => $m,
            // CORE-DATA-2: the password of scheme://user:<password>@host (the user may be empty: redis://:pw@host). Only the password is masked; it runs to the last `@`
            // before the path, query or fragment (an unescaped `@` in a password does not cut the mask short).
            '~(://[^/?#\s:@]*+:)[^/?#\s]+(?=@[^/?#\s@]*+(?!@))~' => '$1' . $m,
            // CORE-DATA-2: password-like names: an unquoted value is masked to the end of its line (`password: a b c`), not only to the first space.
            $toEol => '$1$2$3$5' . $m . '$4$6',
            // key=value / key: value with secret-looking names.
            // Starts at the keyword (the text before it is kept as it was) and the rest of the name is bounded: an unanchored name prefix made this rule quadratic.
            '/((?:password|passw[o0]rd|passwd|pwd|passphrase|secret|token|api[_-]?key|apikey|access[_-]?key|private[_-]?key|client[_-]?secret|credential|psk)[A-Za-z0-9_.-]{0,128}+)' . self::VALUE_LINEAR . '/i' => '$1$2$3$5' . $m . '$4$6',
            // Names the change monitor already masks (rc.31): DB_PASS, SMTP_PW, APP_KEY, api.key, camelCase licenseKey (CORE-06, parity with ChangeProtocol::harden).
            '/\b((?:[A-Za-z0-9]{1,40}[_.-]){0,6}(?:pass|pw|psk)(?:[_.-][A-Za-z0-9_.-]{0,40}+)?|(?:[A-Za-z0-9]{1,40}[_.-]){1,6}key)' . self::VALUE_LINEAR . '/i' => '$1$2$3$5' . $m . '$4$6',
            // camelCase: found at the keyword (the name before it is kept as it was, so it need not be matched): the earlier `\b[A-Za-z0-9_.-]{0,80}` prefix backtracked up
            // to 80 steps at every word boundary and was the slowest rule on adversarial text (CORE-DATA-2 kept the 800 KB in under 0.5 s bound with the new rules).
            // Same output; it also masks a name longer than 80 characters, which the prefix bound let through.
            '/(?<=[a-z0-9])(Key|Pass|Pw)' . self::VALUE_LINEAR . '/' => '$1$2$3$5' . $m . '$4$6',
            // curl -u user:secret, mysql -pSECRET / -p SECRET, sshpass -p SECRET.
            '/(\bcurl\b[^\n]{0,200}?\s-u\s+[^\s:]{1,100}:)\S+/i' => '$1' . $m,
            '/(\b(?:mysql\w*|mariadb\w*|sshpass|mongo\w*)\b[^\n]{0,200}?\s-p\s?)(?!assword)\S+/i' => '$1' . $m,
            // PowerShell -Password / -Token style switches and ConvertTo-SecureString -String.
            '/(-(?:password|token|secret|apikey|key)\s+)(["\']?)[^\s"\']+\2/i' => '$1' . $m,
            '/(ConvertTo-SecureString\s+(?:-String\s+)?)(["\'])[^"\']*\2/i' => '$1' . $m,
            // CORE-DATA-2: a password in the next word of a switch, `--pass x`, `-pw x` (plink), `-pass x` (openssl), as ChangeProtocol::harden() does for these names
            // (`--passive` is not one of them; `-p` stays limited to the tools above, so `psql -p 5432` keeps its port).
            '/((?:^|\s)--?(?:pass(?:word|wd|phrase)?|pwd|pw)[ \t]++)(?:"[^"\r\n]*+"?|\'[^\'\r\n]*+\'?|\S++)/im' => '$1' . $m,
            // CORE-DATA-2: `net use [drive] \\server\share <password> [/user:...]`, and a password written after `/user:name`. A switch (`/persistent:yes`) or `*` (prompt) is
            // not a password.
            '/(\bnet[ \t]++use\b[^\r\n]{0,200}?[ \t]\\\\\\\\\S++[ \t]++)(?![\/*])\S++/i' => '$1' . $m,
            '/(\bnet[ \t]++use\b[^\r\n]{0,200}?[ \t]\/u(?:ser)?:\S++[ \t]++)(?![\/*])\S++/i' => '$1' . $m,
            // CORE-DATA-2: the signature of a URL with a query string (Azure SAS `...?sv=...&sig=...`); a `sig=` that is not a query parameter is left alone.
            '/([?&]sig=)[^&#\s"\']++/i' => '$1' . $m,
            // Provider key shapes.
            '/\bAKIA[0-9A-Z]{16}\b/' => $m,
            '/\bgh[pousr]_[A-Za-z0-9]{30,}\b/' => $m,
            '/\bxox[abprs]-[A-Za-z0-9-]{10,}\b/' => $m,
            // CORE-DATA-2: Stripe secret and restricted keys (a bare `sk_live_` with nothing after it is text), and the path of a Slack incoming webhook (the secret).
            '/\b[rs]k_(?:live|test)_[0-9A-Za-z]{10,}+/' => $m,
            '~(hooks\.slack\.com/services/)[A-Za-z0-9_/-]++~i' => '$1' . $m,
            // This application's own credential formats: the agent and panel enrollment tokens and the RivetPanel device credential (rc.38: an underscore is a word
            // character, so the generic 64-hex rule below can never see the hex of `rvpd_<hex>`).
            '/\brvte1\.[0-9A-Za-z]{6,}\.[0-9A-Za-z_-]{8,}/' => $m,
            '/\brvtp1\.[0-9A-Za-z]{6,}\.[0-9A-Za-z_-]{8,}/' => $m,
            '/\brvpd_[0-9A-Za-z]{16,}/' => $m,
            '/\b[0-9a-f]{64}\b/' => $m,
        ];
        if (!$passwordToEndOfLine) {
            unset($rules[$toEol]);
        }
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
