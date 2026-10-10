<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Fields;

/**
 * The `{{field.name}}` placeholder a script parameter (or a job parameter) may carry. It is replaced, per device, by the value of the custom
 * field of that name that applies to the device. A secret field can only fill a parameter declared `secret` with nothing around the
 * placeholder; a plain field may appear anywhere inside text.
 *
 * @api
 */
final class FieldPlaceholders
{
    public const PATTERN = '/\{\{\s*field\.([a-z][a-z0-9_]{0,39})\s*\}\}/';

    public static function validName(string $name): bool
    {
        return preg_match('/^[a-z][a-z0-9_]{0,39}$/', $name) === 1;
    }

    /** @return list<string> the field names a text refers to, in order, without repeats */
    public static function names(string $text): array
    {
        if (!str_contains($text, '{{') || preg_match_all(self::PATTERN, $text, $m) === 0) {
            return [];
        }

        return array_values(array_unique($m[1]));
    }

    /** The field name when the whole text is exactly one placeholder, else null. */
    public static function whole(string $text): ?string
    {
        return preg_match('/^\s*\{\{\s*field\.([a-z][a-z0-9_]{0,39})\s*\}\}\s*$/', $text, $m) === 1 ? $m[1] : null;
    }

    /**
     * Replace the placeholders of a text.
     *
     * @param array<string,string> $values field name => value
     */
    public static function fill(string $text, array $values): string
    {
        return (string) preg_replace_callback(self::PATTERN, static fn (array $m): string => $values[$m[1]] ?? '', $text);
    }
}
