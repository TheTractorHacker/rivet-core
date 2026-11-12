<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Remote;

/**
 * Text that came from the console (or a person) made safe for an audit row: control characters and line breaks removed so nothing can forge a second log line, then capped.
 *
 * @api
 */
final class ConsoleText
{
    public static function clean(string $s, int $max): string
    {
        $s = mb_scrub($s);
        $s = (string) preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', '', $s);

        return mb_substr(trim($s), 0, $max);
    }

    /** "Alice": letters, digits and a few separators only; `unnamed` when nothing is left. */
    public static function name(string $name): string
    {
        $n = trim((string) preg_replace('/[^\p{L}\p{N} ._@-]/u', '', $name));

        return $n === '' ? 'unnamed' : mb_substr($n, 0, 60);
    }
}
