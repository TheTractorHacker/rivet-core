<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Policy;

use RivetCore\Rmm\RmmProtocol;
use RivetCore\Rmm\Settings\ChecksValidator;
use RivetCore\Rmm\Settings\RmmSettings;

/**
 * The vocabulary of a policy and its validation. A policy body is `{"settings": {<key>: {"mode": "override"|"disable"|"inherit", "value": ...}}}`
 * (a bare value is shorthand for an override). Keys:
 *
 *   interval.check_in_s        seconds between check-ins, clamped to the global 60..3600
 *   interval.collect_s         seconds between samples, clamped to the global 30..3600
 *   feature.software_inventory the device reports its software list (never turns on what the instance has off)
 *   feature.script_checks      script checks are delivered to the device
 *   feature.checks             any check is delivered (the device keeps reporting metrics)
 *   agent.ring                 update ring this device is offered, "stable" or "pilot"
 *   check.<key>                a check template: {type, params, interval_s}; disable removes the check of that key, also one of the global list
 *
 * Modes: override sets the value, disable switches the key off (for a feature: off; for a check: removed; for an interval or the ring: the
 * key is dropped so the less specific value, or the global default, applies), inherit says nothing and exists so an assignment can cancel a key its
 * policy sets.
 *
 * @api
 */
final class PolicySettings
{
    public const MODES = ['override', 'disable', 'inherit'];
    public const FEATURES = ['software_inventory', 'script_checks', 'checks'];
    public const RINGS = ['stable', 'pilot'];
    public const MAX_SETTINGS = 120;
    public const MAX_BODY_BYTES = 262144;

    /** What a key is, or null when it is not a policy key. @return 'interval'|'feature'|'ring'|'check'|null */
    public static function kind(string $key): ?string
    {
        if ($key === 'interval.check_in_s' || $key === 'interval.collect_s') {
            return 'interval';
        }
        if ($key === 'agent.ring') {
            return 'ring';
        }
        if (str_starts_with($key, 'feature.') && in_array(substr($key, 8), self::FEATURES, true)) {
            return 'feature';
        }
        if (str_starts_with($key, 'check.') && preg_match(RmmProtocol::CHECK_KEY_RE, substr($key, 6)) === 1) {
            return 'check';
        }

        return null;
    }

    /**
     * Normalise the `settings` member of a policy body or of an assignment's overrides.
     *
     * @return array{0:?array<string,array{mode:string,value:mixed}>,1:?string} [normalised map, error]
     */
    public static function normalize(mixed $settings): array
    {
        if ($settings === null) {
            return [[], null];
        }
        if (!is_array($settings) || ($settings !== [] && array_is_list($settings))) {
            return [null, 'Settings must be an object of setting key to {mode, value}.'];
        }
        if (count($settings) > self::MAX_SETTINGS) {
            return [null, 'A policy holds at most ' . self::MAX_SETTINGS . ' settings.'];
        }
        $out = [];
        foreach ($settings as $key => $entry) {
            $key = (string) $key;
            $kind = self::kind($key);
            if ($kind === null) {
                return [null, "Unknown setting $key."];
            }
            $explicit = is_array($entry) && !array_is_list($entry) && array_key_exists('mode', $entry);
            $mode = $explicit ? $entry['mode'] : 'override';
            if (!is_string($mode) || !in_array($mode, self::MODES, true)) {
                return [null, "Setting $key: mode must be override, disable or inherit."];
            }
            if ($mode === 'inherit') {
                $out[$key] = ['mode' => 'inherit', 'value' => null];
                continue;
            }
            if ($mode === 'disable') {
                if (($entry['value'] ?? null) !== null) {
                    return [null, "Setting $key: a disabled setting carries no value."];
                }
                $out[$key] = ['mode' => 'disable', 'value' => null];
                continue;
            }
            $value = $explicit ? ($entry['value'] ?? null) : $entry;
            [$clean, $err] = self::value($key, $kind, $value);
            if ($err !== null) {
                return [null, "Setting $key: $err"];
            }
            $out[$key] = ['mode' => 'override', 'value' => $clean];
        }
        ksort($out, SORT_STRING);

        return [$out, null];
    }

    /** @return array{0:mixed,1:?string} */
    private static function value(string $key, string $kind, mixed $value): array
    {
        switch ($kind) {
            case 'interval':
                [$min, $max] = $key === 'interval.check_in_s'
                    ? [RmmSettings::CHECK_IN_INTERVAL_MIN_S, RmmSettings::CHECK_IN_INTERVAL_MAX_S]
                    : [RmmSettings::COLLECT_INTERVAL_MIN_S, RmmSettings::COLLECT_INTERVAL_MAX_S];
                if (!is_int($value) || $value < $min || $value > $max) {
                    return [null, "must be a whole number of seconds from $min to $max."];
                }

                return [$value, null];
            case 'feature':
                return is_bool($value) ? [$value, null] : [null, 'must be true or false.'];
            case 'ring':
                return is_string($value) && in_array($value, self::RINGS, true) ? [$value, null] : [null, 'must be "stable" or "pilot".'];
            default:
                if (!is_array($value) || array_is_list($value)) {
                    return [null, 'a check template is an object {type, params, interval_s}.'];
                }
                $one = ['key' => substr($key, 6), 'type' => $value['type'] ?? '', 'params' => $value['params'] ?? [], 'interval_s' => $value['interval_s'] ?? 0];
                [$list, $err] = ChecksValidator::validate((string) json_encode([$one]));
                if ($err !== null || $list === null || !isset($list[0])) {
                    return [null, $err ?? 'invalid check template.'];
                }
                $c = $list[0];
                $params = $c['params'];

                return [['type' => $c['type'], 'params' => $params instanceof \stdClass ? [] : $params, 'interval_s' => $c['interval_s']], null];
        }
    }

    /**
     * Normalise a whole policy body.
     *
     * @return array{0:?array{settings:array<string,array{mode:string,value:mixed}>},1:?string}
     */
    public static function normalizeBody(mixed $body): array
    {
        if (is_string($body)) {
            if (strlen($body) > self::MAX_BODY_BYTES) {
                return [null, 'The policy is too large.'];
            }
            $body = json_decode($body, true);
        }
        if (!is_array($body) || array_is_list($body) && $body !== []) {
            return [null, 'A policy body is an object with a "settings" member.'];
        }
        $unknown = array_diff(array_keys($body), ['settings']);
        if ($unknown !== []) {
            return [null, 'Unknown policy member ' . (string) reset($unknown) . '.'];
        }
        [$settings, $err] = self::normalize($body['settings'] ?? null);
        if ($err !== null || $settings === null) {
            return [null, $err ?? 'Invalid settings.'];
        }

        return [['settings' => $settings], null];
    }
}
