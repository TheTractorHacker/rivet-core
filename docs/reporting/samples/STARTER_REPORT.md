# Starter report: build it in about 30 minutes

Six pages from the views in the [data dictionary](../DATA_DICTIONARY.md), using the queries and measures in this folder. Do [POWER_BI.md](../POWER_BI.md) sections 3 to 5 first (account, connection, model). **Not verified in Power BI Desktop**: visual names follow Power BI's standard visuals; if one is named differently in your version, pick the closest.

Conventions: `[Measure]` = a measure from [measures.dax](measures.dax). Slicers use *Dropdown* style unless noted. Put **Client** and **Date** slicers on every page and use *View > Sync slicers* to share them.

## Before the pages (10 minutes)

1. Parameters `ServerName`, `DatabaseName`; load all 17 views with the files in `power-query/mysql-mariadb/` (or the 11 control views from MariaDB / MySQL and the 6 hot views from `power-query/postgresql/`).
2. *Enter data*: `ClientNames` (`client_id`, `client_name`) with your clients. Add `Clients.pq`, merge the names in, relate `Clients[client_id]` to `rpt_devices[client_id]`.
3. Relationships as in POWER_BI.md section 5; `DimDate` as a calculated table.
4. Paste the measures. Set `[Device Availability %]`, `[Patch Compliance %]`, `[Avg CPU %]`, `[SLA ...]` to *Percentage* format with 1 decimal.
5. For the history tables, limit the loaded range with the incremental refresh filter (POWER_BI.md section 7): 90 days of hourly health and 400 of daily is plenty.

## Page 1: Fleet overview

| Visual | Fields |
|---|---|
| Card x5 | `[Active Devices]`, `[Online Devices]`, `[Active Devices Last 7 Days]`, `[Patch Compliance %]`, `[Open Alerts]` |
| Donut | Legend `rpt_devices[status]`, Values `[Active Devices]` (filter out `retired`, `revoked`) |
| Bar (horizontal) | Axis `rpt_devices[os]`, Values `[Active Devices]` |
| Column | Axis `rpt_devices[agent_version]`, Values `[Active Devices]` ("who is behind on the agent") |
| Table | `rpt_devices[hostname]`, `[status]`, `[last_checkin_at]`, `[os_version]`; visual filter `status` is `offline` or `stale`; sort by `last_checkin_at` |
| Slicers | `Clients[client_name]`, `rpt_device_groups[group_name]`, `rpt_device_tags[tag_name]`, `rpt_devices[os]` |

## Page 2: Health

| Visual | Fields |
|---|---|
| Line | Axis `rpt_device_health_daily[day_start]` (date), Values `[Avg CPU %]` and `[Peak CPU %]` |
| Line | Same axis, Values `[Avg Memory %]` |
| Area | Axis `day_start`, Values `[Disk Used Bytes]` (format as GB), Legend none |
| Card | `[Storage Growth Per Day (bytes)]` (format GB/day), `[Device Availability %]` |
| Matrix | Rows `rpt_devices[hostname]`, Columns `day_start` (day), Values `[Peak CPU %]`, conditional formatting (red above 90) |
| Slicers | `rpt_device_health_daily[day_start]` (Between), `rpt_devices[hostname]` |

Use the **daily** table for anything wider than two weeks; for the "last 48 hours" detail use `rpt_device_health_hourly` with a *Relative time* slicer on `hour_start`.

## Page 3: Alerts

| Visual | Fields |
|---|---|
| Card x4 | `[Alerts]`, `[Mean Time To Resolve (h)]`, `[Mean Time To Acknowledge (h)]`, `[Alerts per 100 Devices]` |
| Column | Axis `rpt_alerts[opened_at]` (date hierarchy: day), Values `[Alerts]`, Legend `rpt_alerts[severity]` |
| Bar | **Top noisy checks:** Axis `rpt_alerts[check_name]`, Values `[Alerts]`; visual filter *Top N*: 10 by `[Alerts]` |
| Table | **Open alerts:** `rpt_devices[hostname]`, `rpt_alerts[check_name]`, `severity`, `opened_at`, `duration_s` (as hours); visual filter `is_open` = 1; sort by `duration_s` descending |
| Card | `[Flapping Checks]`, `[Failing Checks]` (from `rpt_check_status`) |
| Slicers | `Clients[client_name]`, `rpt_alerts[severity]`, `rpt_alerts[opened_at]` (Between) |

## Page 4: Patching

| Visual | Fields |
|---|---|
| Gauge | Value `[Patch Compliance %]`, target 95% |
| Card x3 | `[Devices Missing Important Updates]`, `[Failed Update Installs]`, `[Overdue Days]` (change it with a *What-if parameter*: *Modeling > New parameter* from 1 to 60) |
| Stacked bar | Axis `rpt_devices[hostname]` (Top 15 by missing), Values `rpt_patch_compliance[pending_critical]`, `[pending_security]`, `[pending_optional]` |
| Table | Devices with `missing_important_count` > 0: `hostname`, `missing_important_count`, `oldest_missing_important_at`, `compliance_status` |
| Column | **Update installs per day:** `rpt_patch_history[occurred_at]` day, `COUNT` of `patch_history_id`, Legend `result` |
| Table | `rpt_patch_updates[title]`, `kind`, `status`, count of devices (what is missing most widely) |

## Page 5: Software and virtualization

| Visual | Fields |
|---|---|
| Bar | **Most installed software:** Axis `rpt_software_inventory[software_name]` (Top 15), Values count of `device_id` |
| Table | Outdated check: `software_name`, `version`, count of devices (spot mixed versions of one product) |
| Column | **Install/uninstall activity:** `rpt_software_changes[occurred_at]` day, count of `software_change_id`, Legend `change_type` |
| Card x3 | `[VMs Without Backup]`, `[Old Snapshots (7d+)]`, count of `rpt_hypervisor_vms[vm_id]` |
| Stacked column | Axis `rpt_hypervisor_hosts[platform]` / `cluster_name`, Legend `rpt_hypervisor_vms[state]`, Values count of VMs |
| Table | VMs: `vm_name`, `state`, `last_backup_at`, `last_backup_result`, `snapshot_count`, `oldest_snapshot_at`, `missing_since` |

## Page 6: Changes and audit

| Visual | Fields |
|---|---|
| Column | `rpt_change_events[occurred_at]` day, `[Config Changes]`, Legend `change_name` |
| Bar | Axis `rpt_change_events[watch]`, Values `[Config Changes]` (which watches fire most) |
| Table | Devices by `[Config Changes]` (Top 10) |
| Table | **Audit summary (admin-only dataset, hide this page from client audiences):** `rpt_audit_events[created_at]` day, `event_type`, `action`, count |

The change monitor exposes counts and types only; the values that changed stay in RivetIT's own change page. `rpt_audit_events` has no `client_id`: remove this page from any report published to a client.

## Page 7 (edition): Tickets and SLA

When the edition ships its views (phase 4), add `rpt_tickets` and the measures in [measures-edition.dax](measures-edition.dax): `[Tickets per 100 Devices]`, `[Mean Time To Resolve Tickets (h)]`, `[SLA Breach Rate]` as cards; tickets per week as a column; a table of breached tickets.

## Finish

* Set page names, hide the key columns, turn *Sync slicers* on.
* *View as* each client role (POWER_BI.md "Row-level security per client") on every page.
* Publish, set the gateway and scheduled refresh (POWER_BI.md section 8).
