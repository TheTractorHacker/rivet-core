<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Settings;

/**
 * Minimum retention an outside rule imposes on the RMM history, per data class ({@see RetentionClasses::MAP} lists the class ids). The compliance module
 * implements it from the selected framework profile ({@see \RivetCore\Compliance\Profile\RetentionFloors}); an install with nothing selected passes none
 * and every setting behaves exactly as before. Implementations must be cheap (the settings call them on busy paths) and must not throw.
 *
 * @api
 */
interface RetentionFloorsInterface
{
    /** Minimum days for a data class id, or 0 when there is none. */
    public function floorDays(string $dataClass): int;

    /** Who asks, for messages: for example "Contract X (version 3)". */
    public function requiredBy(): string;
}
