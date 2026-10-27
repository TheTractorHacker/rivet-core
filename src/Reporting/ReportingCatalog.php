<?php

declare(strict_types=1);

namespace RivetCore\Reporting;

/**
 * The list of reporting views (#91 phase 2), the single source for the DDL ({@see ReportingSchema}), the frozen-column test, the data dictionary and the Power Query samples
 * (`php scripts/reporting-docs.php` regenerates docs/reporting/DATA_DICTIONARY.md and docs/reporting/samples/ from it, and a test fails when they drift).
 *
 * Rules every view follows:
 *  - an allow-list of columns (never `SELECT *`); a column name never contains token, secret, password, hash, key, credential, cookie, signature or `_enc`;
 *  - nothing free-form: no script output, command line, registry or file value, check detail, alert message, log text, IP address, user name or serial number;
 *    counts, codes, names of things the administrator configured, and timestamps only;
 *  - every timestamp is UTC (MariaDB / MySQL `datetime`, PostgreSQL `timestamptz` with the session in UTC);
 *  - `client_id` on every row of a client-scoped view (joined from the device where the underlying table does not carry it), so a report can be scoped by client;
 *  - the ids are the ones of the control database: `device_id`, `client_id`, `site_id` (the edition's location), `asset_id` (the edition's asset).
 *
 * Adding a column is a deliberate act: append it to the END of the view, update tests/Fixtures/reporting_views.json (the contract test) and add a migration that
 * re-runs `CREATE OR REPLACE VIEW` (and a new, versioned PostgreSQL statement list; the applied one is checksummed and must not change).
 *
 * @api
 */
final class ReportingCatalog
{
    /** Base-table columns whose name contains a deny-list word but which are plain identifiers of a metric, check, software item or VM, never a secret. */
    public const ALLOWED_BASE_COLUMNS = ['metric_key', 'check_key', 'software_key', 'vm_key'];

    /** The most recent days of check history the view returns (the history is kept 7 days by default). */
    public const CHECK_RESULT_DAYS = 31;

    /** The newest device of each asset (an asset can be re-enrolled; the metric tables are keyed by the asset). */
    private const DEVICE_OF_ASSET = '(SELECT asset_id, MAX(device_id) AS device_id FROM endpoint_agent_devices WHERE asset_id IS NOT NULL AND asset_id > 0 GROUP BY asset_id)';

    /** @var list<ReportingView>|null */
    private static ?array $views = null;

    /** @return list<ReportingView> */
    public static function views(): array
    {
        return self::$views ??= self::build();
    }

    public static function get(string $name): ReportingView
    {
        foreach (self::views() as $v) {
            if ($v->name === $name) {
                return $v;
            }
        }
        throw new \InvalidArgumentException('Unknown reporting view ' . $name);
    }

    /** @return list<ReportingView> */
    private static function build(): array
    {
        return [
            new ReportingView(
                'rpt_devices', ReportingView::STORE_CONTROL,
                'one row per enrolled device (current state)',
                'The device register: who owns it, what it runs, whether it is reachable, and the headline patch state. The hub that every other view relates to.',
                'live (read from the control tables at query time)', true,
                [
                    ['device_id', 'int', 'Primary key of the device. Stable for the life of the device record.'],
                    ['client_id', 'int', 'The client (customer) that owns the device. Relate to the edition\'s clients table for the name.'],
                    ['site_id', 'int', 'The device\'s location (site) id in the edition; 0 when none.'],
                    ['asset_id', 'int', 'The edition\'s asset linked to the device; NULL while the device is not linked. The metric tables are keyed by this.'],
                    ['hostname', 'text', 'Host name reported by the agent.'],
                    ['os', 'text', 'Operating system family: windows, linux, macos.'],
                    ['os_version', 'text', 'Operating system version string.'],
                    ['arch', 'text', 'CPU architecture (amd64, arm64, ...).'],
                    ['manufacturer', 'text', 'Hardware manufacturer, when reported.'],
                    ['model', 'text', 'Hardware model, when reported.'],
                    ['agent_version', 'text', 'Version of the RivetIT agent.'],
                    ['ring', 'text', 'Update ring the device is in (stable, ...).'],
                    ['link_state', 'text', 'pending_approval, linked or rejected: whether an administrator approved the device.'],
                    ['status', 'text', 'online, offline, stale, never, retired or revoked. Online/offline use the module\'s offline-after setting (default 15 minutes), stale the stale-after setting (default 7 days).'],
                    ['first_seen_at', 'datetime', 'UTC. When the device first enrolled.'],
                    ['last_checkin_at', 'datetime', 'UTC. Last check-in received; NULL if it never checked in.'],
                    ['last_inventory_at', 'datetime', 'UTC. Last full inventory received.'],
                    ['retired_at', 'datetime', 'UTC. When an administrator retired the device; NULL while active.'],
                    ['revoked_at', 'datetime', 'UTC. When the device credential was revoked; NULL otherwise.'],
                    ['uptime_s', 'int', 'Uptime in seconds at the last check-in.'],
                    ['pending_reboot', 'flag', '1 when the operating system reported a pending reboot at the last check-in, 0 when not, NULL when unknown.'],
                    ['is_hypervisor_host', 'flag', '1 when the device reports as a hypervisor host (Proxmox, Hyper-V), else 0.'],
                    ['patch_scan_state', 'text', 'State of the last patch scan: never, ok, failed, ...; NULL when patching never ran on the device.'],
                    ['patch_scanned_at', 'datetime', 'UTC. When the device was last scanned for updates.'],
                    ['patch_pending_count', 'int', 'Updates the last scan found missing (all kinds).'],
                    ['patch_critical_count', 'int', 'Of those, critical.'],
                    ['patch_security_count', 'int', 'Of those, security.'],
                    ['patch_reboot_pending', 'flag', '1 when patching left a reboot pending.'],
                ],
                ['device_id', 'client_id', 'site_id', 'asset_id'],
                "SELECT d.device_id, d.client_id, d.location_id AS site_id, d.asset_id, d.hostname, d.os, d.os_version, d.arch, d.manufacturer, d.model, d.agent_version, d.ring, d.link_state,
 CASE WHEN d.retired_at IS NOT NULL THEN 'retired' WHEN d.revoked_at IS NOT NULL THEN 'revoked' WHEN d.last_checkin_at IS NULL THEN 'never'
  WHEN d.last_checkin_at >= (UTC_TIMESTAMP() - INTERVAL COALESCE(st.offline_after_s, 900) SECOND) THEN 'online'
  WHEN d.last_checkin_at >= (UTC_TIMESTAMP() - INTERVAL COALESCE(st.stale_after_s, 604800) SECOND) THEN 'offline' ELSE 'stale' END AS status,
 d.first_seen_at, d.last_checkin_at, d.last_inventory_at, d.retired_at, d.revoked_at, d.uptime_s, d.pending_reboot,
 CAST(CASE WHEN hh.device_id IS NULL THEN 0 ELSE 1 END AS SIGNED) AS is_hypervisor_host,
 ps.scan_state AS patch_scan_state, ps.scanned_at AS patch_scanned_at, ps.total_count AS patch_pending_count, ps.critical_count AS patch_critical_count, ps.security_count AS patch_security_count, ps.reboot_pending AS patch_reboot_pending
FROM endpoint_agent_devices d
LEFT JOIN endpoint_agent_settings st ON st.id = 1
LEFT JOIN rmm_hypervisor_hosts hh ON hh.device_id = d.device_id
LEFT JOIN rmm_patch_scans ps ON ps.device_id = d.device_id",
                null, [],
                ['asset_id' => 'the edition\'s assets table', 'client_id' => 'the edition\'s clients table', 'site_id' => 'the edition\'s locations table'],
            ),
            new ReportingView(
                'rpt_device_groups', ReportingView::STORE_CONTROL,
                'one row per device per group it belongs to',
                'Static group membership (a device can be in several groups). Use it to slice any device view by group.',
                'live', true,
                [
                    ['device_id', 'int', 'Device.'], ['client_id', 'int', 'Client of the device.'], ['group_id', 'int', 'Group.'], ['group_name', 'text', 'Name of the group.'],
                ],
                ['device_id', 'group_id'],
                'SELECT d.device_id, d.client_id, g.group_id, g.name AS group_name FROM rmm_group_devices gd JOIN rmm_groups g ON g.group_id = gd.group_id JOIN endpoint_agent_devices d ON d.device_id = gd.device_id',
                null, [], ['device_id' => 'rpt_devices.device_id'],
            ),
            new ReportingView(
                'rpt_device_tags', ReportingView::STORE_CONTROL,
                'one row per device per tag',
                'Tags on devices (manual or automatic). Use it to slice any device view by tag.',
                'live', true,
                [
                    ['device_id', 'int', 'Device.'], ['client_id', 'int', 'Client of the device.'], ['tag_id', 'int', 'Tag.'], ['tag_name', 'text', 'Name of the tag.'],
                    ['tag_source', 'text', 'manual or auto: how the tag got on the device.'],
                ],
                ['device_id', 'tag_id'],
                'SELECT d.device_id, d.client_id, t.tag_id, t.name AS tag_name, dt.source AS tag_source FROM rmm_device_tags dt JOIN rmm_tags t ON t.tag_id = dt.tag_id JOIN endpoint_agent_devices d ON d.device_id = dt.device_id',
                null, [], ['device_id' => 'rpt_devices.device_id'],
            ),
            new ReportingView(
                'rpt_device_health_hourly', ReportingView::STORE_HOT,
                'one row per asset, metric, instance and UTC hour',
                'Hourly roll-up of the numeric metrics (cpu.utilization, memory.utilization, disk.utilization, disk.free_bytes, ...). Kept 14 days by default. Always filter by hour_start.',
                'every check-in (the roll-up is written as samples arrive)', true,
                [
                    ['device_id', 'int', 'Device that owns the asset (the newest device if an asset was re-enrolled).'],
                    ['client_id', 'int', 'Client of that device.'],
                    ['asset_id', 'int', 'Asset the series belongs to. In the PostgreSQL store this is the only key (relate to rpt_devices.asset_id).'],
                    ['metric_name', 'text', 'Metric, for example cpu.utilization or disk.free_bytes.'],
                    ['instance', 'text', 'Instance of the metric (a disk mount point, a CPU core number, ...); empty for whole-machine metrics.'],
                    ['hour_start', 'datetime', 'UTC. Start of the hour.'],
                    ['samples', 'int', 'Number of samples in the hour.'],
                    ['sum_value', 'double', 'Sum of the samples (divide by samples for the mean).'],
                    ['min_value', 'double', 'Smallest sample.'],
                    ['max_value', 'double', 'Largest sample.'],
                    ['avg_value', 'double', 'Mean of the samples (sum_value / samples).'],
                ],
                ['asset_id', 'metric_name', 'instance', 'hour_start'],
                "SELECT dv.device_id, d.client_id, m.asset_id, m.metric_key AS metric_name, m.instance, m.hour_start, CAST(m.samples AS SIGNED) AS samples, m.sum_value, m.min_value, m.max_value,
 m.sum_value / NULLIF(m.samples, 0) AS avg_value
FROM rmm_metric_hourly m JOIN " . self::DEVICE_OF_ASSET . ' dv ON dv.asset_id = m.asset_id JOIN endpoint_agent_devices d ON d.device_id = dv.device_id',
                'SELECT m.asset_id, m.metric_key AS metric_name, m.instance, m.hour_start, m.samples::bigint AS samples, m.sum_value, m.min_value, m.max_value,
 m.sum_value / NULLIF(m.samples, 0) AS avg_value
FROM rmm_metric_hourly m',
                ['device_id', 'client_id'], ['device_id' => 'rpt_devices.device_id', 'asset_id' => 'rpt_devices.asset_id'],
            ),
            new ReportingView(
                'rpt_device_health_daily', ReportingView::STORE_HOT,
                'one row per asset, metric, instance and UTC day',
                'Daily roll-up of the same metrics (from the hourly tier). Kept 400 days by default: use this for anything longer than two weeks.',
                'daily roll-up job (housekeeping); the last day is complete after the next run', true,
                [
                    ['device_id', 'int', 'Device that owns the asset (the newest device if an asset was re-enrolled).'],
                    ['client_id', 'int', 'Client of that device.'],
                    ['asset_id', 'int', 'Asset the series belongs to. In the PostgreSQL store this is the only key (relate to rpt_devices.asset_id).'],
                    ['metric_name', 'text', 'Metric, for example cpu.utilization or disk.free_bytes.'],
                    ['instance', 'text', 'Instance of the metric; empty for whole-machine metrics.'],
                    ['day_start', 'datetime', 'UTC. Midnight UTC of the day.'],
                    ['samples', 'int', 'Number of samples in the day.'],
                    ['sum_value', 'double', 'Sum of the samples.'],
                    ['min_value', 'double', 'Smallest sample.'],
                    ['max_value', 'double', 'Largest sample.'],
                    ['avg_value', 'double', 'Mean of the samples (sum_value / samples).'],
                ],
                ['asset_id', 'metric_name', 'instance', 'day_start'],
                "SELECT dv.device_id, d.client_id, m.asset_id, m.metric_key AS metric_name, m.instance, m.day_start, CAST(m.samples AS SIGNED) AS samples, m.sum_value, m.min_value, m.max_value,
 m.sum_value / NULLIF(m.samples, 0) AS avg_value
FROM rmm_metric_daily m JOIN " . self::DEVICE_OF_ASSET . ' dv ON dv.asset_id = m.asset_id JOIN endpoint_agent_devices d ON d.device_id = dv.device_id',
                'SELECT m.asset_id, m.metric_key AS metric_name, m.instance, m.day_start, m.samples::bigint AS samples, m.sum_value, m.min_value, m.max_value,
 m.sum_value / NULLIF(m.samples, 0) AS avg_value
FROM rmm_metric_daily m',
                ['device_id', 'client_id'], ['device_id' => 'rpt_devices.device_id', 'asset_id' => 'rpt_devices.asset_id'],
            ),
            new ReportingView(
                'rpt_alerts', ReportingView::STORE_CONTROL,
                'one row per alert episode (open and history)',
                'Every alert the alerting engine raised, open or resolved, with its timings. The alert text is not exposed.',
                'live; resolved rows are pruned by the alerting housekeeping', true,
                [
                    ['alert_row_id', 'int', 'Primary key of the episode.'],
                    ['alert_id', 'int', 'The edition\'s alert id for this episode (RivetIT: rmm_alerts.id, which links the alert to its ticket). Relate to the edition\'s alert-to-ticket view.'],
                    ['device_id', 'int', 'Device the alert is about.'],
                    ['client_id', 'int', 'Client of the device when the alert opened.'],
                    ['check_name', 'text', 'The check that alerted (cpu.utilization, disk.c, patch.compliance, ...).'],
                    ['episode', 'int', 'Counter of episodes of the same check on the same device.'],
                    ['severity', 'text', 'warn or fail.'],
                    ['state', 'text', 'open, acknowledged or resolved.'],
                    ['is_open', 'flag', '1 while the episode is not resolved.'],
                    ['opened_at', 'datetime', 'UTC. When the alert opened.'],
                    ['acked_at', 'datetime', 'UTC. When a person acknowledged it; NULL if not.'],
                    ['resolved_at', 'datetime', 'UTC. When it resolved; NULL while open.'],
                    ['resolve_reason', 'text', 'Why it resolved (recovered, manual, ...); NULL while open.'],
                    ['duration_s', 'int', 'Seconds from opened_at to resolved_at, or to now while the episode is open.'],
                    ['time_to_ack_s', 'int', 'Seconds from opened_at to acked_at; NULL if never acknowledged.'],
                ],
                ['alert_row_id', 'alert_id', 'device_id'],
                "SELECT a.meta_id AS alert_row_id, a.alert_id, a.device_id, a.client_id, a.check_key AS check_name, a.episode, a.severity, a.state,
 CAST(CASE WHEN a.state = 'resolved' THEN 0 ELSE 1 END AS SIGNED) AS is_open, a.opened_at, a.acked_at, a.resolved_at, a.resolve_reason,
 CAST(TIMESTAMPDIFF(SECOND, a.opened_at, COALESCE(a.resolved_at, UTC_TIMESTAMP())) AS SIGNED) AS duration_s,
 CAST(TIMESTAMPDIFF(SECOND, a.opened_at, a.acked_at) AS SIGNED) AS time_to_ack_s
FROM rmm_alert_meta a",
                null, [], ['device_id' => 'rpt_devices.device_id'],
            ),
            new ReportingView(
                'rpt_check_status', ReportingView::STORE_CONTROL,
                'one row per device and check (current state)',
                'The current result of every check on every device, with the alerting engine\'s tier and flapping flag. The check detail text is not exposed.',
                'live', true,
                [
                    ['device_id', 'int', 'Device.'], ['client_id', 'int', 'Client of the device.'], ['check_name', 'text', 'The check.'],
                    ['status', 'text', 'ok, warn, fail or unknown.'],
                    ['tier', 'text', 'The alerting engine\'s confirmed tier (ok, warn, fail); NULL if the check has no evaluation state.'],
                    ['flapping', 'flag', '1 while the engine treats the check as flapping (changing state too often).'],
                    ['consecutive_failures', 'int', 'Failures in a row.'], ['consecutive_ok', 'int', 'Successes in a row.'],
                    ['episode', 'int', 'Current episode counter (see rpt_alerts).'],
                    ['last_reported_at', 'datetime', 'UTC. When the device last reported the check.'],
                    ['last_changed_at', 'datetime', 'UTC. When the status last changed.'],
                ],
                ['device_id', 'check_name'],
                'SELECT c.device_id, d.client_id, c.check_key AS check_name, c.status, e.tier, e.flapping, c.consecutive_failures, c.consecutive_ok, c.episode, c.last_reported_at, c.last_changed_at
FROM endpoint_agent_checks c JOIN endpoint_agent_devices d ON d.device_id = c.device_id LEFT JOIN rmm_check_eval e ON e.device_id = c.device_id AND e.check_key = c.check_key',
                null, [], ['device_id' => 'rpt_devices.device_id'],
            ),
            new ReportingView(
                'rpt_check_results', ReportingView::STORE_HOT,
                'one row per recorded check result',
                'The raw check history (every status change report), limited to the last ' . self::CHECK_RESULT_DAYS . ' days. Large: always filter by reported_at and device. For trends use rpt_alerts and rpt_device_health_daily instead. The result detail text is not exposed.',
                'every check-in', true,
                [
                    ['result_id', 'int', 'Primary key of the result.'],
                    ['device_id', 'int', 'Device.'],
                    ['client_id', 'int', 'Client of the device (not in the PostgreSQL store: relate device_id to rpt_devices).'],
                    ['check_name', 'text', 'The check.'],
                    ['status', 'text', 'ok, warn, fail or unknown.'],
                    ['reported_at', 'datetime', 'UTC. When the device reported it.'],
                ],
                ['result_id', 'device_id'],
                'SELECT h.hist_id AS result_id, h.device_id, d.client_id, h.check_key AS check_name, h.status, h.reported_at
FROM endpoint_agent_check_history h JOIN endpoint_agent_devices d ON d.device_id = h.device_id WHERE h.reported_at >= (UTC_TIMESTAMP() - INTERVAL ' . self::CHECK_RESULT_DAYS . ' DAY)',
                "SELECT h.hist_id AS result_id, h.device_id, h.check_key AS check_name, h.status, h.reported_at
FROM endpoint_agent_check_history h WHERE h.reported_at >= (now() - INTERVAL '" . self::CHECK_RESULT_DAYS . " days')",
                ['client_id'], ['device_id' => 'rpt_devices.device_id'],
            ),
            new ReportingView(
                'rpt_patch_compliance', ReportingView::STORE_CONTROL,
                'one row per device that has been (or should be) scanned for updates',
                'Patch state per device: the last scan, how many updates are missing by kind, how long the oldest critical or security update has been missing, and the verdict of the built-in patch.compliance check (which uses the thresholds you configured).',
                'live; the scan runs on the patch scan interval (default daily)', true,
                [
                    ['device_id', 'int', 'Device.'], ['client_id', 'int', 'Client of the device.'],
                    ['scan_state', 'text', 'never, ok, failed, ...'],
                    ['wu_state', 'text', 'State of the operating system\'s updater (n/a where not applicable).'],
                    ['manager', 'text', 'Which update manager was scanned (wua, apt, dnf, ...).'],
                    ['scanned_at', 'datetime', 'UTC. Last successful scan; NULL if never.'],
                    ['last_install_at', 'datetime', 'UTC. Last time an update was installed by the module.'],
                    ['reboot_pending', 'flag', '1 when a reboot is pending after patching.'],
                    ['pending_total', 'int', 'Updates the last scan found missing.'],
                    ['pending_critical', 'int', 'Of those, critical.'], ['pending_security', 'int', 'Of those, security.'],
                    ['pending_optional', 'int', 'Of those, optional.'], ['pending_driver', 'int', 'Of those, drivers.'],
                    ['reboot_required_count', 'int', 'Missing updates that need a reboot.'],
                    ['missing_important_count', 'int', 'Critical or security updates that are available, approved or failed (not declined, not installed).'],
                    ['oldest_missing_important_at', 'datetime', 'UTC. When the oldest of those was first seen; NULL if none.'],
                    ['failed_update_count', 'int', 'Updates whose last install attempt failed.'],
                    ['compliance_status', 'text', 'Status of the patch.compliance check (ok, warn, fail); NULL if the check has not run.'],
                ],
                ['device_id'],
                "SELECT ps.device_id, d.client_id, ps.scan_state, ps.wu_state, ps.manager, ps.scanned_at, ps.last_install_at, ps.reboot_pending, ps.total_count AS pending_total, ps.critical_count AS pending_critical,
 ps.security_count AS pending_security, ps.optional_count AS pending_optional, ps.driver_count AS pending_driver, ps.reboot_required_count,
 CAST(COALESCE(av.important_n, 0) AS SIGNED) AS missing_important_count, av.oldest_important_at AS oldest_missing_important_at, CAST(COALESCE(av.failed_n, 0) AS SIGNED) AS failed_update_count, ck.status AS compliance_status
FROM rmm_patch_scans ps JOIN endpoint_agent_devices d ON d.device_id = ps.device_id
LEFT JOIN (SELECT device_id,
   SUM(CASE WHEN kind IN ('critical', 'security') AND status IN ('available', 'approved', 'failed') THEN 1 ELSE 0 END) AS important_n,
   MIN(CASE WHEN kind IN ('critical', 'security') AND status IN ('available', 'approved', 'failed') THEN first_seen_at END) AS oldest_important_at,
   SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_n FROM rmm_patch_available GROUP BY device_id) av ON av.device_id = ps.device_id
LEFT JOIN endpoint_agent_checks ck ON ck.device_id = ps.device_id AND ck.check_key = 'patch.compliance'",
                null, [], ['device_id' => 'rpt_devices.device_id'],
            ),
            new ReportingView(
                'rpt_patch_updates', ReportingView::STORE_CONTROL,
                'one row per device and update that is or was available',
                'The updates the scans found, with their approval status. Titles are the public update titles.',
                'live; installed rows are pruned after 14 days', true,
                [
                    ['patch_row_id', 'int', 'Primary key.'], ['device_id', 'int', 'Device.'], ['client_id', 'int', 'Client of the device.'],
                    ['update_id', 'text', 'Vendor update id.'], ['kb', 'text', 'KB number or package name, when the vendor has one.'], ['title', 'text', 'Public title of the update.'],
                    ['kind', 'text', 'critical, security, optional, driver or other.'], ['severity', 'text', 'Vendor severity (critical, important, moderate, low, unspecified).'],
                    ['status', 'text', 'available, approved, declined, installing, installed or failed.'],
                    ['reboot_required', 'flag', '1 when installing needs a reboot.'], ['size_bytes', 'int', 'Download size.'],
                    ['released_at', 'datetime', 'UTC. Vendor release time, when known.'],
                    ['first_seen_at', 'datetime', 'UTC. When a scan first saw the update on this device.'], ['last_seen_at', 'datetime', 'UTC. When a scan last saw it.'],
                    ['status_at', 'datetime', 'UTC. When the status last changed.'], ['fail_count', 'int', 'Failed install attempts.'],
                ],
                ['patch_row_id', 'device_id'],
                'SELECT p.id AS patch_row_id, p.device_id, d.client_id, p.update_id, p.kb, p.title, p.kind, p.severity, p.status, p.reboot_required, p.size_bytes, p.released_at, p.first_seen_at, p.last_seen_at, p.status_at, p.fail_count
FROM rmm_patch_available p JOIN endpoint_agent_devices d ON d.device_id = p.device_id',
                null, [], ['device_id' => 'rpt_devices.device_id'],
            ),
            new ReportingView(
                'rpt_patch_history', ReportingView::STORE_HOT,
                'one row per patch action recorded',
                'What the patching module did (approve, install, ...) and the result code. The result detail text is not exposed.',
                'when the action is recorded', true,
                [
                    ['patch_history_id', 'int', 'Primary key.'], ['device_id', 'int', 'Device.'], ['client_id', 'int', 'Client of the device when the action ran.'],
                    ['update_id', 'text', 'Vendor update id.'], ['title', 'text', 'Public title of the update.'],
                    ['action', 'text', 'install, ...'], ['result', 'text', 'success, failed, ...'], ['code', 'text', 'Short result code.'],
                    ['occurred_at', 'datetime', 'UTC. When it happened.'],
                ],
                ['patch_history_id', 'device_id'],
                'SELECT h.id AS patch_history_id, h.device_id, h.client_id, h.update_id, h.title, h.action, h.result, h.code, h.`at` AS occurred_at FROM rmm_patch_history h',
                'SELECT h.id AS patch_history_id, h.device_id, h.client_id, h.update_id, h.title, h.action, h.result, h.code, h."at" AS occurred_at FROM rmm_patch_history h',
                [], ['device_id' => 'rpt_devices.device_id'],
            ),
            new ReportingView(
                'rpt_software_inventory', ReportingView::STORE_CONTROL,
                'one row per device and installed software item (current)',
                'What is installed now. Removed items are not listed (see rpt_software_changes).',
                'live; refreshed when a device reports a changed inventory', true,
                [
                    ['device_id', 'int', 'Device.'], ['client_id', 'int', 'Client of the device.'],
                    ['software_name', 'text', 'Name of the software item.'], ['source', 'text', 'Where it was found (msi, store, apt, rpm, ...).'],
                    ['version', 'text', 'Installed version.'], ['publisher', 'text', 'Publisher / vendor.'],
                    ['installed_on', 'date', 'Install date as the system recorded it, when known.'],
                    ['first_seen_at', 'datetime', 'UTC. When the module first saw it on this device.'], ['last_seen_at', 'datetime', 'UTC. Last inventory that listed it.'],
                ],
                ['device_id', 'software_name', 'source'],
                'SELECT s.device_id, d.client_id, s.name AS software_name, s.source, s.version, s.publisher, s.installed_on, s.first_seen_at, s.last_seen_at
FROM rmm_device_software s JOIN endpoint_agent_devices d ON d.device_id = s.device_id WHERE s.removed_at IS NULL',
                null, [], ['device_id' => 'rpt_devices.device_id'],
            ),
            new ReportingView(
                'rpt_software_changes', ReportingView::STORE_HOT,
                'one row per software install, removal or version change',
                'The software change log. Kept 365 days by default.',
                'when a device reports a changed inventory', true,
                [
                    ['software_change_id', 'int', 'Primary key.'], ['device_id', 'int', 'Device.'],
                    ['client_id', 'int', 'Client of the device (not in the PostgreSQL store: relate device_id to rpt_devices).'],
                    ['software_name', 'text', 'Name of the software item.'], ['source', 'text', 'Where it was found.'],
                    ['change_type', 'text', 'added, removed or updated.'],
                    ['old_version', 'text', 'Version before; NULL for added.'], ['new_version', 'text', 'Version after; NULL for removed.'],
                    ['publisher', 'text', 'Publisher / vendor.'], ['occurred_at', 'datetime', 'UTC. When the change was noticed.'],
                ],
                ['software_change_id', 'device_id'],
                'SELECT h.history_id AS software_change_id, h.device_id, d.client_id, h.name AS software_name, h.source, h.change_type, h.old_version, h.new_version, h.publisher, h.occurred_at
FROM rmm_software_history h JOIN endpoint_agent_devices d ON d.device_id = h.device_id',
                'SELECT h.history_id AS software_change_id, h.device_id, h.name AS software_name, h.source, h.change_type, h.old_version, h.new_version, h.publisher, h.occurred_at FROM rmm_software_history h',
                ['client_id'], ['device_id' => 'rpt_devices.device_id'],
            ),
            new ReportingView(
                'rpt_hypervisor_hosts', ReportingView::STORE_CONTROL,
                'one row per hypervisor host device',
                'Proxmox / Hyper-V hosts that report through the agent. The error text is not exposed.',
                'live; the host reports on its collection interval', true,
                [
                    ['device_id', 'int', 'The host device (rpt_devices.device_id).'], ['client_id', 'int', 'Client of the host.'],
                    ['platform', 'text', 'proxmox or hyperv.'], ['version', 'text', 'Hypervisor version.'], ['cluster_name', 'text', 'Cluster name, when clustered.'],
                    ['quorate', 'flag', '1 when the cluster has quorum, 0 when not, NULL if not clustered.'],
                    ['failed_groups', 'int', 'High-availability groups in a failed state.'], ['vm_count', 'int', 'VMs the host reports.'],
                    ['is_truncated', 'flag', '1 when the host reported more VMs than the module keeps.'],
                    ['last_collected_at', 'datetime', 'UTC. When the agent last collected.'], ['last_report_at', 'datetime', 'UTC. When the server last received a report.'],
                    ['ignored', 'flag', '1 when an administrator excluded the host from alerts.'],
                ],
                ['device_id'],
                'SELECT h.device_id, d.client_id, h.platform, h.version, h.cluster_name, h.quorate, h.failed_groups, h.vm_count, h.truncated AS is_truncated, h.last_collected_at, h.last_report_at, h.ignored
FROM rmm_hypervisor_hosts h JOIN endpoint_agent_devices d ON d.device_id = h.device_id',
                null, [], ['device_id' => 'rpt_devices.device_id'],
            ),
            new ReportingView(
                'rpt_hypervisor_vms', ReportingView::STORE_CONTROL,
                'one row per virtual machine (current state)',
                'The VM inventory of every hypervisor host with its state, size, snapshots and backup status. VM tags and ignore reasons are not exposed.',
                'live; the host reports on its collection interval', true,
                [
                    ['vm_id', 'int', 'Primary key of the VM.'], ['host_device_id', 'int', 'The hypervisor host (rpt_devices.device_id).'], ['client_id', 'int', 'Client of the host.'],
                    ['vm_name', 'text', 'Name of the VM.'], ['vm_type', 'text', 'Kind of guest (qemu, lxc, hyperv).'], ['state', 'text', 'running, stopped, paused, ... or unknown.'],
                    ['state_changed_at', 'datetime', 'UTC. When the state last changed.'], ['node', 'text', 'Cluster node the VM runs on.'],
                    ['cpus', 'int', 'Virtual CPUs.'], ['cpu_pct', 'double', 'CPU use, percent.'],
                    ['mem_bytes', 'int', 'Memory in use.'], ['mem_max_bytes', 'int', 'Memory assigned.'],
                    ['disk_bytes', 'int', 'Disk assigned.'], ['disk_used_bytes', 'int', 'Disk used, when known.'],
                    ['uptime_s', 'int', 'Uptime in seconds.'], ['is_template', 'flag', '1 for a template.'],
                    ['snapshot_count', 'int', 'Snapshots.'], ['oldest_snapshot_at', 'datetime', 'UTC. Age of the oldest snapshot.'],
                    ['last_backup_at', 'datetime', 'UTC. Last backup.'], ['last_backup_result', 'text', 'ok or failed.'], ['backup_covered', 'flag', '1 when a backup job covers the VM.'],
                    ['guest_agent', 'flag', '1 when the guest agent responds.'], ['replication', 'text', 'Replication state, when used.'],
                    ['ignored', 'flag', '1 when an administrator excluded the VM from alerts.'],
                    ['first_seen_at', 'datetime', 'UTC. First seen.'], ['last_seen_at', 'datetime', 'UTC. Last seen.'], ['missing_since', 'datetime', 'UTC. When the VM stopped being reported; NULL while present.'],
                ],
                ['vm_id', 'host_device_id'],
                'SELECT v.vm_id, v.device_id AS host_device_id, d.client_id, v.name AS vm_name, v.type AS vm_type, v.state, v.state_changed_at, v.node, v.cpus, v.cpu_pct, v.mem_bytes, v.mem_max_bytes, v.disk_bytes, v.disk_used_bytes,
 v.uptime_s, v.template AS is_template, v.snapshot_count, v.oldest_snapshot_at, v.last_backup_at, v.last_backup_result, v.backup_covered, v.guest_agent, v.replication, v.ignored, v.first_seen_at, v.last_seen_at, v.missing_since
FROM rmm_hypervisor_vms v JOIN endpoint_agent_devices d ON d.device_id = v.device_id',
                null, [], ['host_device_id' => 'rpt_devices.device_id'],
            ),
            new ReportingView(
                'rpt_change_events', ReportingView::STORE_HOT,
                'one row per recorded configuration change',
                'The change monitor\'s records as counts and types only: which watch fired, what kind of change, when. The changed values, paths, summaries, who and which program are NOT exposed.',
                'when a device reports changes', true,
                [
                    ['change_id', 'int', 'Primary key.'], ['device_id', 'int', 'Device.'],
                    ['client_id', 'int', 'Client of the device when the change was recorded (the device\'s current client if the record has none).'],
                    ['occurred_at', 'datetime', 'UTC. When the agent detected the change.'], ['received_at', 'datetime', 'UTC. When the server received it.'],
                    ['kind', 'text', 'registry, file, group or task.'], ['watch', 'text', 'Id of the configured watch that fired (win.run_keys, lnx.sudoers, ...).'],
                    ['change_type', 'int', '1 added, 2 modified, 3 removed.'], ['change_name', 'text', 'added, modified or removed.'],
                    ['flags', 'int', 'Bit 1: a value was redacted; bit 2: a value was truncated.'],
                ],
                ['change_id', 'device_id'],
                "SELECT e.change_id, e.device_id, COALESCE(e.client_id, d.client_id) AS client_id, e.occurred_at, e.received_at, e.kind, e.watch, e.change_type,
 CASE e.change_type WHEN 1 THEN 'added' WHEN 3 THEN 'removed' ELSE 'modified' END AS change_name, e.flags
FROM endpoint_agent_change_records e LEFT JOIN endpoint_agent_devices d ON d.device_id = e.device_id",
                "SELECT e.change_id, e.device_id, e.client_id, e.occurred_at, e.received_at, e.kind, e.watch, e.change_type,
 CASE e.change_type WHEN 1 THEN 'added' WHEN 3 THEN 'removed' ELSE 'modified' END AS change_name, e.flags
FROM endpoint_agent_change_records e",
                [], ['device_id' => 'rpt_devices.device_id'],
            ),
            new ReportingView(
                'rpt_audit_events', ReportingView::STORE_CONTROL,
                'one row per audit event (summary only)',
                'WHO-did-WHAT-WHEN at the level of event type and action. The summary, metadata, IP address, user agent and entity id are NOT exposed. Not client-scoped: audit events do not carry a client, so this view is for administrators only and must not be granted to a per-client role.',
                'live', false,
                [
                    ['audit_id', 'int', 'Primary key.'], ['event_type', 'text', 'Event type (user.login, asset.update, ...).'], ['action', 'text', 'create, update, delete, ...'],
                    ['entity_type', 'text', 'The kind of thing the event was about.'], ['actor_user_id', 'int', 'The user who did it (an id, not a name); NULL for the system.'],
                    ['created_at', 'datetime', 'When it happened, in the database server\'s time zone (UTC when the server runs in UTC, which is the recommended setup).'],
                ],
                ['audit_id'],
                'SELECT a.audit_id, a.event_type, a.action, a.entity_type, a.actor_user_id, a.created_at FROM audit_events a',
                null, [], [],
                'No client_id: audit events are global.',
            ),
        ];
    }
}
