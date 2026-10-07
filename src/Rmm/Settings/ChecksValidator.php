<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Settings;

use RivetCore\Rmm\RmmProtocol;

/**
 * Validation and normalisation of the admin-supplied check definition list (the checks_json setting). Ported 1:1 from RivetIT
 * Config::validateChecks. Check types: service, disk, pending_reboot, script (script checks execute code on the endpoint, so
 * the delivered definitions are signed; see Crypto\Signer::checkMessage). Values must be integers (canonical JSON refuses floats).
 *
 * @api
 */
final class ChecksValidator
{
    public const TYPES = ['service', 'disk', 'pending_reboot', 'script'];
    public const MAX_CHECKS = 50;
    public const MIN_INTERVAL_S = 30;
    public const MAX_INTERVAL_S = 86400;
    public const MAX_SCRIPT_BYTES = 8192;
    public const SCRIPT_TIMEOUT_DEFAULT_S = 30;
    public const SCRIPT_TIMEOUT_MAX_S = 60;

    /**
     * @return array{0:?list<array{key:mixed,type:string,params:array<mixed>|\stdClass,interval_s:int}>,1:?string} [normalised list, error]
     */
    public static function validate(string $json): array
    {
        $list = json_decode($json, true);
        if (!is_array($list) || !array_is_list($list) || count($list) > self::MAX_CHECKS) {
            return [null, 'Checks must be a JSON list of at most 50 items.'];
        }
        $seen = [];
        $out = [];
        foreach ($list as $c) {
            $key = is_array($c) ? ($c['key'] ?? '') : '';
            if (!is_array($c) || preg_match(RmmProtocol::CHECK_KEY_RE, self::str($key)) !== 1) {
                return [null, 'Each check needs a key of letters, digits and _ . : -'];
            }
            $key = self::str($c['key']);
            if (isset($seen[$key])) {
                return [null, 'Duplicate check key ' . $key];
            }
            $seen[$key] = 1;
            $type = $c['type'] ?? '';
            if (!is_string($type) || !in_array($type, self::TYPES, true)) {
                return [null, 'Check type must be service, disk, pending_reboot or script.'];
            }
            $iv = self::int($c['interval_s'] ?? 0);
            if ($iv < self::MIN_INTERVAL_S || $iv > self::MAX_INTERVAL_S) {
                return [null, 'Check interval_s must be 30 to 86400.'];
            }
            $params = $c['params'] ?? [];
            if (self::hasFloat($c)) {
                return [null, 'Check values must be whole numbers (no decimals).'];
            }
            if (!is_array($params)) {
                return [null, 'Check params must be an object.'];
            }
            if ($type === 'script') {
                $body = self::str($params['script'] ?? '');
                if ($body === '' || strlen($body) > self::MAX_SCRIPT_BYTES) {
                    return [null, 'Script checks need params.script (at most 8 KiB).'];
                }
                $params['timeout_s'] = max(1, min(self::SCRIPT_TIMEOUT_MAX_S, self::int($params['timeout_s'] ?? self::SCRIPT_TIMEOUT_DEFAULT_S)));
            }
            $out[] = ['key' => $c['key'], 'type' => $type, 'params' => $params ?: new \stdClass(), 'interval_s' => $iv];
        }

        return [$out, null];
    }

    private static function hasFloat(mixed $v): bool
    {
        if (is_float($v)) {
            return true;
        }
        if (is_array($v)) {
            foreach ($v as $x) {
                if (self::hasFloat($x)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** PHP's (string) cast for scalars; arrays and null become '' (the original cast would warn on arrays). */
    private static function str(mixed $v): string
    {
        return is_scalar($v) ? (string) $v : '';
    }

    private static function int(mixed $v): int
    {
        return is_scalar($v) ? (int) $v : 0;
    }
}
