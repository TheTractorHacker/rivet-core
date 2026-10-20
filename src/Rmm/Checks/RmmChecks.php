<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Checks;

/**
 * Per-OS targeting of check definitions (1.0.0-rc.24, additive). A check definition may carry `os`: 'windows', 'linux' or 'any'
 * (omitted = any = the behaviour before rc.24). Only 'windows' and 'linux' are stored and delivered; 'any' is never written, so a
 * definition without a target is byte for byte what it always was (and so is its signature).
 *
 * @api
 */
final class RmmChecks
{
    public const OS_VALUES = ['windows', 'linux', 'any'];

    /** Key families the server itself reports into endpoint_agent_checks (never part of a delivered list). */
    public const SERVER_KEY_PREFIXES = ['log.rule_', 'change.rule_', 'patch.'];

    /**
     * Validate an `os` value from an admin or a policy.
     *
     * @return array{0:?string,1:?string} [normalised 'windows'|'linux'|null (= any), error]
     */
    public static function normalizeOs(mixed $v): array
    {
        if ($v === null || $v === '') {
            return [null, null];
        }
        if (!is_string($v) || !in_array($v, self::OS_VALUES, true)) {
            return [null, 'os must be one of windows, linux or any (or left out), not ' . (is_scalar($v) ? '"' . substr((string) $v, 0, 30) . '"' : 'a non-text value') . '.'];
        }

        return [$v === 'any' ? null : $v, null];
    }

    /**
     * The OS a check is limited to, or null for any (an unknown stored value counts as any).
     *
     * @param array<string,mixed> $check
     */
    public static function osOf(array $check): ?string
    {
        $o = $check['os'] ?? null;

        return $o === 'windows' || $o === 'linux' ? $o : null;
    }

    /**
     * The OS to filter a device's checks by: its stored `os` when that is windows or linux, else null (no filtering).
     *
     * @param array<string,mixed> $dev
     */
    public static function deviceOs(array $dev): ?string
    {
        $o = $dev['os'] ?? null;

        return $o === 'windows' || $o === 'linux' ? $o : null;
    }

    /** @param array<string,mixed> $check */
    public static function appliesTo(array $check, string $os): bool
    {
        $t = self::osOf($check);

        return $t === null || $t === $os || ($os !== 'windows' && $os !== 'linux');   // a device of unknown OS is not filtered
    }

    /**
     * The checks that apply to a device of this OS ('windows' or 'linux'), order kept.
     *
     * @param list<array<string,mixed>> $checks
     * @return list<array<string,mixed>>
     */
    public static function forOs(array $checks, string $os): array
    {
        return array_values(array_filter($checks, static fn (array $c): bool => self::appliesTo($c, $os)));
    }

    public static function isServerKey(string $key): bool
    {
        foreach (self::SERVER_KEY_PREFIXES as $p) {
            if (str_starts_with($key, $p)) {
                return true;
            }
        }

        return false;
    }
}
