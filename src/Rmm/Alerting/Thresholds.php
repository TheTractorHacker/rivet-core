<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Alerting;

/**
 * Threshold tiers of a check that reports a numeric `value`: a warning and a critical limit, hysteresis, and a duration (N consecutive
 * samples and/or T minutes) before a tier takes effect. Pure functions; the state between samples lives in `rmm_check_eval`
 * ({@see CheckEvalStore}).
 *
 * The definition is the object `params.thresholds` of the (signed) check definition, and may be overridden per device
 * ({@see ThresholdResolver}). All numbers are whole numbers, because the signed canonical JSON of a check refuses decimals:
 *
 *   {"warn": {"op": "gt", "value": 80}, "crit": {"op": "gt", "value": 95}, "hysteresis": 5, "for_samples": 3, "for_minutes": 10}
 *
 * `op` is one of gt, gte, lt, lte. A tier whose limit is missing never triggers. `hysteresis` (0 to 1000, default 0) keeps a tier
 * until the value is that far back on the safe side of its limit, so a value hovering around a limit does not flip. `for_samples`
 * (1 to 100, default 1) and `for_minutes` (0 to 1440, default 0) delay RAISING the tier: the new, higher tier must hold for that
 * many consecutive samples AND that many minutes. Lowering or clearing a tier is immediate; the recovery debounce of the alert still applies.
 *
 * @api
 */
final class Thresholds
{
    public const OPS = ['gt', 'gte', 'lt', 'lte'];
    public const TIERS = ['ok', 'warn', 'crit'];
    public const MAX_HYSTERESIS = 1000;
    public const MAX_SAMPLES = 100;
    public const MAX_MINUTES = 1440;
    private const RANK = ['ok' => 0, 'warn' => 1, 'crit' => 2];

    /**
     * Validate and normalise a thresholds definition.
     *
     * @return array{0:?array<string,mixed>,1:?string} [normalised, error]
     */
    public static function normalize(mixed $t): array
    {
        if (!is_array($t) || ($t !== [] && array_is_list($t))) {
            return [null, 'thresholds must be an object.'];
        }
        $out = [];
        foreach (array_keys($t) as $k) {
            if (!in_array($k, ['warn', 'crit', 'hysteresis', 'for_samples', 'for_minutes'], true)) {
                return [null, "thresholds has an unknown field \"$k\"."];
            }
        }
        foreach (['warn', 'crit'] as $tier) {
            if (!array_key_exists($tier, $t)) {
                continue;
            }
            $l = $t[$tier];
            if (!is_array($l) || !is_string($l['op'] ?? null) || !in_array($l['op'], self::OPS, true) || !is_int($l['value'] ?? null) || abs($l['value']) > 1000000000000) {
                return [null, "thresholds.$tier needs op (gt, gte, lt or lte) and a whole-number value."];
            }
            $out[$tier] = ['op' => $l['op'], 'value' => $l['value']];
        }
        if ($out === []) {
            return [null, 'thresholds needs a warn or a crit limit.'];
        }
        foreach ([['hysteresis', 0, self::MAX_HYSTERESIS, 0], ['for_samples', 1, self::MAX_SAMPLES, 1], ['for_minutes', 0, self::MAX_MINUTES, 0]] as [$f, $min, $max, $def]) {
            $v = $t[$f] ?? $def;
            if (!is_int($v) || $v < $min || $v > $max) {
                return [null, "thresholds.$f must be a whole number from $min to $max."];
            }
            $out[$f] = $v;
        }
        // The critical limit must be on the far side of the warning limit, otherwise the tiers are inverted.
        if (isset($out['warn'], $out['crit']) && $out['warn']['op'] === $out['crit']['op']) {
            $up = in_array($out['warn']['op'], ['gt', 'gte'], true);
            if (($up && $out['crit']['value'] < $out['warn']['value']) || (!$up && $out['crit']['value'] > $out['warn']['value'])) {
                return [null, 'thresholds.crit must be beyond thresholds.warn.'];
            }
        }

        return [$out, null];
    }

    /** True when $value breaches the limit. */
    private static function breach(float $value, string $op, int $limit): bool
    {
        return match ($op) {
            'gt' => $value > $limit,
            'gte' => $value >= $limit,
            'lt' => $value < $limit,
            default => $value <= $limit,
        };
    }

    /** True when $value is still on the breaching side of the limit once the hysteresis is applied (the tier is kept). */
    private static function holds(float $value, string $op, int $limit, int $hyst): bool
    {
        if ($hyst <= 0) {
            return false;
        }

        return in_array($op, ['gt', 'gte'], true) ? self::breach($value, $op, $limit - $hyst) : self::breach($value, $op, $limit + $hyst);
    }

    /**
     * The tier the value is in right now, before any duration: the previous EFFECTIVE tier is only used for the hysteresis.
     *
     * @param array<string,mixed> $t a normalised definition
     */
    public static function rawTier(float $value, array $t, string $prevTier = 'ok'): string
    {
        $h = (int) ($t['hysteresis'] ?? 0);
        $prev = self::RANK[$prevTier] ?? 0;
        foreach (['crit' => 2, 'warn' => 1] as $tier => $rank) {
            if (!isset($t[$tier])) {
                continue;
            }
            $l = $t[$tier];
            if (self::breach($value, $l['op'], $l['value']) || ($prev >= $rank && self::holds($value, $l['op'], $l['value'], $h))) {
                return $tier;
            }
        }

        return 'ok';
    }

    /**
     * One step of the duration state machine.
     *
     * @param array{tier:string,cand_tier:string,cand_since:?int,cand_count:int} $state
     * @param array<string,mixed> $t a normalised definition
     * @return array{tier:string,cand_tier:string,cand_since:?int,cand_count:int} the new state; `tier` is the effective tier
     */
    public static function step(array $state, string $raw, int $now, array $t): array
    {
        $tier = $state['tier'];
        if (self::RANK[$raw] <= self::RANK[$tier]) {
            return ['tier' => $raw, 'cand_tier' => 'ok', 'cand_since' => null, 'cand_count' => 0];
        }
        $forSamples = max(1, (int) ($t['for_samples'] ?? 1));
        $forSeconds = (int) ($t['for_minutes'] ?? 0) * 60;
        if ($state['cand_tier'] === $raw && $state['cand_since'] !== null) {
            $count = $state['cand_count'] + 1;
            $since = $state['cand_since'];
        } else {
            $count = 1;
            $since = $now;
        }
        if ($count >= $forSamples && $now - $since >= $forSeconds) {
            return ['tier' => $raw, 'cand_tier' => 'ok', 'cand_since' => null, 'cand_count' => 0];
        }

        return ['tier' => $tier, 'cand_tier' => $raw, 'cand_since' => $since, 'cand_count' => $count];
    }

    /** The check status the existing evaluator understands for a tier. */
    public static function status(string $tier): string
    {
        return match ($tier) {
            'crit' => 'fail',
            'warn' => 'warn',
            default => 'ok',
        };
    }

    public static function tierOfStatus(string $status): string
    {
        return match ($status) {
            'fail' => 'crit',
            'warn' => 'warn',
            default => 'ok',
        };
    }

    public static function rank(string $tier): int
    {
        return self::RANK[$tier] ?? 0;
    }

    /**
     * Human text for a detail line: "91 > 80 (warn)".
     *
     * @param array<string,mixed> $t
     */
    public static function describe(float $value, string $tier, array $t): string
    {
        $v = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
        if ($tier === 'ok' || !isset($t[$tier])) {
            return "value $v";
        }
        $sym = ['gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<='][$t[$tier]['op']] ?? '?';

        return "value $v $sym {$t[$tier]['value']} ($tier)";
    }
}
