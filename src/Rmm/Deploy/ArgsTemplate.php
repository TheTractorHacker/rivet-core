<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Deploy;

/**
 * The silent-install argument TEMPLATE of an exe version: the fixed arguments an administrator saved, with named placeholders `{{name}}` for vendor parameters
 * (an organisation key, an account key, a server address). The template is the allow-list: it is saved with the version, can never be edited, and is the only
 * thing a technician can influence by choosing values. A technician never types an argument, only a value for a name the template already declares.
 *
 * RULES (checked when the version is saved and again when the job is built):
 *  - a placeholder is `{{` + a lower-case name (letters, digits, underscore; at most 30) + `}}`; at most {@see MAX_PLACEHOLDERS} in a template;
 *  - a placeholder must come straight after `=` or `:`, so it is always the VALUE of a named option (`/ORG_KEY={{org_key}}`) and can never be a whole token
 *    (a value can never become an option of its own);
 *  - with every placeholder replaced by a plain letter the template must pass {@see DeployProtocol::argsOk()} (no quotes, no shell characters, token limits);
 *  - a value is {@see VALUE_RE}: 1 to 128 characters of letters, digits and `. _ ~ : / = + , -`, with no space, quote, brace, backslash or shell character, so a
 *    value can neither add a token nor leave its own;
 *  - the rendered text passes {@see DeployProtocol::argsOk()} again (the agent checks it a third time).
 *
 * @api
 */
final class ArgsTemplate
{
    public const NAME_RE = '/^[a-z][a-z0-9_]{0,29}$/D';
    public const VALUE_RE = '/^[A-Za-z0-9._~:\/=+,-]{1,128}$/D';
    public const MAX_PLACEHOLDERS = 8;
    private const PLACEHOLDER_RE = '/\{\{([^{}]*)\}\}/';

    /** Whether the text holds any `{{` (it is then a template and goes through {@see validate()}). */
    public static function hasPlaceholders(string $tpl): bool
    {
        return str_contains($tpl, '{{') || str_contains($tpl, '}}');
    }

    /**
     * The distinct placeholder names in order of first use.
     *
     * @return list<string>
     */
    public static function names(string $tpl): array
    {
        preg_match_all(self::PLACEHOLDER_RE, $tpl, $m);

        return array_values(array_unique(array_map('strval', $m[1])));
    }

    /** Why a template is not acceptable, or null. */
    public static function validate(string $tpl): ?string
    {
        if (!self::hasPlaceholders($tpl)) {
            return DeployProtocol::argsOk($tpl) ? null : 'The arguments hold characters or tokens that are not allowed.';
        }
        preg_match_all(self::PLACEHOLDER_RE, $tpl, $m, PREG_OFFSET_CAPTURE);
        if (count($m[0]) > self::MAX_PLACEHOLDERS) {
            return 'At most ' . self::MAX_PLACEHOLDERS . ' placeholders.';
        }
        foreach ($m[1] as $i => [$name]) {
            if (preg_match(self::NAME_RE, (string) $name) !== 1) {
                return 'A placeholder name is lower-case letters, digits and underscore, starting with a letter, at most 30 characters.';
            }
            $at = (int) $m[0][$i][1];
            $prev = $at === 0 ? '' : $tpl[$at - 1];
            if ($prev !== '=' && $prev !== ':') {
                return 'A placeholder must follow = or : so that it is the value of a named option, such as /ORG_KEY={{org_key}}.';
            }
        }
        $stub = (string) preg_replace(self::PLACEHOLDER_RE, 'x', $tpl);
        if (str_contains($stub, '{') || str_contains($stub, '}')) {
            return 'Braces are only allowed as {{name}}.';
        }

        return DeployProtocol::argsOk($stub) ? null : 'The arguments hold characters or tokens that are not allowed.';
    }

    public static function valueOk(mixed $v): bool
    {
        return is_string($v) && preg_match(self::VALUE_RE, $v) === 1;
    }

    /**
     * The final argument string, or null when a value is missing or invalid or the result is not acceptable.
     *
     * @param array<string,string> $values
     */
    public static function render(string $tpl, array $values): ?string
    {
        $ok = true;
        $out = preg_replace_callback(self::PLACEHOLDER_RE, static function (array $m) use ($values, &$ok): string {
            $v = $values[$m[1]] ?? null;
            if (!self::valueOk($v)) {
                $ok = false;

                return '';
            }

            return (string) $v;
        }, $tpl);

        return $ok && is_string($out) && DeployProtocol::argsOk($out) ? $out : null;
    }
}
