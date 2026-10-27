<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Scripts;

/**
 * A library script's parameter definition and the validation of the values a caller supplies.
 *
 * A definition is a list of `{name, type, required?, default?, label?, description?, choices?, max_length?, min?, max?}` with `type` one of
 * string, int, bool, choice, secret. Names are lower case identifiers (`^[a-z][a-z0-9_]{0,31}$`) that are not a reserved word or an automatic
 * variable of bash, PowerShell or Python, because the agent exposes each parameter to the script under its own name. A `secret` is never stored
 * in the job row, never shown in a job list or an audit line, and removed from the job output.
 *
 * @api
 */
final class ParamSchema
{
    public const MAX_PARAMS = 20;
    public const MAX_VALUE_BYTES = 1024;
    public const MAX_SCHEMA_BYTES = 16384;
    public const TYPES = ['string', 'int', 'bool', 'choice', 'secret'];

    /** Names that would shadow something the interpreter needs. Lower case; PowerShell compares names without regard to case. */
    private const RESERVED = ['args', 'error', 'home', 'host', 'input', 'matches', 'pid', 'ppid', 'profile', 'pwd', 'oldpwd', 'this', 'true', 'false', 'null', 'psitem', 'shellid',
        'env', 'path', 'ifs', 'uid', 'euid', 'random', 'lineno', 'seconds', 'shell', 'term', 'user', 'hostname', 'optarg', 'optind', 'reply', 'status', 'params', 'foreach',
        'consolehost', 'executioncontext', 'lastexitcode', 'myinvocation', 'psboundparameters', 'pscmdlet', 'psscriptroot', 'pscommandpath', 'stacktrace', 'sender',
        // bash reserved words
        'then', 'fi', 'esac', 'select', 'until', 'do', 'done', 'function', 'time', 'coproc',
        // python keywords
        'and', 'as', 'assert', 'async', 'await', 'break', 'class', 'continue', 'def', 'del', 'elif', 'else', 'except', 'finally', 'for', 'from', 'global', 'if', 'import', 'in',
        'is', 'lambda', 'nonlocal', 'not', 'or', 'pass', 'raise', 'return', 'try', 'while', 'with', 'yield', 'os', 'sys', 'json'];

    public static function validName(string $name): bool
    {
        return preg_match('/^[a-z][a-z0-9_]{0,31}$/', $name) === 1 && !in_array($name, self::RESERVED, true) && !str_starts_with($name, 'rivet');
    }

    /**
     * Normalise a definition.
     *
     * @return array{0:?list<array<string,mixed>>,1:?string} [normalised list, error]
     */
    public static function normalizeSchema(mixed $schema): array
    {
        if ($schema === null || $schema === '' || $schema === []) {
            return [[], null];
        }
        if (is_string($schema)) {
            if (strlen($schema) > self::MAX_SCHEMA_BYTES) {
                return [null, 'The parameter definition is too large.'];
            }
            $schema = json_decode($schema, true);
        }
        if (!is_array($schema) || !array_is_list($schema) || count($schema) > self::MAX_PARAMS) {
            return [null, 'Parameters must be a list of at most ' . self::MAX_PARAMS . ' definitions.'];
        }
        $out = [];
        $seen = [];
        foreach ($schema as $p) {
            if (!is_array($p) || array_is_list($p)) {
                return [null, 'Each parameter is an object.'];
            }
            $name = $p['name'] ?? null;
            if (!is_string($name) || !self::validName($name)) {
                return [null, 'A parameter name is lower case letters, digits and _, starts with a letter, is at most 32 characters and is not a reserved word.'];
            }
            if (isset($seen[$name])) {
                return [null, "Duplicate parameter $name."];
            }
            $seen[$name] = 1;
            $type = $p['type'] ?? 'string';
            if (!is_string($type) || !in_array($type, self::TYPES, true)) {
                return [null, "Parameter $name: type must be string, int, bool, choice or secret."];
            }
            $d = ['name' => $name, 'type' => $type, 'required' => !empty($p['required'])];
            foreach (['label' => 80, 'description' => 200] as $k => $max) {
                if (isset($p[$k])) {
                    if (!is_string($p[$k]) || mb_strlen($p[$k]) > $max || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $p[$k]) === 1) {
                        return [null, "Parameter $name: $k is text of at most $max characters."];
                    }
                    $d[$k] = $p[$k];
                }
            }
            switch ($type) {
                case 'string':
                case 'secret':
                    $max = $p['max_length'] ?? 256;
                    if (!is_int($max) || $max < 1 || $max > self::MAX_VALUE_BYTES) {
                        return [null, "Parameter $name: max_length is 1 to " . self::MAX_VALUE_BYTES . '.'];
                    }
                    $d['max_length'] = $max;
                    break;
                case 'int':
                    foreach (['min', 'max'] as $k) {
                        if (isset($p[$k])) {
                            if (!is_int($p[$k])) {
                                return [null, "Parameter $name: $k is a whole number."];
                            }
                            $d[$k] = $p[$k];
                        }
                    }
                    if (isset($d['min'], $d['max']) && $d['min'] > $d['max']) {
                        return [null, "Parameter $name: min is above max."];
                    }
                    break;
                case 'choice':
                    $c = $p['choices'] ?? null;
                    if (!is_array($c) || !array_is_list($c) || $c === [] || count($c) > 50) {
                        return [null, "Parameter $name: choices is a list of 1 to 50 strings."];
                    }
                    foreach ($c as $x) {
                        if (!is_string($x) || $x === '' || strlen($x) > 100 || str_contains($x, "\0")) {
                            return [null, "Parameter $name: a choice is a string of 1 to 100 characters."];
                        }
                    }
                    $d['choices'] = array_values(array_unique($c));
                    break;
                default:
                    break;
            }
            if (array_key_exists('default', $p) && $p['default'] !== null) {
                if ($type === 'secret') {
                    return [null, "Parameter $name: a secret has no default."];
                }
                [$v, $err] = self::coerce($d, $p['default']);
                if ($err !== null) {
                    return [null, "Parameter $name: default: $err"];
                }
                $d['default'] = $v;
            }
            $out[] = $d;
        }

        return [$out, null];
    }

    /**
     * Check supplied values against a definition and apply the defaults.
     *
     * @param list<array<string,mixed>> $schema a normalised definition
     * @param array<array-key,mixed> $supplied name => value
     * @return array{0:?array<string,string|int|bool>,1:list<string>,2:?string} [values by name, names of the secret parameters, error]
     */
    public static function validate(array $schema, array $supplied): array
    {
        $by = [];
        foreach ($schema as $p) {
            $by[(string) $p['name']] = $p;
        }
        foreach (array_keys($supplied) as $k) {
            if (!isset($by[(string) $k])) {
                return [null, [], 'Unknown parameter ' . (is_string($k) && preg_match('/^[A-Za-z0-9_]{1,40}$/', $k) === 1 ? $k : '(invalid name)') . '.'];
            }
        }
        $out = [];
        $secrets = [];
        foreach ($by as $name => $p) {
            if (array_key_exists($name, $supplied) && $supplied[$name] !== null && $supplied[$name] !== '') {
                [$v, $err] = self::coerce($p, $supplied[$name]);
                if ($err !== null) {
                    return [null, [], "Parameter $name: $err"];
                }
                $out[$name] = $v;
            } elseif (array_key_exists('default', $p)) {
                $out[$name] = $p['default'];
            } elseif (!empty($p['required'])) {
                return [null, [], "Parameter $name is required."];
            } else {
                continue;
            }
            if ($p['type'] === 'secret') {
                $secrets[] = $name;
            }
        }

        return [$out, $secrets, null];
    }

    /**
     * @param array<string,mixed> $p one definition
     * @return array{0:string|int|bool|null,1:?string}
     */
    public static function coerce(array $p, mixed $v): array
    {
        switch ($p['type']) {
            case 'string':
            case 'secret':
                if (is_int($v) || is_float($v) || is_bool($v)) {
                    $v = is_bool($v) ? ($v ? 'true' : 'false') : (string) $v;
                }
                if (!is_string($v) || str_contains($v, "\0") || !mb_check_encoding($v, 'UTF-8')) {
                    return [null, 'must be text.'];
                }
                if (strlen($v) > (int) ($p['max_length'] ?? 256)) {
                    return [null, 'is longer than ' . (int) ($p['max_length'] ?? 256) . ' bytes.'];
                }

                return [$v, null];
            case 'int':
                if (is_string($v) && preg_match('/^-?\d{1,15}$/', trim($v)) === 1) {
                    $v = (int) trim($v);
                }
                if (!is_int($v)) {
                    return [null, 'must be a whole number.'];
                }
                if ((isset($p['min']) && $v < $p['min']) || (isset($p['max']) && $v > $p['max'])) {
                    return [null, 'is outside ' . ($p['min'] ?? '-inf') . ' to ' . ($p['max'] ?? 'inf') . '.'];
                }

                return [$v, null];
            case 'bool':
                if (is_string($v)) {
                    $l = strtolower(trim($v));
                    $v = in_array($l, ['true', '1', 'yes', 'on'], true) ? true : (in_array($l, ['false', '0', 'no', 'off'], true) ? false : $v);
                } elseif ($v === 1 || $v === 0) {
                    $v = $v === 1;
                }

                return is_bool($v) ? [$v, null] : [null, 'must be true or false.'];
            case 'choice':
                $s = is_string($v) ? $v : (is_int($v) ? (string) $v : null);

                return $s !== null && in_array($s, (array) ($p['choices'] ?? []), true) ? [$s, null] : [null, 'is not one of the allowed choices.'];
        }

        return [null, 'unsupported type.'];
    }
}
