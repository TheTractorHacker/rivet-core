<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Checks;

use RivetCore\Rmm\Alerting\FlapDetector;
use RivetCore\Rmm\Alerting\Thresholds;

/**
 * The check type registry: every type the module can define, where it runs, whether it reports a numeric `value` (which makes
 * `params.thresholds` meaningful), and the schema of its params. The four original types (`service`, `disk`, `pending_reboot`, `script`)
 * are LEGACY: every agent understands them, their params are not schema-checked (they never were) and they are offered to every agent. A
 * Phase 3 type is offered only to a device that announced the capability `check:<type>` (the agent announces exactly the types it can run
 * on its platform), so an old agent never sees a type it does not know and a Linux agent never gets `av`.
 *
 * Params are whole numbers, strings, booleans and lists of those: the signed canonical JSON of a check refuses decimals. Every field has a
 * size cap and a safe default (see the agent: an absent field means the default, never "unlimited").
 *
 * @api
 */
final class CheckCatalog
{
    public const LEGACY = ['service', 'disk', 'pending_reboot', 'script'];
    /** Params every check may carry in addition to its own: alert behaviour, read by the server only. */
    public const COMMON = ['thresholds', 'flap', 'ignore_parent'];

    /**
     * type => platforms, whether it reports a value, the unit of the value, and the field schema. Field kinds: int[min,max,default],
     * str[maxlen,pattern?], enum[values,default], bool[default], list[maxitems,itemmaxlen,pattern?]. `req` marks a required field.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function types(): array
    {
        $host = '/^[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$|^[0-9A-Fa-f:]{2,45}$/';

        return [
            'cpu' => ['platforms' => ['windows', 'linux'], 'unit' => 'percent', 'fields' => [
                'window_s' => ['int', 1, 30, 3], 'warn_pct' => ['int', 1, 100, 90], 'fail_pct' => ['int', 1, 100, 98]]],
            'memory' => ['platforms' => ['windows', 'linux'], 'unit' => 'percent', 'fields' => [
                'metric' => ['enum', ['mem_used_pct', 'swap_used_pct', 'psi_some_avg10'], 'mem_used_pct'], 'warn_pct' => ['int', 1, 100, 85], 'fail_pct' => ['int', 1, 100, 95]]],
            'process' => ['platforms' => ['windows', 'linux'], 'unit' => 'count', 'fields' => [
                'name' => ['str', 128, '/^[A-Za-z0-9._ +-]{1,128}$/', 'req' => true], 'min_count' => ['int', 0, 1000, 1], 'max_count' => ['int', 0, 100000, 0]]],
            'port' => ['platforms' => ['windows', 'linux'], 'unit' => 'ms', 'fields' => [
                'host' => ['str', 253, $host, 'req' => true], 'port' => ['int', 1, 65535, 0, 'req' => true], 'timeout_ms' => ['int', 100, 30000, 3000], 'warn_ms' => ['int', 0, 30000, 0]]],
            'ping' => ['platforms' => ['windows', 'linux'], 'unit' => 'ms', 'fields' => [
                'host' => ['str', 253, $host, 'req' => true], 'count' => ['int', 1, 10, 3], 'timeout_ms' => ['int', 200, 10000, 2000],
                'warn_ms' => ['int', 0, 30000, 0], 'fail_ms' => ['int', 0, 30000, 0], 'warn_loss_pct' => ['int', 1, 100, 1], 'fail_loss_pct' => ['int', 1, 100, 100]]],
            'http' => ['platforms' => ['windows', 'linux'], 'unit' => 'ms', 'fields' => [
                'url' => ['str', 2000, '#^https?://[^\s@/]+(/[^\s]*)?$#', 'req' => true], 'method' => ['enum', ['GET', 'HEAD'], 'GET'], 'expect_min' => ['int', 100, 599, 200], 'expect_max' => ['int', 100, 599, 399],
                'timeout_ms' => ['int', 500, 60000, 10000], 'warn_ms' => ['int', 0, 60000, 0], 'contains' => ['str', 200, '/^[^\x00-\x1f]{0,200}$/'], 'verify_tls' => ['bool', true],
                'cert_warn_days' => ['int', 0, 3650, 14], 'cert_fail_days' => ['int', 0, 3650, 3], 'follow_redirects' => ['bool', true]]],
            'cert' => ['platforms' => ['windows', 'linux'], 'unit' => 'days', 'fields' => [
                'paths' => ['list', 10, 300, '#^(/|[A-Za-z]:[\\\\/])[^\x00-\x1f]*$#'], 'store' => ['str', 80, '/^(LocalMachine|CurrentUser)\\\\[A-Za-z0-9 _.-]{1,64}$/'],
                'host' => ['str', 253, $host], 'port' => ['int', 1, 65535, 443], 'warn_days' => ['int', 0, 3650, 30], 'fail_days' => ['int', 0, 3650, 7]]],
            'ntp' => ['platforms' => ['windows', 'linux'], 'unit' => 'ms', 'fields' => [
                'server' => ['str', 253, $host, 'default' => 'pool.ntp.org'], 'timeout_ms' => ['int', 500, 10000, 3000], 'warn_ms' => ['int', 1, 3600000, 1000], 'fail_ms' => ['int', 1, 3600000, 5000]]],
            'smart' => ['platforms' => ['windows', 'linux'], 'unit' => null, 'fields' => [
                'devices' => ['list', 16, 32, '#^/dev/[A-Za-z0-9]{1,24}$#']]],
            'av' => ['platforms' => ['windows'], 'unit' => 'days', 'fields' => [
                'max_signature_age_days' => ['int', 1, 365, 7], 'require_realtime' => ['bool', true]]],
            'systemd_failed' => ['platforms' => ['linux'], 'unit' => 'count', 'fields' => [
                'ignore' => ['list', 20, 128, '/^[A-Za-z0-9:_.@\\\\-]{1,128}$/'], 'max_failed' => ['int', 0, 1000, 0]]],
            'inodes' => ['platforms' => ['linux'], 'unit' => 'percent', 'fields' => [
                'mount' => ['str', 200, '#^/[^\x00-\x1f]*$#'], 'warn_pct' => ['int', 1, 100, 85], 'fail_pct' => ['int', 1, 100, 95]]],
        ];
    }

    /** @return list<string> every defined type, legacy first */
    public static function all(): array
    {
        return array_merge(self::LEGACY, array_keys(self::types()));
    }

    public static function isLegacy(string $type): bool
    {
        return in_array($type, self::LEGACY, true);
    }

    /** True when the type reports a numeric `value`. Legacy types do not. */
    public static function reportsValue(string $type): bool
    {
        $t = self::types()[$type] ?? null;

        return $t !== null && $t['unit'] !== null;
    }

    /**
     * Is this check offered to a device with these announced capabilities? A legacy type always is; a Phase 3 type needs `check:<type>`.
     *
     * @param list<string>|null $caps null/unknown = a device that announced nothing
     */
    public static function offeredTo(string $type, ?array $caps): bool
    {
        return self::isLegacy($type) || ($caps !== null && in_array('check:' . $type, $caps, true));
    }

    /**
     * Validate and normalise the params of a Phase 3 check. Unknown fields are refused; absent fields stay absent (the agent applies the default).
     *
     * @param array<mixed> $params
     * @return array{0:?array<string,mixed>,1:?string} [normalised params, error]
     */
    public static function validateParams(string $type, array $params): array
    {
        $spec = self::types()[$type] ?? null;
        if ($spec === null) {
            return [$params, null];
        }
        if ($params !== [] && array_is_list($params)) {
            return [null, 'Check params must be an object.'];
        }
        $out = [];
        $fields = $spec['fields'];
        foreach ($params as $k => $v) {
            if (in_array($k, self::COMMON, true)) {
                continue;
            }
            if (!isset($fields[$k])) {
                return [null, "Check type $type has no param \"$k\"."];
            }
            $f = $fields[$k];
            $err = null;
            $out[$k] = self::field($type, (string) $k, $f, $v, $err);
            if ($err !== null) {
                return [null, $err];
            }
        }
        foreach ($fields as $k => $f) {
            if (!empty($f['req']) && !array_key_exists($k, $out)) {
                return [null, "Check type $type needs param \"$k\"."];
            }
        }
        $err = self::crossCheck($type, $out);
        if ($err !== null) {
            return [null, $err];
        }
        foreach (self::COMMON as $c) {
            if (!array_key_exists($c, $params)) {
                continue;
            }
            if ($c === 'thresholds') {
                if (!self::reportsValue($type)) {
                    return [null, "Check type $type does not report a value, so it cannot have thresholds."];
                }
                [$t, $e] = Thresholds::normalize($params['thresholds']);
                if ($t === null) {
                    return [null, $e];
                }
                $out['thresholds'] = $t;
            } elseif ($c === 'flap') {
                [$f, $e] = FlapDetector::normalize($params['flap']);
                if ($f === null) {
                    return [null, $e];
                }
                $out['flap'] = $f;
            } else {
                if (!is_bool($params['ignore_parent'])) {
                    return [null, 'ignore_parent must be true or false.'];
                }
                $out['ignore_parent'] = $params['ignore_parent'];
            }
        }
        $size = strlen((string) json_encode($out));
        if ($size > 4096) {
            return [null, 'Check params are too large (at most 4 KiB).'];
        }

        return [$out, null];
    }

    /**
     * @param array<mixed> $f field spec
     */
    private static function field(string $type, string $name, array $f, mixed $v, ?string &$err): mixed
    {
        $label = "$type.$name";
        switch ($f[0]) {
            case 'int':
                if (!is_int($v) || $v < $f[1] || $v > $f[2]) {
                    $err = "$label must be a whole number from {$f[1]} to {$f[2]}.";
                }

                return $v;
            case 'bool':
                if (!is_bool($v)) {
                    $err = "$label must be true or false.";
                }

                return $v;
            case 'enum':
                if (!is_string($v) || !in_array($v, $f[1], true)) {
                    $err = "$label must be one of " . implode(', ', $f[1]) . '.';
                }

                return $v;
            case 'str':
                if (!is_string($v) || $v === '' || strlen($v) > $f[1] || (isset($f[2]) && preg_match($f[2] . 'D', $v) !== 1)) {
                    $err = "$label is not valid (1 to {$f[1]} characters in the allowed form).";
                }

                return $v;
            case 'list':
                if (!is_array($v) || !array_is_list($v) || $v === [] || count($v) > $f[1]) {
                    $err = "$label must be a list of 1 to {$f[1]} items.";

                    return $v;
                }
                foreach ($v as $item) {
                    if (!is_string($item) || $item === '' || strlen($item) > $f[2] || (isset($f[3]) && preg_match($f[3] . 'D', $item) !== 1)) {
                        $err = "$label has an item that is not valid (at most {$f[2]} characters in the allowed form).";
                    }
                }

                return $v;
        }

        return $v;
    }

    /** @param array<string,mixed> $p */
    private static function crossCheck(string $type, array $p): ?string
    {
        if (in_array($type, ['cpu', 'memory', 'inodes'], true) && isset($p['warn_pct'], $p['fail_pct']) && $p['fail_pct'] < $p['warn_pct']) {
            return "$type.fail_pct must not be below warn_pct.";
        }
        if ($type === 'cert' && isset($p['warn_days'], $p['fail_days']) && $p['fail_days'] > $p['warn_days']) {
            return 'cert.fail_days must not be above warn_days.';
        }
        if ($type === 'ntp' && isset($p['warn_ms'], $p['fail_ms']) && $p['fail_ms'] < $p['warn_ms']) {
            return 'ntp.fail_ms must not be below warn_ms.';
        }
        if ($type === 'http' && isset($p['expect_min'], $p['expect_max']) && $p['expect_max'] < $p['expect_min']) {
            return 'http.expect_max must not be below expect_min.';
        }
        if ($type === 'http' && isset($p['cert_warn_days'], $p['cert_fail_days']) && $p['cert_fail_days'] > $p['cert_warn_days']) {
            return 'http.cert_fail_days must not be above cert_warn_days.';
        }
        if ($type === 'cert' && isset($p['paths'])) {
            foreach ($p['paths'] as $path) {
                if (str_contains($path, '..') || str_starts_with($path, '//')) {
                    return 'cert.paths must be absolute paths without "..".';
                }
            }
        }
        if ($type === 'cert') {
            $targets = (isset($p['paths']) ? 1 : 0) + (isset($p['store']) ? 1 : 0) + (isset($p['host']) ? 1 : 0);
            if ($targets !== 1) {
                return 'cert needs exactly one of paths, store or host.';
            }
        }
        if ($type === 'ping' && isset($p['warn_ms'], $p['fail_ms']) && $p['fail_ms'] > 0 && $p['fail_ms'] < $p['warn_ms']) {
            return 'ping.fail_ms must not be below warn_ms.';
        }

        return null;
    }
}
