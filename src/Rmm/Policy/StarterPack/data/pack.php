<?php

declare(strict_types=1);

/**
 * The RivetCore policy starter pack manifest: pure data. Loaded and validated by {@see \RivetCore\Rmm\Policy\StarterPack\PolicyPack}
 * with the same validators the UI and REST API use (PolicySettings, PatchPolicySettings, MaintenanceSchedule, ChangePresets), so a pack can never
 * contain an invalid policy. Bump `version` for any change, and an item's `rev` when its content changes (that is what offers "update available").
 *
 * Item members: id, kind (check_policy | alert_preset | patch_policy | maintenance_window | change_presets), rev, name, platform (windows | linux | any),
 * group (what the UI groups by), summary (what it does, plain language), why (why it is worth having), body (the data, shape per kind), why_settings (setting => rationale;
 * every setting must have one), params (install-time parameters, optional).
 */

$chk = static fn (string $type, array $params, int $every, ?string $os = null): array => ['type' => $type, 'params' => $params, 'interval_s' => $every] + ($os === null ? [] : ['os' => $os]);
$svc = static fn (string $name, ?string $os = null): array => $chk('service', ['name' => $name, 'expected' => 'running'], 300, $os);
$on = ['feature.checks' => true];

$batteryPs = <<<'PS'
$b = Get-CimInstance Win32_Battery -ErrorAction SilentlyContinue
if (-not $b) { "No battery: not applicable"; exit 0 }
try {
  $d = (Get-CimInstance -Namespace root\wmi -ClassName BatteryStaticData -ErrorAction Stop | Select-Object -First 1).DesignedCapacity
  $f = (Get-CimInstance -Namespace root\wmi -ClassName BatteryFullChargedCapacity -ErrorAction Stop | Select-Object -First 1).FullChargedCapacity
} catch { "Battery capacity not readable"; exit 0 }
if (-not $d -or $d -le 0 -or -not $f) { "Battery capacity not reported"; exit 0 }
$p = [int](100 * $f / $d)
if ($p -lt 50) { "Battery health $p percent of design capacity: replace soon"; exit 2 }
if ($p -lt 70) { "Battery health $p percent of design capacity"; exit 1 }
"Battery health $p percent of design capacity"
exit 0
PS;
$uptimePs = <<<'PS'
$b = (Get-CimInstance Win32_OperatingSystem).LastBootUpTime
$d = [int]((Get-Date) - $b).TotalDays
if ($d -ge 30) { "Up $d days (limit 30 days): restart soon"; exit 2 }
if ($d -ge 14) { "Up $d days (limit 14 days)"; exit 1 }
"Up $d days"
exit 0
PS;

$role = static fn (string $id, string $name, string $service, string $what): array => [
    'id' => $id, 'kind' => 'check_policy', 'rev' => 1, 'name' => $name, 'platform' => 'windows', 'group' => 'Windows server role add-ons',
    'summary' => "Alerts when the $what service stops on a server that has this role. An add-on: assign it only to servers that run the role.",
    'why' => "A stopped $what service is an outage for everything that depends on it, and nothing else in the baseline watches it.",
    'body' => ['settings' => $on + ["check.role.$id" => ['type' => 'service', 'params' => ['name' => $service, 'expected' => 'running'], 'interval_s' => 120, 'os' => 'windows']]],
    'why_settings' => ['feature.checks' => 'Checks are delivered to the device.', "check.role.$id" => "Service $service must be running; checked every 2 minutes because a role outage is user visible."],
];

$items = [];

// ---------------------------------------------------------------- check policies
$items[] = [
    'id' => 'windows-workstation', 'kind' => 'check_policy', 'rev' => 1, 'name' => 'Pack: Windows workstation baseline', 'platform' => 'windows', 'group' => 'Check policies',
    'summary' => 'The everyday health checks for a Windows desktop or laptop: disk space, a pending reboot, Defender, firewall, time, the event log and the agent update channel.',
    'why' => 'These are the problems that turn into tickets on a workstation. Limits are loose enough that a normal machine stays quiet.',
    'body' => ['settings' => $on + [
        'agent.ring' => 'stable',
        'check.starter.disk_free' => $chk('disk', ['warn_free_pct' => 20, 'fail_free_pct' => 10, 'warn_free_gb' => 10, 'fail_free_gb' => 3], 300, 'windows'),
        'check.starter.pending_reboot' => $chk('pending_reboot', [], 3600, 'windows'),
        'check.starter.av' => $chk('av', ['max_signature_age_days' => 7, 'require_realtime' => true], 3600, 'windows'),
        'check.starter.svc_firewall' => $svc('mpssvc', 'windows'),
        'check.starter.svc_time' => $svc('W32Time', 'windows'),
        'check.starter.svc_eventlog' => $svc('EventLog', 'windows'),
        'check.starter.time_skew' => $chk('ntp', ['server' => 'pool.ntp.org', 'warn_ms' => 1000, 'fail_ms' => 5000, 'timeout_ms' => 3000], 3600, 'windows'),
    ]],
    'why_settings' => [
        'feature.checks' => 'Checks are delivered to the device.',
        'agent.ring' => 'Stable agent channel: workstations get agent updates after the pilot devices proved them.',
        'check.starter.disk_free' => 'Warn under 20 % or 10 GB free, fail under 10 % or 3 GB: a full system drive breaks updates and profiles. Every 5 minutes.',
        'check.starter.pending_reboot' => 'A reboot waiting after updates leaves the machine unpatched. Hourly, it is slow-moving.',
        'check.starter.av' => 'Defender real-time protection on and signatures at most 7 days old.',
        'check.starter.svc_firewall' => 'The Windows Firewall service (mpssvc) must run; without it the firewall is off.',
        'check.starter.svc_time' => 'Windows Time must run or Kerberos and certificates start failing.',
        'check.starter.svc_eventlog' => 'Without the Event Log service nothing is audited and many components fail.',
        'check.starter.time_skew' => 'Clock offset from pool.ntp.org: warn over 1 s, fail over 5 s (Kerberos allows 5 minutes, so this is an early warning). Hourly.',
    ],
    'note_windows_update' => 'Windows Update is not a service check: wuauserv is trigger-started and would flap. Updates are covered by the patch policies.',
];
$items[] = [
    'id' => 'windows-server', 'kind' => 'check_policy', 'rev' => 1, 'name' => 'Pack: Windows server baseline', 'platform' => 'windows', 'group' => 'Check policies',
    'summary' => 'The workstation checks with stricter disk limits, the core server services, certificate expiry and disk health. Add the role add-ons for DNS, DHCP, AD DS and the rest.',
    'why' => 'A server has more to lose than a workstation: thresholds fire earlier and the server, scheduler and certificate checks are added.',
    'body' => ['settings' => $on + [
        'agent.ring' => 'stable',
        'check.starter.disk_free' => $chk('disk', ['warn_free_pct' => 15, 'fail_free_pct' => 8, 'warn_free_gb' => 20, 'fail_free_gb' => 5], 300, 'windows'),
        'check.starter.pending_reboot' => $chk('pending_reboot', [], 3600, 'windows'),
        'check.starter.av' => $chk('av', ['max_signature_age_days' => 3, 'require_realtime' => true], 3600, 'windows'),
        'check.starter.svc_firewall' => $svc('mpssvc', 'windows'),
        'check.starter.svc_time' => $svc('W32Time', 'windows'),
        'check.starter.svc_eventlog' => $svc('EventLog', 'windows'),
        'check.starter.svc_server' => $svc('LanmanServer', 'windows'),
        'check.starter.svc_scheduler' => $svc('Schedule', 'windows'),
        'check.starter.svc_wmi' => $svc('Winmgmt', 'windows'),
        'check.starter.svc_dns_client' => $svc('Dnscache', 'windows'),
        'check.starter.time_skew' => $chk('ntp', ['server' => 'pool.ntp.org', 'warn_ms' => 500, 'fail_ms' => 3000, 'timeout_ms' => 3000], 1800, 'windows'),
        'check.starter.cert_expiry' => $chk('cert', ['store' => 'LocalMachine\\My', 'warn_days' => 30, 'fail_days' => 7], 21600, 'windows'),
        'check.starter.smart' => $chk('smart', [], 21600, 'windows'),
    ]],
    'why_settings' => [
        'feature.checks' => 'Checks are delivered to the device.',
        'agent.ring' => 'Stable agent channel.',
        'check.starter.disk_free' => 'Warn under 15 % or 20 GB free, fail under 8 % or 5 GB: the absolute limits matter on large volumes where 15 % is hundreds of GB.',
        'check.starter.pending_reboot' => 'A server waiting for a reboot is unpatched; hourly.',
        'check.starter.av' => 'Defender on with signatures at most 3 days old: servers are a bigger target.',
        'check.starter.svc_firewall' => 'Firewall service must run.',
        'check.starter.svc_time' => 'Windows Time must run; on a domain controller or member it keeps Kerberos working.',
        'check.starter.svc_eventlog' => 'Event Log service must run.',
        'check.starter.svc_server' => 'The Server service (LanmanServer) provides file shares; stopped means shares are gone.',
        'check.starter.svc_scheduler' => 'Task Scheduler runs backups and maintenance jobs.',
        'check.starter.svc_wmi' => 'WMI is how most monitoring and management tools read the machine.',
        'check.starter.svc_dns_client' => 'DNS Client service must run for name resolution.',
        'check.starter.time_skew' => 'Warn over 0.5 s, fail over 3 s, every 30 minutes: tighter than a workstation. On a domain, set the time server to a domain controller by editing the policy.',
        'check.starter.cert_expiry' => 'Machine certificates (LocalMachine\\My) warn 30 days and fail 7 days before expiry, every 6 hours.',
        'check.starter.smart' => 'Physical disk health (SMART) every 6 hours: the earliest sign of a failing disk.',
    ],
];
foreach ([
    ['dns', 'Pack add-on: DNS server role', 'DNS', 'DNS Server'],
    ['dhcp', 'Pack add-on: DHCP server role', 'DHCPServer', 'DHCP Server'],
    ['adds', 'Pack add-on: Active Directory Domain Services role', 'NTDS', 'Active Directory Domain Services'],
    ['spooler', 'Pack add-on: Print Spooler role', 'Spooler', 'Print Spooler'],
    ['sql', 'Pack add-on: SQL Server role (default instance)', 'MSSQLSERVER', 'SQL Server (default instance)'],
    ['iis', 'Pack add-on: IIS role', 'W3SVC', 'World Wide Web Publishing (IIS)'],
    ['hyperv', 'Pack add-on: Hyper-V host role', 'vmms', 'Hyper-V Virtual Machine Management'],
    ['veeam', 'Pack add-on: Veeam Backup role', 'VeeamBackupSvc', 'Veeam Backup'],
] as [$k, $n, $s, $w]) {
    $items[] = $role("role-$k", $n, $s, $w);
}
$items[] = [
    'id' => 'linux-server', 'kind' => 'check_policy', 'rev' => 1, 'name' => 'Pack: Linux server baseline', 'platform' => 'linux', 'group' => 'Check policies',
    'summary' => 'Disk and inode space, failed systemd units, the ssh, cron, log and time services, fail2ban and a pending reboot.',
    'why' => 'The usual ways a Linux server quietly breaks: a full disk or inode table, a dead cron or ssh, a clock drift, a logger that stopped.',
    'params' => [
        'ssh_unit' => ['default' => 'ssh', 'label' => 'ssh service name (sshd on RHEL family)'],
        'cron_unit' => ['default' => 'cron', 'label' => 'cron service name (crond on RHEL family)'],
        'time_unit' => ['default' => 'systemd-timesyncd', 'label' => 'time sync service name (chronyd on RHEL family)'],
        'syslog_unit' => ['default' => 'rsyslog', 'label' => 'syslog service name'],
    ],
    'body' => ['settings' => $on + [
        'agent.ring' => 'stable',
        'check.starter.disk_free' => $chk('disk', ['warn_free_pct' => 15, 'fail_free_pct' => 8, 'warn_free_gb' => 10, 'fail_free_gb' => 3], 300, 'linux'),
        'check.starter.inodes' => $chk('inodes', ['mount' => '/', 'warn_pct' => 85, 'fail_pct' => 95], 600, 'linux'),
        'check.starter.systemd_failed' => $chk('systemd_failed', ['max_failed' => 0], 300, 'linux'),
        'check.starter.pending_reboot' => $chk('pending_reboot', [], 3600, 'linux'),
        'check.starter.svc_ssh' => $chk('service', ['name' => ['$param' => 'ssh_unit'], 'expected' => 'running'], 300, 'linux'),
        'check.starter.svc_cron' => $chk('service', ['name' => ['$param' => 'cron_unit'], 'expected' => 'running'], 300, 'linux'),
        'check.starter.svc_time' => $chk('service', ['name' => ['$param' => 'time_unit'], 'expected' => 'running'], 300, 'linux'),
        'check.starter.svc_syslog' => $chk('service', ['name' => ['$param' => 'syslog_unit'], 'expected' => 'running'], 300, 'linux'),
        'check.starter.svc_fail2ban' => $chk('service', ['name' => 'fail2ban', 'expected' => 'running'], 300, 'linux'),
        'check.starter.time_skew' => $chk('ntp', ['server' => 'pool.ntp.org', 'warn_ms' => 500, 'fail_ms' => 3000, 'timeout_ms' => 3000], 1800, 'linux'),
        'check.starter.smart' => $chk('smart', [], 21600, 'linux'),
    ]],
    'why_settings' => [
        'feature.checks' => 'Checks are delivered to the device.',
        'agent.ring' => 'Stable agent channel.',
        'check.starter.disk_free' => 'Warn under 15 % or 10 GB free, fail under 8 % or 3 GB.',
        'check.starter.inodes' => 'Inodes on / at 85 % warn, 95 % fail: millions of small files fill the inode table while df still shows free space.',
        'check.starter.systemd_failed' => 'Any failed systemd unit is reported; a failed unit is a service that is not running.',
        'check.starter.pending_reboot' => 'Kernel or library updates waiting for a reboot.',
        'check.starter.svc_ssh' => 'ssh must run or the server cannot be managed. Service name is an install parameter.',
        'check.starter.svc_cron' => 'cron runs scheduled jobs and backups. Service name is an install parameter.',
        'check.starter.svc_time' => 'Time sync service. Service name is an install parameter.',
        'check.starter.svc_syslog' => 'rsyslog collects logs. Service name is an install parameter. Remove the check on journald-only systems.',
        'check.starter.svc_fail2ban' => 'fail2ban blocks brute force on ssh. Delete the check from your copy where it is not installed.',
        'check.starter.time_skew' => 'Clock offset from pool.ntp.org, warn over 0.5 s, fail over 3 s.',
        'check.starter.smart' => 'Physical disk SMART health every 6 hours; reports not applicable on virtual disks.',
    ],
];
$items[] = [
    'id' => 'laptop', 'kind' => 'check_policy', 'rev' => 1, 'name' => 'Pack: Laptop add-on', 'platform' => 'windows', 'group' => 'Check policies',
    'summary' => 'Adds battery health and a "restart it, it has been on for weeks" check for Windows laptops. An add-on to the workstation baseline.',
    'why' => 'Laptops get sleep-and-resume instead of restarts, so updates stay pending, and batteries wear out silently.',
    'body' => ['settings' => $on + [
        'feature.script_checks' => true,
        'check.laptop.battery' => $chk('script', ['script' => $batteryPs, 'timeout_s' => 30], 21600, 'windows'),
        'check.laptop.uptime' => $chk('script', ['script' => $uptimePs, 'timeout_s' => 30], 3600, 'windows'),
    ]],
    'why_settings' => [
        'feature.checks' => 'Checks are delivered to the device.',
        'feature.script_checks' => 'Script checks run only where this is on (and the instance allows them); the two scripts are short and read only.',
        'check.laptop.battery' => 'Full-charge capacity versus design capacity: warn under 70 %, fail under 50 %. Desktops without a battery report not applicable.',
        'check.laptop.uptime' => 'Warn after 14 days, fail after 30 days without a restart.',
    ],
];
$items[] = [
    'id' => 'minimal', 'kind' => 'check_policy', 'rev' => 1, 'name' => 'Pack: Minimal / low noise', 'platform' => 'any', 'group' => 'Check policies',
    'summary' => 'Only the two things nobody argues about: a nearly full disk and (on Windows) real-time protection switched off.',
    'why' => 'For clients or machines where you want almost no alerts. Nothing warns early; it only fires when something is really wrong.',
    'body' => ['settings' => $on + [
        'check.minimal.disk_free' => $chk('disk', ['warn_free_pct' => 8, 'fail_free_pct' => 4, 'warn_free_gb' => 2, 'fail_free_gb' => 1], 900),
        'check.minimal.av' => $chk('av', ['max_signature_age_days' => 30, 'require_realtime' => true], 21600, 'windows'),
    ]],
    'why_settings' => [
        'feature.checks' => 'Checks are delivered to the device.',
        'check.minimal.disk_free' => 'Warn under 8 % or 2 GB, fail under 4 % or 1 GB, every 15 minutes. Applies to Windows and Linux.',
        'check.minimal.av' => 'Defender real-time protection must be on; signatures may be a month old. Windows only.',
    ],
];

// ---------------------------------------------------------------- alerting presets (thresholds, flapping, debounce)
$flap = ['window' => 20, 'high_pct' => 60, 'low_pct' => 30];
$th = static fn (int $warn, int $crit, int $hyst, int $samples, int $minutes): array => ['warn' => ['op' => 'gt', 'value' => $warn], 'crit' => ['op' => 'gt', 'value' => $crit], 'hysteresis' => $hyst, 'for_samples' => $samples, 'for_minutes' => $minutes];
$cpu = static fn (array $t): array => $chk('cpu', ['window_s' => 30, 'warn_pct' => 100, 'fail_pct' => 100, 'thresholds' => $t, 'flap' => ['window' => 20, 'high_pct' => 60, 'low_pct' => 30]], 60);
$mem = static fn (array $t): array => $chk('memory', ['metric' => 'mem_used_pct', 'warn_pct' => 100, 'fail_pct' => 100, 'thresholds' => $t, 'flap' => ['window' => 20, 'high_pct' => 60, 'low_pct' => 30]], 60);
$friendly = ' Alerts that would open during a maintenance window are held back by the window itself (mute mode), and the duration requirement means a reboot or a patch run never raises one.';
$items[] = [
    'id' => 'alert-workstation', 'kind' => 'alert_preset', 'rev' => 1, 'name' => 'Pack alerting: Workstation thresholds', 'platform' => 'any', 'group' => 'Alerting presets',
    'summary' => 'CPU and memory alerts for desktops and laptops: warn at 90 %, critical at 98 % / 97 %, only after the level has held for 15 minutes.',
    'why' => 'Workstations spike all the time; a user opening a large file is not an incident. Only sustained pressure should open an alert.' . $friendly,
    'body' => ['settings' => $on + [
        'check.alert.cpu' => $cpu($th(90, 98, 5, 3, 15)),
        'check.alert.memory' => $mem($th(90, 97, 3, 3, 15)),
    ]],
    'why_settings' => [
        'feature.checks' => 'Checks are delivered to the device.',
        'check.alert.cpu' => 'Warn over 90 %, critical over 98 %, must hold 3 samples and 15 minutes, hysteresis 5 points; flapping above 60 % state changes in 20 samples holds the alert state. The agent instant verdict is set to 100 % so spikes never alert.',
        'check.alert.memory' => 'Warn over 90 %, critical over 97 %, 3 samples and 15 minutes, hysteresis 3 points, same flap guard.',
    ],
];
$items[] = [
    'id' => 'alert-server', 'kind' => 'alert_preset', 'rev' => 1, 'name' => 'Pack alerting: Server thresholds', 'platform' => 'any', 'group' => 'Alerting presets',
    'summary' => 'CPU, memory and swap alerts for servers: earlier warnings (85 %), a shorter 10 minute hold, and memory pressure and swap on Linux.',
    'why' => 'A server should not sit above 85 % for long. Servers get warned sooner than workstations but still need a hold time so backups and patch runs do not page anyone.' . $friendly,
    'body' => ['settings' => $on + [
        'check.alert.cpu' => $cpu($th(85, 95, 5, 3, 10)),
        'check.alert.memory' => $mem($th(88, 95, 3, 3, 10)),
        'check.alert.swap' => $chk('memory', ['metric' => 'swap_used_pct', 'warn_pct' => 100, 'fail_pct' => 100, 'thresholds' => $th(50, 80, 5, 3, 20), 'flap' => $flap], 300, 'linux'),
        'check.alert.mem_pressure' => $chk('memory', ['metric' => 'psi_some_avg10', 'warn_pct' => 100, 'fail_pct' => 100, 'thresholds' => $th(20, 40, 5, 3, 10), 'flap' => $flap], 60, 'linux'),
    ]],
    'why_settings' => [
        'feature.checks' => 'Checks are delivered to the device.',
        'check.alert.cpu' => 'Warn over 85 %, critical over 95 %, held 3 samples and 10 minutes, hysteresis 5, flap guard.',
        'check.alert.memory' => 'Warn over 88 %, critical over 95 %, held 3 samples and 10 minutes, hysteresis 3, flap guard.',
        'check.alert.swap' => 'Linux only: swap use over 50 % warns and over 80 % is critical, held 20 minutes; heavy swapping means the memory estimate was wrong.',
        'check.alert.mem_pressure' => 'Linux only: kernel memory pressure (PSI some avg10) over 20 warns and over 40 is critical for 10 minutes: tasks are stalled waiting for memory.',
    ],
];
$items[] = [
    'id' => 'alert-quiet', 'kind' => 'alert_preset', 'rev' => 1, 'name' => 'Pack alerting: Quiet (critical only)', 'platform' => 'any', 'group' => 'Alerting presets',
    'summary' => 'Only critical CPU and memory: over 98 % / 97 % for 30 minutes. No warnings.',
    'why' => 'For noisy clients: you hear about it only when a machine is effectively unusable.' . $friendly,
    'body' => ['settings' => $on + [
        'check.alert.cpu' => $cpu(['crit' => ['op' => 'gt', 'value' => 98], 'hysteresis' => 5, 'for_samples' => 5, 'for_minutes' => 30]),
        'check.alert.memory' => $mem(['crit' => ['op' => 'gt', 'value' => 97], 'hysteresis' => 3, 'for_samples' => 5, 'for_minutes' => 30]),
    ]],
    'why_settings' => [
        'feature.checks' => 'Checks are delivered to the device.',
        'check.alert.cpu' => 'Critical only, over 98 % for 5 samples and 30 minutes.',
        'check.alert.memory' => 'Critical only, over 97 % for 5 samples and 30 minutes.',
    ],
    'note_metrics' => 'Disk queue length and network error metrics have no threshold-capable check type in this RivetCore release, so the pack ships no preset for them.',
];

// ---------------------------------------------------------------- patch policies (rings pilot, broad, last must exist)
$items[] = [
    'id' => 'patch-pilot', 'kind' => 'patch_policy', 'rev' => 1, 'name' => 'Pack patch: Pilot (about 10 % of devices, next day)', 'platform' => 'any', 'group' => 'Patch policies',
    'summary' => 'Approves updates automatically one day after they appear, for a pilot group, and installs them at night. Assign it to roughly one device in ten.',
    'why' => 'The pilot group finds a bad update before everyone else gets it. Pair it with the Standard policy for the rest.',
    'body' => ['settings' => ['ring' => 'pilot', 'approval_mode' => 'auto', 'auto_days_critical' => 1, 'auto_days_security' => 1, 'auto_days_important' => 1, 'install_enabled' => true,
        'window_mode' => 'daily', 'window_days' => 'mon,tue,wed,thu,fri', 'window_start' => '20:00', 'window_minutes' => 240, 'reboot_policy' => 'if_required_defer', 'max_minutes' => 90]],
    'why_settings' => [
        'ring' => 'Ring pilot (no extra delay).', 'approval_mode' => 'Automatic: the pilot is the test, a person does not have to approve each update.',
        'auto_days_critical' => 'Critical updates approved 1 day after first seen.', 'auto_days_security' => 'Security updates approved 1 day after first seen.',
        'auto_days_important' => 'Important updates approved 1 day after first seen. Optional and driver updates are never approved by this policy.',
        'install_enabled' => 'The scheduler may install approved updates.', 'window_mode' => 'Install only inside the daily window below.',
        'window_days' => 'Weekdays only, so a bad update is noticed during working hours.', 'window_start' => 'From 20:00 (the policy time zone is UTC unless you set one).',
        'window_minutes' => 'Four hours.', 'reboot_policy' => 'Reboots are reported, not performed: a restart by itself needs a person to confirm it on the policy.',
        'max_minutes' => 'An install may run 90 minutes.',
    ],
];
$items[] = [
    'id' => 'patch-standard', 'kind' => 'patch_policy', 'rev' => 1, 'name' => 'Pack patch: Standard (Tuesday + 7 days)', 'platform' => 'any', 'group' => 'Patch policies',
    'summary' => 'Approves security and important updates a week after they appear (the Tuesday after Patch Tuesday), and installs them in a Tuesday evening window.',
    'why' => 'A week of pilot soak time catches the bad updates; the Tuesday window gives a fixed, predictable rhythm for the main fleet.',
    'body' => ['settings' => ['ring' => 'broad', 'approval_mode' => 'auto', 'auto_days_critical' => 0, 'auto_days_security' => 0, 'auto_days_important' => 0, 'install_enabled' => true,
        'window_mode' => 'daily', 'window_days' => 'tue', 'window_start' => '19:00', 'window_minutes' => 300, 'reboot_policy' => 'if_required_defer', 'max_minutes' => 120]],
    'why_settings' => [
        'ring' => 'Ring broad adds the 7 day delay; with no extra days that gives Tuesday + 7.', 'approval_mode' => 'Automatic after the delay.',
        'auto_days_critical' => 'No days on top of the ring delay.', 'auto_days_security' => 'No days on top of the ring delay.', 'auto_days_important' => 'No days on top of the ring delay. Optional and driver updates are never approved by this policy.',
        'install_enabled' => 'The scheduler may install approved updates.', 'window_mode' => 'Daily-style window, set to one weekday.', 'window_days' => 'Tuesdays only.',
        'window_start' => 'From 19:00.', 'window_minutes' => 'Five hours.', 'reboot_policy' => 'Reboots are reported, not performed (see the Pilot policy).', 'max_minutes' => 'An install may run 2 hours.',
    ],
];
$items[] = [
    'id' => 'patch-servers', 'kind' => 'patch_policy', 'rev' => 1, 'name' => 'Pack patch: Servers (monthly, manual approval)', 'platform' => 'any', 'group' => 'Patch policies',
    'summary' => 'A person approves each update (a second person must agree); installation happens only in a Saturday night window. Reboots are left to you.',
    'why' => 'Servers must not patch themselves in the middle of the week. Approval, a second pair of eyes and a fixed window make it a planned change.',
    'body' => ['settings' => ['ring' => 'last', 'approval_mode' => 'manual', 'two_person' => true, 'install_enabled' => true,
        'window_mode' => 'daily', 'window_days' => 'sat', 'window_start' => '22:00', 'window_minutes' => 360, 'reboot_policy' => 'if_required_defer', 'max_minutes' => 180]],
    'why_settings' => [
        'ring' => 'Ring last: sensitive machines two weeks behind the pilot.', 'approval_mode' => 'Manual: nothing is approved by itself.',
        'two_person' => 'Approving an update by hand needs a second person.', 'install_enabled' => 'Approved updates may be installed in the window.',
        'window_mode' => 'Installs only inside the window.', 'window_days' => 'Saturday only (run it monthly by approving once a month).', 'window_start' => 'From 22:00.',
        'window_minutes' => 'Six hours.', 'reboot_policy' => 'Reboots outside business hours are queued by you as a reboot job in the Saturday window; the policy never restarts a server by itself.',
        'max_minutes' => 'An install may run 3 hours.',
    ],
];
$items[] = [
    'id' => 'patch-critical', 'kind' => 'patch_policy', 'rev' => 1, 'name' => 'Pack patch: Critical only', 'platform' => 'any', 'group' => 'Patch policies',
    'summary' => 'Approves only updates classified critical, the day after they appear. Everything else waits for a person.',
    'why' => 'For machines that must stay as they are except for emergency fixes.',
    'body' => ['settings' => ['ring' => 'pilot', 'approval_mode' => 'auto', 'auto_days_critical' => 1, 'install_enabled' => true,
        'window_mode' => 'daily', 'window_days' => 'mon,tue,wed,thu,fri,sat,sun', 'window_start' => '01:00', 'window_minutes' => 180, 'reboot_policy' => 'never', 'max_minutes' => 60]],
    'why_settings' => [
        'ring' => 'Ring pilot, no extra delay.', 'approval_mode' => 'Automatic, but only for the kind below.', 'auto_days_critical' => 'Critical updates approved 1 day after first seen; all other kinds are never approved by this policy.',
        'install_enabled' => 'Approved updates may be installed.', 'window_mode' => 'Nightly window.', 'window_days' => 'Every night.', 'window_start' => 'From 01:00.', 'window_minutes' => 'Three hours.',
        'reboot_policy' => 'Never reboot, not even a report.', 'max_minutes' => 'An install may run one hour.',
    ],
];

// ---------------------------------------------------------------- maintenance window templates (created switched off; time zone is an install parameter)
$mw = static fn (string $id, string $name, string $summary, string $why, array $w): array => [
    'id' => $id, 'kind' => 'maintenance_window', 'rev' => 1, 'name' => $name, 'platform' => 'any', 'group' => 'Maintenance windows', 'summary' => $summary, 'why' => $why,
    'params' => ['timezone' => ['default' => 'UTC', 'label' => 'IANA time zone the window times are in, for example America/Chicago']],
    'body' => ['window' => ['mode' => 'mute', 'scope_type' => 'all', 'scope_id' => 0, 'kind' => 'recurring'] + $w],
    'why_settings' => ['mode' => 'Mute: checks keep running, new alerts are held back until the window ends, open alerts stay open.', 'scope_type' => 'Applies to every device; narrow the scope after installing if you only want some.',
        'kind' => 'Repeats.', 'recur_freq' => 'How often it repeats.', 'recur_days' => 'Which days.', 'local_start' => 'Start time in the window time zone.', 'duration_min' => 'How long it stays open.', 'scope_id' => 'Not used for all.', 'recur_interval' => 'Every period.'],
];
$items[] = $mw('mw-nightly', 'Pack window: Nightly 02:00-04:00', 'Every night from 02:00 to 04:00 local time. Created switched off.', 'Backups, scans and updates run at night and make noise. This keeps alerts quiet during that time.',
    ['recur_freq' => 'daily', 'recur_interval' => 1, 'recur_days' => '', 'local_start' => '02:00', 'duration_min' => 120]);
$items[] = $mw('mw-sunday-patching', 'Pack window: Sunday patching 01:00-05:00', 'Sundays from 01:00 to 05:00 local time. Created switched off.', 'A weekly patching slot for workstations, so reboots and service restarts do not raise alerts.',
    ['recur_freq' => 'weekly', 'recur_interval' => 1, 'recur_days' => '7', 'local_start' => '01:00', 'duration_min' => 240]);
$items[] = $mw('mw-server-patching-sat', 'Pack window: Server patching Saturday 22:00-04:00', 'Saturdays from 22:00 for six hours, local time. Created switched off.', 'The slot for the Servers patch policy, long enough for a reboot.',
    ['recur_freq' => 'weekly', 'recur_interval' => 1, 'recur_days' => '6', 'local_start' => '22:00', 'duration_min' => 360]);

// ---------------------------------------------------------------- change monitor presets (global switch, explicit confirmation needed)
$items[] = [
    'id' => 'change-servers', 'kind' => 'change_presets', 'rev' => 1, 'name' => 'Pack change monitoring: server persistence and security presets', 'platform' => 'any', 'group' => 'Change monitoring',
    'summary' => 'Turns on the change-monitor watches for startup keys, local administrators and Defender exclusions on Windows, and Linux ssh, sudo and accounts. Optional.',
    'why' => 'These are the places an attacker, or an unplanned change, shows up first. Unlike the other items this is an instance-wide setting (change monitoring has no per-policy scope in this release), so installing needs an explicit confirmation and it also applies to workstations.',
    'body' => ['presets' => ['windows' => ['win.startup', 'win.local_admins', 'win.defender'], 'linux' => ['lnx.ssh', 'lnx.sudo', 'lnx.accounts']]],
    'why_settings' => ['presets' => 'Windows: win.startup (Run keys, Winlogon, IFEO), win.local_admins (Administrators group), win.defender (exclusions and policy). Linux: lnx.ssh, lnx.sudo, lnx.accounts.'],
];

return ['format' => 1, 'id' => 'rivetcore-policies', 'name' => 'RivetCore policy starter pack', 'version' => '1.0.0', 'items' => $items];
