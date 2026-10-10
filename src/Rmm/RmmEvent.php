<?php

declare(strict_types=1);

namespace RivetCore\Rmm;

/**
 * The `rmm.*` event ids the module publishes through {@see \RivetCore\Rmm\Contracts\RmmEventsInterface}, with their own payload fields
 * (every payload also has device_id, asset_id, client_id, hostname and occurred_at). Listed in Core's EventCatalog, group "rmm".
 *
 * @api
 */
final class RmmEvent
{
    /** A device enrolled (or re-enrolled). Fields: link_state ("linked" or "pending_approval"), os, outcome ("enrolled", "re-enrolled"). */
    public const DEVICE_ENROLLED = 'rmm.device.enrolled';
    /** A device stopped checking in for longer than offline_after_s. Fields: last_checkin_at. */
    public const DEVICE_OFFLINE = 'rmm.device.offline';
    /** A device that was reported offline checked in again. Fields: offline_since. */
    public const DEVICE_ONLINE = 'rmm.device.online';
    /** A check opened an alert (after the failure debounce). Fields: check_key, status ("warn" or "fail"), detail, alert_id, episode. */
    public const CHECK_FAILED = 'rmm.check.failed';
    /** A check that had an open alert recovered (after the recovery debounce). Fields: check_key, alert_id, episode. */
    public const CHECK_RECOVERED = 'rmm.check.recovered';
    /** A job finished successfully. Fields: job_id, job_type, exit_code. */
    public const JOB_COMPLETED = 'rmm.job.completed';
    /** A job failed or timed out. Fields: job_id, job_type, state ("failed" or "timed_out"), exit_code. */
    public const JOB_FAILED = 'rmm.job.failed';
    /** New software appeared on a device (not the first report). Fields: name, version, publisher, source. */
    public const SOFTWARE_INSTALLED = 'rmm.software.installed';
    /** Software disappeared from a device. Fields: name, version, publisher, source. */
    public const SOFTWARE_REMOVED = 'rmm.software.removed';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::DEVICE_ENROLLED, self::DEVICE_OFFLINE, self::DEVICE_ONLINE, self::CHECK_FAILED, self::CHECK_RECOVERED,
            self::JOB_COMPLETED, self::JOB_FAILED, self::SOFTWARE_INSTALLED, self::SOFTWARE_REMOVED];
    }
}
