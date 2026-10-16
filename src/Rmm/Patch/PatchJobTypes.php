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
}
