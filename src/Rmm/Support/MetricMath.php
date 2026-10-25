<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Support;

/**
 * The rules of the metric tiers that do not depend on the database, shared by every {@see \RivetCore\Rmm\Store\MetricStore} so two stores cannot drift apart:
 * which samples are accepted, how a batch folds into latest readings and hourly rollups, and how the tiers of a window combine into one peak.
 *
 * @internal
 */
final class MetricMath
{
    public const KEY_RE = '/^[a-z0-9_.]{1,64}$/D';

    /**
     * Fold a batch into the newest reading per series and one rollup per series and hour. A utilization outside 0..100, a negative and a non-finite value, a bad key and
     * a non-positive asset are dropped (never clamped).
     *
     * @param list<array{asset_id:int,key:string,instance:?string,value:int|float,at:\DateTimeImmutable,label:?string}> $samples
     * @return array{0:array<string,array{0:int,1:string,2:string,3:float,4:?string,5:int}>,1:array<string,array{0:int,1:string,2:string,3:int,4:int,5:float,6:float,7:float}>} latest, hourly
     */
    public static function fold(array $samples): array
    {
        $latest = [];
        $hourly = [];
        foreach ($samples as $s) {
            $key = $s['key'];
            $value = $s['value'];
            $asset = $s['asset_id'];
            if ($asset <= 0 || preg_match(self::KEY_RE, $key) !== 1 || !is_finite((float) $value) || $value < 0 || (str_ends_with($key, '.utilization') && $value > 100)) {
                continue;
            }
            $inst = $s['instance'] === null ? '' : mb_substr($s['instance'], 0, 64);
            $ts = $s['at']->getTimestamp();
            $id = $asset . "\0" . $key . "\0" . $inst;
            if (!isset($latest[$id]) || $ts >= $latest[$id][5]) {
                $latest[$id] = [$asset, $key, $inst, (float) $value, $s['label'] === null ? null : mb_substr($s['label'], 0, 64), $ts];
            }
            if (in_array($key, \RivetCore\Rmm\Support\DatabaseMetricSink::ROLLUP_KEYS, true)) {
                $hour = $ts - $ts % 3600;
                $hid = $id . "\0" . $hour;
                if (!isset($hourly[$hid])) {
                    $hourly[$hid] = [$asset, $key, $inst, $hour, 1, (float) $value, (float) $value, (float) $value];
                } else {
                    $h = &$hourly[$hid];
                    ++$h[4];
                    $h[5] += (float) $value;
                    $h[6] = min($h[6], (float) $value);
                    $h[7] = max($h[7], (float) $value);
                    unset($h);
                }
            }
        }

        return [$latest, $hourly];
    }

    /**
     * Combine the aggregates of the tiers a window touches (oldest tier first) into the answer of `peak()`.
     *
     * @param list<array{n:int,total:float,lo:float,hi:float,at:string}> $tiers one aggregate per tier that has data
     * @return array{min:float,max:float,avg:float,samples:int,peak_at:\DateTimeImmutable}|null
     */
    public static function combine(array $tiers): ?array
    {
        if ($tiers === []) {
            return null;
        }
        $n = 0;
        $total = 0.0;
        $lo = $tiers[0]['lo'];
        $hi = $tiers[0]['hi'];
        $at = $tiers[0]['at'];
        foreach ($tiers as $t) {
            $n += $t['n'];
            $total += $t['total'];
            $lo = min($lo, $t['lo']);
            if ($t['hi'] >= $hi) {
                $hi = $t['hi'];
                $at = $t['at'];   // on a tie the later tier (more recent data) holds the peak
            }
        }

        return ['min' => $lo, 'max' => $hi, 'avg' => $total / $n, 'samples' => $n, 'peak_at' => self::at($at)];
    }

    public static function number(mixed $v): int|float
    {
        $f = (float) $v;

        return $f == floor($f) && abs($f) < 9e15 ? (int) $f : $f;
    }

    public static function at(string $utc): \DateTimeImmutable
    {
        return new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
    }
}
