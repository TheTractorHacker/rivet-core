<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Contracts;

/**
 * Optional: a say in whether a scheduled script may start on a device right now. The alerting phase (maintenance windows) implements it; the
 * default {@see \RivetCore\Rmm\Scripts\OpenScheduleGate} lets everything run. It is asked once per device and slot, from the housekeeping cron, and must
 * be cheap and free of side effects (it may be asked again for a deferred device on the next pass).
 *
 * @api
 */
interface ScheduleGateInterface
{
    public const RUN = 'run';
    public const SKIP = 'skip';
    public const DEFER = 'defer';

    /**
     * @param array<string,mixed> $device the endpoint_agent_devices row
     * @param array<string,mixed> $schedule the schedule as {@see \RivetCore\Rmm\Scripts\ScheduleService::get()} shows it
     * @param int $slotTimestamp Unix time of the nominal start of this run
     * @return string `run` (create the job now), `skip` (give this device no job for this slot, final) or `defer` (ask again on the next pass; the
     *         device is dropped when the run's window closes)
     */
    public function decide(array $device, array $schedule, int $slotTimestamp): string;
}
