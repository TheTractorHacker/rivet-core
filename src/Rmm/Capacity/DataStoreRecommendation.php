<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Capacity;

use RivetCore\Rmm\Store\RmmDataStoreKind;

/**
 * The "consider PostgreSQL" advice of the capacity panel (phase 3 of #89). A pure function of numbers: it states a fact and never acts. The message exists only while
 * the active store is MariaDB and either the fleet reaches 80 % of the configured comfortable size (`rmm_pg_recommend_devices`) or the hot tables exceed
 * `rmm_pg_recommend_hot_mb` (0 = size never triggers).
 *
 * @api
 */
final class DataStoreRecommendation
{
    public const FLEET_FRACTION = 0.8;

    /**
     * @param array{kind:string,available:bool,total_bytes:int,oldest_at:?string} $sizes see {@see \RivetCore\Rmm\Store\StoreSizes}
     * @return array{store:string,devices:int,threshold_devices:int,threshold_hot_mb:int,hot_bytes:?int,hot_mb:?float,growth_mb_per_day:?float,recommend:bool,reasons:list<string>,message:?string}
     */
    public static function evaluate(string $activeStore, int $devices, int $thresholdDevices, int $thresholdHotMb, array $sizes, int $nowTs): array
    {
        $x = max(1, $thresholdDevices);
        $hot = $sizes['available'] ? $sizes['total_bytes'] : null;
        $growth = null;
        if ($hot !== null && $sizes['oldest_at'] !== null && ($t = strtotime($sizes['oldest_at'] . ' UTC')) !== false) {
            $growth = round($hot / 1048576 / max(1.0, ($nowTs - $t) / 86400), 2);
        }
        $reasons = [];
        if ($activeStore === RmmDataStoreKind::MARIADB) {
            if ($devices >= self::FLEET_FRACTION * $x) {
                $reasons[] = 'devices';
            }
            if ($hot !== null && $thresholdHotMb > 0 && $hot > $thresholdHotMb * 1048576) {
                $reasons[] = 'size';
            }
        }
        $recommend = $reasons !== [];

        return [
            'store' => $activeStore, 'devices' => $devices, 'threshold_devices' => $x, 'threshold_hot_mb' => $thresholdHotMb,
            'hot_bytes' => $hot, 'hot_mb' => $hot === null ? null : round($hot / 1048576, 2), 'growth_mb_per_day' => $growth,
            'recommend' => $recommend, 'reasons' => $reasons,
            'message' => $recommend ? sprintf('MariaDB is comfortable up to %d devices. You have %d. Consider PostgreSQL.', $x, $devices) : null,
        ];
    }
}
