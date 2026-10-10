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

    /**
     * A policy was assigned to a scope, or an assignment was removed. Not about one device: device_id is 0, asset_id null, hostname empty, client_id the
     * client of a client-scoped assignment (else 0). Fields: policy_id, policy_name, scope_type, scope_id, action ("assigned" or "unassigned").
     */
    public const POLICY_ASSIGNED = 'rmm.policy.assigned';
    /** A library script was queued on a device. Fields: script_id, script_name, script_version, job_id, origin ("manual", "schedule" or "approval"). Never the script text. */
    public const SCRIPT_RUN = 'rmm.script.run';
    /** A run, or a schedule, is waiting for a second person. Not about one device (device_id 0). Fields: approval_id, kind ("run" or "schedule"), summary, device_count, requested_by. */
    public const APPROVAL_REQUESTED = 'rmm.approval.requested';
    /** A pending approval was approved, rejected, cancelled or lapsed. Not about one device (device_id 0). Fields: approval_id, kind, state, decided_by (null when it lapsed). */
    public const APPROVAL_DECIDED = 'rmm.approval.decided';
    /** An alert episode opened (Phase 3, `alerting` switch). Fields: alert_id, check_key, severity ("warning" or "error"), episode, message, group_key. */
    public const ALERT_OPENED = 'rmm.alert.opened';
    /** An open alert was escalated: a policy step fired, a repeat fired, or the severity rose. Fields: alert_id, check_key, severity, reason ("step", "repeat" or "severity"), step, policy_id. */
    public const ALERT_ESCALATED = 'rmm.alert.escalated';
    /** A technician acknowledged an open alert. Fields: alert_id, check_key, user_id. */
    public const ALERT_ACKNOWLEDGED = 'rmm.alert.acknowledged';
    /** An alert was resolved. Fields: alert_id, check_key, reason ("recovered", "manual" or "device_retired"), user_id (null unless manual). */
    public const ALERT_RESOLVED = 'rmm.alert.resolved';
    /** A maintenance window opened. Fields: window_id, name, mode ("mute" or "suppress"), scope_type, scope_id, ends_at. Not about one device: device_id is 0, asset_id null, hostname empty, client_id the scope client or 0 (the same convention as the policy and approval events); a device-scoped window carries the device in scope_id. */
    public const MAINTENANCE_STARTED = 'rmm.maintenance.started';
    /** A maintenance window closed. Fields: window_id, name, mode, scope_type, scope_id. Same device_id / client_id / hostname rule as MAINTENANCE_STARTED. */
    public const MAINTENANCE_ENDED = 'rmm.maintenance.ended';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::DEVICE_ENROLLED, self::DEVICE_OFFLINE, self::DEVICE_ONLINE, self::CHECK_FAILED, self::CHECK_RECOVERED,
            self::JOB_COMPLETED, self::JOB_FAILED, self::SOFTWARE_INSTALLED, self::SOFTWARE_REMOVED,
            self::ALERT_OPENED, self::ALERT_ESCALATED, self::ALERT_ACKNOWLEDGED, self::ALERT_RESOLVED, self::MAINTENANCE_STARTED, self::MAINTENANCE_ENDED,
            self::POLICY_ASSIGNED, self::SCRIPT_RUN, self::APPROVAL_REQUESTED, self::APPROVAL_DECIDED];
    }
}
