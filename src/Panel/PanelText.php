<?php

declare(strict_types=1);

namespace RivetCore\Panel;

use RivetCore\Rmm\Http\ApiError;

/**
 * Text hygiene of the panel API.
 *
 * INPUT ({@see self::input()}): must be valid UTF-8; NUL, C0/C1 controls (newline and tab kept, CRLF and CR normalised to LF),
 * every bidirectional override/isolate/mark, zero-width and invisible formatting characters and the Unicode line/paragraph separators are removed, then the
 * text is trimmed and length-checked in characters. Markup is NOT stripped (a user may write "<error>"): what is stored is plain text and the edition's
 * renderer must escape it, as for any other user text.
 *
 * OUTPUT ({@see self::output()}): everything the API echoes back (subjects, chat messages, names) is plain text with markup neutralised: entities are decoded once,
 * tags are stripped, the same invisible characters are removed, and the two markup characters left over are returned as the entities `&lt;` and `&gt;`, so even a
 * client that wrongly inserts the value into HTML is inert. A client renders the text as text and decodes exactly those two entities.
 *
 * @api
 */
final class PanelText
{
    /** Bidi controls, zero-width and invisible formatting characters, soft hyphen, BOM, line/paragraph separators. */
    private const INVISIBLE = '/[\x{00AD}\x{061C}\x{180E}\x{200B}-\x{200F}\x{2028}-\x{202E}\x{2060}-\x{2069}\x{FEFF}\x{FFF9}-\x{FFFB}]/u';

    /**
     * @throws ApiError 422 when the value is not a string, not UTF-8, empty (unless $allowEmpty) or longer than $max characters
     */
    public static function input(mixed $value, string $field, int $max, bool $multiline, bool $allowEmpty = false): string
    {
        if (!is_string($value)) {
            throw new ApiError(422, 'invalid', "$field must be a string.");
        }
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new ApiError(422, 'invalid', "$field is not valid UTF-8.");
        }
        $s = self::clean($value, $multiline);
        if ($s === '' && !$allowEmpty) {
            throw new ApiError(422, 'invalid', "$field is required.");
        }
        if (mb_strlen($s) > $max) {
            throw new ApiError(422, 'invalid', "$field is longer than $max characters.");
        }

        return $s;
    }

    /** Text from the gateway or the database on its way to the panel. Never throws. */
    public static function output(mixed $value, bool $multiline = true): string
    {
        $s = is_scalar($value) ? (string) $value : '';
        if (!mb_check_encoding($s, 'UTF-8')) {
            $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        }
        $s = html_entity_decode($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        $s = strip_tags($s);
        $s = self::clean($s, $multiline);

        return str_replace(['<', '>'], ['&lt;', '&gt;'], $s);
    }

    private static function clean(string $s, bool $multiline): string
    {
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        $s = (string) preg_replace(self::INVISIBLE, '', $s);
        // controls: keep \n and \t only (and drop \n for single-line fields)
        $s = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{0080}-\x{009F}]/u', '', $s);
        if (!$multiline) {
            $s = (string) preg_replace('/[\n\t]+/', ' ', $s);
        }

        return trim($s);
    }
}
