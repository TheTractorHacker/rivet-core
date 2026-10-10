<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Policy;

use RivetCore\Rmm\Crypto\CanonicalJson;
use RivetCore\Rmm\Settings\RmmSettings;

/**
 * Turns the resolver's decisions into what one device is told: its check list, its two intervals, its feature toggles and its update ring.
 * Pure. With no decisions at all the result is the instance's own configuration (the global check list and intervals, every feature on),
 * so a fleet without policies is delivered exactly what it was delivered before policies existed.
 *
 * @api
 */
final class EffectiveConfig
{
    /**
     * @param array<string,array{mode:string,value:mixed,source:array<string,mixed>}> $resolved {@see PolicyResolver::resolve()}
     * @param list<array<string,mixed>> $baseChecks the instance's global check list (key, type, params, interval_s)
     * @param list<string>|null $capabilities what the device announced (`check:<type>` entries), or null when it never announced anything
     * @return array{applied:bool,checks:list<array<string,mixed>>,check_in_interval_s:int,collect_interval_s:int,features:array<string,bool>,ring:?string,version:string,sources:array<string,array<string,mixed>>}
     */
    public static function compose(array $resolved, array $baseChecks, int $globalCheckIn, int $globalCollect, ?array $capabilities = null): array
    {
        $checkIn = $globalCheckIn;
        $collect = $globalCollect;
        $ring = null;
        $features = array_fill_keys(PolicySettings::FEATURES, true);
        $overrides = [];
        $removed = [];
        foreach ($resolved as $key => $d) {
            $kind = PolicySettings::kind($key);
            $on = $d['mode'] === 'override';
            switch ($kind) {
                case 'interval':
                    if ($on && is_int($d['value'])) {
                        if ($key === 'interval.check_in_s') {
                            $checkIn = max(RmmSettings::CHECK_IN_INTERVAL_MIN_S, min(RmmSettings::CHECK_IN_INTERVAL_MAX_S, $d['value']));
                        } else {
                            $collect = max(RmmSettings::COLLECT_INTERVAL_MIN_S, min(RmmSettings::COLLECT_INTERVAL_MAX_S, $d['value']));
                        }
                    }
                    break;
                case 'feature':
                    $features[substr($key, 8)] = $on && $d['value'] === true;
                    break;
                case 'ring':
                    $ring = $on && is_string($d['value']) ? $d['value'] : null;
                    break;
                case 'check':
                    $k = substr($key, 6);
                    if ($on && is_array($d['value'])) {
                        $overrides[$k] = $d['value'];
                    } else {
                        $removed[$k] = true;
                    }
                    break;
            }
        }

        $checks = [];
        $seen = [];
        foreach ($baseChecks as $c) {
            $k = (string) $c['key'];
            if (isset($removed[$k])) {
                continue;
            }
            if (isset($overrides[$k])) {
                $c = ['key' => $k] + $overrides[$k];
            }
            $checks[] = $c;
            $seen[$k] = true;
        }
        $added = array_diff_key($overrides, $seen);
        ksort($added, SORT_STRING);
        foreach ($added as $k => $v) {
            $checks[] = ['key' => (string) $k] + $v;
        }

        if (!$features['checks']) {
            $checks = [];
        } elseif (!$features['script_checks']) {
            $checks = array_values(array_filter($checks, static fn (array $c): bool => $c['type'] !== 'script'));
        }
        $checks = self::filterByCapabilities($checks, $capabilities);
        $checks = array_map(static function (array $c): array {
            $p = $c['params'] ?? [];
            $c['params'] = is_array($p) && $p === [] ? new \stdClass() : $p;
            $c['interval_s'] = (int) $c['interval_s'];

            return ['key' => (string) $c['key'], 'type' => (string) $c['type'], 'params' => $c['params'], 'interval_s' => $c['interval_s']];
        }, $checks);

        $sources = [];
        foreach ($resolved as $key => $d) {
            $sources[$key] = ['mode' => $d['mode']] + $d['source'];
        }
        $version = hash('sha256', CanonicalJson::encode(['checks' => $checks, 'check_in_interval_s' => $checkIn, 'collect_interval_s' => $collect, 'features' => $features, 'ring' => $ring]));

        return ['applied' => $resolved !== [], 'checks' => $checks, 'check_in_interval_s' => $checkIn, 'collect_interval_s' => $collect, 'features' => $features,
            'ring' => $ring, 'version' => $version, 'sources' => $sources];
    }

    /**
     * Keep the checks of a type the device said it can run. A device that announced no `check:` capability at all (an old agent) gets the list
     * unchanged: it ignores what it cannot run.
     *
     * @param list<array<string,mixed>> $checks
     * @param list<string>|null $capabilities
     * @return list<array<string,mixed>>
     */
    public static function filterByCapabilities(array $checks, ?array $capabilities): array
    {
        if ($capabilities === null) {
            return $checks;
        }
        $types = [];
        foreach ($capabilities as $c) {
            if (str_starts_with($c, 'check:')) {
                $types[substr($c, 6)] = true;
            }
        }
        if ($types === []) {
            return $checks;
        }

        return array_values(array_filter($checks, static fn (array $c): bool => isset($types[(string) $c['type']])));
    }
}
