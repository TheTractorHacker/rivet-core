<?php

declare(strict_types=1);

namespace RivetCore\Compliance;

/**
 * Minimum-retention presets for the audit trail and activity logs.
 *
 * A preset is a floor, not a promise of compliance: it only guarantees that log rows are never deleted younger than the
 * stated number of days, whatever the stored settings say. Keeping rows forever (0) is always allowed because it is more
 * retention, not less. The figures are the commonly cited minimums for each framework and are meant as starting points for
 * an organization's own policy:
 *
 *  - ISO/IEC 27001 (Annex A logging control): the standard sets no number; 12 months is the usual audit-evidence horizon.
 *  - SOC 2: auditors typically sample a 6 to 12 month window; 12 months covers it.
 *  - PCI DSS 10.5.1: retain audit log history for at least 12 months.
 *  - HIPAA (45 CFR 164.316(b)(2)): required documentation is kept 6 years, which organizations apply to audit records.
 *  - NIST SP 800-171 / CMMC (3.3.1): requires audit logs to be retained but sets no number; 12 months is the common
 *    organization-defined value (and FedRAMP's baseline), and DFARS 7012 separately requires preserving incident data for 90 days.
 *
 * @api
 */
final class RetentionPolicy
{
    public const NONE = 'none';

    /** @var array<string, array{label:string, min_days:int, note:string}> */
    public const PROFILES = [
        self::NONE => ['label' => 'No preset', 'min_days' => 0, 'note' => 'No minimum. You choose how long records are kept.'],
        'iso27001' => ['label' => 'ISO/IEC 27001', 'min_days' => 365, 'note' => 'Keeps records at least 12 months, the usual audit-evidence horizon.'],
        'soc2' => ['label' => 'SOC 2', 'min_days' => 365, 'note' => 'Keeps records at least 12 months, which covers a typical audit window.'],
        'pci' => ['label' => 'PCI DSS', 'min_days' => 365, 'note' => 'Keeps records at least 12 months (PCI DSS requirement 10.5.1).'],
        'hipaa' => ['label' => 'HIPAA', 'min_days' => 2190, 'note' => 'Keeps records at least 6 years, the HIPAA documentation period.'],
        'nist171' => ['label' => 'NIST 800-171 / CMMC', 'min_days' => 365, 'note' => 'Keeps records at least 12 months. NIST 800-171 (3.3.1) requires retaining audit logs but sets no number; 12 months is the common choice to define in your policy.'],
    ];

    /** The log families that have their own horizon. Audit uses the preset's full floor; the other two may be shorter. */
    public const KIND_AUDIT = 'audit';
    public const KIND_DELIVERIES = 'deliveries';
    public const KIND_JOBS = 'jobs';

    /** Shortest horizon allowed for webhook deliveries and finished jobs when pruning is on (0 = keep forever is always allowed). */
    public const OPERATIONAL_MIN_DAYS = 7;

    /** Floor for webhook deliveries / finished jobs under a regulated preset: delivery logs are operational evidence, so 30 days. */
    public const OPERATIONAL_REGULATED_DAYS = 30;

    public static function isValidProfile(string $profile): bool
    {
        return isset(self::PROFILES[$profile]);
    }

    /** Minimum days for a preset; an unknown preset means no minimum. */
    public static function floorDays(string $profile): int
    {
        return self::PROFILES[$profile]['min_days'] ?? 0;
    }

    /**
     * The retention that is actually enforced: 0 (keep everything) stays 0, a negative number counts as 0, anything else
     * is raised to the preset's floor.
     */
    public static function effectiveDays(string $profile, int $configuredDays): int
    {
        if ($configuredDays <= 0) {
            return 0;
        }

        return max($configuredDays, self::floorDays($profile));
    }

    /** True when a saved value would be raised by the preset (so a form can tell the user why). */
    public static function isBelowFloor(string $profile, int $configuredDays): bool
    {
        return $configuredDays > 0 && $configuredDays < self::floorDays($profile);
    }

    /** Human wording for a retention value: "forever" or "365 days". */
    public static function describeDays(int $days): string
    {
        return $days <= 0 ? 'kept forever' : $days . ($days === 1 ? ' day' : ' days');
    }

    /**
     * Minimum days for one kind of log under a preset. Audit events use the preset floor itself. Webhook deliveries and
     * finished jobs use a shorter floor: 7 days with no preset, 30 days under any framework preset (never above the
     * preset's own floor).
     */
    public static function floorDaysFor(string $profile, string $kind): int
    {
        if ($kind === self::KIND_AUDIT) {
            return self::floorDays($profile);
        }
        if (self::floorDays($profile) === 0) {
            return self::OPERATIONAL_MIN_DAYS;
        }

        return min(self::OPERATIONAL_REGULATED_DAYS, self::floorDays($profile));
    }

    /** Like effectiveDays() but for a specific kind: 0 or below stays 0 (keep forever), anything else is raised to that kind's floor. */
    public static function effectiveDaysFor(string $profile, string $kind, int $configuredDays): int
    {
        if ($configuredDays <= 0) {
            return 0;
        }

        return max($configuredDays, self::floorDaysFor($profile, $kind));
    }
}
