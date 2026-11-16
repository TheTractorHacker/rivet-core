<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Patch;

/**
 * Windows Update control, the pure part: what a device's effective patch policy asks the Windows Update POLICY to look like, how that compares to what the device
 * reported, and the validation of the report itself (the `windows_update` object of a scan and the before/after of a `patch_configure` result). No database, no clock.
 *
 * THE VALUES (names are the agent's JSON names; the registry names they map to are in docs/rmm/PATCHING.md and the agent's wupolicy.go):
 *   no_auto_update / au_options          the "Configure Automatic Updates" policy: 0 and 2 = Windows only NOTIFIES before download and install (managed mode)
 *   target_release_version / _info /
 *   product_version                      the "Select the target Feature Update version" policy: 1, "25H2", "Windows 11"
 *   defer_quality_updates / _days        the "Select when Quality Updates are received" policy: 1 and 0..30
 *
 * A report is never trusted: unknown keys are dropped, every value is cut and typed.
 *
 * @api
 */
final class WindowsUpdatePlan
{
    /** The policy values Core may ever own, in the agent's JSON names (and their types: i = whole number, s = string). */
    public const KEYS = ['no_auto_update' => 'i', 'au_options' => 'i', 'target_release_version' => 'i', 'target_release_version_info' => 's', 'product_version' => 's', 'defer_quality_updates' => 'i', 'defer_quality_days' => 'i'];

    /**
     * The values a policy wants Core to own. For `hold` the pinned version is the one the agent already pinned ($state['core']['pinned']); until the agent pinned one the
     * entry `target_release_version_info` is null (any pin is then a change to make). `product_version` is decided by the agent from the build, so it is never compared.
     *
     * @param array{update_mode:string,feature_target:string,quality_defer_days:int,active:bool} $w
     * @param array<string,mixed>|null $state the device's reported state (see {@see cleanState()})
     * @return array<string,int|string|null>
     */
    public static function desired(array $w, ?array $state): array
    {
        $d = [];
        if ($w['update_mode'] === 'managed') {
            $d['no_auto_update'] = 0;
            $d['au_options'] = 2;
        }
        $t = $w['feature_target'];
        if ($t === PatchProtocol::FEATURE_TARGET_HOLD) {
            $pin = ($state['core']['held'] ?? false) === true ? ($state['core']['pinned'] ?? null) : null;   // a pin that came from a named version is not a hold
            $d['target_release_version'] = 1;
            $d['target_release_version_info'] = is_string($pin) && PatchProtocol::validFeatureVersion($pin) ? $pin : null;
        } elseif (PatchProtocol::validFeatureVersion($t)) {
            $d['target_release_version'] = 1;
            $d['target_release_version_info'] = $t;
        }
        if ($w['quality_defer_days'] > 0) {
            $d['defer_quality_updates'] = 1;
            $d['defer_quality_days'] = $w['quality_defer_days'];
        }

        return $d;
    }

    /**
     * Where a device stands against its policy.
     *
     * status: `not_reported` (nothing reported yet), `off` (policy asks nothing and Core owns nothing), `in_sync`, `pending` (a change is due), `restore_pending` (the policy asks nothing
     * but Core still owns values), `externally_managed` (something else owns the setting: nothing is queued), `unsupported_agent`.
     *
     * @param array{update_mode:string,feature_target:string,quality_defer_days:int,active:bool} $w
     * @param array<string,mixed>|null $state
     * @return array{status:string,needs:bool,desired:array<string,int|string|null>,changes:list<string>}
     */
    public static function evaluate(array $w, ?array $state, bool $capable): array
    {
        $desired = self::desired($w, $state);
        if (!$capable) {
            return ['status' => 'unsupported_agent', 'needs' => false, 'desired' => $desired, 'changes' => []];
        }
        if ($state === null) {
            return ['status' => 'not_reported', 'needs' => false, 'desired' => $desired, 'changes' => []];
        }
        $coreKeys = $state['core']['keys'];
        if ($state['external'] !== []) {
            return ['status' => 'externally_managed', 'needs' => false, 'desired' => $desired, 'changes' => []];
        }
        $changes = [];
        foreach ($desired as $k => $v) {
            $have = $state['policy'][$k] ?? null;
            if ($k === 'target_release_version_info' && $v === null) {
                $changes[] = $k;   // hold: the agent has not held a version yet (or what it pinned came from a named version): it pins the version the device runs
                continue;
            }
            if ($have !== $v) {
                $changes[] = $k;
            }
        }
        foreach ($coreKeys as $k) {
            if (!array_key_exists($k, $desired) && $k !== 'product_version') {
                $changes[] = $k;   // Core owns it, the policy no longer asks for it: restore
            }
        }
        if ($desired === [] && $coreKeys === []) {
            return ['status' => 'off', 'needs' => false, 'desired' => $desired, 'changes' => []];
        }
        if ($changes === []) {
            return ['status' => 'in_sync', 'needs' => false, 'desired' => $desired, 'changes' => []];
        }

        return ['status' => $desired === [] ? 'restore_pending' : 'pending', 'needs' => true, 'desired' => $desired, 'changes' => array_values(array_unique($changes))];
    }

    /**
     * The feature version a device is held at, as far as the report tells: an explicit policy version, or what Core pinned for `hold`. Null: no target.
     *
     * @param array{update_mode:string,feature_target:string,quality_defer_days:int,active:bool} $w
     * @param array<string,mixed>|null $state
     */
    public static function targetVersion(array $w, ?array $state): ?string
    {
        $t = $w['feature_target'];
        if (PatchProtocol::validFeatureVersion($t)) {
            return $t;
        }
        if ($t === PatchProtocol::FEATURE_TARGET_HOLD) {
            $pin = ($state['core']['held'] ?? false) === true ? ($state['core']['pinned'] ?? null) : null;

            return is_string($pin) && PatchProtocol::validFeatureVersion($pin) ? $pin : null;
        }

        return null;
    }

    /**
     * Validate the `windows_update` object an agent reported (in a scan, or as the `after` of a configure result). Null when it is absent or not an object.
     *
     * @return array{feature_version:string,build:string,product:string,external:list<string>,policy:array<string,int|string>,core:array{keys:list<string>,pinned:?string,held:bool}}|null
     */
    public static function cleanState(mixed $raw): ?array
    {
        if (!is_array($raw) || array_is_list($raw)) {
            return null;
        }
        $fv = $raw['feature_version'] ?? '';
        $build = $raw['build'] ?? '';
        $product = $raw['product'] ?? '';
        $external = [];
        foreach (is_array($raw['external'] ?? null) && array_is_list($raw['external']) ? $raw['external'] : [] as $e) {
            if (is_string($e) && in_array($e, PatchProtocol::EXTERNAL_SOURCES, true)) {
                $external[$e] = $e;
            }
        }
        $policy = [];
        foreach (is_array($raw['policy'] ?? null) ? $raw['policy'] : [] as $k => $v) {
            $type = is_string($k) ? (self::KEYS[$k] ?? null) : null;
            if ($type === 'i' && is_int($v) && $v >= 0 && $v <= 4294967295) {
                $policy[$k] = $v;
            } elseif ($type === 's' && is_string($v) && strlen($v) <= 40 && preg_match('/^[A-Za-z0-9 ._-]*$/D', $v) === 1) {
                $policy[$k] = $v;
            }
        }
        ksort($policy, SORT_STRING);
        $core = is_array($raw['core'] ?? null) ? $raw['core'] : [];
        $keys = [];
        foreach (is_array($core['keys'] ?? null) && array_is_list($core['keys']) ? $core['keys'] : [] as $k) {
            if (is_string($k) && isset(self::KEYS[$k])) {
                $keys[$k] = $k;
            }
        }
        ksort($keys, SORT_STRING);
        $pin = $core['pinned'] ?? null;

        return [
            'feature_version' => PatchProtocol::validFeatureVersion($fv) ? $fv : '',
            'build' => is_string($build) && preg_match('/^[0-9]{4,6}$/D', $build) === 1 ? $build : '',
            'product' => is_string($product) && in_array($product, ['Windows 10', 'Windows 11', 'Windows Server'], true) ? $product : '',
            'external' => array_values($external),
            'policy' => $policy,
            'core' => ['keys' => array_values($keys), 'pinned' => PatchProtocol::validFeatureVersion($pin) ? $pin : null, 'held' => ($core['held'] ?? false) === true],
        ];
    }

    /**
     * Validate the result document of a `patch_configure` job.
     *
     * @return array{outcome:string,mode:string,changes:list<array{key:string,from:int|string|null,to:int|string|null}>,before:?array<string,mixed>,after:?array<string,mixed>,detail:string}|null null when unreadable
     */
    public static function cleanResult(string $output): ?array
    {
        $j = json_decode($output, true);
        if (!is_array($j) || array_is_list($j) || ($j['ok'] ?? false) !== true) {
            return null;
        }
        $outcome = $j['outcome'] ?? null;
        $mode = $j['mode'] ?? null;
        if (!is_string($outcome) || !in_array($outcome, PatchProtocol::CONFIGURE_OUTCOMES, true) || !is_string($mode) || !in_array($mode, PatchProtocol::CONFIGURE_MODES, true)) {
            return null;
        }
        $changes = [];
        foreach (array_slice(is_array($j['changes'] ?? null) && array_is_list($j['changes']) ? $j['changes'] : [], 0, 20) as $c) {
            if (!is_array($c) || !is_string($c['key'] ?? null) || !isset(self::KEYS[$c['key']])) {
                continue;
            }
            $from = $c['from'] ?? null;
            $to = $c['to'] ?? null;
            $changes[] = ['key' => $c['key'], 'from' => is_int($from) || (is_string($from) && strlen($from) <= 40) ? $from : null, 'to' => is_int($to) || (is_string($to) && strlen($to) <= 40) ? $to : null];
        }
        $detail = is_string($j['detail'] ?? null) ? mb_substr(preg_replace('/[\x00-\x1f]/', ' ', $j['detail']) ?? '', 0, 200) : '';

        return ['outcome' => $outcome, 'mode' => $mode, 'changes' => $changes, 'before' => self::cleanState($j['before'] ?? null), 'after' => self::cleanState($j['after'] ?? null), 'detail' => $detail];
    }
}
