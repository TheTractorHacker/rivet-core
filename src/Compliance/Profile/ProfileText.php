<?php

declare(strict_types=1);

namespace RivetCore\Compliance\Profile;

/**
 * Text rules for everything a person types into a profile (label, description, requirement notes). Plain text only: valid UTF-8, no control characters
 * (a description may contain line breaks), and nothing that looks like a credential, because profiles are exported, shared and printed in reports.
 *
 * @internal
 */
final class ProfileText
{
    private const SECRET_PATTERNS = [
        '/-----BEGIN [A-Z ]*(KEY|CERTIFICATE)/i',
        '/\b(password|passwd|pwd|passphrase|secret|token|api[_ -]?key|private[_ -]?key|bearer|credential)s?\b\s*[:=]\s*\S+/i',
        '/\bAKIA[0-9A-Z]{16}\b/',
        '/\b(ghp|gho|ghs|ghu|github_pat|glpat|xox[abprs]|sk_live|rk_live)[_-][A-Za-z0-9_-]{10,}/',
        '/\b[A-Fa-f0-9]{32,}\b/',
        '/[A-Za-z0-9+\/_-]{40,}={0,2}/',
        '/:\/\/[^\/\s:@]+:[^\/\s@]+@/',
    ];

    /** @return string|null the cleaned text, or null with the reason added to $errors */
    public static function clean(mixed $value, string $field, int $min, int $max, bool $multiline, array &$errors): ?string
    {
        if (!is_string($value)) {
            $errors[] = "$field must be text.";

            return null;
        }
        if (!mb_check_encoding($value, 'UTF-8')) {
            $errors[] = "$field is not valid UTF-8 text.";

            return null;
        }
        $text = trim(str_replace("\r\n", "\n", $value));
        $control = $multiline ? '/[\x00-\x09\x0b-\x1f\x7f]/' : '/[\x00-\x1f\x7f]/';
        if (preg_match($control, $text) === 1) {
            $errors[] = "$field must not contain control characters" . ($multiline ? '' : ' or line breaks') . '.';

            return null;
        }
        $len = mb_strlen($text);
        if ($len < $min || $len > $max) {
            $errors[] = $min > 0 ? "$field must have $min to $max characters." : "$field may have at most $max characters.";

            return null;
        }
        foreach (self::SECRET_PATTERNS as $re) {
            if (preg_match($re, $text) === 1) {
                $errors[] = "$field must not contain secrets such as passwords, keys or tokens.";

                return null;
            }
        }

        return $text;
    }
}
