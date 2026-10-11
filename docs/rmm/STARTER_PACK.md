# RMM starter pack

A new RMM install is empty: no scripts, no policies, nothing to run on the first device. The starter pack ships with RivetCore so day one is useful: **73 ready-made scripts** (41 Windows PowerShell, 32 Linux bash), **3 check template sets** and **3 policy drafts** (Workstation, Windows Server, Linux Server). An administrator installs all of it, or a selection, with one call. Nothing is installed by itself, nothing runs by itself, and no policy reaches a device until an administrator has read it, switched it on and assigned it.

Status: built in Core (the Phase 2 script library and policy store are its only dependencies, no new table, no migration). Not adopted by an edition yet: the editions need a "Starter pack" page and a "Starter" badge in the script library, see [section 10](#10-what-an-edition-needs-to-build).

Related: [FEATURES.md](FEATURES.md) rows 7.13 and 11.7, [docs/modules/rmm.md](../modules/rmm.md), [openapi-device.yaml](openapi-device.yaml) (`/endpoint_devices/starter/*`), [starter-pack.manifest.json](starter-pack.manifest.json) (the public catalog the website lists).

## 1. What is in it

| | Count |
|---|---|
| Windows PowerShell scripts | 41 (29 read only, 8 change the system, 4 disruptive) |
| Linux bash scripts | 32 (28 read only, 2 change the system, 2 disruptive) |
| Check template sets | 3: `workstation-baseline`, `windows-server-baseline`, `linux-server-baseline` |
| Policy drafts | 3: `Starter: Workstation baseline`, `Starter: Windows Server baseline`, `Starter: Linux Server baseline` |

The pack is the folder `src/Rmm/Starter/data/` inside the package. It is versioned (`pack.json`), every file has a SHA-256 in `manifest.json`, and `StarterPack` refuses to load a pack whose files do not match.

## 2. Safety model

* **Risk classes.** Every script has one. `read_only` only reads and reports. `changes_system` changes files or settings but does not interrupt people or the machine (clean temp, flush DNS, resync time, start a Defender scan, vacuum the journal). `disruptive` can interrupt users or services (restart a service, restart the print spooler, clear the print queue, reboot).
* **Approval.** `requires_approval` defaults per class: `read_only` no, `changes_system` no, `disruptive` yes (a second person with `rmm.job.approve` approves the run). `disruptive` scripts are also flagged `destructive`, so the job is offered to a device at most once and never retried. An administrator can change both flags in the library afterwards; an update from the pack never changes them back.
* **Dry run first.** Every `changes_system` and `disruptive` script has a bool parameter `dry_run` whose default is **true**. With it on the script changes nothing, prints `[DRY-RUN] would ...` lines and exits 0. The first run of anything is safe; the technician turns `dry_run` off on purpose. With it off the script prints `[DONE] ...` and `[SKIP] ...` per action, is idempotent, and logs what it did.
* **Typed, bounded parameters.** `string`, `int`, `bool` and `choice` only (never `secret`); integers have a minimum and a maximum; a free string is validated by an anchored allow-list pattern before use (host names and addresses, service and unit names, absolute paths without `..`) and is never put into a command string. A hostile value (leading dash, `;`, `$()`, backticks, a newline, `..`) is refused with exit code 2; the Linux proof feeds those values to every string parameter.
* **What no script does.** Create users, change passwords or group membership, disable or weaken security tooling (Defender, firewalls, fail2ban, auditd, SELinux), download or execute anything from the internet, send data to another host, or embed a secret, key or web address. `StarterLint` enforces a denylist for that when a pack loads, a read-only script may not even contain a command that changes the machine, and the test suite runs the same denylist over every file with a list of known-bad snippets as a control.
* **Signed.** Scripts are signed with the instance key when they are installed (the library does that), and the integrity check runs before every run, like any other script.
* **Exit codes.** 0 success, including "not present on this machine" (`NOT AVAILABLE: ...`); 1 only where a script's job is to detect a bad state; 2 invalid parameter; 3 a prerequisite of a changing script failed.
* **Windows.** PowerShell 5.1 compatible, no PowerShell 7 syntax, each script at most 8,500 characters (the server's hard cap is 10,000 including the parameter lines, because a PowerShell script travels in an encoded command line).

## 3. The catalog

Generated from the pack by `php scripts/build-starter-pack.php`; a test fails when it is stale.

<!-- starter:catalog:start -->

### Windows (PowerShell) scripts

| Id | Name | Risk | Approval | What it does | Parameters |
|---|---|---|---|---|---|
| `win-battery-report` | Battery health report (Windows) | read_only | no | Reports battery status and charge, and compares design to full-charge capacity as a health percentage. | none |
| `win-bitlocker-status` | BitLocker status (Windows) | read_only | no | Reports BitLocker protection status, encryption percentage and key protector types for each volume. Never prints recovery passwords or keys. | none |
| `win-browser-cache-clean` | Clean Chrome and Edge cache (Windows) | changes_system | no | Clears the Cache, Code Cache and GPUCache folders of Chrome and Edge for all user profiles, skipping a running browser. Cookies, history and passwords are not touched. Dry run by default. | `browser` (choice, both)<br>`dry_run` (bool, true) |
| `win-cert-expiring` | Expiring certificates (Windows) | read_only | no | Lists certificates in a LocalMachine store that are expired or expire within a number of days. | `days` (int 1..3650, 30)<br>`store` (choice, My) |
| `win-clean-temp` | Clean Windows and user temp files (Windows) | changes_system | no | Removes files older than N days from C:\Windows\Temp and each profile Temp folder, reports bytes freed. Does not touch SoftwareDistribution. Dry run by default. | `age_days` (int 1..365, 7)<br>`dry_run` (bool, true) |
| `win-defender-quick-scan` | Start Microsoft Defender quick scan (Windows) | changes_system | no | Starts a Defender quick scan and waits a limited time for it to finish. Dry run by default. | `dry_run` (bool, true) |
| `win-defender-status` | Microsoft Defender status (Windows) | read_only | no | Reports real-time protection, signature age and version, last scan times and tamper protection state. | none |
| `win-defender-update-signatures` | Update Microsoft Defender signatures (Windows) | changes_system | no | Asks Defender to update its antimalware signatures with Update-MpSignature. Dry run by default. | `dry_run` (bool, true) |
| `win-disk-health` | Physical disk health (Windows) | read_only | no | Reports physical disk health, operational status, media type and size, plus temperature, wear and read error counters when available. | none |
| `win-dotnet-vcredist-versions` | .NET and Visual C++ runtimes (Windows) | read_only | no | Lists the installed .NET Framework version, .NET runtimes (if dotnet is installed) and Visual C++ redistributables. | none |
| `win-eventlog-errors` | Critical and error events (Windows) | read_only | no | Summarises Critical and Error events from the System and Application logs, grouped by source and event ID with the newest message. | `hours` (int 1..168, 24) |
| `win-firewall-status` | Windows Firewall status (Windows) | read_only | no | Shows each firewall profile with enabled state and default actions, plus counts of enabled rules. | none |
| `win-flush-dns` | Flush DNS resolver cache (Windows) | changes_system | no | Clears the Windows DNS client resolver cache. Dry run by default. | `dry_run` (bool, true) |
| `win-free-space-volumes` | Free space by volume (Windows) | read_only | no | Lists each fixed volume with size, free space and percent free. | none |
| `win-hosts-file` | Hosts file contents (Windows) | read_only | no | Prints the active entries of the hosts file with the entry count, SHA256 hash and last write time. | none |
| `win-installed-software` | Installed software list (Windows) | read_only | no | Lists installed programs from the 64-bit, 32-bit and loaded user uninstall registry keys, de-duplicated and sorted by name. | `limit` (int 10..1000, 300) |
| `win-largest-folders` | Largest top-level folders (Windows) | read_only | no | Totals disk usage of each top-level folder under a root directory (one level deep) and lists the largest, within a time budget. | `root_dir` (string, C:\)<br>`top_n` (int 1..50, 15) |
| `win-last-logons` | Recent interactive logons (Windows) | read_only | no | Lists recent interactive, RDP and cached logons from Security event 4624 and the last use time of each local profile. Reports clearly if the Security log is unreadable. | `days` (int 1..30, 7)<br>`limit` (int 10..500, 100) |
| `win-listening-ports` | Listening ports and owning processes (Windows) | read_only | no | Lists listening TCP ports and bound UDP endpoints with the process that owns each. | none |
| `win-local-administrators` | Local Administrators group members (Windows) | read_only | no | Lists the members of the local Administrators group with their source and object class. Report only. | none |
| `win-local-users` | Local user accounts (Windows) | read_only | no | Lists local accounts with enabled state, last logon, password last set and whether a password is required. No password data is shown. | none |
| `win-network-diagnostics` | Network diagnostics to a target (Windows) | read_only | no | Runs ping, a traceroute (max 15 hops, no name lookups) and a DNS lookup against one validated host name or IP address. | `target` (string, required)<br>`count` (int 1..10, 4) |
| `win-pending-reboot-reasons` | Pending reboot reasons (Windows) | read_only | no | Reports each pending-reboot signal separately (CBS, Windows Update, file renames, rename, domain join, SCCM) and a final verdict. | none |
| `win-pending-windows-updates` | Pending Windows updates (Windows) | read_only | no | Lists updates not yet installed and not hidden, using the Windows Update Agent search. May take a few minutes. | none |
| `win-print-queue-clear` | Clear print queue (Windows) | disruptive | yes | Stops the Print Spooler, deletes only the *.SPL and *.SHD files in the spool PRINTERS folder, then starts the Spooler again. Dry run by default. | `dry_run` (bool, true) |
| `win-print-spooler-restart` | Restart Print Spooler (Windows) | disruptive | yes | Restarts the Print Spooler service and reports its state before and after. Dry run by default. | `dry_run` (bool, true) |
| `win-rdp-status` | Remote Desktop status (Windows) | read_only | no | Reports whether RDP is enabled, whether NLA is required, the port, the Remote Desktop firewall rules and current sessions. | none |
| `win-reboot-if-pending` | Restart if a reboot is pending (Windows) | disruptive | yes | Checks for a pending reboot and, if one is needed, schedules a restart after a delay with a visible message. Will not stack a second schedule. Dry run by default. | `delay_minutes` (int 1..1440, 15)<br>`dry_run` (bool, true) |
| `win-restart-service` | Restart a named service (Windows) | disruptive | yes | Restarts one service by exact name after strict validation. Refuses the agent and critical system services. Dry run by default. | `service_name` (string, required)<br>`wait_seconds` (int 5..300, 60)<br>`dry_run` (bool, true) |
| `win-scheduled-tasks` | Non-Microsoft scheduled tasks (Windows) | read_only | no | Lists scheduled tasks outside the Microsoft folders with state, run-as account, last result and last/next run times. | `limit` (int 10..500, 200) |
| `win-services-auto-stopped` | Stopped automatic services (Windows) | read_only | no | Lists services set to start automatically that are not running, excluding trigger-start services and known benign ones. | none |
| `win-smb1-status` | SMB1 status (Windows) | read_only | no | Checks the SMB1 server and client features and the SMB server configuration, and gives a plain-language verdict. | none |
| `win-startup-items` | Startup items (Windows) | read_only | no | Lists Run and RunOnce registry entries (machine and loaded user hives), startup folders and Win32_StartupCommand entries. | none |
| `win-system-info` | System information summary (Windows) | read_only | no | Prints OS and build, model, serial number, CPU, memory, domain or workgroup, IP addresses, last boot time and BIOS details. | none |
| `win-teams-cache-clean` | Clean Microsoft Teams cache (Windows) | changes_system | no | Clears classic Teams cache folders and the new Teams local cache for all user profiles. Skips everything if Teams is running and skip_running is true. Dry run by default. | `skip_running` (bool, true)<br>`dry_run` (bool, true) |
| `win-time-resync` | Force time resync (Windows) | changes_system | no | Starts the Windows Time service if stopped and runs w32tm /resync /force, reporting offset before and after. Dry run by default. | `dry_run` (bool, true) |
| `win-time-status` | Time synchronisation status (Windows) | read_only | no | Shows W32Time service state, time source, configuration, offset, last sync and the time zone. | none |
| `win-top-processes` | Top processes by CPU and memory (Windows) | read_only | no | Lists the busiest processes by current CPU use and by working set memory. | `top_n` (int 1..50, 10) |
| `win-uptime-last-boot` | Uptime and last boot time (Windows) | read_only | no | Shows the last boot time and how long the machine has been running, with a note when Fast Startup can make uptime look longer than it is. | none |
| `win-windows-update-reset` | Reset Windows Update components (Windows) | changes_system | no | Stops the update services, renames SoftwareDistribution and catroot2 to dated .bak folders, removes older .bak folders and restarts the services. Dry run by default. | `dry_run` (bool, true) |
| `win-winget-outdated` | Outdated packages (winget) (Windows) | read_only | no | Lists packages with available upgrades using winget. Only lists; nothing is installed or upgraded. | none |

### Linux (bash) scripts

| Id | Name | Risk | Approval | What it does | Parameters |
|---|---|---|---|---|---|
| `lin-cert-expiry` | Certificate expiry check (Linux) | read_only | no | Shows subject, issuer and days left for a PEM certificate file. Refuses files containing private keys. | `cert_file` (string, required) |
| `lin-containers` | Containers (Linux) | read_only | no | Lists Docker and Podman containers and disk usage if installed. | none |
| `lin-cron-jobs` | Scheduled jobs (Linux) | read_only | no | Lists system crontab, cron.d, per-user crontabs and systemd timers. | none |
| `lin-disk-usage` | Disk usage by filesystem (Linux) | read_only | no | Shows filesystem usage with df, excluding tmpfs, overlay and similar noise. | none |
| `lin-fail2ban-status` | Fail2ban status (Linux) | read_only | no | Shows fail2ban jails and their ban counts if fail2ban is installed. | none |
| `lin-failed-units` | Failed systemd units (Linux) | read_only | no | Lists failed systemd units and the slowest boot units. | none |
| `lin-firewall-status` | Firewall status (Linux) | read_only | no | Reports the state and rules summary of ufw, firewalld, nftables and iptables, whichever exist. | none |
| `lin-inode-usage` | Inode usage (Linux) | read_only | no | Shows inode usage per filesystem with df -i and flags filesystems at 90 percent or more. | none |
| `lin-installed-packages` | Installed packages (Linux) | read_only | no | Lists installed package names and versions sorted by name. | `limit` (int 50..5000, 500) |
| `lin-journal-errors` | Recent journal errors (Linux) | read_only | no | Shows error-level log entries from the last N hours grouped by unit, with a fallback to syslog files. | `hours` (int 1..168, 24)<br>`limit` (int 10..1000, 200) |
| `lin-journal-vacuum` | Vacuum systemd journal (Linux) | changes_system | no | Removes journal entries older than a number of days and reports size before and after. | `keep_days` (int 1..365, 14)<br>`dry_run` (bool, true) |
| `lin-largest-dirs` | Largest directories under a path (Linux) | read_only | no | Shows the largest first-level directories under a root directory, staying on one filesystem. Aborts after 120 seconds. | `root_dir` (string, /)<br>`top_n` (int 1..50, 15) |
| `lin-last-logins` | Recent logins (Linux) | read_only | no | Shows recent logins, failed login count when readable, and users currently logged in. | none |
| `lin-listening-ports` | Listening ports (Linux) | read_only | no | Lists listening TCP and UDP sockets with owning processes using ss or netstat. | none |
| `lin-logged-in-users-sessions` | Logged-in users and sessions (Linux) | read_only | no | Shows active login sessions using loginctl, who and w. | none |
| `lin-memory-swap` | Memory, swap and pressure (Linux) | read_only | no | Reports free memory, swap devices, the top memory consumers and pressure stall information when present. | none |
| `lin-mounts-fstab` | Mounts and fstab review (Linux) | read_only | no | Lists mounts, read-only mounts, fstab contents and filesystems at 90 percent or more. | none |
| `lin-needrestart-check` | Reboot and restart needed check (Linux) | read_only | no | Reports whether a reboot is required and which services need a restart (needrestart, Debian flag files, needs-restarting, zypper ps). | none |
| `lin-network-diagnostics` | Network diagnostics to a target (Linux) | read_only | no | Pings, traces the route to and resolves a validated host name or IP address. | `target` (string, required)<br>`count` (int 1..10, 4) |
| `lin-network-interfaces` | Network interfaces and routes (Linux) | read_only | no | Shows addresses, routes, default gateway and DNS servers. | none |
| `lin-package-cache-clean` | Clean package cache (Linux) | changes_system | no | Cleans the apt, dnf, yum or zypper package cache and reports bytes before and after. | `dry_run` (bool, true) |
| `lin-privileged-accounts` | Privileged accounts audit (Linux) | read_only | no | Lists uid 0 accounts, sudo/wheel/admin members, sudoers entries, NOPASSWD rules, login shells and password-less accounts without printing hashes. | none |
| `lin-reboot-if-required` | Reboot if required (Linux) | disruptive | yes | Schedules a delayed reboot with a wall message only if a reboot is required and none is already scheduled. | `delay_minutes` (int 1..1440, 15)<br>`dry_run` (bool, true) |
| `lin-restart-unit` | Restart a systemd unit (Linux) | disruptive | yes | Restarts a named systemd unit and reports its state before and after. Refuses rivet*, systemd-* and dbus* units. | `unit_name` (string, required)<br>`wait_seconds` (int 5..300, 30)<br>`dry_run` (bool, true) |
| `lin-security-updates` | Security updates available (Linux) | read_only | no | Lists pending security-only updates using apt, dnf, yum or zypper without installing anything. | none |
| `lin-services-running` | Running services (Linux) | read_only | no | Lists running systemd services and counts enabled unit files. | none |
| `lin-smart-health` | Disk SMART health (Linux) | read_only | no | Runs smartctl health checks on physical disks and NVMe devices. | none |
| `lin-ssh-audit` | SSH server configuration audit (Linux) | read_only | no | Read-only audit of the effective sshd configuration with PASS or REVIEW remarks. | none |
| `lin-system-summary` | System summary (Linux) | read_only | no | OS release, kernel, architecture, uptime, load, virtualization, CPU and memory, hostname and last boot time. | none |
| `lin-time-sync` | Time synchronization (Linux) | read_only | no | Reports clock sync state using timedatectl, chrony, ntpq or systemd-timesyncd. | none |
| `lin-top-processes` | Top processes by CPU and memory (Linux) | read_only | no | Lists the busiest processes by CPU and by memory using ps. | `top_n` (int 1..50, 10) |
| `lin-updates-available` | Updates available (Linux) | read_only | no | Lists pending package updates using apt, dnf, yum or zypper without refreshing indexes or installing anything. | none |

### Check template sets

| Id | Use | Checks | Parameters |
|---|---|---|---|
| `workstation-baseline` | workstation (windows) | `starter.disk_free` (disk, every 300 s)<br>`starter.memory` (memory, every 60 s)<br>`starter.cpu_sustained` (cpu, every 60 s)<br>`starter.svc_eventlog` (service, every 300 s)<br>`starter.svc_firewall` (service, every 300 s)<br>`starter.svc_dns_client` (service, every 300 s)<br>`starter.svc_wmi` (service, every 300 s)<br>`starter.pending_reboot` (pending_reboot, every 3600 s)<br>`starter.time_skew` (ntp, every 3600 s)<br>`starter.cert_expiry` (cert, every 21600 s)<br>`starter.smart` (smart, every 21600 s)<br>`starter.uptime` (script, every 3600 s)<br>`starter.av` (av, every 3600 s) | `ntp_server` (string, pool.ntp.org)<br>`uptime_warn_days` (int, 30)<br>`uptime_fail_days` (int, 60) |
| `windows-server-baseline` | windows-server (windows) | `starter.disk_free` (disk, every 300 s)<br>`starter.memory` (memory, every 60 s)<br>`starter.cpu_sustained` (cpu, every 60 s)<br>`starter.svc_eventlog` (service, every 300 s)<br>`starter.svc_firewall` (service, every 300 s)<br>`starter.svc_dns_client` (service, every 300 s)<br>`starter.svc_wmi` (service, every 300 s)<br>`starter.svc_server` (service, every 300 s)<br>`starter.svc_time` (service, every 300 s)<br>`starter.svc_scheduler` (service, every 300 s)<br>`starter.pending_reboot` (pending_reboot, every 3600 s)<br>`starter.time_skew` (ntp, every 3600 s)<br>`starter.cert_expiry` (cert, every 21600 s)<br>`starter.smart` (smart, every 21600 s)<br>`starter.uptime` (script, every 3600 s)<br>`starter.av` (av, every 3600 s)<br>`starter.backup_service` (service, every 300 s, only when `backup_service` is set) | `ntp_server` (string, pool.ntp.org)<br>`uptime_warn_days` (int, 45)<br>`uptime_fail_days` (int, 90)<br>`backup_service` (string, empty) |
| `linux-server-baseline` | linux-server (linux) | `starter.disk_free` (disk, every 300 s)<br>`starter.inodes` (inodes, every 600 s)<br>`starter.memory` (memory, every 60 s)<br>`starter.swap` (memory, every 300 s)<br>`starter.cpu_sustained` (cpu, every 60 s)<br>`starter.systemd_failed` (systemd_failed, every 300 s)<br>`starter.svc_ssh` (service, every 300 s)<br>`starter.pending_reboot` (pending_reboot, every 3600 s)<br>`starter.time_skew` (ntp, every 3600 s)<br>`starter.cert_expiry` (cert, every 21600 s, only when `cert_paths` is set)<br>`starter.smart` (smart, every 21600 s)<br>`starter.uptime` (script, every 3600 s)<br>`starter.backup_service` (service, every 300 s, only when `backup_service` is set) | `ntp_server` (string, pool.ntp.org)<br>`ssh_service` (string, sshd)<br>`cert_paths` (list, empty)<br>`uptime_warn_days` (int, 45)<br>`uptime_fail_days` (int, 90)<br>`backup_service` (string, empty) |

### Policy drafts

| Id | Name | Built from | Notes |
|---|---|---|---|
| `policy-workstation` | Starter: Workstation baseline | `workstation-baseline` | Draft. Baseline checks for Windows workstations. Review, then enable and assign it. |
| `policy-windows-server` | Starter: Windows Server baseline | `windows-server-baseline` | Draft. Baseline checks for Windows servers, with an optional backup agent service. Review, then enable and assign it. |
| `policy-linux-server` | Starter: Linux Server baseline | `linux-server-baseline` | Draft. Baseline checks for Linux servers, with optional certificate and backup service checks. Review, then enable and assign it. |

<!-- starter:catalog:end -->

How the check template sets are built: disk free uses the original `disk` check (warn below 20 % free, critical below 10 %; servers add absolute limits). Memory and CPU use the Phase 3 `thresholds` with a duration (a tier must hold for 3 samples and 15 minutes, with hysteresis), and the agent's own instant verdict is set to 100 % so a short spike never alerts: only sustained pressure does. Services are `service` checks; Windows key services are Event Log, Windows Firewall, DNS Client and WMI, servers add Server, Windows Time and Task Scheduler. `pending_reboot`, `ntp` (clock offset, warn 1 s, critical 5 s), `cert` (30 and 7 days), `smart` and `av` (Windows only) are the Phase 3 types. "Uptime too long" is a script check (warn at 30 days, critical at 60; servers 45 and 90) because no check type reports uptime; script checks are signed and need `feature.script_checks`, which the drafts turn on. The optional checks (a backup agent service named by `backup_service`, Linux certificate paths in `cert_paths`) are left out of a draft while their parameter is empty.

## 4. Installing

All routes need `rmm.admin`; everything below is also available as `RmmModule::starterActions()` (`catalog`, `plan`, `install`, `uninstall`) and `RmmModule::starterInstaller()`.

```
GET  /endpoint_devices/starter/catalog?platform=linux&risk=read_only
POST /endpoint_devices/starter/plan      {"platform":"windows","risk":["read_only"]}
POST /endpoint_devices/starter/install   {"ids":["win-flush-dns","policy-linux-server"]}
POST /endpoint_devices/starter/install   {"ids":["policy-windows-server"],"params":{"backup_service":"VeeamEndpointBackupSvc"}}
POST /endpoint_devices/starter/uninstall {"tags":["printing"],"dry_run":true}
```

A selection is `{pack?, ids?, tags?, platform?, risk?, kinds?, params?, policy_params?}`. With nothing in it, it is the whole pack. A `risk` or `tags` filter selects scripts only (policies come with `ids`, `kinds` or the whole pack). Unknown members and unknown ids are refused with 422.

**`plan` writes nothing.** Each item says what an install would do:

| action | meaning |
|---|---|
| `add` | not installed yet; created. A script whose name an administrator already uses is added as `<name> (starter)`. |
| `update` | the pack has a newer version and nobody edited the script (or the policy draft is untouched, off and unassigned): a new version is published. |
| `stage` | the pack has a newer version but an administrator edited the script. See section 5. |
| `unchanged` | already installed with the same content. Running install twice changes nothing. |
| `skip` | an administrator retired the script; an update is already parked; the policy was edited, is switched on or is assigned. |
| `blocked` | no instance signing key (generate it in the module settings), a name that cannot be resolved, a bad policy parameter. |

`install` does the plan. An item that fails does not stop the others (`failed` with a safe message). Each changed item gets an activity-log line (never any script text), and the whole call raises one `rmm.starter.installed` event when anything changed.

**Identification, with no new table.** A script is recognised by the note of its version, `starter|<pack id>|<item id>|<pack version>|<content hash>`, and by the tags `starter` and `risk:<class>`. A policy is recognised by a marker at the end of its description, ` [starter|<pack id>|<item id>|<pack version>|<settings hash>]`. Items are identified by (pack id, item id) and compared by content hash, so renaming a script does not lose it; deleting the marker from a policy description does (it is then treated as the administrator's own policy).

## 5. Updating, and what an administrator's edits are worth

[UPGRADING.md](../../UPGRADING.md) says how a new RivetCore release brings a new pack version. Installing again, with the same call, then follows these rules:

1. **Untouched script**: its current version is still exactly what the installer published (the stored text and parameters hash to the value in the note). It gets a new version; the flags, description, name and timeout the administrator set are kept.
2. **Edited script** (the administrator published a version of their own): never overwritten. The pack's new text is added as a **further version** whose note starts `starter-updated|...`, **not current**: the script keeps running what it ran. It is signed like any version and can be read with `GET /endpoint_devices/scripts/{id}/versions/{n}`; an administrator who wants it applies it by saving that text as a new version (`PUT scripts/{id}`). The next install sees the parked version and reports it instead of adding another.
3. **Retired script** stays retired.
4. **Policy draft**: updated only while untouched (its settings hash equals the marker), switched off and unassigned. Otherwise it is skipped with the reason, because changing a live policy changes every device in its scope without a review. To take the new version: switch it off and unassign it (or delete it) and install again.

## 6. Policy drafts

A draft is created switched off, assigned to nothing, named `Starter: ...` and described `Draft. ...`. To use one: read its checks, set the parameters you need (install again with `params`, for example `backup_service`, `ntp_server` for a domain controller, `ssh_service`, `cert_paths`, `uptime_warn_days`), switch it on and assign it to a client, group or tag (`POST policies/{id}/assignments`). Parameters change an untouched draft; once you edit it yourself it is yours.

## 7. Uninstalling

`uninstall` retires starter scripts whose current version is untouched (the history stays; a retired script can be restored) and deletes starter policy drafts that are untouched, off and unassigned. Anything an administrator changed is left alone and reported. `dry_run: true` previews it.

## 8. Writing your own pack

A pack is a folder in the same format; the bundled pack is the reference. An edition (or the instance's code) adds folders with the module option `starter_packs` (a list of paths); every pack in the list shows up in the same catalog, plan and install, keyed by its pack id.

```
my-pack/
  pack.json                 {"format":1,"id":"acme-extras","name":"Acme extras","version":"1.0.0","description":"..."}
  scripts/windows/<slug>.json + <slug>.ps1
  scripts/linux/<slug>.json  + <slug>.sh
  checks.json               {"sets":[...]}        (may hold an empty "sets" list)
  checks/<file>             script text of script checks, optional
  policies.json             {"policies":[...]}    (may be empty)
  manifest.json             written by the build script
```

* **Script metadata** (`<slug>.json`): `id` (`win-` or `lin-` is a convention, 3 to 60 of `a-z 0-9 -`, unique in the pack), `name` (at most 90 characters, unique; keep the `(Windows)` or `(Linux)` ending), `description` (at most 300), `platform` (`windows` or `linux`, the folder), `language` (`powershell` on Windows, `bash` on Linux), `risk`, `tags` (1 to 6 lowercase words; `starter` and `risk:*` are added for you), `timeout_s` (10 to 3600), `params` (the library's definition: name, type, label, description, default, min, max, max_length, choices), `output` (what it prints and its exit codes), and optionally `requires_approval` (a `disruptive` script cannot turn it off).
* **Rules that fail the load:** a script that changes the machine needs a bool `dry_run` with default `true`; a text with a CR, a NUL or invalid UTF-8; a PowerShell text over 8,500 characters; a duplicate id or name; any finding of `StarterLint` (see section 2); a file the manifest does not list or whose hash differs.
* **How parameters reach the script:** as variables of the same name, defined by the agent before your text (`name="${RIVETIT_PARAM_name-}"` in bash, `$name=$env:RIVETIT_PARAM_name;` in PowerShell, integers and booleans as literals). Default them yourself (`hours="${hours:-24}"`) so the script also runs by hand, and do not use a PowerShell variable whose name differs from a parameter only by case.
* **Check template sets** (`checks.json`): `{id, name, description, platform, use (workstation | windows-server | linux-server), params: [{name, type (string | int | list), default, label, description, example}], checks: [{key, label, type, interval_s, params, when?}]}`. A value `{"$param":"name"}` is replaced by the parameter, `{"$file":"checks/x.sh"}` by the text of a pack file whose `{{name}}` tokens become integer parameters, and `when` leaves the check out while that parameter is empty. Check types and params are those of `CheckCatalog`; the whole set is validated through `PolicySettings` at load.
* **Policy drafts** (`policies.json`): `{id, name, description (at most 180), platform, check_sets: [...], settings: {"feature.checks": true, ...}}`.
* **Build the manifest:** `php scripts/build-starter-pack.php path/to/my-pack` (writes `manifest.json`), `--check` in CI. Bump `version` in `pack.json` whenever anything changes; the installer compares content hashes, so a changed text with the same version still updates, but the version is what an administrator sees.
* **Test it:** `StarterPack::fromDirectory($dir)` throws with the file name on the first problem; the bundled pack's tests (`tests/Unit/Rmm/Starter`) are a template.

## 9. Quality gates

| Gate | Where |
|---|---|
| Manifest checksums, tamper and unlisted-file refusal, unique ids and names, parameter schemas valid and bounded, dry-run default, approval per risk, size caps, check sets and policies valid | `tests/Unit/Rmm/Starter/StarterPackTest.php` |
| Denylist over every script, with known-bad and known-good snippets as controls | the same file |
| PowerShell: the real parser (`[System.Management.Automation.Language.Parser]::ParseFile`) in `mcr.microsoft.com/powershell`, plus a structural fallback that always runs; bash: `bash -n` and ShellCheck | `tests/Unit/Rmm/Starter/StarterScriptSyntaxTest.php` |
| Every Linux script **run for real**, as root, in `ubuntu:24.04` with the agent's variable lines and the parameter defaults; exit 0 and output required; hostile values for every string parameter refused without being executed; the same run again with common tools installed (`RIVETCORE_STARTER_PROOF_TOOLS=1`) | `tests/Integration/Rmm/StarterLinuxProofTest.php` |
| Install on a scratch database: golden counts, signatures verify, drafts off and unassigned, idempotence, selection, edited and retired scripts, policy updates, uninstall, REST routes, abilities | `tests/Integration/Rmm/StarterInstallerTest.php` |

**What was executed and what was not.** The 32 Linux scripts were run in a real Ubuntu 24.04 container (a bare one, and one with `iproute2`, `procps`, ping, `dig`, `traceroute`, `openssh-server`, `openssl`, `smartmontools`, `cron`, `ufw`, `nftables` and `net-tools`). Not run anywhere: the container has no systemd, journald or Docker, so the `systemctl` restart, the journal vacuum and the real reboot paths printed `NOT AVAILABLE` or stayed dry runs; the zypper and yum branches were not run; and **all 41 Windows scripts are only parse-checked** (real PowerShell parser plus the denylist), never run on a Windows host. Treat the first run of each Windows script on a real machine as a test: they all start as dry runs or read only.

## 10. What an edition needs to build

* A **"Starter pack" page** (administrators): `GET starter/catalog` to browse by platform, risk and tag, with each item's state; select items and call `POST starter/plan` to show what will happen (add, update, stage, skip, blocked) before `POST starter/install` for the selected `ids`; the policy parameters (`params`) as a small form (backup service name, time server, SSH unit, certificate paths); a result view per item; an uninstall with its preview. Show `ready: false` and the `blocker` text first (no signing key).
* A **"Starter" badge** in the script library: a script is a starter script when its tags contain `starter` (and `risk:read_only`, `risk:changes_system` or `risk:disruptive` give the risk chip). A script with a version whose `note` starts `starter-updated|` has an update waiting for review: show it, and offer to diff that version against the current one. A policy is a starter draft when its description ends with the ` [starter|...]` marker.
* The existing pages do the rest: run a script (`dry_run` is a normal parameter and defaults to true), approvals, assignments.
* Subscribe to `rmm.starter.installed` if you want a notification.

## 11. Upgrading

A new pack version ships inside a new RivetCore release; nothing changes on an instance until an administrator installs again. See [UPGRADING.md](../../UPGRADING.md). `ScriptService::stage()` (new, public) publishes a version without making it current; `ScriptService::update()` now numbers the next version after the highest existing one (it used the current version before, which would collide with a parked version).
