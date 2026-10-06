<?php

declare(strict_types=1);

namespace RivetCore\Webhooks;

/**
 * A SAFE placeholder renderer for admin-written webhook bodies. It is a pure substitution engine: no code execution, no
 * loops, no conditionals, no function calls, no access to anything but the context array it is given.
 *
 *   {{path.to.value}}              value at a dotted path (list items by number: data.items.0.name); missing = ""
 *   {{path|default:"fallback"}}    fallback when the value is missing or empty
 *   {{path|upper|lower|trim}}      case / whitespace filters
 *   {{path|truncate:80}}           cut to 80 characters (ellipsis included)
 *   {{path|json}}                  the value as a JSON literal (strings quoted, arrays/objects/numbers as JSON); must be last
 *
 * Escaping follows the chosen encoding, so a template cannot break out of its own structure:
 *   json  a plain {{x}} is escaped for the inside of a JSON string, so write "title": "{{summary.title}}";
 *         use {{data.id|json}} (no quotes around it) for numbers, booleans, arrays and objects
 *   text  inserted as is (NUL bytes removed)
 *   form  percent-encoded for application/x-www-form-urlencoded
 *
 * Limits: template 8 KB, rendered output 64 KB, 100 placeholders.
 *
 * @api
 */
final class PayloadTemplate
{
    public const MAX_TEMPLATE_BYTES = 8192;
    public const MAX_RENDERED_BYTES = 65536;
    public const MAX_PLACEHOLDERS = 100;
    public const ENCODINGS = ['json', 'text', 'form'];
    public const FILTERS = ['default', 'json', 'upper', 'lower', 'trim', 'truncate'];

    private const PATH_RE = '/^[A-Za-z_][A-Za-z0-9_-]*(?:\.[A-Za-z0-9_-]+)*$/';
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

    /**
     * @param array<string,mixed> $context
     * @throws \InvalidArgumentException when the template is invalid or the output would exceed the limits
     */
    public static function render(string $template, array $context, string $encoding = 'json'): string
    {
        if (!in_array($encoding, self::ENCODINGS, true)) {
            throw new \InvalidArgumentException('Unknown encoding "' . $encoding . '".');
        }
        [$parts, $errors] = self::parse($template);
        if ($errors !== []) {
            throw new \InvalidArgumentException($errors[0]);
        }
        $out = '';
        foreach ($parts as $part) {
            $out .= is_string($part) ? $part : self::evaluate($part, $context, $encoding);
            if (strlen($out) > self::MAX_RENDERED_BYTES) {
                throw new \InvalidArgumentException('The rendered body is larger than ' . (self::MAX_RENDERED_BYTES / 1024) . ' KB.');
            }
        }

        return $out;
    }

    /**
     * @return list<string> human-readable problems; empty when the template is usable
     */
    public static function validate(string $template, string $encoding = 'json'): array
    {
        if (!in_array($encoding, self::ENCODINGS, true)) {
            return ['Unknown encoding "' . $encoding . '".'];
        }
        if (trim($template) === '') {
            return ['The template is empty.'];
        }
        [, $errors] = self::parse($template);
        if ($errors !== []) {
            return array_values(array_unique($errors));
        }
        try {
            $sample = self::render($template, self::sampleContext('ticket.created'), $encoding);
        } catch (\InvalidArgumentException $e) {
            return [$e->getMessage()];
        }
        if ($encoding === 'json') {
            json_decode($sample);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return ['The template does not produce valid JSON (' . json_last_error_msg() . '). Put {{placeholders}} inside quotes for text, or use {{path|json}} without quotes for numbers, booleans, lists and objects.'];
            }
        }

        return [];
    }

    /**
     * Distinct data paths the template reads, in first-use order.
     *
     * @return list<string>
     */
    public static function placeholders(string $template): array
    {
        [$parts] = self::parse($template);
        $paths = [];
        foreach ($parts as $part) {
            if (is_array($part)) {
                $paths[$part['path']] = true;
            }
        }

        return array_map('strval', array_keys($paths));
    }

    /**
     * Realistic sample data for previews and tests: {event, timestamp, data, summary}.
     *
     * @return array<string,mixed>
     */
    public static function sampleContext(string $eventType): array
    {
        $family = explode('.', $eventType)[0];
        $data = in_array($family, ['ticket', 'sla'], true)
            ? [
                'ticket_id' => 1042, 'ticket_number' => 'TCK-1042', 'ticket_subject' => 'Printer on floor 2 is offline',
                'ticket_priority' => 'High', 'ticket_status' => 'Open', 'client_id' => 7, 'client_name' => 'Acme Corp',
                'contact_id' => 31, 'contact_name' => 'Dana Reyes', 'assigned_to_user_id' => 4, 'assigned_to_user_name' => 'Sam Technician',
            ]
            : [
                'summary' => 'Sample event "' . $eventType . '" recorded by Alex Admin',
                'action' => explode('.', $eventType)[1] ?? 'update',
                'entity_type' => $family, 'entity_id' => '15', 'actor' => 'Alex Admin',
                'client_name' => 'Acme Corp',
            ];

        return [
            'event' => $eventType,
            'timestamp' => '2026-01-02T03:04:05Z',
            'data' => $data,
            'summary' => EventSummary::fromEvent($eventType, $data),
        ];
    }

    // ------------------------------------------------------------------------------------------------------------

    /**
     * @return array{0:list<string|array{path:string,filters:list<array{0:string,1:mixed}>}>,1:list<string>}
     */
    private static function parse(string $template): array
    {
        $errors = [];
        if (strlen($template) > self::MAX_TEMPLATE_BYTES) {
            return [[], ['The template is larger than ' . (self::MAX_TEMPLATE_BYTES / 1024) . ' KB.']];
        }
        if (!mb_check_encoding($template, 'UTF-8')) {
            return [[], ['The template is not valid UTF-8.']];
        }
        $parts = [];
        $pos = 0;
        $count = 0;
        while (preg_match('/\{\{(.*?)\}\}/s', $template, $m, PREG_OFFSET_CAPTURE, $pos) === 1) {
            $start = $m[0][1];
            $literal = substr($template, $pos, $start - $pos);
            if (str_contains($literal, '{{')) {
                $errors[] = 'Unbalanced braces: "{{" without a closing "}}".';
            }
            if ($literal !== '') {
                $parts[] = $literal;
            }
            if (++$count > self::MAX_PLACEHOLDERS) {
                $errors[] = 'Too many placeholders (max ' . self::MAX_PLACEHOLDERS . ').';
                break;
            }
            $expr = self::parseExpression($m[1][0]);
            if (is_string($expr)) {
                $errors[] = $expr;
            } else {
                $parts[] = $expr;
            }
            $pos = $start + strlen($m[0][0]);
        }
        $tail = substr($template, $pos);
        if (str_contains($tail, '{{')) {
            $errors[] = 'Unbalanced braces: "{{" without a closing "}}".';
        }
        if ($tail !== '') {
            $parts[] = $tail;
        }

        return [$parts, $errors];
    }

    /** @return array{path:string,filters:list<array{0:string,1:mixed}>}|string error message */
    private static function parseExpression(string $raw)
    {
        $segments = self::splitPipes(trim($raw));
        $path = trim((string) array_shift($segments));
        $shown = '{{' . self::clip(trim($raw)) . '}}';
        if (preg_match(self::PATH_RE, $path) !== 1) {
            return 'Invalid placeholder ' . $shown . ': expected a path such as data.ticket_id (letters, digits, _ - and dots only; no code or functions).';
        }
        $filters = [];
        foreach ($segments as $i => $seg) {
            $seg = trim($seg);
            if (!preg_match('/^([a-z]+)(?::(.*))?$/s', $seg, $fm)) {
                return 'Invalid filter in ' . $shown . '.';
            }
            $name = $fm[1];
            $arg = $fm[2] ?? null;
            if (!in_array($name, self::FILTERS, true)) {
                return 'Unknown filter "' . $name . '" in ' . $shown . '. Allowed: ' . implode(', ', self::FILTERS) . '.';
            }
            if ($name === 'default') {
                if ($arg === null || preg_match('/^"((?:[^"\\\\]|\\\\.)*)"$/s', trim($arg), $am) !== 1) {
                    return 'The default filter needs a quoted value, e.g. |default:"n/a" in ' . $shown . '.';
                }
                $filters[] = ['default', preg_replace('/\\\\(.)/s', '$1', $am[1])];
            } elseif ($name === 'truncate') {
                if ($arg === null || preg_match('/^\s*(\d{1,5})\s*$/', $arg, $am) !== 1 || (int) $am[1] < 1) {
                    return 'The truncate filter needs a length, e.g. |truncate:80 in ' . $shown . '.';
                }
                $filters[] = ['truncate', (int) $am[1]];
            } else {
                if ($arg !== null) {
                    return 'The ' . $name . ' filter takes no argument in ' . $shown . '.';
                }
                if ($name === 'json' && $i !== count($segments) - 1) {
                    return 'The json filter must be the last filter in ' . $shown . '.';
                }
                $filters[] = [$name, null];
            }
        }

        return ['path' => $path, 'filters' => $filters];
    }

    /** @return list<string> */
    private static function splitPipes(string $s): array
    {
        $out = [];
        $cur = '';
        $inQuote = false;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            if ($inQuote && $c === '\\' && $i + 1 < $len) {
                $cur .= $c . $s[++$i];
                continue;
            }
            if ($c === '"') {
                $inQuote = !$inQuote;
            }
            if ($c === '|' && !$inQuote) {
                $out[] = $cur;
                $cur = '';
                continue;
            }
            $cur .= $c;
        }
        $out[] = $cur;

        return $out;
    }

    /**
     * @param array{path:string,filters:list<array{0:string,1:mixed}>} $expr
     * @param array<string,mixed> $context
     */
    private static function evaluate(array $expr, array $context, string $encoding): string
    {
        $value = self::lookup($context, $expr['path']);
        $raw = false;
        foreach ($expr['filters'] as [$name, $arg]) {
            switch ($name) {
                case 'default':
                    if ($value === null || $value === '' || $value === []) {
                        $value = $arg;
                    }
                    break;
                case 'upper':
                    $value = mb_strtoupper(self::stringify($value));
                    break;
                case 'lower':
                    $value = mb_strtolower(self::stringify($value));
                    break;
                case 'trim':
                    $value = trim(self::stringify($value));
                    break;
                case 'truncate':
                    $s = self::stringify($value);
                    $n = (int) $arg;
                    $value = mb_strlen($s) > $n ? mb_substr($s, 0, max(0, $n - 1)) . "\u{2026}" : $s;
                    break;
                case 'json':
                    $json = json_encode($value, self::JSON_FLAGS);
                    $value = $json === false ? 'null' : $json;
                    $raw = true;
                    break;
            }
        }
        $s = self::stringify($value);
        if (strlen($s) > self::MAX_RENDERED_BYTES) {
            throw new \InvalidArgumentException('The rendered body is larger than ' . (self::MAX_RENDERED_BYTES / 1024) . ' KB.');
        }
        if ($raw && $encoding === 'json') {
            return $s;
        }

        return match ($encoding) {
            'json' => (string) substr((string) json_encode($s, self::JSON_FLAGS), 1, -1),
            'form' => rawurlencode($s),
            default => str_replace("\0", '', $s),
        };
    }

    /** @param array<string,mixed> $context */
    private static function lookup(array $context, string $path): mixed
    {
        $cur = $context;
        foreach (explode('.', $path) as $seg) {
            if (!is_array($cur) || !array_key_exists($seg, $cur)) {
                return null;
            }
            $cur = $cur[$seg];
        }

        return $cur;
    }

    private static function stringify(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_scalar($v)) {
            return (string) $v;
        }
        if (is_array($v)) {
            $j = json_encode($v, self::JSON_FLAGS);

            return $j === false ? '' : $j;
        }

        return '';
    }

    private static function clip(string $s): string
    {
        return mb_strlen($s) > 40 ? mb_substr($s, 0, 39) . "\u{2026}" : $s;
    }
}
