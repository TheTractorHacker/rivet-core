<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Metrics;

/**
 * The step (bucket width) used when a window of a given length is read from an external store: one hour up to two days, one day
 * beyond. Shared by {@see ExternalMetricReader} and the adapters so peak_at means the same everywhere.
 *
 * @api
 */
final class MetricSteps
{
    public const HOUR = 3600;
    public const DAY = 86400;

    public static function forSpan(int $seconds): int
    {
        return $seconds <= 2 * self::DAY ? self::HOUR : self::DAY;
    }

    /** Start of the bucket of width $step that holds the Unix time $ts (buckets are aligned to the epoch). */
    public static function floor(int $ts, int $step): int
    {
        $step = max(1, $step);

        return $ts - (($ts % $step) + $step) % $step;
    }
}
