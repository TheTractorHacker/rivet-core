<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Crypto;

/**
 * The canonical JSON the endpoint agent signatures cover. The Go agent reproduces this byte for byte
 * (endpoint-agent/testdata/vectors/agent_job_signing_vectors.json pins it; docs/ENDPOINT_AGENT.md section 6).
 *
 *   - UTF-8, no insignificant whitespace.
 *   - Object members sorted by key, comparing the UTF-8 bytes (strcmp order), recursively.
 *   - Arrays keep their order. {} and [] are distinct, so objects must arrive as \stdClass or as non-list arrays.
 *   - Strings escape only \" \\ \b \f \n \r \t, every other code point below U+0020 as \u00xx (lowercase hex).
 *     Everything else, including "/", "<", ">", "&", U+007F, U+2028/2029 and non-ASCII, is written raw.
 *   - Numbers are integers only (no fraction, no exponent, no "-0"); true/false/null literal. Floats are refused.
 *
 * @api
 */
final class CanonicalJson
{
    /**
     * @param mixed $value decoded with json_decode($json, false) so {} and [] stay distinct
     * @throws \InvalidArgumentException for floats, invalid UTF-8 and unsupported types
     */
    public static function encode(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            throw new \InvalidArgumentException('canonical JSON does not allow floating point numbers');
        }
        if (is_string($value)) {
            return self::string($value);
        }
        if ($value instanceof \stdClass) {
            $members = (array) $value;
        } elseif (is_array($value)) {
            if (array_is_list($value)) {
                return '[' . implode(',', array_map(self::encode(...), $value)) . ']';
            }
            $members = $value;
        } else {
            throw new \InvalidArgumentException('unsupported type in canonical JSON');
        }
        $byKey = [];
        foreach ($members as $k => $v) {
            $byKey[(string) $k] = $v;
        }
        $keys = array_keys($byKey);
        usort($keys, static fn (int|string $a, int|string $b): int => strcmp((string) $a, (string) $b));
        $parts = [];
        foreach ($keys as $k) {
            $parts[] = self::string((string) $k) . ':' . self::encode($byKey[$k]);
        }

        return '{' . implode(',', $parts) . '}';
    }

    /**
     * Recursively turns associative arrays into \stdClass so empty objects serialise as {}. Lists stay lists.
     */
    public static function toObject(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $o = new \stdClass();
            foreach ((array) $value as $k => $x) {
                $o->{(string) $k} = self::toObject($x);
            }

            return $o;
        }
        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(self::toObject(...), $value);
            }
            $o = new \stdClass();
            foreach ($value as $k => $x) {
                $o->{(string) $k} = self::toObject($x);
            }

            return $o;
        }

        return $value;
    }

    private static function string(string $s): string
    {
        if (!mb_check_encoding($s, 'UTF-8')) {
            throw new \InvalidArgumentException('canonical JSON strings must be valid UTF-8');
        }
        $map = ['"' => '\\"', '\\' => '\\\\', "\x08" => '\\b', "\x0c" => '\\f', "\n" => '\\n', "\r" => '\\r', "\t" => '\\t'];
        $out = preg_replace_callback('/["\\\\\x00-\x1f]/', static fn (array $m): string => $map[$m[0]] ?? sprintf('\\u%04x', ord($m[0])), $s);

        return '"' . ($out ?? '') . '"';
    }
}
