<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Alerting;

use RivetCore\Rmm\Contracts\ScheduleGateInterface;
use RivetCore\Rmm\Settings\RmmSettings;

/**
 * The maintenance windows' say in scheduled scripts (Phase 2's {@see ScheduleGateInterface}): while a `suppress` window is open for a device
 * ("leave this device alone"), a scheduled script does not start on it. The decision is `defer`, so the next housekeeping pass asks again and
 * the script runs after the window if the schedule's own window (`expires_s`) is still open; a `mute` window (alerts only) never holds a script
 * back. Only while the `alerting` sub-switch is on; otherwise every script runs, exactly as before.
 *
 * @api
 */
final class MaintenanceScheduleGate implements ScheduleGateInterface
{
    public function __construct(private readonly MaintenanceService $maintenance, private readonly RmmSettings $settings)
    {
    }

    public function decide(array $device, array $schedule, int $slotTimestamp): string
    {
        if (!$this->settings->featureOn('alerting')) {
            return self::RUN;
        }

        return $this->maintenance->stateFor($device)['suppress'] ? self::DEFER : self::RUN;
    }
}
