# RMM policy starter pack

The [script starter pack](STARTER_PACK.md) fills an empty install with scripts and three policy drafts. This pack fills it with **policies an MSP would write on day one**: check policies per OS and role, alerting thresholds, patch policies, maintenance window templates and an optional change-monitoring preset. One click installs it from the Policies page; an edition can also seed it from a shell.

Status: built in Core (no table, no migration). Editions need a card on the Policies page, see [section 7](#7-what-an-edition-needs-to-build).

## 1. Safety model

* **Nothing changes on a fleet by installing.** Every check policy, alerting preset and patch policy is created **switched off and assigned to nothing**; every maintenance window is created **switched off**. The only exception is the change-monitoring item (section 4), which is an instance-wide setting and refuses to install without an explicit `confirm_instance_wide`.
* **Every item says what it does and why**, in plain language, and every setting has a one-line rationale (section 6). The manifest refuses to load when a setting has no rationale.
* **Valid by construction.** The manifest ([`src/Rmm/Policy/StarterPack/data/pack.php`](../../src/Rmm/Policy/StarterPack/data/pack.php), pure data, with a `version` and a per-item `rev`) is validated at load by the same validators the UI and REST API use (`PolicySettings`, `PatchPolicySettings`, `MaintenanceSchedule`, `ChangePresets`), plus pack rules: a Windows item only holds checks that say `os: windows` (and the other way round), a check type that runs on one OS must say so, disk limits are sane. A pack with an invalid item cannot be loaded, offered or installed.
* **No new trust.** Script checks (the laptop add-on) are two short read-only PowerShell scripts embedded in the manifest; they are delivered and signed like any other check by the existing check pipeline, and run only where `feature.script_checks` allows.
* **Never overwrites an administrator.** See section 2.

## 2. States, install, update, reset, uninstall

Identity needs no table. A pack entity carries the marker ` [pp|<item id>|<rev>|<hash>]` at the end of its description (at the end of the note for a maintenance window). The hash covers what the pack owns: name, description and settings (for a window the schedule fields; the time zone and the on/off switch belong to the administrator). Comparing it with the stored entity detects an edit.

| State | Meaning |
|---|---|
| `not_installed` | nothing of the pack's in the instance (or an administrator removed the marker, which makes it theirs) |
| `installed` | present, untouched, current revision |
| `update_available` | untouched, but the pack has a newer `rev` |
| `edited` | name, description or settings changed by an administrator. Never overwritten. |

Enabling a pack policy or assigning it is **not** an edit.

| Operation | Does |
|---|---|
| `install` | creates `not_installed` items (off, unassigned). Anything already there is `unchanged`; running it twice changes nothing. A foreign policy with the same name makes the item `blocked` (rename it first). |
| `update` | rewrites `update_available` items. `edited` items are `skipped`. An item that is **live** (enabled, or assigned) is `skipped` too, because changing it changes every device in its scope without a review; pass `include_live: true` to update it anyway. The on/off switch and assignments are kept. |
| `reset` | rewrites an item with the pack version, also an `edited` one (this is the "reset to pack version" button). Switch and assignments are kept. |
| `uninstall` | deletes untouched, unassigned items (a window must be off). `edited`, assigned and foreign things are `skipped`. The change-monitoring item is never uninstalled (turn presets off in their settings). |

Every operation takes `ids` (default: all), `params` (per item, install-time values: the Linux service names, the maintenance window time zone) and `dry_run`. Parameters are not recoverable after install: `update` and `reset` apply the values given or the defaults. Outcomes per item: `added`, `updated`, `reset`, `removed`, `unchanged`, `skipped` (with a reason), `blocked` (with a reason), `failed`.

## 3. REST

All under `endpoint_devices/starter/policies`, all needing `rmm.admin` (the pack also writes patch policies and windows, and a separate `rmm.policy.manage` ability does not exist in RivetCore: policy editing is `rmm.admin`). A caller without it gets 403 on every route and nothing is written.

```
GET  endpoint_devices/starter/policies                      read model: every item, its state, summary, why, per-setting rationale, parameters
POST endpoint_devices/starter/policies/plan                 {op, ids?, params?, include_live?, confirm_instance_wide?}  writes nothing
POST endpoint_devices/starter/policies/install              {ids?, params?, confirm_instance_wide?, dry_run?}
POST endpoint_devices/starter/policies/update               {ids?, include_live?, params?, dry_run?}
POST endpoint_devices/starter/policies/reset                {ids?, params?, dry_run?}
POST endpoint_devices/starter/policies/uninstall            {ids?, dry_run?}
```

Every changed item writes an audit row (`Policy Pack Item Added|Updated|Reset|Removed`, never any setting values) and one summary row per call. The read model has `counts` and per item `state`, `edited`, `update_available`, `enabled`, `assignments`, `entity_id`, `instance_wide`.

CLI for editions' deploy scripts (the edition supplies a file returning its configured `RmmModule`):

```
php scripts/policy-pack.php --bootstrap=/path/module.php list
php scripts/policy-pack.php --bootstrap=/path/module.php install [--ids=a,b] [--confirm-instance-wide] [--dry-run]
php scripts/policy-pack.php --bootstrap=... update|reset|uninstall [--ids=a,b] [--include-live]
```

## 4. Scope notes (read before relying on it)

* **Alerting presets.** Thresholds live in the check definition, so the presets are check policies (`check.alert.cpu`, `check.alert.memory`, ...) with `thresholds` (warn/critical, hysteresis, hold samples and minutes) and `flap` (flapping detection). Maintenance-window friendliness comes from the hold time plus the windows themselves (mute mode holds new alerts back). **Disk queue length and network error metrics have no threshold-capable check type in this release**, so the pack ships nothing for them; they can be added as items when the check types exist. Windows Update is not a service check (`wuauserv` is trigger-started and would flap); updates are covered by the patch policies.
* **Patch policies** point at the default rings `pilot`, `broad` and `last`; an item is `blocked` if its ring was deleted. The pack never sets `reboot_policy: if_required_now` (it needs a person's `reboot_confirm`): reboots are reported (`if_required_defer`) or never. "Pilot 10 %" is a size of group the administrator assigns, not a setting; the policy approves one day after the update appears.
* **Maintenance windows** are created switched off, scope "all devices", mode `mute`. The time zone is an install parameter (default UTC).
* **Change monitoring** has no per-policy scope in this release: the presets are global. The item therefore needs `confirm_instance_wide`, only ever adds presets (never turns one off) and also applies to workstations.
* **Software** deployments are not in the pack; the software catalog is separate.
* **Linux service names** differ by distribution; the Linux baseline takes them as install parameters (defaults are Debian family: `ssh`, `cron`, `systemd-timesyncd`, `rsyslog`).

## 5. Versioning and upgrading

`version` is the pack release; each item has a `rev` that is raised when its content changes, and that is what makes `update_available` appear. A new RivetCore release brings new data; nothing changes on an instance until an administrator updates. See [UPGRADING.md](../../UPGRADING.md).

## 6. Every item and the rationale of every setting

Generated from the manifest by `php scripts/build-policy-pack-doc.php`; a test fails when it is stale.

<!-- policypack:items:start -->

### Check policies

#### Pack: Windows workstation baseline

`windows-workstation` · check_policy · platform: windows · revision 1

**What it does.** The everyday health checks for a Windows desktop or laptop: disk space, a pending reboot, Defender, firewall, time, the event log and the agent update channel.

**Why.** These are the problems that turn into tickets on a workstation. Limits are loose enough that a normal machine stays quiet.

**Note.** Windows Update is not a service check: wuauserv is trigger-started and would flap. Updates are covered by the patch policies.

| Setting | Value | Why |
|---|---|---|
| `agent.ring` | `"stable"` | Stable agent channel: workstations get agent updates after the pilot devices proved them. |
| `check.starter.av` | `{"type":"av","params":{"max_signature_age_days":7,"require_realtime":true},"interval_s":3600,"os":"windows"}` | Defender real-time protection on and signatures at most 7 days old. |
| `check.starter.disk_free` | `{"type":"disk","params":{"warn_free_pct":20,"fail_free_pct":10,"warn_free_gb":10,"fail_free_gb":3},"interval_s":300,"os":"windows"}` | Warn under 20 % or 10 GB free, fail under 10 % or 3 GB: a full system drive breaks updates and profiles. Every 5 minutes. |
| `check.starter.pending_reboot` | `{"type":"pending_reboot","params":[],"interval_s":3600,"os":"windows"}` | A reboot waiting after updates leaves the machine unpatched. Hourly, it is slow-moving. |
| `check.starter.svc_eventlog` | `{"type":"service","params":{"name":"EventLog","expected":"running"},"interval_s":300,"os":"windows"}` | Without the Event Log service nothing is audited and many components fail. |
| `check.starter.svc_firewall` | `{"type":"service","params":{"name":"mpssvc","expected":"running"},"interval_s":300,"os":"windows"}` | The Windows Firewall service (mpssvc) must run; without it the firewall is off. |
| `check.starter.svc_time` | `{"type":"service","params":{"name":"W32Time","expected":"running"},"interval_s":300,"os":"windows"}` | Windows Time must run or Kerberos and certificates start failing. |
| `check.starter.time_skew` | `{"type":"ntp","params":{"server":"pool.ntp.org","warn_ms":1000,"fail_ms":5000,"timeout_ms":3000},"interval_s":3600,"os":"windows"}` | Clock offset from pool.ntp.org: warn over 1 s, fail over 5 s (Kerberos allows 5 minutes, so this is an early warning). Hourly. |
| `feature.checks` | `true` | Checks are delivered to the device. |

#### Pack: Windows server baseline

`windows-server` · check_policy · platform: windows · revision 1

**What it does.** The workstation checks with stricter disk limits, the core server services, certificate expiry and disk health. Add the role add-ons for DNS, DHCP, AD DS and the rest.

**Why.** A server has more to lose than a workstation: thresholds fire earlier and the server, scheduler and certificate checks are added.

| Setting | Value | Why |
|---|---|---|
| `agent.ring` | `"stable"` | Stable agent channel. |
| `check.starter.av` | `{"type":"av","params":{"max_signature_age_days":3,"require_realtime":true},"interval_s":3600,"os":"windows"}` | Defender on with signatures at most 3 days old: servers are a bigger target. |
| `check.starter.cert_expiry` | `{"type":"cert","params":{"store":"LocalMachine\\My","warn_days":30,"fail_days":7},"interval_s":21600,"os":"windows"}` | Machine certificates (LocalMachine\My) warn 30 days and fail 7 days before expiry, every 6 hours. |
| `check.starter.disk_free` | `{"type":"disk","params":{"warn_free_pct":15,"fail_free_pct":8,"warn_free_gb":20,"fail_free_gb":5},"interval_s":300,"os":"windows"}` | Warn under 15 % or 20 GB free, fail under 8 % or 5 GB: the absolute limits matter on large volumes where 15 % is hundreds of GB. |
| `check.starter.pending_reboot` | `{"type":"pending_reboot","params":[],"interval_s":3600,"os":"windows"}` | A server waiting for a reboot is unpatched; hourly. |
| `check.starter.smart` | `{"type":"smart","params":[],"interval_s":21600,"os":"windows"}` | Physical disk health (SMART) every 6 hours: the earliest sign of a failing disk. |
| `check.starter.svc_dns_client` | `{"type":"service","params":{"name":"Dnscache","expected":"running"},"interval_s":300,"os":"windows"}` | DNS Client service must run for name resolution. |
| `check.starter.svc_eventlog` | `{"type":"service","params":{"name":"EventLog","expected":"running"},"interval_s":300,"os":"windows"}` | Event Log service must run. |
| `check.starter.svc_firewall` | `{"type":"service","params":{"name":"mpssvc","expected":"running"},"interval_s":300,"os":"windows"}` | Firewall service must run. |
| `check.starter.svc_scheduler` | `{"type":"service","params":{"name":"Schedule","expected":"running"},"interval_s":300,"os":"windows"}` | Task Scheduler runs backups and maintenance jobs. |
| `check.starter.svc_server` | `{"type":"service","params":{"name":"LanmanServer","expected":"running"},"interval_s":300,"os":"windows"}` | The Server service (LanmanServer) provides file shares; stopped means shares are gone. |
| `check.starter.svc_time` | `{"type":"service","params":{"name":"W32Time","expected":"running"},"interval_s":300,"os":"windows"}` | Windows Time must run; on a domain controller or member it keeps Kerberos working. |
| `check.starter.svc_wmi` | `{"type":"service","params":{"name":"Winmgmt","expected":"running"},"interval_s":300,"os":"windows"}` | WMI is how most monitoring and management tools read the machine. |
| `check.starter.time_skew` | `{"type":"ntp","params":{"server":"pool.ntp.org","warn_ms":500,"fail_ms":3000,"timeout_ms":3000},"interval_s":1800,"os":"windows"}` | Warn over 0.5 s, fail over 3 s, every 30 minutes: tighter than a workstation. On a domain, set the time server to a domain controller by editing the policy. |
| `feature.checks` | `true` | Checks are delivered to the device. |

### Windows server role add-ons

#### Pack add-on: DNS server role

`role-dns` · check_policy · platform: windows · revision 1

**What it does.** Alerts when the DNS Server service stops on a server that has this role. An add-on: assign it only to servers that run the role.

**Why.** A stopped DNS Server service is an outage for everything that depends on it, and nothing else in the baseline watches it.

| Setting | Value | Why |
|---|---|---|
| `check.role.role-dns` | `{"type":"service","params":{"name":"DNS","expected":"running"},"interval_s":120,"os":"windows"}` | Service DNS must be running; checked every 2 minutes because a role outage is user visible. |
| `feature.checks` | `true` | Checks are delivered to the device. |

#### Pack add-on: DHCP server role

`role-dhcp` · check_policy · platform: windows · revision 1

**What it does.** Alerts when the DHCP Server service stops on a server that has this role. An add-on: assign it only to servers that run the role.

**Why.** A stopped DHCP Server service is an outage for everything that depends on it, and nothing else in the baseline watches it.

| Setting | Value | Why |
|---|---|---|
| `check.role.role-dhcp` | `{"type":"service","params":{"name":"DHCPServer","expected":"running"},"interval_s":120,"os":"windows"}` | Service DHCPServer must be running; checked every 2 minutes because a role outage is user visible. |
| `feature.checks` | `true` | Checks are delivered to the device. |

#### Pack add-on: Active Directory Domain Services role

`role-adds` · check_policy · platform: windows · revision 1

**What it does.** Alerts when the Active Directory Domain Services service stops on a server that has this role. An add-on: assign it only to servers that run the role.

**Why.** A stopped Active Directory Domain Services service is an outage for everything that depends on it, and nothing else in the baseline watches it.

| Setting | Value | Why |
|---|---|---|
| `check.role.role-adds` | `{"type":"service","params":{"name":"NTDS","expected":"running"},"interval_s":120,"os":"windows"}` | Service NTDS must be running; checked every 2 minutes because a role outage is user visible. |
| `feature.checks` | `true` | Checks are delivered to the device. |

#### Pack add-on: Print Spooler role

`role-spooler` · check_policy · platform: windows · revision 1

**What it does.** Alerts when the Print Spooler service stops on a server that has this role. An add-on: assign it only to servers that run the role.

**Why.** A stopped Print Spooler service is an outage for everything that depends on it, and nothing else in the baseline watches it.

| Setting | Value | Why |
|---|---|---|
| `check.role.role-spooler` | `{"type":"service","params":{"name":"Spooler","expected":"running"},"interval_s":120,"os":"windows"}` | Service Spooler must be running; checked every 2 minutes because a role outage is user visible. |
| `feature.checks` | `true` | Checks are delivered to the device. |

#### Pack add-on: SQL Server role (default instance)

`role-sql` · check_policy · platform: windows · revision 1

**What it does.** Alerts when the SQL Server (default instance) service stops on a server that has this role. An add-on: assign it only to servers that run the role.

**Why.** A stopped SQL Server (default instance) service is an outage for everything that depends on it, and nothing else in the baseline watches it.

| Setting | Value | Why |
|---|---|---|
| `check.role.role-sql` | `{"type":"service","params":{"name":"MSSQLSERVER","expected":"running"},"interval_s":120,"os":"windows"}` | Service MSSQLSERVER must be running; checked every 2 minutes because a role outage is user visible. |
| `feature.checks` | `true` | Checks are delivered to the device. |

#### Pack add-on: IIS role

`role-iis` · check_policy · platform: windows · revision 1

**What it does.** Alerts when the World Wide Web Publishing (IIS) service stops on a server that has this role. An add-on: assign it only to servers that run the role.

**Why.** A stopped World Wide Web Publishing (IIS) service is an outage for everything that depends on it, and nothing else in the baseline watches it.

| Setting | Value | Why |
|---|---|---|
| `check.role.role-iis` | `{"type":"service","params":{"name":"W3SVC","expected":"running"},"interval_s":120,"os":"windows"}` | Service W3SVC must be running; checked every 2 minutes because a role outage is user visible. |
| `feature.checks` | `true` | Checks are delivered to the device. |

#### Pack add-on: Hyper-V host role

`role-hyperv` · check_policy · platform: windows · revision 1

**What it does.** Alerts when the Hyper-V Virtual Machine Management service stops on a server that has this role. An add-on: assign it only to servers that run the role.

**Why.** A stopped Hyper-V Virtual Machine Management service is an outage for everything that depends on it, and nothing else in the baseline watches it.

| Setting | Value | Why |
|---|---|---|
| `check.role.role-hyperv` | `{"type":"service","params":{"name":"vmms","expected":"running"},"interval_s":120,"os":"windows"}` | Service vmms must be running; checked every 2 minutes because a role outage is user visible. |
| `feature.checks` | `true` | Checks are delivered to the device. |

#### Pack add-on: Veeam Backup role

`role-veeam` · check_policy · platform: windows · revision 1

**What it does.** Alerts when the Veeam Backup service stops on a server that has this role. An add-on: assign it only to servers that run the role.

**Why.** A stopped Veeam Backup service is an outage for everything that depends on it, and nothing else in the baseline watches it.

| Setting | Value | Why |
|---|---|---|
| `check.role.role-veeam` | `{"type":"service","params":{"name":"VeeamBackupSvc","expected":"running"},"interval_s":120,"os":"windows"}` | Service VeeamBackupSvc must be running; checked every 2 minutes because a role outage is user visible. |
| `feature.checks` | `true` | Checks are delivered to the device. |

### Check policies

#### Pack: Linux server baseline

`linux-server` · check_policy · platform: linux · revision 1

**What it does.** Disk and inode space, failed systemd units, the ssh, cron, log and time services, fail2ban and a pending reboot.

**Why.** The usual ways a Linux server quietly breaks: a full disk or inode table, a dead cron or ssh, a clock drift, a logger that stopped.

Install parameters: `ssh_unit` (default `ssh`): ssh service name (sshd on RHEL family); `cron_unit` (default `cron`): cron service name (crond on RHEL family); `time_unit` (default `systemd-timesyncd`): time sync service name (chronyd on RHEL family); `syslog_unit` (default `rsyslog`): syslog service name

| Setting | Value | Why |
|---|---|---|
| `agent.ring` | `"stable"` | Stable agent channel. |
| `check.starter.disk_free` | `{"type":"disk","params":{"warn_free_pct":15,"fail_free_pct":8,"warn_free_gb":10,"fail_free_gb":3},"interval_s":300,"os":"linux"}` | Warn under 15 % or 10 GB free, fail under 8 % or 3 GB. |
| `check.starter.inodes` | `{"type":"inodes","params":{"mount":"/","warn_pct":85,"fail_pct":95},"interval_s":600,"os":"linux"}` | Inodes on / at 85 % warn, 95 % fail: millions of small files fill the inode table while df still shows free space. |
| `check.starter.pending_reboot` | `{"type":"pending_reboot","params":[],"interval_s":3600,"os":"linux"}` | Kernel or library updates waiting for a reboot. |
| `check.starter.smart` | `{"type":"smart","params":[],"interval_s":21600,"os":"linux"}` | Physical disk SMART health every 6 hours; reports not applicable on virtual disks. |
| `check.starter.svc_cron` | `{"type":"service","params":{"name":"cron","expected":"running"},"interval_s":300,"os":"linux"}` | cron runs scheduled jobs and backups. Service name is an install parameter. |
| `check.starter.svc_fail2ban` | `{"type":"service","params":{"name":"fail2ban","expected":"running"},"interval_s":300,"os":"linux"}` | fail2ban blocks brute force on ssh. Delete the check from your copy where it is not installed. |
| `check.starter.svc_ssh` | `{"type":"service","params":{"name":"ssh","expected":"running"},"interval_s":300,"os":"linux"}` | ssh must run or the server cannot be managed. Service name is an install parameter. |
| `check.starter.svc_syslog` | `{"type":"service","params":{"name":"rsyslog","expected":"running"},"interval_s":300,"os":"linux"}` | rsyslog collects logs. Service name is an install parameter. Remove the check on journald-only systems. |
| `check.starter.svc_time` | `{"type":"service","params":{"name":"systemd-timesyncd","expected":"running"},"interval_s":300,"os":"linux"}` | Time sync service. Service name is an install parameter. |
| `check.starter.systemd_failed` | `{"type":"systemd_failed","params":{"max_failed":0},"interval_s":300,"os":"linux"}` | Any failed systemd unit is reported; a failed unit is a service that is not running. |
| `check.starter.time_skew` | `{"type":"ntp","params":{"server":"pool.ntp.org","warn_ms":500,"fail_ms":3000,"timeout_ms":3000},"interval_s":1800,"os":"linux"}` | Clock offset from pool.ntp.org, warn over 0.5 s, fail over 3 s. |
| `feature.checks` | `true` | Checks are delivered to the device. |

#### Pack: Laptop add-on

`laptop` · check_policy · platform: windows · revision 1

**What it does.** Adds battery health and a "restart it, it has been on for weeks" check for Windows laptops. An add-on to the workstation baseline.

**Why.** Laptops get sleep-and-resume instead of restarts, so updates stay pending, and batteries wear out silently.

| Setting | Value | Why |
|---|---|---|
| `check.laptop.battery` | `{"type":"script","params":{"script":"$b = Get-CimInstance Win32_Battery -ErrorAction SilentlyContinue\nif (-not $b) { \" ...(script)` | Full-charge capacity versus design capacity: warn under 70 %, fail under 50 %. Desktops without a battery report not applicable. |
| `check.laptop.uptime` | `{"type":"script","params":{"script":"$b = (Get-CimInstance Win32_OperatingSystem).LastBootUpTime\n$d = [int]((Get-Date)  ...(script)` | Warn after 14 days, fail after 30 days without a restart. |
| `feature.checks` | `true` | Checks are delivered to the device. |
| `feature.script_checks` | `true` | Script checks run only where this is on (and the instance allows them); the two scripts are short and read only. |

#### Pack: Minimal / low noise

`minimal` · check_policy · platform: any · revision 1

**What it does.** Only the two things nobody argues about: a nearly full disk and (on Windows) real-time protection switched off.

**Why.** For clients or machines where you want almost no alerts. Nothing warns early; it only fires when something is really wrong.

| Setting | Value | Why |
|---|---|---|
| `check.minimal.av` | `{"type":"av","params":{"max_signature_age_days":30,"require_realtime":true},"interval_s":21600,"os":"windows"}` | Defender real-time protection must be on; signatures may be a month old. Windows only. |
| `check.minimal.disk_free` | `{"type":"disk","params":{"warn_free_pct":8,"fail_free_pct":4,"warn_free_gb":2,"fail_free_gb":1},"interval_s":900}` | Warn under 8 % or 2 GB, fail under 4 % or 1 GB, every 15 minutes. Applies to Windows and Linux. |
| `feature.checks` | `true` | Checks are delivered to the device. |

### Alerting presets

#### Pack alerting: Workstation thresholds

`alert-workstation` · alert_preset · platform: any · revision 1

**What it does.** CPU and memory alerts for desktops and laptops: warn at 90 %, critical at 98 % / 97 %, only after the level has held for 15 minutes.

**Why.** Workstations spike all the time; a user opening a large file is not an incident. Only sustained pressure should open an alert. Alerts that would open during a maintenance window are held back by the window itself (mute mode), and the duration requirement means a reboot or a patch run never raises one.

| Setting | Value | Why |
|---|---|---|
| `check.alert.cpu` | `{"type":"cpu","params":{"window_s":30,"warn_pct":100,"fail_pct":100,"thresholds":{"warn":{"op":"gt","value":90},"crit":{ ...(script)` | Warn over 90 %, critical over 98 %, must hold 3 samples and 15 minutes, hysteresis 5 points; flapping above 60 % state changes in 20 samples holds the alert state. The agent instant verdict is set to 100 % so spikes never alert. |
| `check.alert.memory` | `{"type":"memory","params":{"metric":"mem_used_pct","warn_pct":100,"fail_pct":100,"thresholds":{"warn":{"op":"gt","value" ...(script)` | Warn over 90 %, critical over 97 %, 3 samples and 15 minutes, hysteresis 3 points, same flap guard. |
| `feature.checks` | `true` | Checks are delivered to the device. |

#### Pack alerting: Server thresholds

`alert-server` · alert_preset · platform: any · revision 1

**What it does.** CPU, memory and swap alerts for servers: earlier warnings (85 %), a shorter 10 minute hold, and memory pressure and swap on Linux.

**Why.** A server should not sit above 85 % for long. Servers get warned sooner than workstations but still need a hold time so backups and patch runs do not page anyone. Alerts that would open during a maintenance window are held back by the window itself (mute mode), and the duration requirement means a reboot or a patch run never raises one.

| Setting | Value | Why |
|---|---|---|
| `check.alert.cpu` | `{"type":"cpu","params":{"window_s":30,"warn_pct":100,"fail_pct":100,"thresholds":{"warn":{"op":"gt","value":85},"crit":{ ...(script)` | Warn over 85 %, critical over 95 %, held 3 samples and 10 minutes, hysteresis 5, flap guard. |
| `check.alert.mem_pressure` | `{"type":"memory","params":{"metric":"psi_some_avg10","warn_pct":100,"fail_pct":100,"thresholds":{"warn":{"op":"gt","valu ...(script)` | Linux only: kernel memory pressure (PSI some avg10) over 20 warns and over 40 is critical for 10 minutes: tasks are stalled waiting for memory. |
| `check.alert.memory` | `{"type":"memory","params":{"metric":"mem_used_pct","warn_pct":100,"fail_pct":100,"thresholds":{"warn":{"op":"gt","value" ...(script)` | Warn over 88 %, critical over 95 %, held 3 samples and 10 minutes, hysteresis 3, flap guard. |
| `check.alert.swap` | `{"type":"memory","params":{"metric":"swap_used_pct","warn_pct":100,"fail_pct":100,"thresholds":{"warn":{"op":"gt","value ...(script)` | Linux only: swap use over 50 % warns and over 80 % is critical, held 20 minutes; heavy swapping means the memory estimate was wrong. |
| `feature.checks` | `true` | Checks are delivered to the device. |

#### Pack alerting: Quiet (critical only)

`alert-quiet` · alert_preset · platform: any · revision 1

**What it does.** Only critical CPU and memory: over 98 % / 97 % for 30 minutes. No warnings.

**Why.** For noisy clients: you hear about it only when a machine is effectively unusable. Alerts that would open during a maintenance window are held back by the window itself (mute mode), and the duration requirement means a reboot or a patch run never raises one.

**Note.** Disk queue length and network error metrics have no threshold-capable check type in this RivetCore release, so the pack ships no preset for them.

| Setting | Value | Why |
|---|---|---|
| `check.alert.cpu` | `{"type":"cpu","params":{"window_s":30,"warn_pct":100,"fail_pct":100,"thresholds":{"crit":{"op":"gt","value":98},"hystere ...(script)` | Critical only, over 98 % for 5 samples and 30 minutes. |
| `check.alert.memory` | `{"type":"memory","params":{"metric":"mem_used_pct","warn_pct":100,"fail_pct":100,"thresholds":{"crit":{"op":"gt","value" ...(script)` | Critical only, over 97 % for 5 samples and 30 minutes. |
| `feature.checks` | `true` | Checks are delivered to the device. |

### Patch policies

#### Pack patch: Pilot (about 10 % of devices, next day)

`patch-pilot` · patch_policy · platform: any · revision 1

**What it does.** Approves updates automatically one day after they appear, for a pilot group, and installs them at night. Assign it to roughly one device in ten.

**Why.** The pilot group finds a bad update before everyone else gets it. Pair it with the Standard policy for the rest.

| Setting | Value | Why |
|---|---|---|
| `approval_mode` | `"auto"` | Automatic: the pilot is the test, a person does not have to approve each update. |
| `auto_days_critical` | `1` | Critical updates approved 1 day after first seen. |
| `auto_days_important` | `1` | Important updates approved 1 day after first seen. Optional and driver updates are never approved by this policy. |
| `auto_days_security` | `1` | Security updates approved 1 day after first seen. |
| `install_enabled` | `true` | The scheduler may install approved updates. |
| `max_minutes` | `90` | An install may run 90 minutes. |
| `reboot_policy` | `"if_required_defer"` | Reboots are reported, not performed: a restart by itself needs a person to confirm it on the policy. |
| `ring` | `"pilot"` | Ring pilot (no extra delay). |
| `window_days` | `"mon,tue,wed,thu,fri"` | Weekdays only, so a bad update is noticed during working hours. |
| `window_minutes` | `240` | Four hours. |
| `window_mode` | `"daily"` | Install only inside the daily window below. |
| `window_start` | `"20:00"` | From 20:00 (the policy time zone is UTC unless you set one). |

#### Pack patch: Standard (Tuesday + 7 days)

`patch-standard` · patch_policy · platform: any · revision 1

**What it does.** Approves security and important updates a week after they appear (the Tuesday after Patch Tuesday), and installs them in a Tuesday evening window.

**Why.** A week of pilot soak time catches the bad updates; the Tuesday window gives a fixed, predictable rhythm for the main fleet.

| Setting | Value | Why |
|---|---|---|
| `approval_mode` | `"auto"` | Automatic after the delay. |
| `auto_days_critical` | `0` | No days on top of the ring delay. |
| `auto_days_important` | `0` | No days on top of the ring delay. Optional and driver updates are never approved by this policy. |
| `auto_days_security` | `0` | No days on top of the ring delay. |
| `install_enabled` | `true` | The scheduler may install approved updates. |
| `max_minutes` | `120` | An install may run 2 hours. |
| `reboot_policy` | `"if_required_defer"` | Reboots are reported, not performed (see the Pilot policy). |
| `ring` | `"broad"` | Ring broad adds the 7 day delay; with no extra days that gives Tuesday + 7. |
| `window_days` | `"tue"` | Tuesdays only. |
| `window_minutes` | `300` | Five hours. |
| `window_mode` | `"daily"` | Daily-style window, set to one weekday. |
| `window_start` | `"19:00"` | From 19:00. |

#### Pack patch: Servers (monthly, manual approval)

`patch-servers` · patch_policy · platform: any · revision 1

**What it does.** A person approves each update (a second person must agree); installation happens only in a Saturday night window. Reboots are left to you.

**Why.** Servers must not patch themselves in the middle of the week. Approval, a second pair of eyes and a fixed window make it a planned change.

| Setting | Value | Why |
|---|---|---|
| `approval_mode` | `"manual"` | Manual: nothing is approved by itself. |
| `install_enabled` | `true` | Approved updates may be installed in the window. |
| `max_minutes` | `180` | An install may run 3 hours. |
| `reboot_policy` | `"if_required_defer"` | Reboots outside business hours are queued by you as a reboot job in the Saturday window; the policy never restarts a server by itself. |
| `ring` | `"last"` | Ring last: sensitive machines two weeks behind the pilot. |
| `two_person` | `true` | Approving an update by hand needs a second person. |
| `window_days` | `"sat"` | Saturday only (run it monthly by approving once a month). |
| `window_minutes` | `360` | Six hours. |
| `window_mode` | `"daily"` | Installs only inside the window. |
| `window_start` | `"22:00"` | From 22:00. |

#### Pack patch: Critical only

`patch-critical` · patch_policy · platform: any · revision 1

**What it does.** Approves only updates classified critical, the day after they appear. Everything else waits for a person.

**Why.** For machines that must stay as they are except for emergency fixes.

| Setting | Value | Why |
|---|---|---|
| `approval_mode` | `"auto"` | Automatic, but only for the kind below. |
| `auto_days_critical` | `1` | Critical updates approved 1 day after first seen; all other kinds are never approved by this policy. |
| `install_enabled` | `true` | Approved updates may be installed. |
| `max_minutes` | `60` | An install may run one hour. |
| `reboot_policy` | `"never"` | Never reboot, not even a report. |
| `ring` | `"pilot"` | Ring pilot, no extra delay. |
| `window_days` | `"mon,tue,wed,thu,fri,sat,sun"` | Every night. |
| `window_minutes` | `180` | Three hours. |
| `window_mode` | `"daily"` | Nightly window. |
| `window_start` | `"01:00"` | From 01:00. |

### Maintenance windows

#### Pack window: Nightly 02:00-04:00

`mw-nightly` · maintenance_window · platform: any · revision 1

**What it does.** Every night from 02:00 to 04:00 local time. Created switched off.

**Why.** Backups, scans and updates run at night and make noise. This keeps alerts quiet during that time.

Install parameters: `timezone` (default `UTC`): IANA time zone the window times are in, for example America/Chicago

| Setting | Value | Why |
|---|---|---|
| `mode` | `"mute"` | Mute: checks keep running, new alerts are held back until the window ends, open alerts stay open. |
| `scope_type` | `"all"` | Applies to every device; narrow the scope after installing if you only want some. |
| `scope_id` | `0` | Not used for all. |
| `kind` | `"recurring"` | Repeats. |
| `recur_freq` | `"daily"` | How often it repeats. |
| `recur_interval` | `1` | Every period. |
| `recur_days` | `""` | Which days. |
| `local_start` | `"02:00"` | Start time in the window time zone. |
| `duration_min` | `120` | How long it stays open. |

#### Pack window: Sunday patching 01:00-05:00

`mw-sunday-patching` · maintenance_window · platform: any · revision 1

**What it does.** Sundays from 01:00 to 05:00 local time. Created switched off.

**Why.** A weekly patching slot for workstations, so reboots and service restarts do not raise alerts.

Install parameters: `timezone` (default `UTC`): IANA time zone the window times are in, for example America/Chicago

| Setting | Value | Why |
|---|---|---|
| `mode` | `"mute"` | Mute: checks keep running, new alerts are held back until the window ends, open alerts stay open. |
| `scope_type` | `"all"` | Applies to every device; narrow the scope after installing if you only want some. |
| `scope_id` | `0` | Not used for all. |
| `kind` | `"recurring"` | Repeats. |
| `recur_freq` | `"weekly"` | How often it repeats. |
| `recur_interval` | `1` | Every period. |
| `recur_days` | `"7"` | Which days. |
| `local_start` | `"01:00"` | Start time in the window time zone. |
| `duration_min` | `240` | How long it stays open. |

#### Pack window: Server patching Saturday 22:00-04:00

`mw-server-patching-sat` · maintenance_window · platform: any · revision 1

**What it does.** Saturdays from 22:00 for six hours, local time. Created switched off.

**Why.** The slot for the Servers patch policy, long enough for a reboot.

Install parameters: `timezone` (default `UTC`): IANA time zone the window times are in, for example America/Chicago

| Setting | Value | Why |
|---|---|---|
| `mode` | `"mute"` | Mute: checks keep running, new alerts are held back until the window ends, open alerts stay open. |
| `scope_type` | `"all"` | Applies to every device; narrow the scope after installing if you only want some. |
| `scope_id` | `0` | Not used for all. |
| `kind` | `"recurring"` | Repeats. |
| `recur_freq` | `"weekly"` | How often it repeats. |
| `recur_interval` | `1` | Every period. |
| `recur_days` | `"6"` | Which days. |
| `local_start` | `"22:00"` | Start time in the window time zone. |
| `duration_min` | `360` | How long it stays open. |

### Change monitoring

#### Pack change monitoring: server persistence and security presets

`change-servers` · change_presets · platform: any · revision 1

**What it does.** Turns on the change-monitor watches for startup keys, local administrators and Defender exclusions on Windows, and Linux ssh, sudo and accounts. Optional.

**Why.** These are the places an attacker, or an unplanned change, shows up first. Unlike the other items this is an instance-wide setting (change monitoring has no per-policy scope in this release), so installing needs an explicit confirmation and it also applies to workstations.

| Setting | Value | Why |
|---|---|---|
| `presets` | `{"windows":["win.startup","win.local_admins","win.defender"],"linux":["lnx.ssh","lnx.sudo","lnx.accounts"]}` | Windows: win.startup (Run keys, Winlogon, IFEO), win.local_admins (Administrators group), win.defender (exclusions and policy). Linux: lnx.ssh, lnx.sudo, lnx.accounts. |

<!-- policypack:items:end -->

## 7. What an edition needs to build

* **A "Starter policies" card on the Policies page** (administrators): `GET starter/policies`, grouped by `group`, each row showing the name, a platform chip, a state badge (Not installed, Installed, Update available, Edited) and the `summary`; a "Why" expander with `why` and the per-setting `why_settings`. Checkboxes and a button "Install selected"; "Install recommended" selecting the baselines. Show a notice on the card: "Installing creates the policies switched off and assigned to nothing. Nothing changes on your devices until you switch one on and assign it."
* **Preview first**: call `POST .../plan` and show added, blocked and skipped with the reasons before the real call.
* **Per row actions**: Update (when `update_available`), "Reset to pack version" (confirm dialog: "This replaces your changes to this policy with the pack version."), Uninstall. A live row (`enabled` or `assignments > 0`) shows "In use" and Update asks for a second confirmation that sends `include_live`.
* **Parameters form** for items with `params` (Linux service names; maintenance window time zone as a time zone picker).
* **Change monitoring row** is `instance_wide`: show "Applies to all devices" and send `confirm_instance_wide` only after a checkbox is ticked.
* A **"Starter" badge** on the Policies, Patch policies and Maintenance windows lists for entities whose description (note for windows) ends with the `[pp|...]` marker; hide the marker from the description when showing it.
* Subscribe to nothing: there is no event; the audit rows are the record.
