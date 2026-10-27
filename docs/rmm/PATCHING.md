# RMM patch management (Phase 4)

Detect first, install gated. A device reports which updates it is missing; a person (or a rule you wrote) approves them; an installation happens only for approved updates, only where a policy allows it, only inside an install window and never faster than the limits you set. The agent never restarts a machine by itself.

Status: **built in Core (migration 0028), not adopted by an edition yet**, behind the `patching` sub-switch (off by default). Linux (apt, dnf, yum, zypper best effort) is proven end to end on a real systemd container with a real apt ([section 11](#11-what-is-proven-and-what-is-not)). Windows: the **scan** was run read-only on a real Windows 11 machine (results in [WINDOWS_VERIFICATION.md](WINDOWS_VERIFICATION.md)); the Windows **install** path is cross-compiled and vetted only and has **never been run** on a real machine.

Related: [FEATURES.md](FEATURES.md) rows 9.3 to 9.6, [openapi-device.yaml](openapi-device.yaml) (`/endpoint_devices/{id}/patches*`, `/endpoint_devices/patches*`, `/endpoint_devices/patch_policies*`), [CAPACITY.md](CAPACITY.md), [PROTOCOL.md](PROTOCOL.md), [docs/modules/rmm.md](../modules/rmm.md), issue [#72](https://github.com/TheTractorHacker/rivet-core/issues/72).

## 1. The model in one page

```
agent  --patch_scan-->  rmm_patch_scans + rmm_patch_available   (what is missing, per device)
                              |
        approve / decline (a person, a rule, a policy after N days + the ring's delay)
                              |
                       status: approved
                              |
 PatchScheduler: policy install_enabled + window open + not suppressed + online + limits
                              |
        patch_install (dry_run unless live installs are on) --> rmm_patch_history
                              |
          follow-up scan --> compliance clears (patch.compliance, patch.install_failed alerts)
```

* **Update id.** Windows: the Windows Update id (a GUID, lower case). Linux: the package name (`openssl`, `libc6:amd64`, `kernel-core.x86_64`). Both have a strict syntax checked by the server and again by the agent ([section 6](#6-safety-defaults)).
* **Kind** (worst first): `critical`, `security`, `important`, `optional`, `driver`, `other`. **Severity** is the vendor's (`critical`, `important`, `moderate`, `low`, `unspecified`).
* **Status of one update on one device:** `available` (found, nobody decided), `approved`, `declined`, `installed`, `failed`.
* **Job types:** `patch_scan` (read only) and `patch_install`, each needing the capability the agent announced (`patch:scan`, `patch:install`). An agent from before Phase 4 announces neither, is never offered either job, and the golden replay of older agents is byte-identical.

## 2. Tables (migration `0028_rmm_patching`, additive, idempotent)

| Table | Holds |
|---|---|
| `rmm_patch_scans` | one row per device: last scan time, manager (`wua`, `apt`, `dnf`, `yum`, `zypper`), scan state, Windows Update service state, restart pending, last successful install, counts, error |
| `rmm_patch_available` | one row per (device, update): title, KB, kind, severity, size, restart needed, release date, EULA flag, current and candidate version, status, who or what set it, first and last seen, failure count |
| `rmm_patch_history` | one row per update per run: action (`install` or `dry_run`), result, code, detail, job, user |
| `rmm_patch_rings` | named delays: `pilot` 0 days, `broad` 7, `last` 14 (seeded; you may add more, at most 20) |
| `rmm_patch_policies`, `rmm_patch_assignments` | patch policies and where they apply (global, client, site, group, tag, device) |
| `rmm_patch_rules` | approval rules ("critical and security after 3 days in ring pilot") |
| `rmm_patch_jobs` | the ledger of scan and install jobs (what each was for, whether its result was applied; the idempotency key of a scheduled install) |

The tables are empty and cost nothing until the switch is on and a device reports a scan. Retention: history for `patch_history_days` (365), installed rows for 14 days, applied ledger rows for 30 days.

## 3. How a scan works

### Windows

`internal/patch/scripts/scan.ps1`, embedded in the agent and run as `powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -EncodedCommand` with the same job environment as a `powershell` job (PATHEXT, `COMPUTERNAME`, CLIXML clean-up: lessons F1 and F2 of [WINDOWS_VERIFICATION.md](WINDOWS_VERIFICATION.md)).

* One call to the Windows Update Agent COM API: `Microsoft.Update.Session` > `CreateUpdateSearcher().Search('IsInstalled=0 and IsHidden=0')`. **Nothing is downloaded or installed**, the Windows Update service is never started, stopped or changed.
* Per update (`reboot_required` is "may restart": true unless `RebootBehavior` is "never reboots"): `Identity.UpdateID`, KB number, title, categories, `MsrcSeverity`, `MaxDownloadSize`, `InstallationBehavior.RebootBehavior`, `LastDeploymentChangeTime`, `EulaAccepted`. Kind: *Security Updates* is `security`, *Critical Updates* `critical`, *Drivers* `driver`, rollups and service packs `important`, definition updates `other`, the rest `optional`.
* Also: whether a restart is pending (`Microsoft.Update.SystemInfo`, the Windows Update and CBS registry markers), the state of the `wuauserv` service (read only), the date of the last successful installation from the update history.
* No parameter from the server except an allow-listed category filter (`security,critical,important,definition,feature,driver,optional,other`) and `max_items`, passed as environment variables, never written into the script text. The script prints exactly one JSON line; the agent validates and re-encodes it. The script also works standalone as a plain `powershell` job, which is how it was run on the real machine.

### Linux

The package manager is found at a fixed absolute path (no `PATH` lookup), started from an argument list with `LC_ALL=C`, a timeout and an output cap.

| Manager | Scan | Security detection |
|---|---|---|
| apt | `apt list --upgradable` (held packages excluded), `apt-cache policy` for the candidates | the candidate comes from a `*-security` suite or `security.ubuntu.com` / `security.debian.org`; kind `security`, severity `important`; everything else `optional` (apt has no severity) |
| dnf, yum | `check-update` (exit 100 means updates), `updateinfo list --security` | advisories: `Critical` is kind `critical`, `Important` / `Moderate` / `Low` are `security` with that severity |
| zypper | `list-patches` | best effort; the scan state is always `partial` (plain package updates are not in `list-patches`) |

Restart needed: `/var/run/reboot-required`, `needs-restarting -r`. Last install: `apt/history.log`, `dnf` log. The agent never runs `apt update`: results are as fresh as the machine's package cache.

### When

A device is scanned by the scheduler about every `patch_scan_interval_s` seconds (default 24 h, 1 h to 7 days), spread by a stable hash of the device id so a fleet never scans at once; and on request (`POST .../patches/scan`). A scan already waiting is never queued twice. At load-shedding level 2 and above no scan is queued and a result that arrives is applied by the next housekeeping pass.

## 4. Approval, rings, rules

* **By hand.** `POST patches/approve` / `decline` with `update_ids` (and optionally `device_id` or `client_id`). Only the caller's own clients are touched. A declined update is never approved by anything automatic; a person can approve it again.
* **Two people.** A policy with `two_person` makes a manual approval wait for a second user: the exact (device, update) pairs are frozen in a Phase 2 approval of kind `patch` (`202 pending_approval`). Someone with `rmm.job.approve` and `rmm.patch.manage` decides it with the ordinary approvals endpoint; the requester can never decide their own request.
* **Policy, automatic.** `approval_mode: auto` plus `auto_days_<kind>`: an update is approved N days after it was first seen, **plus the delay of the device's ring**. Without `auto_days_<kind>` that kind is never approved by itself. Optional drivers are by default never automatic.
* **Rules.** "Kinds X and Y, severities Z, after N days, in ring R" (ring optional; client optional). First match wins; evaluated for each device when its scan is applied and about hourly afterwards.
* **Rings.** A ring is only a delay (days) added to automatic approvals. A device is in the ring its effective policy names. `pilot` (0), `broad` (7), `last` (14) are seeded.

## 5. Install, windows, reboot

* **`POST devices/{id}/patches/install`.** `mode` is `dry_run` unless the body says `install`. A dry run needs `rmm.patch.manage` and lists what a real install **would** do (the approved updates, or the ids you name); it changes nothing. A real install needs `rmm.patch.install` and `confirm: true` and takes only `approved` updates (or a failed one, by name). One job carries at most 40 ids; the answer says how many remain. One install job at a time per device.
* **What the agent does.** Re-scans first and installs only ids that are still applicable (others come back `not_applicable`). Windows: download and install through the Windows Update Agent, per-update result codes, **never accepts an EULA** (`eula_required`), honours `max_minutes`. Linux: `apt-get install --only-upgrade -- <packages>` (or `dnf`/`yum`/`zypper`), non-interactive, **a simulation first and a transaction that would remove a package is skipped**, per-package result read back from the package database, a held lock is reported (`locked`) and never broken.
* **Scheduler.** For a device whose effective policy has `install_enabled`: approved updates, window open, not suppressed, online, announced `patch:install`, no install already waiting, under `patch_max_installs` (25) in the fleet and `patch_max_installs_per_client` (5), load shedding at 0 or 1. **Until `patch_live_install` is 1 the scheduler queues dry runs only** (one a day per device). A failed update is retried after 12 hours, at most 3 times; then an administrator approves it again.
* **Windows.** `window_mode`: `none` (any time), `daily` (days, `window_start` `HH:MM`, `window_minutes`, `window_tz`; a window may cross midnight; devices start up to 10 minutes after it opens, by hash), or `maintenance` (only while the chosen Phase 3 maintenance window is open: the administrator chose that window for these machines, so a `suppress` window is exactly the maintenance time). For `none` and `daily` the Phase 3 schedule gate is asked: another `suppress` window ("leave this device alone") holds the install back.
* **Restart.** The agent only reports `reboot_required`. `reboot_policy`: `never` (default), `if_required_defer` (report only: `reboot_pending` shows in the summary and the scan), `if_required_now` (after a **clean** install the server queues a **separate, destructive `reboot` job** with a 300 second delay, attributed to the person who confirmed it). `if_required_now` needs `reboot_confirm: true` in the policy (which needs the reboot grant and records who) or, for a manual install, `confirm_reboot: true` and `rmm.job.reboot`. Without the confirmation it degrades to `if_required_defer`.
* **Results.** Applied from the job report as soon as it arrives (or by the next housekeeping pass): history rows, status, `reboot_pending`, events, a follow-up scan to prove the result, and the two alert checks.

## 6. Safety defaults

* The switch is off; nothing is scanned until an administrator turns it on and reads the cost text (section 9).
* No patch policy means: manual approval, nothing installed by the scheduler, no restart.
* A dry run is the default of the API; the scheduler runs dry runs only until `patch_live_install` is 1.
* Update ids are validated on both sides with anchored patterns (`D` modifier: a trailing newline does not pass): Windows `^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$`, Linux `^[a-z0-9][a-z0-9+._-]{0,99}(:[a-z0-9-]{1,20})?$` (never starts with a symbol). Parameters of the job are a strict allow-list (`ids` of at most 40 / 1000 characters, `mode`, `max_minutes`); an unknown key refuses the job. Linux package names always come after `--`. The server sends only ids from its own table for that device; the agent checks them again.
* Install jobs are destructive: offered at most once, never re-sent when the acknowledgement is lost.
* Nothing the agent runs is built from a string: Windows runs fixed embedded scripts (filters in environment variables), Linux an argument list.
* Out of scope for a client-restricted user: another client's devices are the same 404 as missing ones, on every route (a test per route).
* `disable_patching` in the agent's `config.json` withdraws both job types and capabilities on that machine.

## 7. Compliance and alerts

* `patch.compliance` (a check of the Phase 3 alert engine, so debounce, ownership, escalation and maintenance windows apply): `ok`; `warn` when a critical or security update has been missing for more than `patch_overdue_days` (14); `fail` at twice that; `warn` when the last good scan is more than three scan intervals old. Declined updates are a decision and do not count.
* `patch.install_failed`: `fail` while an update the module tried to install is marked failed; recovers when it installs, the device stops listing it or it is declined. Only devices that ever failed get the check.
* Read models: `RmmReadModel::patchSummary($device)` and `patchCompliance($visibleClientIds, $filters)`: devices, compliant, non-compliant, never scanned, stale, restart pending, failed installs, missing critical, by client, site or group, the non-compliant devices, and the ring distribution (exact up to 1000 devices).

## 8. Events, abilities, routes

Events (through `RmmEventsInterface`): `rmm.patch.scan_completed`, `rmm.patch.approved`, `rmm.patch.install_started`, `rmm.patch.install_finished`, `rmm.patch.install_failed`. Audit rows for scans, installs, approvals, declines and every policy, ring and rule change.

| Ability | Grants |
|---|---|
| `rmm.patch.view` | read patch state, history, compliance, policies, rings, rules (also needs `rmm.device.view`) |
| `rmm.patch.manage` | scan, approve, decline, dry-run, rules, rings, patch policies (rings and policies also need a scope that sees every client) |
| `rmm.patch.install` | a real installation |

Routes: see [openapi-device.yaml](openapi-device.yaml). Editions map the abilities in their role matrix (suggested: `view` like `device.view`, `manage` like `job.run_saved`, `install` like `job.reboot` or stronger).

## 9. Settings

Sub-switch `patching`. Plain-language cost text for the administration page: *"Patch management asks every device for its list of missing updates about once a day (a short read-only check, no download) and keeps that list and the install history in the database. It installs nothing by itself until you assign a patch policy that allows it, approve updates, and switch on live installs."*

Limits (`limits_json`, all validated): `patch_scan_interval_s` 86400 (3600 to 604800), `patch_overdue_days` 14 (1 to 365), `patch_max_installs` 25 (1 to 10000), `patch_max_installs_per_client` 5 (1 to 1000), `patch_live_install` 0 (0 or 1), `patch_history_days` 365 (30 to 3650), `patch_batch` 100 (10 to 5000; devices per step of one pass, halved at shed level 1). Capacity: [CAPACITY.md](CAPACITY.md).

## 10. What is NOT done

* Third-party application patching (winget, Chocolatey, brew): Phase 5. Row 9.7 of the feature list stays planned.
* WSUS and Configuration Manager integration: the scan asks the Windows Update Agent, which follows the machine's own update source (a WSUS-managed machine sees what its WSUS approves); this module does not talk to WSUS, does not approve there and does not report to it.
* Rollback: no uninstall of an update from this module (a Windows KB can be removed with a `powershell` job; apt and dnf downgrades are not offered). `rmm_patch_history.action` has room for `uninstall`, nothing writes it.
* A driver update policy beyond kind `driver`, never approved automatically unless `auto_days_driver` is set.
* Feature updates of Windows (a new Windows version) are not special-cased: they appear as `optional` / `feature` and are not installed unless approved by name.
* macOS: Phase 7.
* Progress while an install runs (the job protocol reports only `running` and the final result).
* Windows installs longer than the job timeout (an hour by default): `max_minutes` is cut to fit; the rest follows in a next job.
* `patch_scan` results are not carried in the check-in; they come as the job result.
* apt freshness: the agent never runs `apt update`.

## 11. What is proven and what is not

| | Proof |
|---|---|
| Server (PHP) | `PatchTest`, `PatchSchedulerTest`, `PatchPolicyResolverTest` (a test per route with a client-restricted user, two-person approval, hostile report content, scheduler gates, shed levels, limits, windows, compliance); PHPStan level 6; full suite |
| Linux agent | 42 Go tests in `internal/patch` on fixture outputs (Ubuntu 22.04 and 24.04, Debian 12, Rocky 9, Alma 9, Fedora dnf5, yum, zypper), hostile id tests; **`endpoint-agent/e2e/run_patch_proof.sh`: the real agent under real systemd in `jrei/systemd-ubuntu:24.04` with real apt and a local repository (suite `noble-security`, two or more versions of a harmless `rivetit-dummy` package), the real PHP server on a scratch database: 57 checks, `PATCH PROOF PASS`** (below) |
| Windows scan | run once, read only, on the owner's Windows 11 laptop (agent 0.8.0, as a plain `powershell` job with the embedded script): 4 optional driver updates, identical to what the older starter script listed two days before; only the *Drivers* category was exercised. [WINDOWS_VERIFICATION.md](WINDOWS_VERIFICATION.md), section "Patch scan" |
| Windows install | **not run.** Cross-compiled and vetted; `install.ps1` was exercised against a stubbed COM layer in PowerShell on Linux only. Treat the first real Windows install as a test: dry run first, one machine in the pilot ring, `max_minutes` small |
| dnf, yum, zypper on real hosts | parsers tested on hand-written fixtures in the real formats; **never run on a real Rocky, Alma, Fedora or SUSE host** |


### The Linux proof, step by step (`e2e/run_patch_proof.sh`)

Run on 2026-10-16. The test host has no network; the Ubuntu archive is removed from apt and replaced by a `file:` repository built by `e2e/patch_repo.sh` (two to five versions of `rivetit-dummy`, a `rivetit-other` and a `rivetit-slow` whose postinst sleeps 100 seconds to hold the dpkg lock). What it shows, each as a check that failed the run when untrue:

1. The agent enrolls against the PHP server and announces `patch:scan`, `patch:install`, `job:patch_scan`, `job:patch_install`.
2. A scan finds both updates (kind `security`, because the candidate comes from the `noble-security` suite, manager `apt`, current 1.0 and candidate 1.1); a second scan request while one waits is not queued twice; the machine is untouched.
3. A dry run of an update that is only `available` works and changes nothing; a real install of an unapproved update is refused (404); after approving only `rivetit-dummy`, a real install needs `confirm`; the default install is a dry run that lists exactly the approved update; the real install **upgrades only `rivetit-dummy` (1.1)**, `rivetit-other` stays 1.0 and the list of every other installed package is byte-identical; history and status are written; the machine is not restarted; the follow-up scan queued by the server shows only `rivetit-other` left; a declined update cannot be installed.
4. A package whose postinst asks for a restart (`/run/reboot-required`): the install succeeds, the server shows `reboot_pending` after the next scan, **no reboot job exists and the boot id is unchanged** (policy `if_required_defer`).
5. **Lock held** by another `apt-get`: the install job ends `failed` (not hung), the update's result is `locked`, nothing is installed, `patch.install_failed` opens an alert, the lock holder is neither killed nor loses its lock file; after it finishes the retry succeeds and the alert recovers.
6. **No longer applicable** (the version vanished from the repository after approval): `not_applicable`, nothing installed. **Unknown package** (a row written straight into the table): `not_found`, the job fails cleanly. Four hostile ids (`-oAPT::Foo`, `a b`, `x;touch /w/pwned`, `$(touch /w/pwned)`) are refused by the server with 422; a job **forged around the server** (written straight into the queue so the server signs it) with the id `-oAPT::Foo` is refused by the agent as `invalid_params`; nothing was touched.
7. **The scheduler**: a housekeeping pass queues the scan by itself; an approval rule approves the new security update (a declined update stays declined); with a policy that allows installing, the pass queues a **dry run only**; after the `patch_live_install` limit is set to 1 the next pass queues the real install, which runs on the device (dummy 1.5, nothing else upgraded, still no restart); the fleet compliance page then reads the device as compliant.

Not covered by the proof: dnf, yum and zypper hosts, a Windows machine, two-person approval over HTTP (it is covered by `PatchTest`), the restart job itself (`if_required_now`; covered by `PatchTest` against the server only, the existing `reboot` job is Phase 0 code).
