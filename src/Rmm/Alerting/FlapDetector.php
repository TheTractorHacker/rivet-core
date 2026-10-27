<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Alerting;

/**
 * Flap detection (state-change dampening) on a bit history of the last N samples of a check (1 = bad, 0 = good; unknown samples are not
 * recorded). The percentage of state changes in the window is compared with a high mark to START flapping and a low mark to STOP it,
 * so the state itself does not flicker. While a check flaps its alert state is held: no alert opens and none resolves.
 *
 * Definition, in the check's `params.flap` (whole numbers): {"window": 20, "high_pct": 50, "low_pct": 25}. window 6 to 50, 1 <= low_pct < high_pct <= 100.
 * Flap detection is off for a check without `params.flap`. Pure; the history bits live in `rmm_check_eval`.
 *
 * @api
 */
final class FlapDetector
{
    public const DEFAULT_WINDOW = 20;
    public const MIN_WINDOW = 6;
    public const MAX_WINDOW = 50;

    /** @return array{0:?array{window:int,high_pct:int,low_pct:int},1:?string} */
    public static function normalize(mixed $f): array
    {
        if (!is_array($f) || ($f !== [] && array_is_list($f))) {
            return [null, 'flap must be an object.'];
        }
        foreach (array_keys($f) as $k) {
            if (!in_array($k, ['window', 'high_pct', 'low_pct'], true)) {
                return [null, "flap has an unknown field \"$k\"."];
            }
        }
        $w = $f['window'] ?? self::DEFAULT_WINDOW;
        $hi = $f['high_pct'] ?? 50;
        $lo = $f['low_pct'] ?? 25;
        if (!is_int($w) || $w < self::MIN_WINDOW || $w > self::MAX_WINDOW) {
            return [null, 'flap.window must be a whole number from ' . self::MIN_WINDOW . ' to ' . self::MAX_WINDOW . '.'];
        }
        if (!is_int($hi) || !is_int($lo) || $lo < 1 || $hi > 100 || $lo >= $hi) {
            return [null, 'flap needs whole numbers 1 <= low_pct < high_pct <= 100.'];
        }

        return [['window' => $w, 'high_pct' => $hi, 'low_pct' => $lo], null];
    }

    /**
     * Record one sample.
     *
     * @param array{bits:int,n:int,flapping:bool} $state
     * @param array{window:int,high_pct:int,low_pct:int} $cfg
     * @return array{bits:int,n:int,flapping:bool,pct:int}
     */
    public static function record(array $state, bool $bad, array $cfg): array
    {
        $w = $cfg['window'];
        $mask = (1 << $w) - 1;
        $bits = ((($state['bits'] << 1) | ($bad ? 1 : 0)) & $mask);
        $n = min($w, $state['n'] + 1);
        $pct = self::percent($bits, $n);
        $flapping = $state['flapping'];
        // A short history says nothing: judge only once at least half the window has been seen.
        if ($n * 2 >= $w) {
            if (!$flapping && $pct >= $cfg['high_pct']) {
                $flapping = true;
            } elseif ($flapping && $pct <= $cfg['low_pct']) {
                $flapping = false;
            }
        }

        return ['bits' => $bits, 'n' => $n, 'flapping' => $flapping, 'pct' => $pct];
    }

    /** Percentage (0 to 100) of the n-1 adjacent pairs of the n newest samples that differ. */
    public static function percent(int $bits, int $n): int
    {
        if ($n < 2) {
            return 0;
        }
        $changes = ($bits ^ ($bits >> 1)) & ((1 << ($n - 1)) - 1);
        $count = 0;
        while ($changes !== 0) {
            $changes &= $changes - 1;
            ++$count;
        }

        return intdiv($count * 100, $n - 1);
    }
}
