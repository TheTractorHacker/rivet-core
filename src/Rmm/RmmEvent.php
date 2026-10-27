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

    /**
     * A file transfer finished (Phase 6, `filetransfer` switch). Fields: transfer_id, direction ("download" is device to server, "upload" server to device), path,
     * state ("complete", "delivered" for an upload the device read to the end, or "failed"), size_bytes, sha256 (null when it failed). Never the file's content.
     */
    public const FILE_TRANSFERRED = 'rmm.file.transferred';
    /** A log rule matched on a device and raised or kept its alert (Phase 8, `logs` switch). Fields: rule_id, rule_name, match_count, sample (the first matching message, at most 200 characters), alert_check_key. */
    public const LOG_RULE_MATCHED = 'rmm.log.rule_matched';

    /**
     * Starter pack content was installed, updated, parked for review or removed (Phase 2 starter pack). Not about one device: device_id is 0, asset_id null,
     * hostname empty, client_id 0. Fields: action ("install" or "uninstall"), pack_id, added, updated, staged, removed, skipped, user_id. Never a script text.
     */
    public const STARTER_INSTALLED = 'rmm.starter.installed';
    /** The MeshCentral agent was found installed on a device that had not reported one (optional Mesh agent install). Fields: version (null when unknown), node_id (null when not yet known). */
    public const MESH_AGENT_INSTALLED = 'rmm.mesh.agent_installed';
    /** A device reported a MeshCentral node id that is already mapped to another live device, so it was NOT mapped. Fields: node_id, other_device_id. */
    public const MESH_NODE_CONFLICT = 'rmm.mesh.node_conflict';

    /**
     * A device's patch scan was applied (Phase 4, `patching` switch). Fields: manager ("wua", "apt", "dnf", "yum" or "zypper"), scan_state ("ok" or "partial"), total, security (security and critical
     * updates), reboot_pending, new (updates not seen before).
     */
    public const PATCH_SCAN_COMPLETED = 'rmm.patch.scan_completed';
    /** Updates were approved for installation, by a person or an approval rule. Not about one device when it covers many (device_id 0). Fields: update_id, title, kind, source ("manual" or "rule:<id>"), devices (rows changed), approved_by (null for a rule). */
    public const PATCH_APPROVED = 'rmm.patch.approved';
    /** An install job (or its dry run) was queued for a device. Fields: job_id, mode ("dry_run" or "install"), update_count, source ("manual" or "scheduler"). */
    public const PATCH_INSTALL_STARTED = 'rmm.patch.install_started';
    /** An install job finished and every requested update ended installed, would be installed (dry run), skipped or not applicable. Fields: job_id, mode, installed, skipped, reboot_required. */
    public const PATCH_INSTALL_FINISHED = 'rmm.patch.install_finished';
    /** An install job failed in whole or in part. Fields: job_id, mode, failed (count), installed (count), reason (the first failing update's result code). */
    public const PATCH_INSTALL_FAILED = 'rmm.patch.install_failed';

    /** A software install, removal or dry run was queued for a device (Phase 5, `software_deploy` switch). Fields: job_id, package, action ("install" or "uninstall"), mode ("dry_run" or "run"), source ("manual" or "scheduler"). */
    public const DEPLOY_STARTED = 'rmm.deploy.started';
    /** A software job finished and ended well (installed, removed, already in the wanted state, or would be done in a dry run). Fields: job_id, package, action, mode, result, version (detected, may be empty), reboot_required. */
    public const DEPLOY_FINISHED = 'rmm.deploy.finished';
    /** A software job failed. Fields: job_id, package, action, mode, result (hash_mismatch, download_failed, not_found, locked, verify_failed or failed), code. */
    public const DEPLOY_FAILED = 'rmm.deploy.failed';

    /**
     * A change was recorded on a device (change monitoring, `change_monitor` switch): a registry value, a configuration file, a local group member or a scheduled task. At most 20 events per
     * reported batch, so a mass change is one burst and not a webhook storm; every change is in the records. Fields: kind ("registry", "file", "group" or "task"), watch (the preset or custom
     * watch id), change ("added", "modified" or "removed"), target (key path, file path, "group:<name>" or "scheduled-tasks"), item (value name, group member or task path), old_value,
     * new_value (cut to 512 characters, secrets masked, empty when none), summary (for a file: "+N -M lines"), actor and process (best effort, empty when Windows did not record them).
     */
    public const CHANGE_DETECTED = 'rmm.change.detected';
    /** A change rule matched on a device and raised or kept its alert. Fields: rule_id, rule_name, match_count, watch, target, item, change, sample (at most 200 characters), alert_check_key. */
    public const CHANGE_RULE_MATCHED = 'rmm.change.rule_matched';

    /** A device's clock differs from the server's by more than the `clock_skew_alert_s` limit (120 s by default; 0 turns it off). At most once per device per day. Fields: skew_s (device minus server, seconds), direction ("ahead" or "behind"), server_time. */
    public const DEVICE_CLOCK_SKEW = 'rmm.device.clock_skew';

    /** A VM that was running is no longer running (hypervisor visibility, `hypervisor` switch; templates and ignored VMs never). Fields: vm_key, vm_name, vm_type, host_device_id, from_state, to_state, node, alert_check_key. */
    public const HYPERVISOR_VM_DOWN = 'rmm.hypervisor.vm_down';
    /** A VM's last backup failed or is older than the `hypervisor_backup_days` limit. Fields: vm_key, vm_name, reason ("failed", "warning" or "stale"), last_backup_at, last_backup_result, age_days, threshold_days, alert_check_key. */
    public const HYPERVISOR_BACKUP_FAILED = 'rmm.hypervisor.backup_failed';
    /** A VM has a snapshot older than the `hypervisor_snapshot_days` limit. Fields: vm_key, vm_name, snapshot_count, oldest_snapshot_at, age_days, threshold_days, alert_check_key. */
    public const HYPERVISOR_SNAPSHOT_OLD = 'rmm.hypervisor.snapshot_old';
    /** A hypervisor storage is above the warning or critical percentage. Fields: storage, type, used_pct, level ("warn" or "fail"), total_bytes, used_bytes, alert_check_key. */
    public const HYPERVISOR_STORAGE_LOW = 'rmm.hypervisor.storage_low';
    /** A ZFS pool or Ceph cluster is degraded or faulted, or a Proxmox storage is unavailable. Fields: storage, type, health ("warn" or "error"), alert_check_key. */
    public const HYPERVISOR_POOL_DEGRADED = 'rmm.hypervisor.pool_degraded';
    /** A Proxmox or Hyper-V cluster lost quorum (as seen by the reporting host). Fields: cluster, platform, nodes_online, nodes_total, alert_check_key. */
    public const HYPERVISOR_CLUSTER_QUORUM_LOST = 'rmm.hypervisor.cluster_quorum_lost';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::DEVICE_ENROLLED, self::DEVICE_OFFLINE, self::DEVICE_ONLINE, self::CHECK_FAILED, self::CHECK_RECOVERED,
            self::JOB_COMPLETED, self::JOB_FAILED, self::SOFTWARE_INSTALLED, self::SOFTWARE_REMOVED,
            self::ALERT_OPENED, self::ALERT_ESCALATED, self::ALERT_ACKNOWLEDGED, self::ALERT_RESOLVED, self::MAINTENANCE_STARTED, self::MAINTENANCE_ENDED,
            self::POLICY_ASSIGNED, self::SCRIPT_RUN, self::APPROVAL_REQUESTED, self::APPROVAL_DECIDED, self::FILE_TRANSFERRED, self::LOG_RULE_MATCHED, self::MESH_AGENT_INSTALLED, self::MESH_NODE_CONFLICT, self::STARTER_INSTALLED,
            self::PATCH_SCAN_COMPLETED, self::PATCH_APPROVED, self::PATCH_INSTALL_STARTED, self::PATCH_INSTALL_FINISHED, self::PATCH_INSTALL_FAILED,
            self::DEPLOY_STARTED, self::DEPLOY_FINISHED, self::DEPLOY_FAILED,
            self::CHANGE_DETECTED, self::CHANGE_RULE_MATCHED, self::DEVICE_CLOCK_SKEW,
            self::HYPERVISOR_VM_DOWN, self::HYPERVISOR_BACKUP_FAILED, self::HYPERVISOR_SNAPSHOT_OLD, self::HYPERVISOR_STORAGE_LOW, self::HYPERVISOR_POOL_DEGRADED, self::HYPERVISOR_CLUSTER_QUORUM_LOST];
    }
}
