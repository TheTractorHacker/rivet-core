<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Software;

/**
 * A forgiving version comparison for the strings real software reports ("23.01", "8.5.0-2ubuntu10.6", "1:2.39-22.el10", "131.0b9"). It is
 * NOT a package manager's ordering: a leading epoch ("1:") is ignored, the rest is split into runs of digits and runs of letters, digit runs
 * compare as numbers and letter runs as text (a text run sorts before the end of the string, so "1.0b1" is older than "1.0"). It is
 * used to label a change as an upgrade or a downgrade and to answer "which devices run something older than X".
 *
 * @api
 */
final class SoftwareVersion
{
    /** -1, 0 or 1 */
    public static function compare(string $a, string $b): int
    {
        if ($a === $b) {
            return 0;
        }
        $x = self::tokens($a);
        $y = self::tokens($b);
        $n = max(count($x), count($y));
        for ($i = 0; $i < $n; ++$i) {
            $p = $x[$i] ?? null;
            $q = $y[$i] ?? null;
            if ($p === null || $q === null) {
                // one string ends: a remaining number makes the longer one newer ("1.0.1" > "1.0", "1.0.0" = "1.0"), a remaining letter run a pre-release ("1.0b1" < "1.0")
                $rest = $p ?? $q;
                if (ctype_digit((string) $rest) && ltrim((string) $rest, '0') === '') {
                    continue;
                }
                $longer = ctype_digit((string) $rest) ? 1 : -1;

                return $p === null ? -$longer : $longer;
            }
            if (ctype_digit($p) && ctype_digit($q)) {
                $p = ltrim($p, '0');
                $q = ltrim($q, '0');
                $c = strlen($p) <=> strlen($q) ?: strcmp($p, $q);
            } elseif (ctype_digit($p) !== ctype_digit($q)) {
                $c = ctype_digit($p) ? 1 : -1;   // a number beats a letter run at the same position
            } else {
                $c = strcmp(strtolower($p), strtolower($q));
            }
            if ($c !== 0) {
                return $c <=> 0;
            }
        }

        return 0;
    }

    /** @return list<string> */
    private static function tokens(string $v): array
    {
        $v = (string) preg_replace('/^\d+:/', '', trim($v));
        preg_match_all('/\d+|[A-Za-z]+/', $v, $m);

        return $m[0];
    }
}
