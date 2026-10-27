<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Patch;

/**
 * The settings of a patch policy and their strict validation. A policy holds only the keys it wants to decide; a key it does not hold is decided by a
 * less specific policy, or falls back to the safe default of {@see PatchPolicyResolver::DEFAULTS} (nothing is installed by itself).
 *
 *   ring             name of a ring (pilot, broad, last, or one you added): its delay is added to every automatic approval
 *   approval_mode    manual (the default: a person approves) | auto (approve by the per-kind days below)
 *   auto_days_<kind> whole days after the update was first seen (plus the ring delay) before it is approved by itself, per kind: critical, security, important, optional, driver;
 *                    absent or null = that kind is never approved by itself
 *   install_enabled  true = the scheduler may install approved updates on its own (default false)
 *   window_mode      none (any time) | maintenance (only while the Phase 3 maintenance window window_maintenance_id is open) | daily (a repeating window below)
 *   window_days      comma list of mon,tue,wed,thu,fri,sat,sun (daily)
 *   window_start     HH:MM in window_tz (daily)
 *   window_minutes   length of the window, 5 to 1440 (daily)
 *   window_tz        a PHP time zone name, for example Europe/Berlin (default UTC)
 *   reboot_policy    never (the default) | if_required_defer (report only) | if_required_now (queues a separate reboot job; needs reboot_confirm)
 *   reboot_confirm   true: someone with the reboot grant accepted that devices of this policy restart by themselves after patching
 *   max_minutes      longest an install may run, 1 to 240 (default 60)
 *   two_person       true: approving an update by hand needs a second person
 *
 * @api
 */
final class PatchPolicySettings
{
    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
    public const AUTO_KINDS = ['critical', 'security', 'important', 'optional', 'driver'];

    /** @return list<string> */
    public static function keys(): array
    {
        $k = ['ring', 'approval_mode', 'install_enabled', 'window_mode', 'window_maintenance_id', 'window_days', 'window_start', 'window_minutes', 'window_tz', 'reboot_policy', 'reboot_confirm', 'max_minutes', 'two_person'];
        foreach (self::AUTO_KINDS as $kind) {
            $k[] = 'auto_days_' . $kind;
        }
        sort($k);

        return $k;
    }

    /**
     * @param mixed $in the `settings` object of a request: key => value (null removes the key)
     * @param list<string> $ringNames names of the rings that exist
     * @return array{0:?array<string,array{mode:string,value:mixed}>,1:?string} [normalised settings in PolicyLayer form, error]
     */
    public static function normalize(mixed $in, array $ringNames): array
    {
        if ($in === null || $in === []) {
            return [[], null];
        }
        if (!is_array($in) || array_is_list($in)) {
            return [null, 'settings must be an object of setting names and values.'];
        }
        $out = [];
        foreach ($in as $key => $v) {
            if (!is_string($key) || !in_array($key, self::keys(), true)) {
                return [null, 'Unknown patch setting ' . (is_string($key) ? '"' . mb_substr($key, 0, 40) . '"' : '') . '.'];
            }
            if ($v === null) {
                continue;
            }
            [$val, $err] = self::one($key, $v, $ringNames);
            if ($err !== null) {
                return [null, $err];
            }
            $out[$key] = ['mode' => 'override', 'value' => $val];
        }
        ksort($out, SORT_STRING);
        if (isset($out['window_mode']) && $out['window_mode']['value'] === 'maintenance' && !isset($out['window_maintenance_id'])) {
            return [null, 'window_mode "maintenance" needs window_maintenance_id.'];
        }
        if (isset($out['window_mode']) && $out['window_mode']['value'] === 'daily' && (!isset($out['window_days'], $out['window_start'], $out['window_minutes']))) {
            return [null, 'window_mode "daily" needs window_days, window_start and window_minutes.'];
        }
        if (isset($out['reboot_policy']) && $out['reboot_policy']['value'] === 'if_required_now' && empty($out['reboot_confirm']['value'])) {
            return [null, 'reboot_policy "if_required_now" restarts devices by itself and needs reboot_confirm = true.'];
        }

        return [$out, null];
    }

    /**
     * @param list<string> $ringNames
     * @return array{0:mixed,1:?string}
     */
    private static function one(string $key, mixed $v, array $ringNames): array
    {
        if (str_starts_with($key, 'auto_days_')) {
            return is_int($v) && $v >= 0 && $v <= 365 ? [$v, null] : [null, "$key must be a whole number of days from 0 to 365."];
        }

        switch ($key) {
            case 'ring':
                return is_string($v) && in_array($v, $ringNames, true) ? [$v, null] : [null, 'ring must be the name of an existing ring.'];
            case 'approval_mode':
                return is_string($v) && in_array($v, PatchProtocol::APPROVAL_MODES, true) ? [$v, null] : [null, 'approval_mode must be manual or auto.'];
            case 'install_enabled':
            case 'reboot_confirm':
            case 'two_person':
                return is_bool($v) ? [$v, null] : [null, "$key must be true or false."];
            case 'window_mode':
                return is_string($v) && in_array($v, PatchProtocol::WINDOW_MODES, true) ? [$v, null] : [null, 'window_mode must be none, maintenance or daily.'];
            case 'window_maintenance_id':
                return is_int($v) && $v > 0 ? [$v, null] : [null, 'window_maintenance_id must be the id of a maintenance window.'];
            case 'window_days':
                if (!is_string($v) || $v === '') {
                    return [null, 'window_days must be a comma list of ' . implode(', ', self::DAYS) . '.'];
                }
                $days = [];
                foreach (explode(',', strtolower($v)) as $d) {
                    if (!in_array($d, self::DAYS, true)) {
                        return [null, 'window_days must be a comma list of ' . implode(', ', self::DAYS) . '.'];
                    }
                    $days[$d] = true;
                }

                return [implode(',', array_keys($days)), null];
            case 'window_start':
                return is_string($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $v) === 1 ? [$v, null] : [null, 'window_start must be HH:MM (24 hours).'];
            case 'window_minutes':
                return is_int($v) && $v >= 5 && $v <= 1440 ? [$v, null] : [null, 'window_minutes must be 5 to 1440.'];
            case 'window_tz':
                return is_string($v) && strlen($v) <= 64 && in_array($v, \DateTimeZone::listIdentifiers(), true) ? [$v, null] : [null, 'window_tz must be a time zone name such as Europe/Berlin or UTC.'];
            case 'reboot_policy':
                return is_string($v) && in_array($v, PatchProtocol::REBOOT_POLICIES, true) ? [$v, null] : [null, 'reboot_policy must be never, if_required_defer or if_required_now.'];
            case 'max_minutes':
                return is_int($v) && $v >= 1 && $v <= PatchProtocol::MAX_MINUTES_MAX ? [$v, null] : [null, 'max_minutes must be 1 to ' . PatchProtocol::MAX_MINUTES_MAX . '.'];
        }

        return [null, 'Unknown patch setting.'];
    }
}
