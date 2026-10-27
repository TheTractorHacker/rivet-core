<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Patch;

use RivetCore\Rmm\Policy\PolicyLayer;
use RivetCore\Rmm\Policy\PolicyResolver;

/**
 * What the patch policies that reach one device decide, as one flat answer. Pure: no database, no clock. It reuses Phase 2's {@see PolicyResolver}
 * (scope precedence global < client < site < group < tag < device, priority and assignment id inside one level, `enforce` wins over everything more
 * specific), so a patch policy behaves exactly like a policy you already know. The layers come from {@see PatchPolicyStore::layersFor()}.
 *
 * SAFE DEFAULTS (a device no policy reaches): approval manual, nothing auto-approved, nothing installed by the scheduler, no window restriction (only
 * relevant once installing is switched on), restarts never, an install may run 60 minutes, no second person.
 *
 * @api
 */
final class PatchPolicyResolver
{
    public const DEFAULTS = [
        'ring' => null,
        'approval_mode' => 'manual',
        'install_enabled' => false,
        'window_mode' => 'none',
        'window_maintenance_id' => null,
        'window_days' => '',
        'window_start' => '',
        'window_minutes' => 0,
        'window_tz' => 'UTC',
        'reboot_policy' => 'never',
        'reboot_confirm' => false,
        'max_minutes' => PatchProtocol::MAX_MINUTES_DEFAULT,
        'two_person' => false,
    ];

    /**
     * @param list<PolicyLayer> $layers
     * @param array<string,int> $ringDelays ring name => delay days
     * @return array{
     *     ring:?string, ring_delay_days:int, approval_mode:string, auto_days:array<string,?int>, install_enabled:bool,
     *     window:array{mode:string,maintenance_id:?int,days:list<string>,start:string,minutes:int,tz:string},
     *     reboot_policy:string, reboot_confirm:bool, max_minutes:int, two_person:bool, sources:array<string,array{policy_id:int,scope_type:string,enforced:bool}>
     * }
     */
    public static function resolve(array $layers, array $ringDelays): array
    {
        $decisions = PolicyResolver::resolve($layers);
        $sources = [];
        $v = self::DEFAULTS;
        foreach (PatchPolicySettings::keys() as $key) {
            if (!isset($decisions[$key]) || $decisions[$key]['mode'] !== 'override') {
                continue;   // absent, or a `disable` (which patch settings never use)
            }
            $val = $decisions[$key]['value'];
            if (array_key_exists($key, $v) || str_starts_with($key, 'auto_days_')) {
                $v[$key] = $val;
                $sources[$key] = ['policy_id' => $decisions[$key]['source']['policy_id'], 'scope_type' => $decisions[$key]['source']['scope_type'], 'enforced' => $decisions[$key]['source']['enforced']];
            }
        }
        $autoDays = [];
        foreach (PatchPolicySettings::AUTO_KINDS as $kind) {
            $d = $v['auto_days_' . $kind] ?? null;
            $autoDays[$kind] = is_int($d) ? $d : null;
        }
        $ring = is_string($v['ring']) && isset($ringDelays[$v['ring']]) ? $v['ring'] : null;
        $days = $v['window_days'] === '' || !is_string($v['window_days']) ? [] : explode(',', $v['window_days']);
        $rebootPolicy = (string) $v['reboot_policy'];
        $confirm = (bool) $v['reboot_confirm'];
        if ($rebootPolicy === 'if_required_now' && !$confirm) {
            $rebootPolicy = 'if_required_defer';   // never restart without the explicit confirmation, even if a less specific policy asked for it
        }

        return [
            'ring' => $ring,
            'ring_delay_days' => $ring === null ? 0 : max(0, $ringDelays[$ring]),
            'approval_mode' => $v['approval_mode'] === 'auto' ? 'auto' : 'manual',
            'auto_days' => $autoDays,
            'install_enabled' => (bool) $v['install_enabled'],
            'window' => ['mode' => (string) $v['window_mode'], 'maintenance_id' => is_int($v['window_maintenance_id']) ? $v['window_maintenance_id'] : null, 'days' => $days,
                'start' => (string) $v['window_start'], 'minutes' => (int) $v['window_minutes'], 'tz' => (string) $v['window_tz']],
            'reboot_policy' => $rebootPolicy,
            'reboot_confirm' => $confirm,
            'max_minutes' => max(1, min(PatchProtocol::MAX_MINUTES_MAX, (int) $v['max_minutes'])),
            'two_person' => (bool) $v['two_person'],
            'sources' => $sources,
        ];
    }

    /**
     * Whether `$at` (Unix time) lies inside a daily window.
     *
     * @param array{mode:string,maintenance_id:?int,days:list<string>,start:string,minutes:int,tz:string} $w
     */
    public static function inDailyWindow(array $w, int $at): bool
    {
        return self::dailyWindowStart($w, $at) !== null;
    }

    /**
     * The Unix time the daily window that is open at `$at` began, or null when none is open.
     *
     * @param array{mode:string,maintenance_id:?int,days:list<string>,start:string,minutes:int,tz:string} $w
     */
    public static function dailyWindowStart(array $w, int $at): ?int
    {
        if ($w['mode'] !== 'daily' || $w['days'] === [] || preg_match('/^(\d\d):(\d\d)$/D', $w['start'], $m) !== 1 || $w['minutes'] < 1) {
            return null;
        }
        try {
            $tz = new \DateTimeZone($w['tz']);
        } catch (\Exception) {
            return null;
        }
        // A window may begin yesterday and still be open after midnight: look at today's and yesterday's start.
        foreach ([0, -1] as $dayShift) {
            $start = (new \DateTimeImmutable('@' . $at))->setTimezone($tz)->modify($dayShift === 0 ? 'today' : 'yesterday')->setTime((int) $m[1], (int) $m[2]);
            if (!in_array(self::DAY_NAMES[(int) $start->format('N') - 1], $w['days'], true)) {
                continue;
            }
            $s = $start->getTimestamp();
            if ($at >= $s && $at < $s + $w['minutes'] * 60) {
                return $s;
            }
        }

        return null;
    }

    private const DAY_NAMES = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /**
     * The days an update of this kind must wait before it is approved by itself (the policy's days for the kind plus the ring's delay), or null when
     * the policy never approves that kind by itself.
     *
     * @param array{approval_mode:string,auto_days:array<string,?int>,ring_delay_days:int} $effective
     */
    public static function autoApproveAfterDays(array $effective, string $kind): ?int
    {
        if ($effective['approval_mode'] !== 'auto') {
            return null;
        }
        $d = $effective['auto_days'][$kind] ?? null;

        return $d === null ? null : $d + $effective['ring_delay_days'];
    }
}
