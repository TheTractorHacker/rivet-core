# Hypervisor visibility: Proxmox VE and Hyper-V

Status: built in 1.0.0-rc.29 (DB migration 0036). **Never run against a real Proxmox VE or Hyper-V host.** Everything below the line "What is NOT verified" is a statement about code and fixtures, not about those products. Read that section before you turn it on for a customer.

Scope, exactly: **Proxmox VE and Hyper-V, and nothing else.** No VMware, no XCP-ng, no libvirt, no cloud. A hypervisor host used to be "just a server" (a real Proxmox host `devpve` is enrolled as a Debian Linux agent); its guests were invisible. The agent on the host now reports its guests, storage and cluster, Core stores and alerts on them, and the edition shows them.

Wire details are in [PROTOCOL.md](PROTOCOL.md) section 3.2.10; this page is the design, the privacy statement and the operating notes.

## 1. How it is switched on

Three gates, all must be open, and the default of every one is closed:

1. **The server sub-switch `hypervisor`** (`RmmSettings::FEATURES`, default off, `rmm.admin` turns it on: `PUT endpoint_devices/hypervisor/settings {"features": {"hypervisor": true}}`). While it is off nothing is offered, a `hypervisor` block in a check-in is ignored without an error, no table is read and housekeeping only cleans up rows that already exist.
2. **The agent announces the capability** `hypervisor` (Linux and Windows builds; not announced when the local switch `disable_hypervisor` is set in `config.json`). A device that did not announce it is answered byte for byte as before and can never feed the tables.
3. **A signed `config.hypervisor`** in the check-in response (feature `hypervisor` in `features`), Ed25519 over the domain line `rivetit-hypervisor-config-v1`, a line feed and the canonical JSON of the object without its signature. The agent verifies it against the key it pinned at enrolment before it runs anything, keeps the last good configuration when one does not verify, refuses a lower `seq` (a replay), and stops (and forgets its memory) the moment a response no longer carries it. The intervals are part of it:

```json
{"v": 1, "kind": "hypervisor_config", "platform": "linux", "full_interval_s": 600, "summary_interval_s": 120,
 "max_vms": 1000, "snapshot_lookups": 300, "seq": 1760000000, "version": "9f2c01aa77d3e5b1", "signature": "<base64>"}
```

The agent clamps the numbers again whatever it is told (`full_interval_s` 300 s to 24 h, `summary_interval_s` 60 s to 600 s and never above the full one, `max_vms` at most 1000, `snapshot_lookups` at most 300). The configuration is offered to every device that announced the capability, because only the agent can tell whether the machine is a hypervisor. On a machine that is not one the agent does one cheap check (see 2) every 10 minutes and sends nothing.

## 2. What the agent runs

Only fixed commands, as an argument list (no shell, no string built from the network or from a VM), with a timeout and an output cap, as the user the agent already runs as (root / SYSTEM). A node name or VM id goes into a command line only after it matched `^[A-Za-z0-9][A-Za-z0-9-]{0,62}$` or `^[1-9][0-9]{0,8}$`. Every command is read-only.

### 2.1 Proxmox VE (Linux agent)

Detection: `/usr/bin/pvesh` and `/etc/pve` exist, or `/usr/bin/pveversion` prints `pve-manager/...`.

| Command (all `pvesh get ... --output-format json` unless noted) | Used for | Timeout |
|---|---|---|
| `/version` | PVE version | 10 s |
| `/cluster/status` | cluster name, quorate, nodes and which one is local | 10 s |
| `/cluster/resources --type vm` | every guest of the cluster: id, name, node, status, cpu, maxcpu, mem, maxmem, maxdisk, uptime, template, tags | 20 s |
| `/nodes/<local>/storage` | storage: total, used, active, enabled, shared, content | 20 s |
| `/cluster/backup-info/not-backed-up` | which guests no backup job covers (older PVE: fails quietly) | 10 s |
| `/nodes/<node>/tasks --typefilter vzdump --limit 20` (up to 8 online nodes) | result of the newest vzdump task per guest | 20 s |
| `/nodes/<local>/storage/<s>/content --content backup` (up to 6 backup-capable, active storages) | the newest backup volume per guest (`ctime`) | 20 s |
| `/nodes/<node>/qemu\|lxc/<vmid>/snapshot` (at most 300 per collection, rotating, templates skipped, 5 s each) | snapshot count and the oldest `snaptime` | 5 s |
| `/usr/sbin/zpool list -H -p -o name,size,allocated,free,health` (only when zpool exists) | ZFS pool size and health | 15 s |
| `/usr/bin/ceph health --format json` (only when ceph exists) | Ceph health | 15 s |

`zpool status -x` is not run: the `health` column of `zpool list` says the same in one line. A VM's CPU is `cpu` (a fraction of its cores) times 100; a stopped guest has no CPU, memory or uptime reading (the field is left out, never 0). Disk is `maxdisk` (provisioned size).

**Per-VM backup, honestly.** A scheduled vzdump job over several guests is ONE task without a guest id, so its status cannot be attributed to a guest. The agent therefore derives the last backup from two sources: the newest backup volume of the guest (result `ok`, because the volume exists) and the newest single-guest vzdump task, if newer (result `ok`, `warning` or `failed`). A multi-guest job that failed shows up as "last backup older than N days" for the guests it did not complete, not as `failed`. Whether a guest is expected to have a backup comes from `not-backed-up` (`backup_covered`).

### 2.2 Hyper-V (Windows agent)

Detection: `sc.exe query vmms` succeeds, then one fixed PowerShell line confirms `Get-Command Get-VM`. A server without the role does one `sc.exe` call.

All PowerShell is `powershell.exe -NoProfile -NonInteractive -Command <fixed text>` from `System32\WindowsPowerShell\v1.0`. The scripts contain no double quote, no variable from outside and only `Get-` cmdlets (a unit test pins that): `Get-VM` (name, id, state, generation, version, processor count, CPU usage, memory assigned / demand / maximum or startup, uptime, heartbeat), `Get-VMSnapshot` (count, oldest creation time), `Get-VMHardDiskDrive` + `Get-VHD` (size and file size, at most 8 drives per VM, only the first 200 VMs, skipped after 100 s), `Get-VMReplication` (health per VM), and volume sizes of the drive letters that hold the VMs ([System.IO.DriveInfo]). Only when the service `ClusSvc` exists: `Get-Cluster`, `Get-ClusterNode`, `Get-ClusterGroup` (failed group count). `quorate` is **inferred** (more than half of the nodes are Up); Windows has no single property for it. A guest on another cluster node is reported by that node's own agent, not by this one.

`guest_agent` for Hyper-V is "the integration-services heartbeat is OK"; for Proxmox it is not collected (it needs the VM configuration or an agent ping per guest, see 3).

## 3. What is never read

No VM configuration (`qm config`, `/nodes/*/qemu/*/config`, `.vmcx`), so no cloud-init user-data, no `cipassword`, no SSH keys, no network configuration, no MACs. No snapshot description (it is free text a person typed, and people type passwords there). No backup notes. No guest file system, no guest agent command, no console. No Proxmox API token or ticket: the agent talks to `pvesh` as root through the local socket and never reads or stores a credential. VM names, tags and node names are the only free text; they are stripped of control characters, bidirectional overrides and zero-width marks, cut (name 100, tag 40, 8 tags, node 64) and **stored as text and escaped on output**; in alerts and events they additionally lose `<>` backtick and quotes. Error text is cut to 300 characters and comes from our own messages (an exit code, never command output).

## 4. What the agent sends

`hypervisor` object in the check-in (additive; only after the offer): `{mode: "full"|"summary", platform, version, collected_at, cluster, vms[], storage[], changes[], vm_count, truncated, error, cfg_version}`. Full list at most every `full_interval_s` (default 600 s), and in between a compact **summary** of state changes (`changes: [{id, state}]`, sent only when a state differs from the last acknowledged report, probed every `summary_interval_s` with one `pvesh get /cluster/resources` or one `Get-VM | Select`). Caps: 1,000 VMs, 200 storages, 64 nodes, 300 KiB for the block (VMs are cut from the tail and `truncated` is set); a request that would pass 900 KiB with the software list in it carries the report one check-in later.

The slow commands run in one background goroutine, never inside the check-in. A collection that fails entirely is reported as `{mode: "full", error: "..."}` (at most once per full interval; retried after 5 minutes); a partial one is reported with what was read and an `error` that says what was not. A collector error never fails, delays or changes the check-in. The unacknowledged report is kept and offered again until the server accepts it.

## 5. What Core stores

Migration **0036**, four tables, nothing altered, nothing inserted: `rmm_hypervisor_hosts` (one row per reporting device: platform, version, cluster name / quorate / nodes, last collected, last full, error, ignored), `rmm_hypervisor_vms` (device, `vm_key`, name, type, state and when it changed, node, cpus, cpu %, memory, disk, uptime, template, tags, snapshot count and oldest, last backup and its result, backup coverage, guest agent, replication, ignore flag and reason, first / last seen, `missing_since`), `rmm_hypervisor_storage`, and `rmm_hypervisor_vm_history` (a ring of state changes, `hypervisor_history_per_vm` rows per VM, default 20).

Ingest validates every field on its own (as the metrics fields are): a bad field is dropped, never clamped, the rest of the VM and of the report counts. A VM whose id, type and platform disagree, or whose id is not `qemu/<n>`, `lxc/<n>` or a GUID, is dropped alone. A report that is not an object or has an unknown mode or platform is ignored. A failure while storing is logged and never fails the check-in.

* A **full** report upserts its VMs (about 10 statements for 1,000 VMs), and marks the VMs of the device that are absent from it as **missing** (`missing_since`), but only if it carried no `error`, was not `truncated`, and the server's per-device cap (`hypervisor_max_vms`) did not cut it. Missing VMs are hidden from the lists, purged after `hypervisor_retention_days` (30) by housekeeping, and come back (missing cleared) if they reappear. Storage follows the same rule.
* A **summary** only changes the state of VMs already known. It never adds, never marks missing.
* Snapshot, backup, coverage, guest-agent and replication values the agent left out this time keep their last value (the agent looks snapshots up in rotation); everything else absent is cleared (a stopped VM has no CPU reading).
* A hard stop: a device never holds more than `hypervisor_max_vms` + 200 VM rows including the missing ones.
* "Ignore this host" stores nothing more from it. "Ignore this VM" keeps the row and silences it.

## 6. Alerts and events

Through the ordinary check pipeline, so debounce, flap detection, maintenance windows, escalation and the bridge to tickets all apply. Check keys: `hv.vmdown.<vm>`, `hv.backup.<vm>`, `hv.snapshot.<vm>`, `hv.storage.<name>`, `hv.pool.<name>`, `hv.quorum` (`<vm>` and `<name>` are made safe for the key alphabet and carry a short hash).

| Event (`rmm.hypervisor.*`) | When | Status | Debounce |
|---|---|---|---|
| `vm_down` | a VM that was running / paused / transitioning / suspended is now stopped or in error; never for a VM first seen stopped, a template or an ignored VM | warn | `failure_debounce` copies at once (the agent saw a transition) |
| `backup_failed` | last backup `failed` (fail), `warning`, or older than `hypervisor_backup_days` (2), or a VM in a backup job with no backup after that many days | fail / warn | failed: at once; stale and warning: `failure_debounce` consecutive full reports |
| `snapshot_old` | the oldest snapshot is at least `hypervisor_snapshot_days` (7) old | warn | consecutive full reports |
| `storage_low` | used % at or above `hypervisor_storage_warn_pct` (85) / `hypervisor_storage_crit_pct` (95); storages without a size (Ceph) are skipped | warn / fail | consecutive full reports |
| `pool_degraded` | a ZFS pool or Ceph is `warn` / `error`, or a Proxmox storage is enabled but not active | warn / fail | at once |
| `cluster_quorum_lost` | the reporting host says `quorate: false` | fail | at once |

Events fire when the condition **starts** (not on every report) and carry `alert_check_key`. Each class can be switched off alone (`hypervisor_alert_vm_down`, `_backup`, `_snapshot`, `_storage`, `_pool`, `_quorum` = 0): the event still fires for `vm_down`, the check is not raised. **Auto-resolve:** a report that no longer shows the condition (or shows the VM ignored, a template, missing or gone, or the class switched off) resolves the alert (recovery debounce for a plain recovery, at once for "no longer applicable"); a VM that stays stopped longer than `hypervisor_vm_down_resolve_h` (24) hours is taken as switched off on purpose and its alert resolves. **Per-VM exclusion:** `PUT endpoint_devices/hypervisor/vms/{vm_id} {"ignored": true, "reason": "..."}` (needs `rmm.hypervisor.manage`; audited); the flag survives every report.

## 7. Abilities, routes, limits

Abilities (`RmmAbility`): `rmm.hypervisor.view` (read; also needs `rmm.device.view`) and `rmm.hypervisor.manage` (ignore a VM or host, change the thresholds). The switch is `rmm.admin`. A caller limited to some clients sees only those; a device, client or VM outside them is the same 404 as a missing one.

Routes (the technician API has one resource root, `endpoint_devices`, so the fleet-wide VM search is `endpoint_devices/hypervisor/vms` and not a second root):

```
GET  endpoint_devices/hypervisor                 hosts with VM counts, a summary (q, platform, client_id, problems=1, limit, offset)
GET  endpoint_devices/hypervisor/vms             VM search (device_id, client_id, q, state, type, template, ignored, missing, snapshots=1, backup=stale|failed|none)
GET  endpoint_devices/hypervisor/vms/{vm_id}     one VM: facts, ring of state changes, open alerts
PUT  endpoint_devices/hypervisor/vms/{vm_id}     {ignored, reason?}
GET  endpoint_devices/hypervisor/vms/{vm_id}/asset-suggestions   ?mac=aa:bb:..&guest_name=   hints only
GET|PUT endpoint_devices/hypervisor/settings     the switch, thresholds, defaults, ranges
GET|PUT endpoint_devices/{id}/hypervisor         one host (cluster, storage, a page of VMs) / {ignored}
```

Limits (`limits_json`, all `hypervisor_*`): `full_interval_s` 600 (300 to 86,400), `summary_interval_s` 120 (60 to 600, not above the full one), `max_vms` 1000, `retention_days` 30, `history_per_vm` 20, `snapshot_days` 7, `backup_days` 2, `storage_warn_pct` 85 and `storage_crit_pct` 95 (warn not above crit), `vm_down_resolve_h` 24, six `alert_*` flags. Sizing is in [CAPACITY.md](CAPACITY.md) section 22.

**VM to asset linking is the edition's.** `RmmModule::hypervisorLinker()->suggest($vm, $macs, $guestName)` returns hints (asset id and name, `matched_by` mac / hostname, `confidence` high / medium / low, whether it is in the host's client, and the agent device if the asset has one). It never links and never creates an asset. Proxmox and Hyper-V collectors do not read VM network configuration, so MACs are only used if the edition has them from elsewhere; the usual match is the VM name against an asset name.

## 8. Security self-review

* **Command construction.** Fixed argv, no shell. The only dynamic parts are a node name and a VM id that passed an anchored allow-list regex first; a hostile guest list with `; rm -rf /`, `$(id)` or `../` in its node or id is dropped (`TestProxmoxHostileStrings` asserts no such text reaches a command line). Hyper-V scripts have no interpolation, no double quote and only `Get-` cmdlets (`TestHyperVScriptsAreReadOnlyAndQuoteSafe`). Absolute paths for the tools (`/usr/bin/pvesh`, `/usr/sbin/zpool`, `System32\...\powershell.exe`), so a `PATH` entry cannot substitute one.
* **Injection through names.** Names reach the database through bound parameters only; every UI must escape (they are stored as text). Alert and event text goes through `HypervisorProtocol::alertText()` which also removes `<>` backtick and quotes (`testAlertTextCannotCarryMarkup`).
* **Resource exhaustion.** Output of one command capped at 16 MiB and 20 s; whole collection 3 minutes; 1000 VMs, 200 storages, 64 nodes, 20,000 backup volumes, 300 snapshot look-ups, 8 + 6 nodes/storages scanned; report 300 KiB; server: per-device VM cap + 200, bounded ring, `INSERT ... ON DUPLICATE KEY UPDATE` in chunks of 100, `NOT IN` list at most 1,000, history deletes per VM. Load shedding level 1 drops the block, level 2 withdraws the offer.
* **Privacy.** Section 3. Nothing is encrypted at rest (no secret is stored); the data is VM names and sizes.
* **Replay / forgery.** Signed configuration with domain separation (a job, check or change signature never verifies as one, and the reverse; tested both ways in Go and PHP), `seq` refuses a replay, the platform is signed. A rogue device can only write to its own device id.
* **What a rogue agent can do.** Create up to `max_vms + 200` rows and a bounded number of alerts for its own device; raise `vm_down` / backup alerts on itself. It cannot touch another device.

## 9. What is NOT verified

Run **none** of the following against real software. Do these on `devpve` (Proxmox) and on one Hyper-V host before relying on it:

1. The `pvesh` calls: the exact JSON shape of `/cluster/resources`, `/nodes/<n>/tasks` (are single-guest vzdump task ids numeric strings?), `/nodes/<n>/storage/<s>/content` on a PBS-backed storage, `/cluster/backup-info/not-backed-up` (exists on PVE 7/8, not verified on 6), the snapshot list, and that `--typefilter` / `--limit` are the right flags on your PVE version. Fixtures are real-looking, written from the PVE API documentation, not captured.
2. That `pvesh` output is stable enough under load (a cluster with 1,000 guests: no timing was measured).
3. All Hyper-V PowerShell: the property names (`Heartbeat`, `MemoryMaximum`, `DynamicMemoryEnabled`, `CPUUsage`, `VMId` of `Get-VMReplication`), the JSON encoding of the output in Windows PowerShell 5.1 (UTF-8 BOM handling), the 32,767-character command-line limit (the script is about 3 KB), run time of `Get-VHD` on a host with hundreds of disks, CSV paths (`C:\ClusterStorage\...` are not mapped to volumes), the inferred quorum.
4. The Windows agent build on a Hyper-V host (`GOOS=windows go vet` passes; nothing was executed).
5. The edition pages (none exist yet), the alert rules in a real edition, ticket creation from `hv.*` checks.
6. The behaviour with **several nodes of one cluster enrolled**: each reports the whole cluster's guests, so a VM appears once per enrolled node (keyed per device). The edition should show the VM under the node that runs it.
7. Scale: no load test (CAPACITY.md has a model, not a measurement).

## 10. Tests

Go (`endpoint-agent/internal/hypervisor`): fixtures in `testdata/` (pvesh JSON for version, cluster, resources, storage, tasks, backup volumes, snapshots; zpool and Ceph output; Hyper-V PowerShell JSON), a fake runner that records every command, a fake platform for the manager. Cases: real-looking, empty, partial failure, malformed, huge (5,000 guests), hostile strings, command allow-list, signed configuration (vector shared with PHP), manager timing (off until configured, async, summary between full, unacknowledged re-offer, error retry, not-a-host recheck, panic contained). `internal/agent/hypervisor_test.go` runs the offer end to end with a signed configuration. PHP: `tests/Integration/Rmm/HypervisorTest.php` (17), `tests/Unit/Rmm/HypervisorVectorsTest.php`, the vector file `agent_hypervisor_vectors.json` (generated by `scripts/endpoint-vectors.php`).
