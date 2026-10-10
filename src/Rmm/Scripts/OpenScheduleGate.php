<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Scripts;

use RivetCore\Rmm\Contracts\ScheduleGateInterface;

/** The default gate: nothing is ever held back. @api */
final class OpenScheduleGate implements ScheduleGateInterface
{
    public function decide(array $device, array $schedule, int $slotTimestamp): string
    {
        return self::RUN;
    }
}
