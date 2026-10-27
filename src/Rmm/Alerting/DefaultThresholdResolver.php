<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Alerting;

/**
 * The definition's `params.thresholds`, with the fields of the per-device override replacing the definition's (a tier the override
 * sets to null is removed). An invalid stored value is ignored rather than allowed to break a check-in.
 *
 * @api
 */
final class DefaultThresholdResolver implements ThresholdResolverInterface
{
    public function resolve(array $dev, string $checkKey, array $params, ?array $deviceOverride): ?array
    {
        $base = $params['thresholds'] ?? null;
        $merged = is_array($base) ? $base : [];
        if ($deviceOverride !== null) {
            foreach ($deviceOverride as $k => $v) {
                if ($v === null) {
                    unset($merged[$k]);
                } else {
                    $merged[$k] = $v;
                }
            }
        }
        if ($merged === []) {
            return null;
        }
        [$t] = Thresholds::normalize($merged);

        return $t;
    }
}
