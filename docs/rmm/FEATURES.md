# RivetIT / RivetMSP RMM: what it does and contains

Status: DRAFT for owner review, 2026-10-07; statuses updated for RivetCore v1.0.0-rc.9 (2026-10-10). This is the product-level feature list for the RMM module that is moving into RivetCore (`RivetCore\Rmm\*`). It is derived from, and must stay consistent with, [the extraction design](../design/endpoint-module-extraction.md) (sections 9 to 13), [ADR-010](../architecture/ADR-010-endpoint-agent-module.md) and the RMM section of [ROADMAP.md](../../ROADMAP.md). It promises nothing beyond the phases those documents define. How the asset (device) page and the fleet pages show all this is in [ASSET_PAGE_REDESIGN.md](ASSET_PAGE_REDESIGN.md).

## How to read this list

**Status** is when a feature first exists for users. Every row has one status.

| Status | Meaning |
|---|---|
| **Shipped** | Works today in RivetIT beta (release v26.10.26, agent `agent-v0.1.0-beta.1`, DB 2.6.146). Verified from the code. |
| **Core** | Built and tested in RivetCore (v1.0.0-rc.9, Phase 1) but not yet adopted by an edition: it works for an edition once it runs migration 0018 and wires the new contracts, and it is not in a tagged RivetIT or RivetMSP release. Moves to `Shipped` after an edition release and its smoke test. |
| **P0** | Phase 0: this build. The code moves into Core, a Linux agent joins the Windows agent, the module gets its on/off switch and capacity controls, and both RivetIT and RivetMSP adopt it. [#60](https://github.com/TheTractorHacker/rivet-core/issues/60) to [#68](https://github.com/TheTractorHacker/rivet-core/issues/68) |
| **P1** | [#69](https://github.com/TheTractorHacker/rivet-core/issues/69) Foundation: Linux packaging, capability negotiation, software inventory with history, sites/groups/tags, `rmm.*` events, MSP metric sink. Built in Core in v1.0.0-rc.9: software inventory with history, tags and groups, nine `rmm.*` events, the database metric sink, per-check history, the live polling document and the capability announcement. Still to do: normalised hardware tables (3.3), site/group/tag at enrollment (2.8), `RmmSitesInterface`, filtering offers by capability (1.6), the remaining events (6.5) |
| **P2** | [#70](https://github.com/TheTractorHacker/rivet-core/issues/70) Policies, check templates, script library, scheduled scripts, approvals, custom fields |
| **P3** | [#71](https://github.com/TheTractorHacker/rivet-core/issues/71) Alerting maturity: thresholds, maintenance windows, suppression, escalation, more check types |
| **P4** | [#72](https://github.com/TheTractorHacker/rivet-core/issues/72) Patch management (needs real Windows test machines) |
| **P5** | [#73](https://github.com/TheTractorHacker/rivet-core/issues/73) Software deployment and reporting |
| **P6** | [#74](https://github.com/TheTractorHacker/rivet-core/issues/74) Remote tools: service/process manager, file transfer, embedded Mesh terminal and files |
| **P7** | [#75](https://github.com/TheTractorHacker/rivet-core/issues/75) macOS agent, Authenticode-signed binaries, MSI (blocked: no test Mac, no signing certificates) |
| **P8** | [#76](https://github.com/TheTractorHacker/rivet-core/issues/76) Event-log and syslog collection (optional, off by default) |
| **Not planned** | Appears in what commercial RMMs offer, has no phase in the design. Not a promise and not a refusal; it needs a new design addendum first. |

The **Windows / Linux / macOS** columns give the same vocabulary per platform. `-` means the feature does not apply to, or is not planned for, that platform. macOS is deferred to P7 as a whole, so its column is `P7` or `-`. Where a row is only about the server or the UI the platform columns say `all`.

Two editions consume the module: **RivetIT** (has a Metrics subsystem: `device_metric_*` tables) and **RivetMSP** (no Metrics subsystem yet; Phase 0 uses a null metric sink). Rows that differ by edition say so in the notes. Every sub-area is individually switchable (see area 14) and off by default for new installs.

## 1. Agents and platforms

| # | Feature | Status | Windows | Linux | macOS | Notes |
|---|---|---|---|---|---|---|
| 1.1 | Windows service agent (amd64 and arm64) | Shipped | Shipped | - | - | Go, built by the agent CI. Service name `RivetIT Agent` is kept for self-update compatibility. |
| 1.2 | Self-update: Ed25519-signed manifest, `pilot` and `stable` rings, rollout %, minimum version, never downgrades, skips a failed version, automatic rollback on a bad update | Shipped | Shipped | P1 | P7 | Linux self-update ships with the packaged agent. |
| 1.3 | Offline buffering: up to 100 samples and check results kept and replayed on reconnect, idempotent by `(device, seq)`; exponential backoff with jitter and `Retry-After` | Shipped | Shipped | P0 | P7 | The Linux agent reuses the same loop. |
| 1.4 | Linux agent: enrols, checks in, reports metrics and checks, runs `collect` and `reboot` jobs | P0 | - | P0 | - | A Linux collector exists today as a test build only. Roadmap Phase 0 includes the Linux agent ([#65](https://github.com/TheTractorHacker/rivet-core/issues/65)); see the note at the end of this section. |
| 1.5 | Linux packaging: systemd service, install script, `.deb` and `.rpm` | P1 | - | P1 | - | |
| 1.6 | Capability negotiation: the agent announces platform, job types, check types and features; the server only offers what the agent supports | P1 | P1 | P1 | P7 | Core (rc.9) stores the announcement (`rmm_device_state`) and uses it for the software offer; filtering job and check offers by capability is not done yet and lands with policies (P2). Old agents are treated as today's Windows agent. |
| 1.7 | Agent runs behind the edition's host only: binaries are downloaded from your server, never from GitHub | Shipped | Shipped | P0 | - | |
| 1.8 | Authenticode-signed Windows binaries and an MSI installer | P7 | P7 | - | - | Needs a code-signing certificate. Unsigned binaries trigger SmartScreen today. |
| 1.9 | macOS agent (launchd, pkg, notarisation) | P7 | - | - | P7 | Blocked: no test Mac, no Apple developer account. |
| 1.10 | HTTP proxy support in the agent | Not planned | - | - | - | Listed in the gap analysis as something a credible RMM has; no phase. |

Note on Linux timing: the ROADMAP table puts "Linux agent" in Phase 0 (issue #65, "Go move + Linux") and the design document's section 10 puts the productised Linux agent (systemd, deb/rpm, install script) in Phase 1. This list follows both: the agent itself is P0, packaging is P1.

## 2. Enrollment and identity

| # | Feature | Status | Windows | Linux | macOS | Notes |
|---|---|---|---|---|---|---|
| 2.1 | Enrollment tokens: single or multi-use, expiry, per client and optional location | Shipped | all | all | all | |
| 2.2 | Device identity: install id, machine GUID, serial and MAC matching; junk serials (`To Be Filled By O.E.M.` and similar) are never used to match | Shipped | Shipped | P0 | P7 | |
| 2.3 | Link to an existing asset, or auto-create one; blanks on the asset are filled, human-entered values are never overwritten | Shipped | Shipped | P0 | P7 | |
| 2.4 | Approval queue: ambiguous or out-of-scope matches wait for a technician | Shipped | all | all | all | |
| 2.5 | Per-client stamped installer (the token and CA are embedded in the download) | Shipped | Shipped | P1 | P7 | Linux uses an install script in P1. |
| 2.6 | Reinstall detection, rotate credential, revoke, retire, transfer to another client | Shipped | all | all | all | |
| 2.7 | Maximum enrolled devices limit (new installs suggest 500) | P0 | all | all | all | `max_devices`; retired and revoked devices do not count. |
| 2.8 | Site, group and tag chosen at enrollment, auto-tagging | P1 | P1 | P1 | P7 | Tags and groups exist (11.3); choosing them in the enrollment token and automatic tags are still to do. |
| 2.9 | Bulk-deployment helper scripts (Intune, GPO) | Not planned | - | - | - | |

## 3. Inventory

| # | Feature | Status | Windows | Linux | macOS | Notes |
|---|---|---|---|---|---|---|
| 3.1 | Hardware and OS summary: OS and version, architecture, CPU model and cores, memory total, disks (mount, filesystem, total, free), network adapters (name, MAC, IPs), serial, make, model, logged-in user, uptime, pending reboot | Shipped | Shipped | P0 | P7 | Stored as one JSON blob (up to 64 KiB), refreshed on change and at least daily. |
| 3.2 | Inventory visible in the device page and applied to the asset | Shipped | all | all | all | |
| 3.3 | Normalised hardware tables (CPU, RAM, disks, BIOS and similar) | P1 | P1 | P1 | P7 | |
| 3.4 | Installed software list with versions | Core | Core | Core | P7 | Windows: registry Uninstall keys (64 and 32 bit; per-user installs are not listed), optional Store apps. Linux: dpkg, rpm, snap, flatpak. Full list on the first report, on request and daily; deltas in between. Switch: `inventory_software`. |
| 3.5 | Software change history (installed, upgraded, removed, with dates) | Core | Core | Core | P7 | Plus downgraded. The first report of a device is a baseline and is not logged. Kept 365 days by default (`software_history_days`). |
| 3.6 | Services list | P6 | P6 | P6 | P7 | Arrives with the service/process manager ([#74](https://github.com/TheTractorHacker/rivet-core/issues/74)). |
| 3.7 | Disk health (SMART), TPM, GPU, local users and groups | Not planned | - | - | - | Named in the gap analysis; no phase. |
| 3.8 | Warranty and purchase data | Shipped | all | all | all | Lives on the edition's asset record, not in the RMM. |

## 4. Monitoring and metrics

| # | Feature | Status | Windows | Linux | macOS | Notes |
|---|---|---|---|---|---|---|
| 4.1 | CPU utilisation %, memory utilisation %, per-disk utilisation % | Shipped | Shipped | P0 | P7 | |
| 4.2 | Per-disk free and total bytes; memory total | Shipped | Shipped | P0 | P7 | |
| 4.3 | Network receive and transmit throughput (sum of all adapters) | Shipped | Shipped | P0 | P7 | Not split by adapter. |
| 4.4 | Uptime and pending-reboot state | Shipped | Shipped | P0 | P7 | |
| 4.5 | "No data" is never drawn as zero: a reading the agent could not take is missing, not 0 | Shipped | all | all | all | |
| 4.6 | Metric history with hourly and daily rollups, ranges from 1 hour to 90 days, charts in the device page | Shipped | all | all | all | RivetIT only (Metrics subsystem). |
| 4.7 | Device status: online, offline, stale (quiet for 7 days), never checked in | Shipped | all | all | all | |
| 4.8 | Metric history in RivetMSP | Core | all | all | P7 | `DatabaseMetricSink`: hourly rollups (count, sum, min, max) and the latest reading, 14 days by default, through the same sink contract. RivetMSP shows the latest reading only until it passes the sink to the module. |
| 4.9 | Ping, port and HTTP probes with latency | P3 | P3 | P3 | P7 | |
| 4.10 | Per-process top N, temperatures, battery | Not planned | - | - | - | |
| 4.11 | Event-log and syslog collection (bounded volume, short retention, alert on event) | P8 | P8 | P8 | P7 | Optional, off by default, storage heavy. |

## 5. Checks

| # | Feature | Status | Windows | Linux | macOS | Notes |
|---|---|---|---|---|---|---|
| 5.1 | Four check types: `service`, `disk`, `pending_reboot`, `script` | Shipped | Shipped | P0 | P7 | `service` on Linux means a systemd unit; `script` checks need the signature to verify. |
| 5.2 | One global, signed check list pushed to every agent; script checks bounded to 8 KiB and 60 s | Shipped | Shipped | P0 | P7 | |
| 5.3 | Debounce: an alert opens after 3 bad results and resolves after 2 good ones; each failure episode is a separate alert | Shipped | all | all | all | |
| 5.4 | Check templates assigned to client, site, group, tag or device, with inheritance and per-device override | P2 | P2 | P2 | P7 | Needs the policy engine (area 11). |
| 5.5 | CPU and memory threshold checks, process-running, certificate expiry, basic event-log check | P3 | P3 | P3 | P7 | |
| 5.6 | Per-check result history (trend of the last N results) | Core | all | all | all | `endpoint_agent_check_history`: every change of status, and an unchanged status at most once per hour, for 7 days. Decision U3 of ASSET_PAGE_REDESIGN.md, taken one step further than "state changes only". Read with availability over the window. |

## 6. Alerts and tickets

| # | Feature | Status | Windows | Linux | macOS | Notes |
|---|---|---|---|---|---|---|
| 6.1 | Alerts with a stable key, severity, auto-resolve; one row per failure episode, never duplicated by a re-sent check-in | Shipped | all | all | all | |
| 6.2 | Alert to ticket through the edition's existing path, with conservative ticket auto-close on resolve | Shipped | all | all | all | |
| 6.3 | Alert list with acknowledge, filter and bulk actions | Shipped | all | all | all | Edition UI. |
| 6.4 | Offline and online transitions feed the edition's `asset_offline` / `asset_online` automation | Shipped | all | all | all | |
| 6.5 | `rmm.*` events for every state change (enrolled, approved, revoked, online, offline, alert opened/resolved, job completed, module enabled/disabled) delivered to webhooks and automation rules | Core | all | all | all | Nine events through `RmmEventsInterface`: device enrolled, offline, online; check failed, recovered; job completed, failed; software installed, removed. Approved, revoked and module on/off events are not emitted yet. |
| 6.6 | Threshold tiers (warning and critical), flap detection | P3 | all | all | all | |
| 6.7 | Maintenance windows and alert suppression | P3 | all | all | all | Today agent alerts ignore the edition's maintenance-mode flag. |
| 6.8 | Escalation chains and notifications (email, chat) | P3 | all | all | all | Through `RmmNotifierInterface` if the edition does not already own it. |
| 6.9 | Auto-remediation: run a script when an alert opens | P3 | P3 | P3 | P7 | |
| 6.10 | SMS notifications | Not planned | - | - | - | |

## 7. Scripts and automation

| # | Feature | Status | Windows | Linux | macOS | Notes |
|---|---|---|---|---|---|---|
| 7.1 | Run a PowerShell script as SYSTEM with timeout, output size cap and secret redaction | Shipped | Shipped | - | - | |
| 7.2 | Saved scripts from the edition's script library | Shipped | Shipped | - | - | Library has no parameters or variables yet. |
| 7.3 | Reboot job with delay; "collect inventory now" job | Shipped | Shipped | P0 | P7 | |
| 7.4 | Signed jobs (Ed25519 over canonical JSON), destructive confirmation, at-most-once delivery for destructive jobs, lost-acknowledgement handling | Shipped | all | all | all | |
| 7.5 | Job history per device with state, exit code, output viewer, cancel while queued | Shipped | all | all | all | |
| 7.6 | Job-type registry (adding a job type becomes a registration, not a protocol change) | P0 | all | all | all | Same three types, same behaviour. |
| 7.7 | `shell` jobs: bash, zsh, PowerShell, cmd | P2 | P2 | P2 | P7 | This is what brings scripts to Linux. |
| 7.8 | Script library with parameters, variables, secrets and versions | P2 | all | all | all | |
| 7.9 | Scheduled and recurring scripts, run once per window, safe across restarts and offline periods | P2 | all | all | all | |
| 7.10 | Bulk run on a client, site, group or tag | P2 | all | all | all | |
| 7.11 | Approval step for destructive or bulk jobs (second user) | P2 | all | all | all | |
| 7.12 | Custom fields (typed, scoped) usable as script variables | P2 | all | all | all | RivetIT's current `custom_fields` is a dead stub and is not built on. |

## 8. Remote access

| # | Feature | Status | Windows | Linux | macOS | Notes |
|---|---|---|---|---|---|---|
| 8.1 | MeshCentral remote desktop launch: a login cookie is minted per click, no URL or token is stored | Shipped | Shipped | P0 | P7 | Needs your own MeshCentral server; optional (`mesh_enabled`). |
| 8.2 | Remote-session log (who, when, from where) | Shipped | all | all | all | |
| 8.3 | Manual mapping of a device to a MeshCentral node | Shipped | all | all | all | |
| 8.4 | Service and process manager (list, start, stop, restart, kill) as signed jobs | P6 | P6 | P6 | P7 | |
| 8.5 | File download and upload with size caps, audited | P6 | P6 | P6 | P7 | |
| 8.6 | Embedded MeshCentral terminal and file browser | P6 | P6 | P6 | P7 | |
| 8.7 | Native remote desktop or terminal relay built into RivetIT | Not planned | - | - | - | The design does not recommend it: it needs a persistent-connection service that PHP-FPM cannot hold. |
| 8.8 | Registry editor, wake-on-LAN, session recording and consent prompts | Not planned | - | - | - | |

## 9. Patching

| # | Feature | Status | Windows | Linux | macOS | Notes |
|---|---|---|---|---|---|---|
| 9.1 | Pending-reboot detection and a reboot job | Shipped | Shipped | P0 | P7 | |
| 9.2 | Patch status for devices managed by a vendor RMM (for example Tactical RMM) | Shipped | all | all | all | The existing "Patches" tab and the dashboard's compliance card read the vendor's data, not this agent. |
| 9.3 | Windows Update scan, approve and install through the agent | P4 | P4 | - | - | |
| 9.4 | Approval rings, deferrals, maintenance windows, reboot policy | P4 | P4 | P4 | - | |
| 9.5 | Patch compliance per device and per client | P4 | P4 | P4 | - | |
| 9.6 | Linux package updates (apt, dnf) | P4 | - | P4 | - | |
| 9.7 | Third-party application patching through winget | P4 | P4 | - | - | |
| 9.8 | macOS software update | P7 | - | - | P7 | Follows the macOS agent. |

## 10. Software deployment

| # | Feature | Status | Windows | Linux | macOS | Notes |
|---|---|---|---|---|---|---|
| 10.1 | Package catalog: winget, Chocolatey, MSI and EXE with silent arguments and detection rules; apt and dnf packages | P5 | P5 | P5 | P7 | |
| 10.2 | Install, uninstall and update, assigned through policies | P5 | P5 | P5 | P7 | |
| 10.3 | Per-device install status verified by detection rules | P5 | P5 | P5 | P7 | |
| 10.4 | Bandwidth control for large packages | Not planned | - | - | - | |

## 11. Policies and scopes

| # | Feature | Status | Windows | Linux | macOS | Notes |
|---|---|---|---|---|---|---|
| 11.1 | Scope by client (department) and optional location; technicians restricted to their clients | Shipped | all | all | all | |
| 11.2 | Role abilities: view device, run saved script, reboot, run script, remote launch, administer | Shipped | all | all | all | In Core these become `rmm.device.view`, `rmm.job.run_saved`, `rmm.job.reboot`, `rmm.job.run_script`, `rmm.remote.launch`, `rmm.admin` (P0). |
| 11.3 | Sites (the edition's locations), groups and tags | Core | all | all | all | Tags (free labels), static groups (devices added by hand plus every device carrying one of the group's tags), fleet filters by tag, group, software and location. Sites are the edition's locations (`location_id`); the `RmmSitesInterface` to list and name them is not built. |
| 11.4 | Policies assigned to client, site, group, tag or device with inheritance and enforce flag; versioned | P2 | all | all | all | |
| 11.5 | Per-policy agent update channel pinning | P2 | all | all | all | |

## 12. Reporting

| # | Feature | Status | Windows | Linux | macOS | Notes |
|---|---|---|---|---|---|---|
| 12.1 | RMM dashboard: online, offline, managed, new alerts, open tickets, script runs, fleet health (CPU, memory, disk, needs reboot), alert volume, alerts by severity, noisiest assets and clients | Shipped | all | all | all | Edition UI, built on the vendor-RMM link rows plus agent link-health. |
| 12.2 | RMM assets list with status filter | Shipped | all | all | all | |
| 12.3 | Capacity report (enrolled, online, check-ins per minute, queue, table sizes, projection) | P0 | all | all | all | Administration panel; see area 15. |
| 12.4 | Health summary, patch compliance, inventory and software reports, uptime and SLA, alert history | P5 | all | all | all | |
| 12.5 | Scheduled CSV and PDF exports; client-facing reports | P5 | all | all | all | |

## 13. Security

| # | Feature | Status | Windows | Linux | macOS | Notes |
|---|---|---|---|---|---|---|
| 13.1 | Signed jobs, check definitions and update manifests; devices reject anything unsigned | Shipped | all | all | all | |
| 13.2 | Per-device bearer token stored as a hash only; TLS required (426 otherwise) | Shipped | all | all | all | |
| 13.3 | Bounded request bodies and per-device rate limits | Shipped | all | all | all | |
| 13.4 | Output redaction (bearer strings, JWTs, passwords, keys) before storage | Shipped | all | all | all | Pattern-based, best effort. |
| 13.5 | Every technician action is audited; scripts are logged as a hash, not text | Shipped | all | all | all | |
| 13.6 | Signing-key rotation (every device must re-enrol afterwards) | Shipped | all | all | all | |
| 13.7 | Module-only logins cannot run jobs or open remote sessions | Shipped | all | all | all | RivetIT. |
| 13.8 | Secret variables stored through the edition's secret box and injected at run time | P2 | all | all | all | |
| 13.9 | Two-person approval for destructive and bulk actions | P2 | all | all | all | Same item as 7.11. |
| 13.10 | Focused security review of the module before it becomes public API | P0 | all | all | all | Required before promotion in Core 1.1.0. |
| 13.11 | Mutual TLS for devices; tamper protection for the agent | Not planned | - | - | - | |

## 14. Administration and the module switch

| # | Feature | Status | Windows | Linux | macOS | Notes |
|---|---|---|---|---|---|---|
| 14.1 | Administration page: settings, enrollment tokens, agent binaries and releases, rings, approval queue, MeshCentral, signing key, device list | Shipped | all | all | all | RivetIT. |
| 14.2 | Master switch "Enable RMM module"; existing installs keep their current value, new installs start OFF | P0 | all | all | all | |
| 14.3 | Sub-switches: monitoring, metrics, jobs, remote, updates (later: inventory, policies, patching, software, logs, reports) with Light and Standard presets | P0 | all | all | all | |
| 14.4 | Module off costs nothing: device endpoints answer `503 module_disabled` with `Retry-After: 3600` and no database work; no navigation, cron work or search entries | P0 | all | all | all | The only intentional wire-visible change in Phase 0. Data is kept; re-enabling resumes. |
| 14.5 | Device status frozen with a banner while the module is off (no false offline alerts) | P0 | all | all | all | |
| 14.6 | RMM in RivetMSP (administration, asset page, device page, jobs, remote) | P0 | all | all | all | RivetMSP gets its own kill switch `config_core_rmm_enabled`. |
| 14.7 | Agents back off cleanly when the server is disabled (keep credential and buffer, retry about every 15 minutes or later) | P0 | P0 | P0 | P7 | Old agents already back off to hourly on 503. |
| 14.8 | Release fetcher: pull agent binaries from GitHub Releases with one button | Not planned | - | - | - | A follow-up the design mentions (decision D6), not scheduled. |

## 15. Capacity and performance controls

| # | Feature | Status | Windows | Linux | macOS | Notes |
|---|---|---|---|---|---|---|
| 15.1 | Interval limits: check-in 60 to 3600 s, collect at least 30 s, server-side clamps, agent jitter of 10% | P0 | P0 | P0 | P7 | |
| 15.2 | New-install defaults that cut write volume: collect every 300 s, raw samples kept 7 days | P0 | all | all | all | Existing installs keep their values. |
| 15.3 | Global check-ins-per-minute limit with polite 503 and jittered `Retry-After` | P0 | all | all | all | |
| 15.4 | Load shedding in three levels with hysteresis (normal, stretch intervals, 503) | P0 | all | all | all | |
| 15.5 | Optional queued ingestion through the job queue (off by default; advised above about 1,000 devices) | P0 | all | all | all | |
| 15.6 | Batched pruning of old check-in and job rows | P0 | all | all | all | |
| 15.7 | Administration "Performance and capacity" panel with projections, warnings and one-click "reduce load" presets | P0 | all | all | all | |
| 15.8 | Load simulator and published gates: 500 devices (release candidate), 5,000 devices (before promotion); zero database work when disabled | P0 | all | all | all | |

## Headline counts

Counted from the Status column of the tables above (one row, one status). `Shipped` rows are what the owner can use today in RivetIT beta.

| Status | Rows |
|---|---|
| Shipped | 49 |
| Core | 6 |
| P0 | 19 |
| P1 | 4 |
| P2 | 11 |
| P3 | 6 |
| P4 | 5 |
| P5 | 5 |
| P6 | 4 |
| P7 | 3 |
| P8 | 1 |
| Not planned | 10 |

(Counts are regenerated by the command in the "Keeping this list honest" section; if the tables change, rerun it.)

## How it compares

Comparison for the headline capabilities only. Cells for other products reflect their publicly documented product at the time of writing and general industry knowledge. We have not tested these products side by side, they change often, and a cell marked "not verified" means we do not know. Do not quote this table externally without re-checking each vendor's current documentation.

"Planned" means a phase in this repository's design, not a delivered feature.

| Capability | RivetIT RMM today | RivetIT RMM when P0 to P5 are done | Tactical RMM | NinjaOne | Level |
|---|---|---|---|---|---|
| Windows agent | Yes | Yes | Yes | Yes | Yes |
| Linux agent | Test build only | Yes (P0 to P1) | Yes | Yes | Yes (not verified in detail) |
| macOS agent | No | Deferred (P7) | Yes | Yes | Yes (not verified in detail) |
| Metrics graphs per device | Yes (RivetIT only) | Yes, both editions (built in Core in rc.9, edition adoption pending) | Yes (not verified in detail) | Yes | Not verified |
| Checks and alerts | 4 check types, global schedule | Policy-assigned, more types (P2, P3) | Yes, policy-assigned | Yes, policy-assigned | Not verified |
| Script library, scheduled scripts | Saved scripts, run now | Yes (P2) | Yes | Yes | Yes (not verified in detail) |
| Remote desktop | MeshCentral launch (you host Mesh) | Plus Mesh terminal and files (P6) | MeshCentral based | Built-in remote | Built-in remote (not verified in detail) |
| Patch management | No (vendor-RMM status only) | Yes (P4) | Yes | Yes | Not verified |
| Software deployment | No | Yes (P5) | Yes (Chocolatey based) | Yes | Not verified |
| Policies by client/site/group | Client only | Yes (P2) | Yes | Yes | Not verified |
| Built-in PSA (tickets, docs, assets, billing) in the same product | Yes (RivetIT is the PSA) | Yes | No (separate PSA) | Ticketing is part of the product; scope differs | No (not verified) |
| Self-hosted, source available | Yes | Yes | Yes (source-available license) | No (SaaS) | No (SaaS) |
| Hosted agent infrastructure scale | Single PHP server; 500 devices gate for the release candidate, 5,000 before promotion | Same | Not verified | Cloud scale | Cloud scale |

What the table does not say: RivetIT's RMM is built to sit inside a PSA, not to match a standalone RMM feature for feature. The honest gaps today are patching, software deployment, policies, macOS and mature alerting; those are P2 to P5 and P7, and each is a separate release behind its own switch.

## Keeping this list honest

* A status of `P2` or later is a plan, not a delivery. Do not move a row to `Shipped` until the feature is in a tagged RivetIT or RivetMSP release and has passed that edition's smoke test.
* Add a feature only if it fits a phase in the design document, or add a design addendum first.
* Regenerate the headline counts: `awk -F'|' '/^\| [0-9]+\.[0-9]+ \|/ {gsub(/^ +| +$/,"",$4); c[$4]++} END {for (k in c) print c[k], k}' docs/rmm/FEATURES.md | sort -k2`
