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

    public static function get(string $key): ?FrameworkProfile
    {
        return Framework::isValid($key) ? self::all()[$key] : null;
    }

    public static function isBuiltIn(string $key): bool
    {
        return Framework::isValid($key);
    }
}
