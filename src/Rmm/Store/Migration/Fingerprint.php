<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\Migration;

/**
 * A comparable text for one row: every column except the surrogate id, normalised the way both stores return it. Two rows with the same fingerprint are the same fact
 * (the surrogate id is the only thing that may legitimately differ between the stores). Text is normalised the way the PostgreSQL connection stores it (a NUL byte becomes
 * U+FFFD, invalid UTF-8 becomes `?`), so a row that MariaDB holds with such a byte compares equal to its copy.
 *
 * @internal
 */
final class Fingerprint
{
    /** @param array<string,mixed> $row */
    public static function of(HotTable $table, array $row): string
    {
        $parts = [];
        foreach ($table->columns as $col => $type) {
            $v = $row[$col] ?? null;
            $parts[] = $v === null ? "\x01N" : match ($type) {
                'f' => var_export((float) $v, true),
                'i' => (string) (int) $v,
                's' => self::text((string) $v),
                'x' => strtolower((string) $v),
                'j' => (string) json_encode(json_decode((string) $v, true)),   // jsonb re-spaces the text; the content is what counts
                default => (string) $v,
            };
        }

        return implode("\x1f", $parts);
    }

    /** @param list<array<string,mixed>> $rows */
    public static function digest(HotTable $table, array $rows): string
    {
        $fps = array_map(static fn (array $r): string => self::of($table, $r), $rows);
        sort($fps, SORT_STRING);

        return md5(implode("\x1e", $fps));
    }

    private static function text(string $s): string
    {
        $s = str_replace("\0", "\u{FFFD}", $s);
        if (!mb_check_encoding($s, 'UTF-8')) {
            $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');   // replaces invalid sequences with "?"
        }

        return $s;
    }
}
