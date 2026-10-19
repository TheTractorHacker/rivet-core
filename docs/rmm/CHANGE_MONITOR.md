# RMM change monitoring (registry, configuration files, groups, tasks, security logs)

Know what changed on a machine, when, and (on Windows, when it was recorded) who did it. The agent keeps a snapshot of the places you ask it to watch, compares it at a safe interval, and reports only the DIFFERENCE. The records live in the same database as everything else (no separate server), with retention, a per-device cap and a fleet cap like the log feature, and a change to a Run key or a Defender exclusion can raise an alert.

Status: **built in Core (migration 0030), not adopted by an edition yet**, behind the `change_monitor` sub-switch (off by default). The Linux half (configuration files) is proven end to end on a real systemd container against the real PHP server ([section 13](#13-the-linux-proof)). **The Windows half has never run on a Windows host**: the registry reader, the registry change notification, `net localgroup`, the scheduled-task reader and the audit-event actor lookup are cross-compiled, vetted and unit-tested with fakes and recorded fixtures only. Everything that does not depend on the operating system (the signed configuration, the policy, the snapshots, the diffs, redaction, the queue, shipping, the server) is the same code in both and is tested on Linux.

Related: [REMOTE_AND_LOGS.md](REMOTE_AND_LOGS.md) (the event-log collector this builds on and the Security log presets), [PROTOCOL.md](PROTOCOL.md) sections 3.2.7 and 3.9, [openapi-device.yaml](openapi-device.yaml) (`/agent_changes`, `/endpoint_devices/changes*`, `/endpoint_devices/{id}/changes*`), [CAPACITY.md](CAPACITY.md), [docs/modules/rmm.md](../modules/rmm.md), [SOFTWARE_DEPLOY.md](SOFTWARE_DEPLOY.md) and [PATCHING.md](PATCHING.md) (the pattern this follows).

## 1. What you get

| Area | What it records | Where it is read | Windows | Linux |
|---|---|---|---|---|
| Registry values | key, value name, old and new value (cut, secrets masked), when, best-effort who | the registry, by polling (and `RegNotifyChangeKeyValue` to poll sooner) | yes (presets and custom) | no registry |
| Configuration files | path, SHA-256 before and after, the lines added and removed, permission and owner changes | files and folders below a short list of configuration roots | the hosts file and the GroupPolicy folders | yes (presets and custom) |
| Local administrators | who was added to or removed from the Administrators group | `net localgroup` (not the registry) | yes | no |
| Scheduled tasks | a task added, removed, enabled or disabled, its program, arguments and account | the task definition files in `System32\Tasks` | yes | no (cron is a file preset) |
| Installed software | installs, removals, version changes | the existing software inventory (nothing is read again) | merged into the timeline | merged into the timeline |
| Security log events | account changes, group membership, log cleared, new service, task created, audit policy changed, new device, repeated failed logons | the existing event-log collector, as named presets | yes | no |

Every record is checked on the server on every call (role, then the device's client, then the ability, then the switch). A device or client outside the caller's clients is the same `404` as a missing one.

## 2. The model in one page

```
administrator --> presets on/off  +  custom watches (pass the watch policy)  +  caps          rmm.change.manage / rmm.admin
                          |
        check-in response: features ["change:monitor"] + config.change  = ONE SIGNED OBJECT (Ed25519, domain-separated)
                          |                                              offered only to an agent that announced change:monitor
   agent: verify the signature  -->  apply its OWN policy again  -->  clamp every number
                          |
   first sight of a watch = silent BASELINE (snapshot kept on the device, redacted, in change_state.json, mode 0600)
                          |
   every interval (60 s to 24 h, default 300 s; Windows also when a registry notification fires):
        read --> compare with the snapshot --> records (added / modified / removed) --> a persisted queue
                          |
   POST agent_changes (batches of at most 100, within a per-minute budget)  --> the server validates EVERY record again
                          |
   endpoint_agent_change_records  +  change rules (alerts through the check evaluator)  +  events  +  timeline
```

* **What is a change.** An item (a registry value, a file, a group member, a task) that was not in the last snapshot (`added`), is not any more (`removed`) or has another hash or other permissions (`modified`). The time of a record is when the agent DETECTED it, not when it happened; a change made while the agent was stopped is found at the next start.
* **Baseline.** The first reading of a watch, and the first reading after its specification changed, is stored and not reported. Turning a preset on therefore never floods the database with "added".
* **Unreadable is not removed.** A key or file that cannot be read this time (access denied, a read error) keeps its old snapshot and the watch is marked `partial`; only a key or file that really does not exist produces `removed`.
* **Not a log.** There is no history on the device beyond the last snapshot. What was not reported (the agent was reinstalled, a state file was deleted) is not recoverable.

## 3. The switch and its warning

Sub-switch `change_monitor`, off by default, together with the `jobs`-style rule that nothing is offered unless the agent asked. Turning it on needs `rmm.admin` and an explicit acknowledgement (`accept_change_storage: true`), else `422 confirmation_required` with the text of `ChangeSettings::STORAGE_WARNING`, which an edition shows verbatim:

> Change monitoring stores, in this server's database, what changed on every monitored device: registry keys and values, the lines added to or removed from configuration files, local group members and scheduled task definitions, with the user and program when Windows records them. Values that look like secrets are never read, hashed or stored, and credential stores (SAM, SECURITY, shadow files, private keys) are refused, but what remains can still contain user names, host names, paths and program command lines. It is capped per device and for the whole fleet and old records are deleted; choose the presets you need, keep retention short, and watch the storage figure shown here before turning it on for a large fleet.

Presets and custom watches need `rmm.change.manage` **and access to every client** (a watch applies to every device of the platform; per-client watches are not built). The configuration is the same for every device of a platform.

## 4. The presets

A preset is a constant in code (`Change\ChangePresets`), not data: turning one on puts exactly its watches into the signed configuration. Ids are `win.<name>` and `lnx.<name>`.

### Windows

| Preset | What is watched |
|---|---|
| `win.startup` | `HKLM` Run, RunOnce, RunOnceEx, `Policies\Explorer\Run`, the 32-bit Run and RunOnce, **every loaded user's** Run and RunOnce (`HKU\*`), Winlogon `Shell`/`Userinit`/`Taskman`/`VMApplet`/`AppSetup`/`GinaDLL`, `AppInit_DLLs`, Image File Execution Options `Debugger`/`GlobalFlag`/`VerifierDlls` |
| `win.services` | for every service: `Start`, `ImagePath`, `ObjectName`, `Type`, `DelayedAutostart`, `ServiceDll` (two levels below `Services`); a new service key shows as added values |
| `win.defender` | `Windows Defender\Exclusions` (paths, extensions, processes, addresses), the Defender group policy, real-time protection, tamper protection, `Spynet` |
| `win.firewall` | the three firewall profiles and every rule (`FirewallPolicy`, local and group policy) |
| `win.rdp` | `fDenyTSConnections` and friends, the RDP-Tcp port, NLA and security layer, the Remote Desktop policy |
| `win.uac` | `EnableLUA`, the consent prompts, the secure desktop, the remote token filter policy |
| `win.update_policy` | the Windows Update policy and Auto Update keys |
| `win.local_admins` | the members of the local Administrators group (`net localgroup`; a localised Windows is resolved by the group's SID `S-1-5-32-544`) |
| `win.tasks` | the scheduled task definitions |
| `win.hosts` | the Windows hosts file |
| `win.software` | (not read: the software inventory is merged into the timeline) |

### Linux

| Preset | What is watched |
|---|---|
| `lnx.ssh` | `sshd_config`, `sshd_config.d/*.conf`, root's `authorized_keys` |
| `lnx.sudo` | `/etc/sudoers`, `/etc/sudoers.d` |
| `lnx.accounts` | `/etc/passwd`, `/etc/group` (never `/etc/shadow`; a passwd line's password field is never kept) |
| `lnx.cron` | `/etc/crontab`, `/etc/cron.d`, hourly, daily, weekly, monthly, the user crontabs |
| `lnx.systemd` | `/etc/systemd/system` (two levels, so enable links show), `/usr/local/lib/systemd/system` |
| `lnx.startup` | `/etc/ld.so.preload`, `rc.local`, `/etc/profile`, `/etc/profile.d`, `/etc/environment` |
| `lnx.hosts` | `/etc/hosts`, `nsswitch.conf`, `hosts.allow`, `hosts.deny` |
| `lnx.pam` | `/etc/pam.d` |
| `lnx.software` | (not read: merged from the inventory) |

The package-managed unit folders (`/usr/lib/systemd/system`) are deliberately not in a preset: every package update would be a change. Add them as a custom watch if you want them.

## 5. Custom watches and the watch policy

A custom watch is `{id, platform, kind: registry|file, title, paths}` (at most 30 watches, 10 paths each); registry paths are `{key, depth 0..4, values?}`, file paths `{path, depth 0..3?, match?}` with `*` and `?` as the only pattern characters. **Every key and path is judged by `ChangeWatchPolicyInterface`** when it is saved, when the signed configuration for a device is built, and for every record a device reports; and by the agent's own copy of the same rules before it reads anything.

What the default policy (`Change\ChangeWatchPolicy`) refuses, in any spelling:

* **Registry:** anything but `HKLM` and `HKU`; a whole hive or its first level (at least three segments); empty, `.` or `..` segments, slashes, padded segments; **`SAM`, `SECURITY`, the boot configuration (`BCD0...`), `HARDWARE`, the LSA key parts that hold the boot key (`Lsa\JD`, `Skew1`, `GBG`, `Data`) under `CurrentControlSet` and every `ControlSetNNN`**, a key named `Secrets`, `SAM` or `SECURITY` anywhere below, and under `HKU` `Credentials`, `Secrets` and the protected storage provider. `HKU\*\...` means every loaded user hive.
* **Files:** relative paths, `.` and `..`, `//`, a trailing separator, a backslash on Linux; anything outside `/etc` (strictly below it: not `/etc` itself), `/usr/lib/systemd`, `/usr/local/lib/systemd`, `/lib/systemd`, `/var/spool/cron`, `/root/.ssh` and `~/.ssh/authorized_keys` (Windows: the `drivers\etc` and `GroupPolicy` folders); **`/etc/shadow`, `gshadow` and their backups, `opasswd`, SSH host private keys, `ssl/private` and every folder named `private`, Let's Encrypt, WireGuard, OpenVPN, NetworkManager connections (Wi-Fi keys), Kerberos keytabs, `chap-secrets`, `debian.cnf`, `ipsec.secrets`, `.htpasswd`, `.netrc`, `id_*` keys, anything ending `.key .pem .p12 .pfx .jks .keytab .kdbx .gpg`, `*_history`, anything with `password` in its name**; on Windows also the `config` folder (the hive files), `ntds`, `sam`, `security`, `system`, certificates.
* **Any text:** a control character or NUL (never "cleaned and accepted"), an invisible or format character such as a zero-width space, leading or trailing white space, invalid UTF-8, more than 600 bytes.

An edition may add file roots in code (`new ChangeWatchPolicy(['/srv/app'])`) or replace the policy (`RmmModule::setChangeWatchPolicy()`, before first use) and must run the conformance kit ([section 12](#12-for-editions)). On the agent, only a local administrator can add roots (`change_extra_roots` in `config.json`); the server can never widen the agent's list, and an extra root never unlocks a credential file.

## 6. Security log presets (the event-log collector)

Named presets add ordinary `eventlog` sources to the Windows log configuration (`PUT endpoint_devices/remote/settings` with `logs_collect: {presets: [...]}`; the `logs` switch and its storage acknowledgement still apply, and so do the per-device and fleet caps, retention, the per-minute budget, redaction and the log rules). The auditing itself must be on in Windows (Advanced Audit Policy); a preset cannot create events Windows does not write. Security-log audit events have Level 0, so these sources read every level and select by event id. The eight presets become **three sources** (Security, System, and the aggregated failed logons).

| Preset | Events |
|---|---|
| `win.sec.accounts` | Security 4720 created, 4722 enabled, 4724 password reset, 4725 disabled, 4726 deleted |
| `win.sec.groups` | Security 4732/4733 local group member added/removed, 4728/4729 global, 4756/4757 universal |
| `win.sec.logon_failures` | Security 4625, **aggregated on the device**: one entry per five minutes and per account and address, only when the count reaches 5 |
| `win.sec.log_cleared` | Security 1102, System 104 |
| `win.sec.scheduled_tasks` | Security 4698 created, 4702 updated |
| `win.sec.new_service` | System 7045, Security 4697 |
| `win.sec.audit_policy` | Security 4719 |
| `win.sec.devices` | Security 6416 (needs "Audit PNP Activity") |

The aggregated source is offered only to an agent that announced `logs:presets`: an older agent would ship every failed logon of a password-spraying run one by one. The aggregation lives in memory on the device (a restart loses at most the open window), is capped at 200 groups per window and 8 windows, and sends one entry per group: `7 events in 300s: TargetUserName=bob IpAddress=10.0.0.9`. The existing log rules (`logs/rules`) alert on these entries by event id.

## 7. What is recorded, and what is not

A record: `ts` (when detected), `kind` (`registry`, `file`, `group`, `task`), `watch`, `change`, `target` (key, file path, `group:<name>`, `scheduled-tasks`), `item` (value name, member, task path), `old` and `new` (at most 512 characters; the agent's cap defaults to 256), `summary` (files: `+2 -1 lines`, `permissions or owner changed: 0644 -> 0666`), `old_hash` and `new_hash` (SHA-256; 32 hex characters stored), `actor` and `process`, and the flags `redacted` and `truncated`.

**Privacy, by design:**

* **A value whose NAME looks like a secret** (`password`, `pwd`, `secret`, `token`, `api key`, `private key`, `credential`, `connection string`, `cookie`, ...) is **never read**: the agent asks the registry for its type and size only, shows `[REDACTED]`, and detects a change with an HMAC under a key that never leaves the device, so the hash cannot be used to guess a weak password. The server applies the same rule again to what it receives.
* **A secret pattern inside a value or a file line** (`--password=...`, `Bearer ...`, keys, tokens) is masked by the agent's redactor before it is stored in the snapshot or sent, and the server redacts again. A value that held such a pattern is hashed with the keyed hash, never in the clear.
* **Files:** only small text files (at most 64 KiB, 2000 lines) keep their lines, each cut to 200 bytes and redacted BEFORE it is stored; a `passwd`/`group` password field is replaced whatever it looks like; a binary or big file keeps only a hash (a file over 1 MiB is hashed on its first MiB plus its size, so a change beyond that shows only through the size and flagged `truncated`). Symlinks are recorded as `-> target` and never followed; the file opened must be the file that was looked at.
* **Registry data:** at most 4096 bytes of a value are read; binary data is described (`REG_BINARY (4 bytes)`), never printed.
* **Credential stores are never read** (section 5), and a credential file inside a watched folder is skipped, not reported.
* **The actor (who).** On Windows the agent asks the Security log, once per poll and only when registry changes were found, for audit event 4657 (a registry value was modified) of the last 20 minutes and matches key and value. Windows writes that event only for keys with an audit entry (SACL), so on most machines the answer is **blank**, and a blank is shown as blank, never guessed. A failed lookup (no rights, no log) switches it off for an hour. On Linux nothing records the actor and the field is always blank. Files on Windows, groups and tasks have no actor lookup.
* **Retention is the only history.** Nothing is kept after the retention or the caps.

## 8. Alerts, rules, events

A **change rule** is a plain filter (watch, kind, `target_contains`, `item_contains`, kind of change) plus a count in a window; it is never a regular expression. A match reports the check `change.rule_<id>` through the existing check evaluator, so the failure debounce (passed at once: a change is a fact), the Phase 3 alerting engine, maintenance windows, escalation and the edition's alert and ticket path all apply unchanged. When the rule has not matched on that device for `resolve_after_min` minutes (or it was disabled or deleted) housekeeping reports the check `ok` and the alert closes.

`POST endpoint_devices/changes/rules/recommended` creates, once each, the rules for: **any change to the startup entries and to the Defender exclusions and policy** (`win.startup`, `win.defender`), any change to Remote Desktop settings, **a member added to the local Administrators group** (`fail`), and any change to `sshd`, sudo and the account files. A rule is just a row; edit or delete it. Rules are per client or for all clients (all-clients rules need unrestricted access).

Events (through `RmmEventsInterface`): `rmm.change.detected` (one per recorded change, **at most 20 per reported batch** so a mass change is a burst and not a webhook storm; every change is still stored; fields: kind, watch, change, target, item, old and new value, summary, actor, process) and `rmm.change.rule_matched`. 34 `rmm.*` events in all.

## 9. The timeline: who changed what

`GET endpoint_devices/{id}/changes/timeline` returns the device's recorded changes and, when the software inventory is on, its installs, removals and version changes (`source: agent|inventory`), newest first, with `actors` (a count per person Windows recorded; the empty actor is "unknown") and `days` (a count per day). `GET endpoint_devices/{id}/changes` and `GET endpoint_devices/changes` search by time, kind, watch, change, target, item, actor and text (substring, case-insensitive; `%` and `_` are text). A device search spans at most 365 days, a fleet search 30, newest first, 500 per page.

## 10. Scale: how much it stores, and what it costs

All numbers below that say "measured" were measured; the rest are arithmetic or estimates and say so.

**Row size (measured on MariaDB 11.8, `ANALYZE TABLE`, 200,000 rows):** a typical record (a Run value change, a service ImagePath, a file diff, a group member, a task) takes **470 bytes** including both indexes (351 data + 121 index). A record with every text field at its maximum (300+200+2×512+200+120+200 characters) takes **about 2,900 bytes**. The caps are shown against a **budget of 900** bytes per record (twice the typical figure), and the worst case (3,000) is shown too: `GET endpoint_devices/changes/settings` returns `bytes_per_record`, `device_cap_bytes`, `fleet_cap_bytes`, `worst_case_bytes_per_device` and `fleet_worst_case_bytes`.

**The caps** (all in the settings row, bounded):

| Limit | Default | Range | What it does |
|---|---|---|---|
| `change_retention_days` | 90 | 1 to 730 | older records are pruned in batches of 2000 (at most 100,000 per housekeeping pass) |
| `change_device_max_rows` | 5,000 | 100 to 200,000 | per device; 20 % above it is tolerated between passes, then records are refused and counted; housekeeping trims the oldest |
| `change_fleet_max_rows` | 500,000 | 1,000 to 50,000,000 | the same for the whole fleet (10 % tolerance) |
| `change_rate_per_min` | 120 | 10 to 2000 | records one device may add per minute; the agent applies the same budget (records over it wait, they are not dropped) and the server enforces it again |
| `change_interval_s` | 300 | 60 to 86400 | the polling interval the agent is told (the agent clamps it too) |
| `change_max_value_bytes` | 256 | 64 to 512 | how much of a value is kept |

**Defaults in numbers.** The fleet cap of 500,000 records is **235 MB typical, 450 MB at the budget figure, 1.45 GB at the absolute worst**; one device's cap of 5,000 records is 2.4 MB typical. Records per day a device produces is the number that decides it, and I did not measure it on a Windows machine. As an order of magnitude (an estimate, not a measurement) a workstation with the startup, services, Defender, firewall, RDP, UAC, tasks, admins and hosts presets changes **a few to a few dozen watched items a day**, with bursts when Windows updates run (service `ImagePath` values and task definitions are rewritten: **hundreds** in one poll). Table of storage at 470 bytes per record, uncapped, for the default 90 days:

| Devices | 5 records/device/day | 20 | 100 |
|---|---|---|---|
| 100 | 21 MB | 85 MB | 424 MB |
| 500 | 106 MB | 424 MB | 2.1 GB |
| 1,000 | 212 MB | 847 MB | 4.2 GB |
| 5,000 | 1.1 GB | 4.2 GB | 21 GB |

Choose the caps from your own figure: `storage = devices x records per device per day x retention days x 470 bytes`. At the default 500,000-record fleet cap, 500 devices at 20 records a day are held for 50 days, not 90 (the fleet cap trims the oldest first), and 1,000 devices at 20 a day for 25. Raise `change_fleet_max_rows` and the disk together, or shorten the retention. The fleet figure is also on the capacity report (`change_monitor` block) once the switch is on.

**Write load.** One accepted batch of 100 records costs **11 statements** (measured by the test that counts them): authentication, settings, the counter row locked, the fleet figure, two multi-row `INSERT IGNORE`, the counter update. A check-in with the offer costs at most three more statements than one without (the settings row and the capability read). 1,000 devices at 20 records a day is 0.23 records a second on average. The worst realistic case is a patch Tuesday: every device sends a few hundred records within minutes; the per-device budget (120 a minute) spreads that over several minutes per device, and under load shedding level 2 the server answers `503 unavailable` with a jittered `Retry-After` and withdraws the offer (agents keep their queue, up to 2000 records, and send later). Level 1 does not stop it (change records are less disposable than log entries).

**On the device.** Poll cost is the read of every watched item: on Linux the default presets are a few dozen small files (the snapshot file of the proof run was 13 KB); on Windows the services preset reads about two levels of `Services` (an estimate of 2,000 values) and the firewall rules a few hundred strings: **an estimate of well under a second per poll and 0.5 to 1 MB of snapshot, not measured on Windows**. Hard limits whatever the configuration says: 5,000 items per watch (a bigger watch is cut and reported `partial: narrow it`), 20,000 keys visited per registry watch, 500 entries per folder, 2,000 files per file watch, 1 MiB hashed per file, 64 KiB and 2,000 lines kept per file, 5,000 task definitions, 500 changes per poll per watch (the rest are counted in `dropped`), 2,000 records waiting to be sent. The interval floor is 60 seconds.

## 11. Security review

| Threat | Control | Where it is tested |
|---|---|---|
| **A rogue or compromised device forges records** (to fill the database, to hide a real change, to plant a record in another device's timeline) | The device id comes from the credential only. Each record must name a watch the device was told to read, of the same kind, a `target` inside that watch's paths AND accepted by the watch policy; a SAM key or a shadow file is dropped whatever the watch. Everything is cleaned, redacted and cut again. A device can only affect its own timeline, so the worst it can do is noise there, bounded by the per-minute budget, its row cap and the fleet cap. Not prevented: a device that is itself compromised can report a false "nothing changed" (the agent is the sensor). | `ChangeMonitorTest::testARogueDeviceCannotRecordWhatItWasNotToldToWatch`, the e2e proof (real token, real TLS) |
| **Altered or forged configuration** (a proxy or a hostile server makes the agent read arbitrary files) | `config.change` is Ed25519-signed by the module's key, with a domain line (a job or check signature never verifies as a configuration); the agent verifies it against the key pinned at enrolment BEFORE it reads anything, keeps the last good configuration when a new one fails, applies its own policy to every path and clamps every number. The server never offers an unsigned configuration (no usable signing key = no offer). The server cannot widen the agent's file roots. | `ConfigTest`, `ChangeMonitorTest::testTheSignedConfigIsOffered...`, `testNoUsableSigningKey...`, the shared vector `agent_change_monitor_vectors.json` (PHP signs, Go verifies) |
| **Reading credentials** | Section 5 on the server, the same rules in Go, replayed against 127 shared vectors (the agent may be stricter, never looser); every key visited during recursion is checked again; secret-named values are never read; a symlink is never followed and the opened file must be the looked-at file. | `TestThePolicyAgreesWithTheServerOnEveryVector`, `TestRecursionNeverStepsIntoACredentialKey`, `TestSymlinksAreRecorded...`, the kit with 37 mutants |
| **Secrets in stored values** | Section 7: name rule, pattern redaction before the snapshot is written, keyed hashes, server redaction; the state file and the database were searched for the planted secrets. | `TestSecretNamedValues...`, `TestSecretsInFileLines...`, e2e |
| **IDOR** | Every route goes through `ChangeActions`: role, then `clientOk`, then the ability for that client; a restricted caller's fleet search is filtered to the clients where the ability holds; rules and settings follow the log-rule pattern (an all-clients rule needs all-client access; watches need all-client access). A device or client outside the caller's clients is a 404. | `testFleetSearchAndEveryRouteAreScopedAndGrantChecked`, `testRuleManagementAndItsScope`, `testSettingsNeedTheRightGrant...` |
| **Injection** | Every query is parameterised; `LIKE` filters escape `!`, `%`, `_`; filters that must be an id or an enum are validated and ignored otherwise; rule matching is `mb_stripos`, never a regular expression; texts are control-character-free and the API returns JSON (an edition must still HTML-escape them: a registry value, a task command line or an actor name is attacker-controlled text). On the agent `net.exe`, `powershell.exe` and `wevtutil.exe` are started from fixed argument lists (the only variable parts are a group name matching `^[A-Za-z0-9][A-Za-z0-9 _.-]{0,63}$` and integers). | `testSearchBy...`, `TestNetLocalgroup...` |
| **Resource exhaustion** | Server: body 256 KiB, 100 records, 30 requests a minute, per-device budget, row caps, batched pruning, a bounded search window, ingest refused at load-shedding level 2, 20 events per batch, 50 rules, 30 custom watches, a 48 KiB configuration. Agent: the limits at the end of section 10, a glob matcher that cannot go exponential, a persisted queue with a cap, back-off with the server's `Retry-After`. | the cost, cap and retention tests |
| **A mass change used to hide a real one** | Every change in a poll is queued up to 500 per watch; the cap is reported (`partial`, `dropped`) rather than silent; the snapshot moves on, so the rest is not re-reported. A real change inside a burst beyond the cap can be missed: not prevented. | `TestMassChangeIsCappedPerPollAndCounted` |

Not covered by controls: a local administrator on the device can stop the agent, change `config.json` or delete the state file (that is a missing sensor, not a forged one). A change made and reverted between two polls is not seen. Any change made by the agent's own service account to a watched item is reported like any other.

## 12. For editions

Abilities: `rmm.change.view` (read; map it like `rmm.job.run_saved`: a record can hold configuration text) and `rmm.change.manage` (presets, custom watches, rules; like `rmm.alert.manage`). The switch and the caps need `rmm.admin`. Routes: see [openapi-device.yaml](openapi-device.yaml) (`agent_changes` is the new DEVICE endpoint). Read model for pages: `RmmReadModel::changeSearch()`, `changeTimeline()`, `changeStats()`; actions for pages: `RmmModule::changeActions()`.

**The watch policy kit.** `Contracts\ChangeWatchPolicyInterface::allows(string $kind, string $target, int $clientId): bool` is the one place a registry key or file path is judged. `RivetCore\Testing\ChangeWatchPolicyConformanceTestCase` checks any implementation (`policy()`, `allowedRegistryKey()`, `allowedFilePath()`): the configured targets are allowed; the same question gets the same answer; credential hives refused in every spelling and under every control set; a whole hive or its first level refused; unsupported hives and malformed keys refused; credential files and keys refused; relative and traversing paths and whole system folders refused; registry hive files refused; NUL and control characters refused (never cleaned); garbage refused and never thrown on; an unknown kind refused; the client id never loosens any rule. Core proves the kit with 37 deliberately broken policies in `tests/Conformance/KitMutationTest.php`; the kit found two real gaps in Core's own default policy while it was written (a trailing space and a zero-width space), fixed.

Adoption (see UPGRADING.md "Adopting change monitoring"): migration 0030, two abilities, **route the new device endpoint `agent_changes`** to `DeviceApi::handle()` like `agent_logs`, the pages (presets and caps, search, timeline, rules), an agent rollout with the new capability, and the conformance case if you replace the policy.

## 13. The Linux proof

`endpoint-agent/e2e/run_change_proof.sh`: the release-built Linux agent under a real systemd in a privileged throwaway `jrei/systemd-ubuntu:24.04` container watching its real `/etc` with the default presets and policy, against the real PHP server on a scratch database through a TLS proxy. Run on 2026-10-19 with the agent built from this tree: **55 checks, `CHANGE PROOF PASS`**. It shows: the agent announces `change:monitor` and `logs:presets`; the signed configuration is verified and run (`change_state.json`, mode 600); the first reading is a silent baseline; real changes to sshd_config, a new sudoers.d file (the `NOPASSWD:` tag stays readable), /etc/passwd (the new account line), cron.d, a systemd unit, /etc/hosts and a custom folder arrive with the right watch, change type and added lines; a secret in a watched file and two credential files in a watched folder reach neither the database nor the agent's state file; the actor is blank on Linux; the next poll is quiet; search, timeline, fleet search and stats read the records; the recommended rules open an alert through the check evaluator; with the real device token and TLS, forged records (SAM key, unknown watch, `..` target, outside the watch) are dropped, only the in-watch record is stored and its secret is redacted, another device id in the body is ignored and no token is a 401; housekeeping prunes by retention; `disable_change` withdraws the capability and wipes the state at once; the server switch off answers `503 feature_disabled`, nothing is read while off, and turning it on again within a day finds the change made in the gap. Custom watches on `/etc/shadow`, SSH host keys, root's key, `/etc/../etc/shadow`, `/etc` and a relative path, and a registry watch on SAM, are refused with 422. Not covered: any Windows path.

## 14. What is NOT done, and what is not proven

* **Windows has never run.** Not run: the registry reader (`registry_windows.go`), the registry notification (`notify_windows.go`: one goroutine per key, at most 16, `RegNotifyChangeKeyValue` with a stop event; it only makes a poll run sooner, so a failure costs a delay, never a change), `net localgroup` against a real Windows (its output is parsed without reading any wording and tested with English and German fixtures), the scheduled-task reader (the on-disk format of `System32\Tasks` is UTF-16 XML; tested with fixtures of that shape, not with real files), the audit-event actor lookup (the shape of event 4657 is from documentation, tested with a fixture), and the Windows `RegNotifyChangeKeyValue`/`wevtutil` calls. Treat the first Windows roll-out as a test: one machine, `win.startup` and `win.local_admins` only, then read what it reports.
* **Per-client watches and per-policy presets** (the configuration is instance-wide per platform).
* **Real-time file watching** (inotify, `ReadDirectoryChangesW`): polling only; a change made and reverted between two polls is not seen.
* **Process, network and file-content auditing** beyond configuration files; a Linux actor (auditd could supply it); macOS (Phase 7).
* **Registry values over 4096 bytes** are hashed on their first 4096 bytes and size.
* **Reading `/etc/shadow` or a change of a password** is refused by design.
