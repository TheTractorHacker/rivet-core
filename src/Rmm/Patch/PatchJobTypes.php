<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Patch;

use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Rmm\Job\JobType;
use RivetCore\Rmm\Job\JobTypeRegistry;

/**
 * The Phase 4 job types and their STRICT parameter allow-lists. A validator keeps only the keys it knows, in a fixed order and with fixed types; an unknown
 * key, a wrong type or a value outside its set refuses the whole job. The ids of an install are validated again against the device's operating system by
 * {@see PatchInstallService} (this class has no device) and by the agent, which also re-checks that each id is still applicable.
 *
 * `patch_scan` is read only. `patch_install` defaults to `dry_run`; a real installation needs `mode` = `install` spelled out. Both are queued only by their
 * own endpoints and the scheduler ({@see \RivetCore\Rmm\Technician\TechnicianActions::submitJob()} refuses them).
 *
 * @api
 */
final class PatchJobTypes
{
    public static function register(JobTypeRegistry $r): void
    {
        $both = ['windows', 'linux'];
        $r->register(new JobType(PatchProtocol::JOB_SCAN, RmmAbility::PATCH_MANAGE, false, $both, false, self::scan(...), 900, true, PatchProtocol::CAP_SCAN));
        // destructive: offered at most once and never re-sent when the acknowledgement is lost (an install must not run twice by accident).
        $r->register(new JobType(PatchProtocol::JOB_INSTALL, RmmAbility::PATCH_INSTALL, true, $both, false, self::install(...), 3600, true, PatchProtocol::CAP_INSTALL));
        // Windows Update control: writes (or restores) policy registry values. Destructive like an install (offered once, never re-sent after a lost acknowledgement), Windows only,
        // needs the device to have ANNOUNCED patch:configure (an older agent never does, so it is never offered the job).
        $r->register(new JobType(PatchProtocol::JOB_CONFIGURE, RmmAbility::PATCH_INSTALL, true, ['windows'], false, self::configure(...), 300, true, PatchProtocol::CAP_CONFIGURE));
    }

    /**
     * @param array<array-key,mixed> $p
     * @return array{0:array<string,mixed>,1:?string}
     */
    public static function scan(array $p): array
    {
        if (array_diff(array_keys($p), ['categories', 'max_items']) !== []) {
            return [$p, 'Only categories and max_items are accepted.'];
        }
        $cats = $p['categories'] ?? '';
        if (!is_string($cats) || strlen($cats) > 120) {
            return [$p, 'categories must be a comma list of: ' . implode(', ', PatchProtocol::CATEGORIES) . '.'];
        }
        $list = [];
        if ($cats !== '') {
            foreach (explode(',', $cats) as $c) {
                if (!in_array($c, PatchProtocol::CATEGORIES, true)) {
                    return [$p, 'categories must be a comma list of: ' . implode(', ', PatchProtocol::CATEGORIES) . '.'];
                }
                $list[$c] = true;
            }
        }
        $max = $p['max_items'] ?? 500;
        if (!is_int($max) || $max < 1 || $max > 1000) {
            return [$p, 'max_items must be 1 to 1000.'];
        }

        return [['categories' => implode(',', array_keys($list)), 'max_items' => $max], null];
    }

    /**
     * @param array<array-key,mixed> $p
     * @return array{0:array<string,mixed>,1:?string}
     */
    public static function install(array $p): array
    {
        if (array_diff(array_keys($p), ['ids', 'mode', 'max_minutes']) !== []) {
            return [$p, 'Only ids, mode and max_minutes are accepted.'];
        }
        $ids = $p['ids'] ?? null;
        if (!is_string($ids) || $ids === '' || strlen($ids) > PatchProtocol::IDS_MAX_CHARS) {
            return [$p, 'ids must be a comma list of at most ' . PatchProtocol::IDS_MAX_CHARS . ' characters.'];
        }
        $parts = explode(',', $ids);
        if (count($parts) > PatchProtocol::IDS_MAX || count(array_unique($parts)) !== count($parts)) {
            return [$p, 'ids holds at most ' . PatchProtocol::IDS_MAX . ' different ids.'];
        }
        foreach ($parts as $id) {
            if (preg_match(PatchProtocol::WINDOWS_ID_RE, $id) !== 1 && preg_match(PatchProtocol::PACKAGE_ID_RE, $id) !== 1) {
                return [$p, 'An update id is not valid.'];
            }
        }
        $mode = $p['mode'] ?? 'dry_run';
        if (!is_string($mode) || !in_array($mode, PatchProtocol::MODES, true)) {
            return [$p, 'mode must be dry_run or install.'];
        }
        $mins = $p['max_minutes'] ?? PatchProtocol::MAX_MINUTES_DEFAULT;
        if (!is_int($mins) || $mins < 1 || $mins > PatchProtocol::MAX_MINUTES_MAX) {
            return [$p, 'max_minutes must be 1 to ' . PatchProtocol::MAX_MINUTES_MAX . '.'];
        }

        return [['ids' => implode(',', $parts), 'mode' => $mode, 'max_minutes' => $mins], null];
    }

    /**
     * `patch_configure`: the DESIRED Windows Update policy state, never a registry path or value name (the agent owns the fixed list of keys). An empty desired state
     * (`update_mode` leave, `feature_target` none, `defer_days` 0) asks the agent to restore whatever it changed earlier.
     *
     * @param array<array-key,mixed> $p
     * @return array{0:array<string,mixed>,1:?string}
     */
    public static function configure(array $p): array
    {
        if (array_diff(array_keys($p), ['mode', 'update_mode', 'feature_target', 'defer_days']) !== []) {
            return [$p, 'Only mode, update_mode, feature_target and defer_days are accepted.'];
        }
        $mode = array_key_exists('mode', $p) ? $p['mode'] : 'dry_run';
        if (!is_string($mode) || !in_array($mode, PatchProtocol::CONFIGURE_MODES, true)) {
            return [$p, 'mode must be dry_run or apply.'];
        }
        $um = array_key_exists('update_mode', $p) ? $p['update_mode'] : 'leave';
        if (!is_string($um) || !in_array($um, PatchProtocol::WU_MODES, true)) {
            return [$p, 'update_mode must be leave or managed.'];
        }
        $ft = array_key_exists('feature_target', $p) ? $p['feature_target'] : PatchProtocol::FEATURE_TARGET_NONE;
        if (!is_string($ft) || !($ft === PatchProtocol::FEATURE_TARGET_NONE || $ft === PatchProtocol::FEATURE_TARGET_HOLD || PatchProtocol::validFeatureVersion($ft))) {
            return [$p, 'feature_target must be none, hold or a feature version such as 25H2.'];
        }
        $dd = array_key_exists('defer_days', $p) ? $p['defer_days'] : 0;
        if (!is_int($dd) || $dd < 0 || $dd > PatchProtocol::DEFER_DAYS_MAX) {
            return [$p, 'defer_days must be 0 to ' . PatchProtocol::DEFER_DAYS_MAX . '.'];
        }

        return [['mode' => $mode, 'update_mode' => $um, 'feature_target' => $ft, 'defer_days' => $dd], null];
    }
}
