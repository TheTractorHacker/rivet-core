<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Alerting;

/**
 * Decides which thresholds are in force for one check on one device. Core's {@see DefaultThresholdResolver} takes the definition's
 * `params.thresholds` and lets a per-device override (table rmm_check_eval) replace its fields. The policy engine of Phase 2 (check
 * templates assigned to a client, site, group, tag or device) plugs in here: give an implementation to RmmModule as the option
 * `threshold_resolver` and it is asked on every evaluation of a check whose definition declares thresholds.
 *
 * @api
 */
interface ThresholdResolverInterface
{
    /**
     * @param array<string,mixed> $dev the device row
     * @param array<string,mixed> $params the check definition's params
     * @param array<string,mixed>|null $deviceOverride the normalised per-device override, or null
     * @return array<string,mixed>|null normalised thresholds (see {@see Thresholds::normalize()}), or null for "none"
     */
    public function resolve(array $dev, string $checkKey, array $params, ?array $deviceOverride): ?array;
}
