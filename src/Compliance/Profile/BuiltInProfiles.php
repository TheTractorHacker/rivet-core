<?php

declare(strict_types=1);

namespace RivetCore\Compliance\Profile;

use RivetCore\Compliance\Framework;
use RivetCore\Compliance\RetentionPolicy;

/**
 * The five built-in profiles, built on every call from {@see Framework::LABELS} and {@see RetentionPolicy::PROFILES}, which stay the single source of truth:
 * a built-in can therefore never drift from the constants the editions already use. Each one has exactly one requirement, the minimum retention of the audit
 * events that the preset has always enforced; selecting a built-in profile asks for nothing the preset did not already ask for. Read-only: the store refuses
 * to change, archive or delete them; copy one to start a custom profile.
 *
 * @api
 */
final class BuiltInProfiles
{
    /** @return array<string, FrameworkProfile> keyed by framework key, in the order of {@see Framework::LABELS} */
    public static function all(): array
    {
        $out = [];
        foreach (Framework::LABELS as $key => $label) {
            $preset = RetentionPolicy::PROFILES[$key];
            $out[$key] = new FrameworkProfile(
                $key,
                $label,
                $preset['note'],
                1,
                true,
                [new Requirement(RequirementType::RETENTION_MIN, DataClass::AUDIT_EVENTS, $preset['min_days'])],
                FrameworkProfile::STATUS_ACTIVE,
                null,
                $preset['label'],
            );
        }

        return $out;
    }

    /**
     * Requirements a framework would typically add on top of its built-in floor, for a custom profile that starts as a copy of the built-in. They are NOT part of
     * {@see all()}: selecting a built-in profile asks for nothing the preset did not already ask for, so adding a requirement here never changes an existing install's
     * results. Today: collecting the Windows security event-log presets of the compliance baseline.
     *
     * @return list<Requirement>
     */
    public static function suggestedExtras(string $key): array
    {
        return Framework::isValid($key) ? [new Requirement(RequirementType::LOG_PRESETS_BASELINE, null, 1, 'Windows audit policy must also be on; see the Security log presets.')] : [];
    }

    public static function get(string $key): ?FrameworkProfile
    {
        return Framework::isValid($key) ? self::all()[$key] : null;
    }

    public static function isBuiltIn(string $key): bool
    {
        return Framework::isValid($key);
    }
}
