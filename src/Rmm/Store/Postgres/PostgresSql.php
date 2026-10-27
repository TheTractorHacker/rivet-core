<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\Postgres;

/**
 * The few SQL fragments every PostgreSQL store builds the same way. Values never go through here: only column names written in this library and numbers.
 *
 * @internal
 */
final class PostgresSql
{
    /** A `timestamptz` column as the UTC database datetime string the stores return (`Y-m-d H:i:s`), whatever the session time zone. */
    public static function ts(string $column, ?string $alias = null): string
    {
        return "to_char($column AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS')" . ($alias === null ? '' : ' AS ' . $alias);
    }

    /** `(?, ?, ?), (?, ?, ?)` for $rows rows of $columns values; $last is the placeholder expression of the last column (`decode(?, 'hex')` for a digest). */
    public static function rows(int $rows, int $columns, string $last = '?'): string
    {
        $one = '(' . implode(', ', [...array_fill(0, $columns - 1, '?'), $last]) . ')';

        return implode(', ', array_fill(0, $rows, $one));
    }

    /** `?, ?, ?` for $n values of an IN list. */
    public static function in(int $n): string
    {
        return implode(', ', array_fill(0, max(1, $n), '?'));
    }

    /** Escape a plain substring for `ILIKE ... ESCAPE '!'` and wrap it in `%`. */
    public static function contains(string $needle): string
    {
        return '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $needle) . '%';
    }

    /** A UTC database datetime for a Unix time. */
    public static function utc(int $ts): string
    {
        return gmdate('Y-m-d H:i:s', $ts);
    }

    /** Seconds since the epoch of a UTC database datetime (0 when empty or unparsable). */
    public static function epoch(?string $utc): int
    {
        if ($utc === null || $utc === '') {
            return 0;
        }
        $ts = strtotime($utc . ' UTC');

        return $ts === false ? 0 : $ts;
    }
}
