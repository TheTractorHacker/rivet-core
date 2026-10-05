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
    ];

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
}
