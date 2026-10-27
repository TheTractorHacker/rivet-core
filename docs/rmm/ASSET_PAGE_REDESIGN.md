# RMM asset page and fleet dashboard: redesign spec

Status: DRAFT for owner review, 2026-10-07. Design only: nothing here is built. Companion to [FEATURES.md](FEATURES.md) (what the RMM does) and the [extraction design](../design/endpoint-module-extraction.md) (sections 9 to 13: gaps, phases, module switch, capacity). Phases and issue numbers are the design's: P0 = [#60 to #68](https://github.com/TheTractorHacker/rivet-core/issues/60), P1 = [#69](https://github.com/TheTractorHacker/rivet-core/issues/69), P2 = [#70](https://github.com/TheTractorHacker/rivet-core/issues/70), P3 = [#71](https://github.com/TheTractorHacker/rivet-core/issues/71), P4 = [#72](https://github.com/TheTractorHacker/rivet-core/issues/72), P5 = [#73](https://github.com/TheTractorHacker/rivet-core/issues/73), P6 = [#74](https://github.com/TheTractorHacker/rivet-core/issues/74), P7 = [#75](https://github.com/TheTractorHacker/rivet-core/issues/75), P8 = [#76](https://github.com/TheTractorHacker/rivet-core/issues/76).

**Interactive mockup:** [docs/rmm/mockups/asset-page.html](mockups/asset-page.html) (a single file, open it in a browser; fonts load from Google Fonts when online and fall back to system fonts) and the published copy at <https://claude.ai/artifact/25yy4zF4THWCP5GmAvhZFv> (private; share it from its Share menu). The mockup has two devices with fictional data (a healthy Windows server and a Linux database box with a failing disk check), an online / offline / never checked in / module off switch, an edition switch (RivetIT with Metrics, RivetMSP without), a role switch (administrator, technician, read-only), a "Patching sub-switch" checkbox, a "Phase badges" checkbox that labels each element with the phase that delivers it, light and dark themes, and a second view for the fleet dashboard. Everything in it is sample data.

## 1. What exists today (verified in RivetIT `origin/beta` 4ba51d352 and RivetMSP `beta`)

| Surface | Where | What it shows | Notes for the redesign |
|---|---|---|---|
| Asset page RMM card | `agent/asset_details.php` (about line 733) in both editions | A tab strip for any asset with an `asset_rmm_links` row: RMM Overview, Hardware, Software, Services, Monitoring (checks), Performance (RivetIT only), Patches, Tickets, Alerts, Scripts. Header has Connect, Reboot, Run Command (Reboot and Run Command only for Tactical RMM links) | Built for vendor RMMs (Tactical, Level, Action1, Sophos). For a RivetIT agent link the card shows the cached link columns only; the real agent detail is on a separate page, reached by an "Agent device" button. |
| Agent device page | `agent/rmm_agent_device.php`, RivetIT only | Status, Inventory (blob), "Latest health sample" as plain numbers, Checks table, Performance (the Metrics tab embedded), Maintenance jobs (form plus history with inline output), MeshCentral node mapping | No gauges, no polling, plain tables, Windows icon hard-coded, timestamps in UTC. RivetMSP has no such page. |
| Metrics tab | `agent/includes/asset/metrics_tab.php`, `js/asset_metrics.js`, `js/chart_theme.js`; RivetIT only | One pane of Chart.js charts per metric group, capability-aware (a chart that cannot exist is absent, an offline banner, empty-state reasons), range pills 1h, 6h, 24h, 7d, 30d, 90d, one batch JSON request per range, `null` shown as a gap | Good base. Reuse it, do not rewrite it. It has no event markers, thresholds, polling or table view. |
| RMM dashboard | `agent/rmm_dashboard.php` (about 980 lines), both editions | Online / offline / managed / alerts / tickets / script-run tiles, "Fleet Health" table (CPU, RAM, disk pills, flags), patch compliance card, alert volume line chart, alerts by severity donut, noisiest assets and departments | Built on `asset_rmm_links` cached columns (`rmm_cpu_percent`, `rmm_ram_percent`, `rmm_disk_percent`, `rmm_needs_reboot`, `rmm_last_boot`, `rmm_maintenance_mode`, `rmm_patches_pending`). The agent's check-in writes the same columns, so the dashboard already works for agent devices in both editions. |
| RMM assets list | `agent/rmm_assets.php` | Status tiles, integration and status filters, table of hostname, department, OS, user, last seen, last sync | No health columns, no sparklines, no bulk actions. |
| Alerts, checks, scripts, network | `agent/rmm_alerts.php`, `rmm_checks.php`, `rmm_scripts.php`, `rmm_script_run.php`, network page | Alert list with acknowledge; check policies page that pushes Tactical checks; script library; Sophos network devices | Vendor-RMM oriented. The agent's own checks are one global list in Administration. |
| Charting | `plugins/chart.js/chart.umd.min.js` (Chart.js UMD, loaded `defer` by `includes/footer.php`) in both editions; `js/chart_theme.js` reads the app's `--if-*` tokens | Line, bar, donut charts | No ApexCharts, ECharts, uPlot or sparkline library. Gauges and sparklines must be inline SVG or Chart.js. |
| Design tokens | `css/itflow_design.css`: `--if-bg #eef2f2`, `--if-surface`, `--if-border`, `--if-ink #16232a`, `--if-muted`, `--if-primary` (teal, per-company accent), IBM Plex Sans and Mono, 12 px and 8 px radii, dark surface about `#14201f`, motion tokens with a reduced-motion override | The redesign adds no new global tokens except the status and series colours below. |
| Date range | `includes/date_range_picker.php`, `js/date_range_picker.js`, `RivetCore\Ui\DateRange` | Presets Today, Yesterday, Last 7/14/30/90 days, weeks, months, quarters, years, Next 7/30, All time, Custom (Litepicker) | Date-granular. There is no "last hour" or "last 24 hours" preset. |

### 1.1 What the metrics subsystem holds (RivetIT)

Tables `device_metric_defs`, `device_metric_instances`, `device_metric_samples` (PK `asset_id, metric_id, instance_id, sampled_at`, UTC), `device_metric_rollups` (`bucket` hour or day, `min_value`, `max_value`, `sum_value`, `sample_count`), plus rollup and collection state. `MetricQueryService` picks raw samples up to 48 h, hour rollups up to 90 d, day rollups beyond, caps at 1,000 points per series (hard cap 5,000) and 200 series.

Series the agent feeds (from `Checkin::samplesFor`): `cpu.utilization`, `memory.utilization`, `disk.utilization` per mount, `network.rx_bytes_per_s` and `network.tx_bytes_per_s` (one `total` instance, all adapters summed, agent bits converted to bytes), `system.uptime_seconds`, `system.pending_reboot`, `memory.total_bytes`, `disk.total_bytes` and `disk.free_bytes` per mount. Not fed by the agent: per-core, IOPS, latency, GPU, battery, temperatures (the registry has some of these for vendor RMMs).

RivetMSP has no Metrics subsystem: no `src/Metrics`, no `device_metric_*` tables, and Phase 0 binds the null metric sink. Until P1 (decision D5) the MSP asset page can show only the **latest reading** (from `endpoint_agent_devices.last_metrics_json` and the `asset_rmm_links` cached columns), never a history.

### 1.2 Findings that contradict or refine the design assumptions

1. **The shared DateRange picker has no sub-day presets.** The requested presets (last hour, 24 h, 7 d, 30 d) are not in `RivetCore\Ui\DateRange`; its smallest preset is "Today" and the custom range is by day. The Metrics tab uses its own pill selector (1h, 6h, 24h, 7d, 30d, 90d). Section 5.3 proposes how to reconcile.
2. **There is no check history.** `endpoint_agent_checks` keeps one row per `(device, check_key)`: the latest status, detail, counters and `last_changed_at`. A "spark history" per check is not possible from stored data today (section 5.5).
3. **There is no link-speed or per-adapter data**, so a network "gauge" has no maximum. Network is a tile with a bar relative to the 24 h peak, not a gauge.
4. **There is no agent latency metric.** What exists is freshness: `last_checkin_at` against the expected interval (`next_check_in_s`, offline after `offline_after_s` = 900 s).
5. **The agent device UI is a separate page in RivetIT and does not exist in RivetMSP**, and the asset page RMM card is vendor-RMM shaped. The redesign folds the agent detail into the asset page in both editions (decision U1).
6. **The device page hard-codes a Windows icon** and its script runner is PowerShell-only; Linux scripts do not exist before P2 (`shell` jobs). The redesign must not offer "Run script" on a Linux device in Phase 0.
7. **Time zones are inconsistent on one page.** The device page prints UTC; the charts convert to the browser's zone. The redesign shows local time and gives UTC on hover or in the data table.
8. **No polling exists in the RMM UI** (only a 5 s refresh on `rmm_script_run.php` and `location.reload()` after actions). Live updates are new and must be budgeted (section 7.2).
9. **Job output is rendered inline** in the device page for up to 15 jobs (up to 64 KiB each). The redesign loads output on demand.
10. **ROADMAP and design section 10 disagree on Linux timing** (Phase 0 "Linux agent" versus Phase 1 "productised"). This spec treats the Linux agent as P0 and packaging as P1; see FEATURES.md.

## 2. Design principles

1. **Summary first, detail on demand.** The strip and the gauges answer "is this device healthy right now" in under two seconds; graphs, checks and jobs answer "why".
2. **Server-rendered snapshot, small JSON for the rest.** The page loads complete without JavaScript charts; live data is a tiny polled JSON document. No WebSockets or SSE (PHP-FPM workers would be held open).
3. **Missing is not zero.** A metric the agent could not read, a device that never reported and a subsystem that does not exist each have their own look.
4. **State is never colour alone.** Every status has an icon and a word; every chart has a data table; series use the validated palette (section 8).
5. **The module switch is respected everywhere.** Module off means the asset page renders as a plain asset.
6. **Core ships data, editions ship markup** (ADR-010 decision 4). Core adds read models; each edition renders its own partials.
7. **Reuse before rewrite.** Chart.js, `chart_theme.js`, `asset_metrics.js`, the metrics JSON endpoint, `render_stat_card`, the date picker and the permission helpers stay.

## 3. Information architecture of the asset page

```
Breadcrumb: Client / Assets / DEMO-WIN-SRV01
+----------------+---------------------------------------------------------------+
| Asset rail     | HEALTH STRIP (card)                                           |
| (existing,     |  status dot + name + status pill + OS chip        [actions]   |
|  kept)         |  Last seen | OS | Agent | Uptime | Client / site              |
|  Type, make,   |  tags ... [+ Tag]                                             |
|  serial,       |  (banner: offline / module off / no metrics edition)          |
|  warranty,     +---------------------------------------------------------------+
|  assignment,   | TABS  Overview | Inventory | Software | Jobs | [Patches] |    |
|  custom fields |       Activity || Tickets | Documents | Credentials | Warranty |
|  (P2), linked  +---------------------------------------------------------------+
|  items         | OVERVIEW                                                      |
|                |  LIVE HEALTH: CPU . Memory . Disk per volume . Network .      |
|                |               Uptime . Agent contact                          |
|                |  PERFORMANCE: range selector + 4 charts (CPU, memory,         |
|                |               disk per volume, network) + event markers       |
|                |  ALERTS (open + recent)        | REMOTE ACCESS                |
|                |  CHECKS table (status, last result, last change, trend,alert) |
+----------------+---------------------------------------------------------------+
```

Decisions:

* **One page, tabs, in both editions.** The agent device page's content moves into the Overview, Inventory, Jobs and Activity tabs. `agent/rmm_agent_device.php` stays as a redirect to `asset_details.php?asset_id=...&tab=overview` for bookmarks (RivetIT).
* **The existing vendor-RMM tabs stay** for assets linked to Tactical, Level and so on: the new Overview is used when the asset has a `rivetit_agent` link; vendor links keep today's card. An asset with both shows the agent view and a "Also managed by Tactical RMM" chip that opens the vendor card.
* **Asset (non-RMM) tabs are retained** after a separator: Tickets, Documents, Credentials, Warranty and the existing others. With the module off these are the only tabs.
* **Tab deep links** use the existing hash convention (`#rdt-...` ids are kept for vendor tabs; new ids are `#rmm-overview`, `#rmm-inventory`, `#rmm-software`, `#rmm-jobs`, `#rmm-patches`, `#rmm-activity`).
* **Patches** is a tab only when the `patching` sub-switch is on (P4); the dashboard shows the patch tile under the same condition.

### 3.1 Header health strip

Status dot (colour plus the pill text), device name, status pill (Online, Online with N critical alerts, Offline, Status frozen, Waiting for first check-in), platform chip (Windows or Linux; macOS later), then quick actions, then facts: last seen (relative, absolute on hover), OS and architecture, agent version and ring, uptime (with a "Reboot pending" pill when set), client and site. Then tags with "+ Tag" (P1).

Quick actions: Run script, Reboot, Remote access (menu: Remote desktop via MeshCentral; Terminal and Files disabled with "Phase 6"), Open ticket (prefilled with the device and its open alerts), More menu (Collect inventory now, Rotate credential, Transfer to another client, Retire device, Open in RMM administration). Reboot always opens an in-page confirmation dialog (destructive, sent at most once). Buttons that the role or device cannot use are disabled with a reason in the tooltip and as visible text on narrow screens, never hidden except administrator-only items for non-administrators.

## 4. Element specification

Legend for **Refresh**: *render* = server-rendered once per page view; *live* = the small JSON document of section 7.2; *series* = the metrics batch request; *lazy* = fetched on demand. **Perm** uses the Core abilities of design section 2.2: `view` = `rmm.device.view`, `run` = `rmm.job.run_saved` / `rmm.job.reboot`, `script` = `rmm.job.run_script`, `remote` = `rmm.remote.launch`, `admin` = `rmm.admin`. Module-only logins can view but never run or remote.

### 4.1 Asset page

| # | Element | Data source (RivetIT; RivetMSP differences noted) | Refresh | Graceful and empty states | Perm | Phase |
|---|---|---|---|---|---|---|
| A1 | Health strip: dot, name, status pill, OS chip, facts | `RmmReadModel::deviceSummary()`: `endpoint_agent_devices` (status derived by `Devices::status`: online, offline, stale, never), `asset_rmm_links.rmm_status` | render + live (status, last seen, uptime) | Never: grey dot, "Waiting for first check-in", facts say "not reported yet". Offline: red dot, banner with last check-in, gauges dimmed. Stale: grey, "last seen N days ago". Module off: "Status frozen" pill and info banner. | view | P0 |
| A2 | Quick actions | `TechnicianActions` (submit job, reboot, launch remote), existing `/agent/post/rmm_agent.php` | click | Disabled with reason: role, device never checked in, revoked or retired, Mesh node not mapped, Linux has no script jobs before P2. Offline allows queueing (jobs are offered until `expires_at`); remote offers "launch anyway". | run, script, remote, admin | P0 |
| A3 | Tags and "+ Tag" | Core tables `rmm_tags`, `rmm_device_tags` | render | Hidden until P1. | run | P1 |
| A4 | Asset rail (type, make, model, serial, purchase, warranty, assignment, linked items) | Existing asset data | render | Unchanged. Collapsible on small screens. | existing | exists |
| A5 | Custom fields in the rail | `rmm_custom_field_values` | render | Hidden until P2. | existing | P2 |
| A6 | Banners (offline, module off, no-metrics edition, maintenance window) | state + `RmmState` + edition capability flag; maintenance windows from alert rules | render + live | One banner at a time, most severe first. Maintenance window banner arrives with P3. | view | P0 (maintenance P3) |

### 4.2 Overview: Live health (gauges)

Gauges are inline SVG, 270 degree arcs, value in the centre, label, caption, and a status pill (icon plus word) below. Bands are display bands (warning at 80%, critical at 95% for CPU and memory; 80% and 90% for disks, matching the shipped disk check). They are UI defaults until P3 adds per-device thresholds; tick marks on the arc show the band edges. The gauge `aria-label` reads "CPU 14 percent, OK".

| # | Element | Data source | Refresh | Graceful and empty states | Phase |
|---|---|---|---|---|---|
| G1 | CPU gauge | `last_metrics_json.cpu_pct` (also `asset_rmm_links.rmm_cpu_percent`) | live | No value: dashed empty ring and "no data", pill "No data" (never 0). Offline: dimmed with "last reading 2 h 14 m ago". | P0 |
| G2 | Memory gauge | `last_metrics_json.mem_pct`; caption from inventory `memory_total_bytes` | live | same | P0 |
| G3 | One gauge per volume | `last_metrics_json.disk[].used_pct` (+ `inventory_json.disks[]` for sizes); at most 32 volumes (agent cap), the first 4 shown, the rest in "N more volumes" which expands | live | same; a volume that disappears is dropped on the next inventory | P0 |
| G4 | Network tile (receive, send, bar against 24 h peak) | `last_metrics_json.net_rx_bps/net_tx_bps` (bits per second in the sample; shown as Mbit/s) and the 24 h peak from the series | live | "Link speed is not reported" caption; no bar when there is no 24 h history (MSP) | P0 |
| G5 | Uptime and last boot | `endpoint_agent_devices.uptime_s`, `pending_reboot`; `asset_rmm_links.rmm_last_boot` | live | Offline: "unknown", still shows last boot. Reboot pending is a warning pill. | P0 |
| G6 | Agent contact | `last_checkin_at` against `next_check_in_s` and `offline_after_s`; three-step bar (on time, late over 330 s, overdue over 900 s) | live (ticks locally between polls) | Never: not shown (the empty state replaces the row). | P0 |
| G7 | Per-core CPU, GPU, battery, temperatures | not collected | n/a | Absent from the DOM. | Not planned |

### 4.3 Overview: Performance (graphs)

Four chart cards in a two-column grid (one column below 1,100 px): **CPU** (single series, area), **Memory** (single series, area), **Disk used per volume** (one line per volume, warn and crit threshold lines at 80% and 90% matching the shipped check), **Network throughput** (receive and send, Mbit/s, auto axis). Chart.js (existing, via `chart_theme.js`) or inline SVG; the mockup draws SVG so it is self-contained.

| # | Feature | Specification | Phase |
|---|---|---|---|
| C1 | Range selector | Last hour, 24 hours, 7 days, 30 days, Custom (two dates). Resolution follows `MetricQueryService::chooseResolution`: raw samples up to 48 h, hourly rollups up to 90 d, daily beyond. The card states the resolution under each chart ("Raw 5-minute samples" or "Hourly rollup, peak in tooltip"). See 5.3 for the picker. | P0 |
| C2 | Hover and keyboard | One crosshair for all series with a single tooltip listing each series value (value first, name second); left and right arrow keys move through points when the chart has focus; Escape hides. Tooltip text is set with `textContent`. | P0 |
| C3 | Threshold lines | Disk chart: warn 80% and crit 90%, labelled with text. CPU and memory: none in P0. Per-device and per-check thresholds from the alert rules arrive in P3, drawn as labelled dashed lines. | P0 (disk), P3 (others) |
| C4 | Event markers | A lane above the plot with three shapes (not colour alone): triangle = alert opened or resolved, diamond = job run, square = reboot. Each has a title and a dashed guide line. Sources: `rmm_alerts` (created, resolved), `endpoint_agent_jobs` (finished), uptime resets (`system.uptime_seconds` drops or `rmm_last_boot`). Markers load with the series request, capped at 50 per range. | P0 |
| C5 | Offline band | A shaded band from the last sample to now with "Offline since HH:MM". Lines break at gaps (`null`), never interpolate. | P0 |
| C6 | Peak preservation | For hour rollups the tooltip also shows the peak in the hour (`max_value`), so a 5-minute CPU spike is not averaged away on 7 d and 30 d views. | P0 |
| C7 | Data table | Each card has a "Data table" disclosure: latest, average, peak, lowest per series (a text alternative; contrast relief for the lighter series colours). | P0 |
| C8 | MSP without Metrics | The card is replaced by an explanation: "No metric history in this edition yet. Current readings are in Live health." No broken or empty charts. | P0 (MSP), history P1 |
| C9 | Capability awareness | Keep the Metrics tab behaviour: a chart for a series the device cannot produce is absent from the DOM; "supported but nothing collected yet" has its own empty state with the reason. | P0 |
| C10 | More charts | Per-process, temperatures, IOPS, latency: not collected, absent. Ping and probe latency charts arrive with the probes in P3. | P3 |

### 4.4 Overview: Alerts, remote access, checks

| # | Element | Data source | Refresh | States | Perm | Phase |
|---|---|---|---|---|---|---|
| L1 | Alerts card: open (with severity, age, ticket link, Acknowledge) and recently resolved (last 5, resolved after N) | `rmm_alerts` by `asset_id` and integration; ticket via the edition's alert-to-ticket link | render + live (count and worst severity) | None open: green "None open". Acknowledge disabled for read-only. "All alerts for this device" links to the alert list filtered. Maintenance suppression shown from P3. | view; acknowledge needs the existing alert permission | P0 |
| L2 | Remote access card: Remote desktop button, Terminal and Files (disabled "Phase 6"), recent sessions | `endpoint_agent_mesh_nodes`, `rmm_remote_sessions` | render | No node mapped: button disabled with "An administrator maps this device to a MeshCentral node". Offline: note, launch still possible with confirmation. Module-only logins: disabled. | remote | P0 (terminal and files P6) |
| L3 | Checks table: status pill (icon and word), check key and type, last result text, "steady for / since" from `last_changed_at`, trend, alert id | `endpoint_agent_checks` (status, detail, `consecutive_failures`, `last_reported_at`, `last_changed_at`, `alert_id`) | render + live | Failing rows get a left severity stripe. Offline: statuses shown as "Unknown" with the last result text. Empty: "No check result yet" with the cause (device never checked in, or no checks configured). | view | P0 |
| L4 | Check trend | Disk checks: sparkline of the matching `disk.utilization` series (24 h, hourly points) in RivetIT; other checks: a 24-result strip needs stored history (5.5). Until then show only "steady for N" text. | render | MSP without metrics: "no history". | view | P0 (disk, RivetIT), strip proposed |
| L5 | Check origin ("from policy X, inherited from client") and per-device override | policy resolver | render | Hidden until P2. | view | P2 |

### 4.5 Other tabs

| # | Tab and element | Data source | Refresh | States | Perm | Phase |
|---|---|---|---|---|---|---|
| T1 | **Inventory**: Hardware card (make, model, serial, CPU, memory), Operating system card (name, version, architecture, logged-in user, last boot, pending reboot), Disks table (volume, filesystem, size, free, used bar), Network adapters (name, MAC, addresses) | `endpoint_agent_devices.inventory_json` (up to 64 KiB), parsed server-side | render | "from the last inventory, N h ago"; no inventory yet: empty state with "Collect inventory now". Unknown keys are ignored. | view | P0 |
| T2 | Inventory: BIOS, TPM, GPU, normalised hardware | `rmm_inventory_hw` | render | Shown as a "Phase 1" placeholder row in the mockup only; in the product the rows simply appear when data exists. | view | P1 |
| T3 | Inventory: Disks and SMART health | not planned | | absent | | Not planned |
| T4 | **Software**: searchable table (name, version, publisher, change history: installed, upgraded, removed) | `rmm_inventory_software`, `rmm_inventory_history` | render, search is client-side up to 500 rows, server-side beyond (pagination 100) | Tab hidden until P1. No data: "Software inventory has not run yet." | view | P1 |
| T5 | Inventory: Services list (state, start type) with start/stop (P6) | inventory + service jobs | lazy | hidden until P6 | view (list), script (actions) | P6 |
| T6 | **Jobs**: table (job, state pill, started, duration, by, exit code), "View output" (lazy), "Re-run", "Run script" (dialog: saved script, timeout, destructive confirmation) | `endpoint_agent_jobs` (last 15; output via a lazy endpoint, up to 64 KiB, redacted and size-capped by the module, shown in a monospace viewer with Copy and a "truncated" note) | render; running jobs poll their row every 5 s while the tab is visible | Linux: "Run script" disabled with "Linux script jobs arrive in Phase 2"; only collect and reboot jobs exist. Queued jobs show "waiting for the device" and can be cancelled. Expired jobs say so. | run, script | P0 |
| T7 | Jobs: parameters, schedules, bulk, approvals ("waiting for approval by ...") | script library, schedules, approvals | | hidden until P2 | script, `rmm.approve` | P2 |
| T8 | **Patches**: compliance, pending patches, last scan, ring, window | `rmm_patch_*` | render | Tab absent unless the `patching` sub-switch is on. | view | P4 |
| T9 | **Activity**: one timeline of audit rows (`logAction` rows for this device) and device events (enrolled, online and offline transitions, alert opened and resolved, job finished, agent updated, remote session opened, reboot) | audit tables plus `endpoint_agent_jobs`, `rmm_alerts`, `rmm_remote_sessions` | render, 50 newest, "Load more" | Each row: icon, text, actor, absolute UTC on hover. | view | P0 (events feed from `rmm.*` P1) |
| T10 | Activity: `rmm.*` events | `RmmEventsInterface` | render | | view | P1 |
| T11 | **Logs** tab (event log and syslog) | `rmm_log_events` | lazy | hidden until P8 | view | P8 |
| T12 | Asset tabs kept: Tickets (count), Documents, Credentials, Warranty and purchase, others | existing | render | Unchanged. | existing | exists |

## 5. Details that need a decision or a small addition

### 5.1 Where the agent detail lives

Fold it into the asset page (section 3). The agent's maintenance forms, job history and MeshCentral mapping (administrators) move to the Jobs tab and the Remote access card; the mapping form moves to the More menu dialog (administrators).

### 5.2 Health bands

Until P3, gauge bands are fixed UI constants (CPU and memory 80 and 95, disks 80 and 90) kept in one PHP array shared by the gauges, the dashboard pills and the devices list, so all three agree. P3 replaces them with the device's resolved thresholds.

### 5.3 Time ranges and the shared date picker

`RivetCore\Ui\DateRange` is date-granular. Options:

* **Recommended:** add an opt-in group "Time windows" with ids `last1h`, `last6h`, `last24h` to `DateRange` (resolved to timestamps, not dates) used only by the RMM graphs, keep Last 7 / 30 / 90 days and Custom as they are, and keep the existing 6h and 90d pills. This is a Core addition (additive, tagged `@api` or `@internal` per the owner's choice).
* **Fallback:** keep the Metrics tab's pill selector as is (1h, 6h, 24h, 7d, 30d, 90d, plus a Custom date pair) and use the shared picker only on the fleet pages.

The mockup shows Last hour, 24 hours, 7 days, 30 days and Custom, which is the recommended option.

### 5.4 Network gauge

The agent reports aggregate throughput only. The tile shows current receive and send and a bar against the 24 h peak. A real gauge would need the link speed in inventory (a small agent addition, not in any phase) and per-adapter series (not planned).

### 5.5 Check history

Storing every check result (3 checks per 300 s per device is about 860 rows per day) is not justified. Proposal for the owner: record **state changes only** in a Core-owned table `endpoint_agent_check_events(device_id, check_key, status, at)`, pruned after 30 days. That feeds "last 10 changes", flap counts and a 24 h strip drawn from change events, at one row per change. It is not in any phase; if declined, the Checks table shows "steady for N" and, for disk checks, the metric sparkline only. Decision U3.

### 5.6 Polling hooks for the Metrics tab

`js/asset_metrics.js` fetches once per range change. For live append it needs a `since` parameter on the batch call so only buckets newer than the last drawn point are returned (RivetIT, edition code).

## 6. Fleet pages

### 6.1 RMM dashboard (`agent/rmm_dashboard.php`, both editions)

Keep the page and its vendor-RMM content; add an **agent fleet** section at the top that is also the default when the install has no vendor integration.

| # | Element | Data source | Refresh | States | Perm | Phase |
|---|---|---|---|---|---|---|
| F1 | KPI strip: Online, Offline, Need attention (open alert or disk at or above 90%), Open alerts (with critical count), Need reboot, Patches pending (P4) | one aggregate query over `asset_rmm_links` joined to client scope (existing `$rmm_asset_scope`), plus `rmm_alerts` counts | render; page header says "updated N s ago"; live refresh every 60 s while visible | Zero devices: onboarding card ("Create an enrollment token"). Module off: whole page replaced by the "RMM module is off" notice. | view | P0 (patches P4) |
| F2 | Fleet health donut with legend and counts (online, offline, stale, never checked in), 2 px gaps, counts printed beside it | `Devices::status` classes aggregated, or `asset_rmm_links.rmm_status` plus `last_checkin_at` | render | Stale and never are separate segments, not "offline". | view | P0 |
| F3 | Devices with open alerts (bar list, top 5 to 10) | `rmm_alerts` grouped by asset | render | "No open alerts". | view | P0 |
| F4 | Offline and stale list (since when) | `asset_rmm_links.rmm_status_changed_at`, `last_checkin_at` | render | Frozen state (module off) hides it. | view | P0 |
| F5 | Needs reboot (and, with the sub-switch, needs patching) | `rmm_needs_reboot`, `rmm_patches_pending` | render | | view | P0 (patching P4) |
| F6 | Performance and capacity panel (administrators): enrolled of limit, check-ins per minute and per hour, p95 check-in latency, ingest mode and queue, shed level pill, projected write rate and storage, warnings, "Reduce load" presets | `CapacityReport` (design 13.5) via `RmmReadModel` | render; live refresh 60 s | Shed level above 0: amber banner and the live endpoints raise `poll_s`. Non-administrators do not see the panel. | admin | P0 |
| F7 | Existing vendor sections (patch compliance, alert volume 30 days, alerts by severity, noisiest assets and departments) | existing queries | render | unchanged | view | exists |
| F8 | Reports links (health summary, patch compliance, inventory) | `Reporting` | | hidden | view | P5 |

### 6.2 Devices list (`agent/rmm_assets.php`, both editions)

| # | Element | Specification | Phase |
|---|---|---|---|
| D1 | Columns | Select box, Status pill, Device (name, client, OS), CPU, Memory, Worst disk (each a thin bar plus number with the band colour and the number visible), CPU sparkline (24 h), Alerts (open count), Flags (reboot pending, maintenance, patches), Last seen. A cell with no value reads "no data". | P0 |
| D2 | Data | `asset_rmm_links` cached columns (`rmm_cpu_percent`, `rmm_ram_percent`, `rmm_disk_percent` = the worst disk) written by every check-in. Sparkline: one batched query for the page's 50 assets from `device_metric_rollups` (hour bucket, `cpu.utilization`, last 24 h). | P0 |
| D3 | RivetMSP | Sparkline column replaced by "no history" until the MSP sink (P1). Everything else works from the cached columns. | P0 |
| D4 | Sorting, filtering, paging | Server-side: status, client, OS family, "needs attention", free text; sort by any column; 50 rows per page. | P0 |
| D5 | Bulk actions | Run saved script, Reboot (confirmation with the device count and a typed confirmation above 10 devices), later Tag and Move to group (P1), Run on group (P2). Each device is authorised individually; devices the user cannot act on are skipped and listed in the result. | P0 (tag P1, group P2) |
| D6 | Status filter chips and counts | Online, Offline, Stale, Never checked in, Pending approval (approval queue count) | P0 |
| D7 | Saved views, columns picker, CSV export | Not in the design. | Not planned (CSV P5 with reports) |

## 7. Cross-cutting rules

### 7.1 Graceful state matrix

| Situation | Asset page | Fleet pages |
|---|---|---|
| **Module off** (master switch) | The asset renders as a normal asset: no RMM strip, no RMM tabs, no live requests, no `/agent/rmm_*` calls; one info line "RMM module is off. Device status is frozen." appears only where the asset has a link row. Status never flips to offline (decision D13). | RMM navigation entry, dashboard, devices list hidden; direct URLs show the standard "module off" notice. |
| **Sub-switch off** (for example metrics) | The affected widget is absent, not empty: no charts; gauges and checks stay if monitoring is on. | KPIs that need the feature are omitted. |
| **Never checked in** | Strip shows "Waiting for first check-in"; the Overview shows one empty state with the install command; gauges, graphs, checks are not rendered. Run script and Reboot disabled. | Counted as "Never checked in", not offline. |
| **Offline** | Offline banner with last check-in; gauges dimmed with "last reading ..."; charts keep history with an offline band; checks show "Unknown" with their last text; jobs can be queued; remote offers "launch anyway". | Counted offline; "since" shown. |
| **Stale** (quiet more than 7 days) | As offline, plus "consider retiring this device". | Separate count. |
| **Pending approval / revoked / retired** | Banner; actions disabled except those an administrator may still do (approve, transfer, retire). | Filter chips. |
| **A metric is missing** | "no data" in the gauge and a break in the line; never 0. | "no data" in the cell. |
| **MSP without Metrics** | Gauges and checks work from the latest sample; the Performance card is an explanation; sparklines say "no history". | Same. |
| **Load shedding** | Live endpoint returns `poll_s` doubled (L1) or `0` (L2); the strip shows "Live updates paused by server load". The page itself still renders. | Same. |
| **Error from a live request** | Keep the last data, show a small "Live update failed, retrying" note after 3 failures, back off. Never blank the gauges. | Same. |
| **Device out of scope or missing** | Same 404 page for both ("does not exist or is outside your departments"). | Rows are simply absent. |

### 7.2 Refresh and load model

* **Render:** the page is a complete server-rendered snapshot (strip, gauges from the latest sample, checks, alerts, jobs headers, inventory). Charts hydrate from one series request.
* **Live document:** `GET /agent/rmm_device_live.php?asset_id=ID` (an edition route, session authenticated like the Metrics tab's JSON; Core supplies `RmmReadModel::deviceLive()`). Proposed shape:

```json
{"v":1,"state":"online","last_checkin_at":"2026-10-07T12:00:02Z","age_s":42,"next_check_in_s":300,
 "agent_version":"0.1.0-beta.1","uptime_s":1834200,"pending_reboot":false,
 "gauges":{"sampled_at":"...Z","cpu_pct":14.2,"mem_pct":56.1,"disks":[{"mount":"C:","used_pct":54.3,"free_bytes":59000000000,"total_bytes":128000000000}],"net_rx_bps":6200000,"net_tx_bps":2200000},
 "checks":[{"key":"disk:C:","status":"ok","detail":"...","since":"...Z","alert_id":null}],
 "alerts":{"open":0,"worst":null},"jobs":{"queued":0,"running":0},
 "poll_s":30,"shed":0,"seq":1234}
```

  Missing readings are `null`, never 0. Size under 4 KB. Cost: three indexed reads (device row by primary key, `endpoint_agent_checks` by device, one alert count) after the ability check; no metrics tables. `ETag` is `W/"<last_seq>-<jobs_updated>-<alert_count>"`; an unchanged state returns `304` with no body and no further queries beyond the device row.
* **Polling policy (JavaScript, one small file shared by both editions):** only while the tab is visible (Page Visibility API; abort the in-flight request on hide); first poll after `poll_s` (default 30, floor 15, server-controlled); `If-None-Match`; after three consecutive `304` responses the interval doubles up to 120 s; ±20% jitter so tabs do not synchronise; one request in flight; stop after 15 minutes without user input and show "Live updates paused. Click to resume"; honour `poll_s: 0`. The agent only reports every 300 s by default, so polling faster than about 30 s adds no information.
* **Series:** one batch request per range change (existing). For ranges up to 24 h the page may append new buckets (with a `since` parameter) once per collect interval; 7 d and longer do not refresh.
* **Job rows:** a running or queued job polls its own row every 5 s while the Jobs tab is visible and stops when it ends.
* **Fleet pages:** the dashboard and list refresh the KPI strip every 60 s while visible, with the same rules; sorting and paging are normal requests.
* **No WebSockets and no server-sent events.** Both would hold a PHP-FPM worker per open tab. If real push is wanted later it needs a separate always-on process, which is outside this module.
* **Cheap when off:** the page checks the module state file (no query) before including any RMM partial or script; the JS file is not even linked when the module is off.

### 7.3 Permissions

| Action | Ability | Notes |
|---|---|---|
| See the device, gauges, graphs, checks, alerts, inventory, jobs list and output | `rmm.device.view` | Output is visible only with `rmm.job.run_saved` (matches today: job output visibility follows run permission). A view-only user sees job state and exit code, not output. |
| Collect inventory, reboot, run saved script | `rmm.job.run_saved`, `rmm.job.reboot` | Not for module-only logins. |
| Run a free-form script | `rmm.job.run_script` | |
| Remote desktop | `rmm.remote.launch` | Not for module-only logins. |
| Rotate credential, transfer, retire, map Mesh node, capacity panel | `rmm.admin` | |
| Acknowledge an alert, open a ticket | existing alert and ticket permissions | |

Disabled buttons carry the reason text. Every action goes through `TechnicianActions` (REST and UI share it) and is audited.

### 7.4 Accessibility

* **Gauges:** `role="img"` with an `aria-label` that gives the name, value and band ("CPU 14 percent, OK"); a visible status pill with icon and word; the value is also text in the SVG.
* **Charts:** the chart is a focusable group with an `aria-label` that says what it is and how to use it; arrow keys step through points and show the tooltip; a "Data table" disclosure under every chart carries latest, average, peak and lowest per series (this is also the contrast relief for the lighter series colours); marker shapes differ (triangle, diamond, square) and each has a text title; no information is only in colour or only on hover.
* **Palette (colour-blind safe):** series use the validated reference slots: blue, orange, aqua (validated for light and dark surfaces, adjacent colour-vision separation at or above the target), with at most three series per chart, always a legend for two or more series and direct names in the tooltip. Status colours (green, amber, orange-red, red) are reserved for state and always paired with an icon and a word; they are never used for series. Threshold lines are dashed and labelled.
* **Dark mode:** all colours are tokens redefined for dark in the three-state pattern (system preference, explicit light, explicit dark); the chart code reads tokens at draw time (as `chart_theme.js` does) and redraws on theme change.
* **Keyboard:** tabs are a `tablist` with arrow-key movement; menus open with Enter and close with Escape; dialogs trap focus and return it; every action button is reachable in the order strip, tabs, content; focus ring is the app's visible ring.
* **Motion:** none required; honour `prefers-reduced-motion` (the app's motion tokens already collapse).
* **Language:** relative times always have the absolute time in a `title` or in the data table; times display in the viewer's zone with UTC available on hover.

### 7.5 Mobile layout

* At 960 px and below the asset rail collapses into a "Show details" toggle above the tabs; the health strip stacks (name and status, then actions wrapping, then facts in a 2-column grid).
* Tabs scroll horizontally inside their own container; the page never scrolls sideways.
* Gauges are a 2-column grid (minimum tile 158 px; 1 column below 360 px); the Overview order is strip, alerts (open first), gauges, checks, graphs, remote access.
* Charts go single-column and keep a 196 px height; the tooltip flips sides; touch drag moves the crosshair; the range selector wraps.
* Tables sit in their own horizontal scroll container; the devices list hides sparkline and flags columns under 700 px and shows them in an expandable row.
* Minimum touch target 36 px for buttons.

### 7.6 Performance budget

| Item | Budget |
|---|---|
| Added server queries for the RMM part of an asset page view | at most 8 (device row, link row, checks, open alerts and 5 recent, last 15 job headers without output, Mesh node row, metrics capability set 1 to 2), all primary-key or indexed; module state costs 0 queries (state file) |
| Added HTML (Overview, gzip) | under 15 KB; no inline job output |
| Live JSON | under 4 KB, 304 when unchanged |
| Series request | one request; at most 1,000 points per series (existing default), at most 6 series per chart, about 150 KB uncompressed worst case, about 30 KB gzip; markers capped at 50 |
| Long ranges | hour rollups up to 90 d and day rollups beyond (existing ladder) so a 30 d view reads 720 points per series, never raw rows |
| Job output | lazy, one request per click, up to 64 KiB |
| Fleet list | 50 rows per page; one aggregate query for KPIs; one batched query for the page's sparklines; KPI aggregate may be cached for 30 s per client scope |
| JS | gauges are server-rendered SVG (no JS); one live-poll file under 4 KB gzip; Chart.js already loaded on the page |
| Polling load (20 technicians watching) | about 40 requests per minute at 30 s intervals, each three indexed reads, less with 304s |

### 7.7 Security notes for the UI

All device-supplied text (hostname, check detail, job output, installed software names) is untrusted: escape on output in PHP (`nullable_htmlentities`) and use `textContent` in JavaScript. Dialog and fetch calls use the session CSRF token; the live endpoint is a GET with no state change. No `confirm()` or `alert()` dialogs; destructive confirmations are in-page dialogs. Job output has been redacted by the module but is still displayed in a `<pre>`, never as HTML.

## 8. Colour and typography

* Chrome: the existing `--if-*` tokens (IBM Plex Sans and Mono, teal primary). The mockup uses the same values.
* Series: slot 1 blue `#2a78d6` (dark `#3987e5`), slot 2 orange `#eb6834` (dark `#d95926`), slot 3 aqua `#1baf7a` (dark `#199e70`).
* Status: good `#0ca30c`, warning `#fab219`, serious `#ec835a`, critical `#d03b3b`, each with soft tinted backgrounds and ink colours chosen for contrast; always icon plus word.
* Numbers use tabular figures wherever digits line up (tables, ticks, gauges).

## 9. Delivery by phase

| Phase | Asset page and fleet UI delivered |
|---|---|
| **P0** | Health strip, gauges, graphs (RivetIT; MSP explanation card), checks table, alerts card, remote card, Jobs tab with lazy output, Inventory tab (current inventory blob), Activity tab (audit and device events), live document and polling, dashboard agent section, devices list with health columns, sparklines (RivetIT), bulk reboot and run saved script, capacity panel, module-off and never/offline states, folding the agent device page into the asset page |
| **P1** | Software tab with search and history, normalised hardware, tags, sites and groups in the strip and filters, `rmm.*` events in Activity, MSP graphs and sparklines (metric sink or Metrics port) |
| **P2** | Custom fields in the rail, check origin and overrides, Linux "Run script" (shell jobs), script parameters, bulk run on group or tag, approvals |
| **P3** | Thresholds from alert rules on gauges and charts, maintenance window banner, suppression state in the alerts card, new check types, probe latency chart |
| **P4** | Patches tab and dashboard tile (only with the sub-switch on) |
| **P5** | Software deployment status in the Software tab, report links |
| **P6** | Services list and actions, terminal and files buttons enabled |
| **P7** | macOS platform chip and icons |
| **P8** | Logs tab |

## 10. Task T10: RMM asset page and fleet dashboard UI in both editions

Added to the work breakdown of the extraction design (section 7). Split into **T10a** (Core read models and RivetIT) and **T10b** (RivetMSP).

| Field | T10a: Core read models and RivetIT | T10b: RivetMSP |
|---|---|---|
| **Depends on** | T4 (check-in writes `last_metrics_json`, link columns), T5 (`RmmReadModel`, `RmmAuthorizer`), T9 (`RmmState`, `CapacityReport`, shed level), T7 (RivetIT adoption merged: the 6 adapters and bridges). Phase 0 only; later phases add tabs behind their own sub-switches. | T10a stable, T8 (MSP adoption) merged |
| **Repos and file ownership** | **Core** (worktree): `src/Rmm/Read/DeviceLiveReadModel.php`, `src/Rmm/Read/FleetReadModel.php`, `src/Rmm/Read/HealthBands.php` (shared constants), `tests/Integration/Rmm/{DeviceLiveReadModel,FleetReadModel}Test.php`, `docs/rmm/UI_CONTRACT.md` (the live JSON shape), optional `src/Ui/DateRange.php` addition (decision U2). **RivetIT** (worktree on a new branch): `agent/asset_details.php` (RMM section extracted), new partials `agent/includes/asset/rmm_{strip,gauges,graphs,checks,alerts,remote,jobs,inventory,activity}.php`, `agent/rmm_device_live.php`, `agent/rmm_job_output.php`, `agent/rmm_agent_device.php` (reduced to a redirect), `agent/rmm_dashboard.php`, `agent/rmm_assets.php`, `agent/post/rmm_agent.php` (bulk actions), `js/rmm_live.js`, `js/asset_metrics.js` (markers, offline band, thresholds, `since`), `css/itflow_design.css` (small additions only), `tests/rmm_ui_*.php`, `tests/browser/rmm_asset_smoke.mjs`, `docs/user-guide` screenshots. | `rivetmsp-beta` worktree: `agent/asset_details.php`, the same partial names under `agent/includes/asset/`, `agent/rmm_device_live.php`, `agent/rmm_dashboard.php`, `agent/rmm_assets.php`, `js/rmm_live.js` (copied), `tests/` equivalents. No Metrics tab, no sparklines; the Performance card is the explanation state. |
| **Must not touch** | `src/Rmm/Agent/**`, wire protocol, migrations, `endpoint_agent_*` DDL, vendor-RMM tabs and code paths (Tactical, Level, Action1, Sophos). | Same, and RivetIT files. |
| **Deliverables** | Read models returning the shapes in section 7.2 and the KPI and list queries; partials and endpoints per sections 3 to 6; polling file; asset page works unchanged for vendor-linked assets; module off renders a plain asset; screenshots for the user guide. | Same elements, using the MSP's cached link columns and last sample only. |

### 10.1 Acceptance tests

HTTP tests (scratch database, real HTTP with the editions' harness, throwaway users; never live data):

1. **Live endpoint contract:** 200 with the documented keys for an online device; missing readings are `null`, never `0`; `ETag` returned; the same ETag in `If-None-Match` returns 304 with an empty body; changing the check-in sequence changes the ETag.
2. **Query budget:** a counting database wrapper asserts at most 3 queries for the live endpoint and at most 8 added queries for the RMM part of an asset page, and 0 RMM queries when the module is off.
3. **Scope and permission matrix:** an out-of-scope or missing device returns the same 404; a read-only user sees gauges and checks but no Run, Reboot, Remote buttons enabled and no job output; a technician can run saved scripts and reboot; only an administrator sees capacity, rotate and retire; a module-only login cannot run or launch remote. The existing role matrix test must stay green.
4. **Module off:** the asset page contains none of the RMM partial markup and does not link `rmm_live.js`; the live endpoint answers as disabled; the dashboard shows the notice; the nav entry is absent; no status flips.
5. **States:** never checked in (empty state, actions disabled), offline (banner, dimmed gauges, charts keep history), stale, revoked and pending approval render without PHP warnings.
6. **Escaping:** a hostile hostname, check detail, job output and software name (script tags, quotes, very long text) are escaped in HTML and in JSON consumers.
7. **Series stays one request** per range change; ranges map to the documented resolutions (raw up to 48 h, hourly up to 90 d).
8. **Linux:** a Linux-test device shows no Windows icon, "Run script" disabled with the reason, reboot and collect enabled.
9. **MSP:** the asset page renders the explanation card, no chart requests are made, gauges show the last sample.
10. **Load shedding:** with shed level 1 the live endpoint doubles `poll_s`; at 2 it returns `poll_s: 0`.

Browser smoke (Playwright against a scratch instance, both editions, desktop and a 390 px viewport, light and dark):

11. Overview loads with no console errors and no failed requests; gauges, four charts (RivetIT) and checks are visible.
12. Range switch fires exactly one series request; the data table matches the plotted latest value.
13. Polling: starts after the interval, stops when the tab is hidden (emulated visibility change), resumes when visible, backs off on 304, honours `poll_s: 0`.
14. Keyboard: tabs reachable and operable with arrow keys; chart arrow keys move the tooltip; dialogs trap and restore focus; Escape closes menus.
15. Reboot confirmation dialog requires the checkbox; no `window.confirm`.
16. No horizontal page scroll at 390 px; tab bar scrolls inside its container.
17. Automated accessibility scan (axe-core) reports no serious or critical violations on Overview and Jobs, in both themes.
18. Offline and never-checked-in states can be reached by seeding rows and are screenshot-checked.

Size: **M to L** (about one to two weeks of agent sessions per edition once the dependencies exist). Nothing in T10 changes the wire protocol, tables or the agent.

## 11. Decisions needed from the owner

| # | Question | Recommendation |
|---|---|---|
| U1 | Fold the agent device page into the asset page in both editions (and redirect the old URL)? | Yes. |
| U2 | Add sub-day presets (last hour, 6 h, 24 h) to the shared `DateRange`, or keep the Metrics pill selector for the graphs? | Add an opt-in group in Core (section 5.3). |
| U3 | Record check state changes in a new Core table to power trends and flap counts (not in any phase)? | Yes, state changes only, 30 day retention; otherwise keep text-only trends. |
| U4 | MSP history: build the metric sink in P1 or port the Metrics subsystem (decision D5)? | Sink first; the UI is identical either way. |
| U5 | Gauge bands before P3: accept fixed UI bands (80/95 and 80/90) shared by gauges, dashboard and list? | Yes. |
| U6 | Poll interval floor and idle cutoff (30 s, 15 min)? | Yes; the agent only reports every 300 s by default. |
| U7 | Show job output to view-only users? Today it follows the run permission. | Keep as today. |
