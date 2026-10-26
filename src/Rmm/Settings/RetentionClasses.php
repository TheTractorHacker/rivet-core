<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Settings;

use RivetCore\Rmm\Change\ChangeSettings;
use RivetCore\Rmm\Remote\RemoteSettings;

/**
 * Which retention setting governs which data class of a compliance profile ({@see \RivetCore\Compliance\Profile\DataClass}), and what to do with a floor
 * ({@see RetentionFloorsInterface}). The class ids are the same strings; this module repeats them so it does not depend on the compliance module (a unit test
 * keeps the two in step).
 *
 * @api
 */
final class RetentionClasses
{
    public const SOURCE_RMM = 'rmm';
    public const SOURCE_REMOTE = 'remote';
    public const SOURCE_CHANGE = 'change';

    /** @var array<string, array{0:string, 1:string}> data class => [settings source, limit key] */
    public const MAP = [
        'rmm_logs' => [self::SOURCE_REMOTE, 'logs_retention_days'],
        'change_records' => [self::SOURCE_CHANGE, 'change_retention_days'],
        'check_history' => [self::SOURCE_RMM, 'check_history_days'],
        'software_history' => [self::SOURCE_RMM, 'software_history_days'],
        'patch_history' => [self::SOURCE_RMM, 'patch_history_days'],
        'deployment_history' => [self::SOURCE_RMM, 'software_deploy_history_days'],
        'metrics_hourly' => [self::SOURCE_RMM, 'metric_hourly_days'],
        'metrics_daily' => [self::SOURCE_RMM, 'metric_daily_days'],
    ];

    /** Human names for messages. */
    public const LABELS = [
        'rmm_logs' => 'RMM logs', 'change_records' => 'change records', 'check_history' => 'check history', 'software_history' => 'software history',
        'patch_history' => 'patch history', 'deployment_history' => 'software deployment history', 'metrics_hourly' => 'hourly metrics', 'metrics_daily' => 'daily metrics',
    ];

    /** @return array<string,string> limit key => data class, for one settings source */
    public static function classesOf(string $source): array
    {
        $out = [];
        foreach (self::MAP as $class => [$src, $key]) {
            if ($src === $source) {
                $out[$key] = $class;
            }
        }

        return $out;
    }

    /** The smallest and largest value the class's own setting accepts (read from the setting, so it cannot drift), or null for a class with no such setting. @return array{0:int,1:int}|null */
    public static function bounds(string $class): ?array
    {
        $m = self::MAP[$class] ?? null;
        if ($m === null) {
            return null;
        }
        [$src, $key] = $m;
        $b = match ($src) {
            self::SOURCE_RMM => RmmSettings::limitKeys()[$key] ?? null,
            self::SOURCE_REMOTE => RemoteSettings::LIMITS[$key] ?? null,
            self::SOURCE_CHANGE => ChangeSettings::LIMITS[$key] ?? null,
            default => null,
        };

        return $b === null ? null : [(int) $b[0], (int) $b[1]];
    }

    /**
     * Raise the values of one settings source to the floors.
     *
     * @param array<string,int> $limits limit key => days
     * @return array<string,int>
     */
    public static function raise(array $limits, string $source, ?RetentionFloorsInterface $floors): array
    {
        if ($floors === null) {
            return $limits;
        }
        foreach (self::classesOf($source) as $key => $class) {
            $floor = $floors->floorDays($class);
            if ($floor > 0 && isset($limits[$key]) && $limits[$key] < $floor) {
                $limits[$key] = $floor;
            }
        }

        return $limits;
    }

    /**
     * Messages for every proposed value below its floor (empty when all are fine or there is no floor). A save with any message is refused as a whole.
     * A class whose floor is above the setting's maximum has no message (see below): the floor applies by itself.
     *
     * @param array<string,mixed> $proposed limit key => value (only keys present are checked)
     * @return list<string>
     */
    public static function violations(array $proposed, string $source, ?RetentionFloorsInterface $floors): array
    {
        if ($floors === null) {
            return [];
        }
        $out = [];
        foreach (self::classesOf($source) as $key => $class) {
            if (!array_key_exists($key, $proposed) || !is_int($proposed[$key])) {
                continue;
            }
            $floor = $floors->floorDays($class);
            // A floor above the setting's own maximum cannot be met by any value of the setting: the floor is applied when the limit is read ({@see raise()}),
            // so a form that posts every limit is not blocked by a value it could never fix.
            if ($floor > 0 && $proposed[$key] < $floor && $floor <= (self::bounds($class)[1] ?? PHP_INT_MAX)) {
                $out[] = 'Retention of ' . self::LABELS[$class] . ' cannot be set below ' . $floor . ' days: the compliance framework ' . $floors->requiredBy()
                    . ' requires at least ' . $floor . ' days (requirement: minimum retention of ' . self::LABELS[$class] . '). Use ' . $floor . ' days or more, or choose another framework.';
            }
        }

        return $out;
    }
}
