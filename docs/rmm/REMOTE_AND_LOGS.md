# RMM remote tools and log collection (Phases 6 and 8)

Status: built in RivetCore after v1.0.0-rc.10 ([#74](https://github.com/TheTractorHacker/rivet-core/issues/74) remote tools, [#76](https://github.com/TheTractorHacker/rivet-core/issues/76) log collection); migration `0022_rmm_remote_and_logs`; not yet adopted by an edition. Everything here is **off by default** behind three sub-switches, and each switch has its own plain-language warning.

This page is for the person who runs the server. The wire contract is [PROTOCOL.md](PROTOCOL.md) (3.2.4, 3.6 to 3.8), the routes are in [openapi-device.yaml](openapi-device.yaml), the engineering notes for editions are in [modules/rmm.md](../modules/rmm.md).

## What you get

| Area | What a technician can do | Switch | Ability |
|---|---|---|---|
| Service manager | list a device's services; start, stop, restart, enable, disable (Windows also: manual) a service | `remote_tools` | `rmm.remote.manage` |
| Process manager | list processes with CPU and memory; end a process by id and name | `remote_tools` | `rmm.remote.manage` |
| Mesh terminal and files | open MeshCentral's terminal or file browser for a device (the same login-token launch as the desktop; RivetIT does not embed MeshCentral, it opens it) | `remote_tools` | `rmm.remote.launch` and `rmm.remote.manage` |
| File download | copy one file from a device to the server, then to the technician | `filetransfer` | `rmm.remote.manage` |
| File upload | send one file to a device | `filetransfer` | `rmm.admin` |
| Log collection | read Windows event-log channels or Linux journald entries into the server, search them, raise alerts on patterns | `logs` | read: `rmm.device.view` + `rmm.job.run_saved`; rules: `rmm.alert.manage`; settings: `rmm.admin` |

Every action is checked on the server on every call (role, then the device's client, then the ability, then the switch), queued as a **signed job** the agent verifies, and written to the audit log with who, what, which device. A technician limited to some clients gets the same `404` for another client's device as for one that does not exist.

## The switches and what they warn about

Turn them on in the remote-tools settings (`GET/PUT endpoint_devices/remote/settings`, `RemoteActions::settings()`); the API refuses to enable `filetransfer` or `logs` unless the request carries `accept_filetransfer_risk` or `accept_logs_storage`, so a client cannot switch them on by accident. The texts are `RemoteSettings::FILE_TRANSFER_RISK` and `LOGS_STORAGE_WARNING`; show them verbatim.

* **`remote_tools`**: a terminal is a shell on the device and a service or process action can interrupt users. `stop`, `restart` and `disable` of a service and every process end need an explicit `confirm`, are queued as destructive jobs (offered at most once, never retried) and are audited. The agent refuses to stop its own service, a short list of critical services, its own process and its parent, system processes and kernel threads, and it re-reads a process's name at the moment of the kill, so a recycled process id cannot end the wrong program.
* **`filetransfer`**: anything in an allowed folder can leave the device, including documents that hold customer or personal data, and a file sent to a device can be a program that runs later. Nothing is allowed until an administrator lists folders (a full path at least two levels deep, per platform; never a whole drive, `/etc`, `/root` or `/home`). A never-transfer list (password databases, private keys, system credential stores, the agent's own files) wins over the allow list, on the server and again on the device. Every request, refusal, completion and fetch is audited, and an `rmm.file.transferred` event is published.
* **`logs`**: log text is stored in your database, for every device that sends it. It is the heaviest optional part of the RMM and it can contain user names, addresses and other personal data. Choose narrow sources, keep retention short and watch the figures in the settings view (`logs_worst_case_bytes_per_device`, `logs_fleet_cap_bytes`, about 600 bytes per entry) before turning it on for a large fleet.

## Limits and caps (all in the settings row, all bounded)

| Limit | Default | Range | What it does |
|---|---|---|---|
| `transfer_max_bytes` | 50 MiB | 1 KiB to 512 MiB | largest file of one transfer; the agent has the same hard cap |
| `transfer_ttl_s` | 24 hours | 10 minutes to 7 days | how long a transfer and its stored bytes live |
| `transfer_store_max_bytes` | 1 GiB | 1 MiB to 100 GiB | the transfer folder's budget; a new transfer is refused (`507 too_large`) when it would not fit |
| `logs_retention_days` | 7 | 1 to 90 | entries older than this are pruned in batches of 2000 (at most 100,000 per housekeeping pass) |
| `logs_device_max_rows` | 20,000 | 100 to 1,000,000 | per-device cap; up to 20 % above it is tolerated between passes, then entries are refused and counted; housekeeping trims the oldest |
| `logs_fleet_max_rows` | 2,000,000 | 1,000 to 200,000,000 | the same for the whole fleet (10 % tolerance) |
| `logs_rate_per_min` | 600 | 10 to 6000 | entries one device may add per minute; the agent applies the same budget and the server enforces it again |
| `logs_interval_s` | 60 | 30 to 3600 | how often an agent ships a batch (a batch is at most 200 entries and 256 KiB) |

Load shedding: log ingest is the first thing given up. At shed level 1 and above the check-in stops offering `logs:collect` and `agent_logs` answers `503 unavailable` with a jittered `Retry-After`; agents keep their bookmark and carry on later. Job reports and file transfer are not shed. See [CAPACITY.md](CAPACITY.md).

## Where the files go

File transfers are stored under the module option `transfer_dir`, or `<binary_dir>/transfers` when only `binary_dir` is set. With neither, transfers answer `409 not_configured` and nothing is stored. The folder should be private to the web user, outside the web root, and on a disk with room for `transfer_store_max_bytes`. Stored files are named `xfer_<random>.bin`; the original name only reaches a response header after it is reduced to `[A-Za-z0-9._-]`.

## Log rules

A rule is a plain filter: severity or worse, a source substring, an optional event id, a phrase, a count within a window, and a severity for the alert. **It is never a regular expression**, so a careless pattern cannot cost the server more than a substring search. A match reports the check `log.rule_<id>` through the existing check evaluator: debounce (a log match is reported enough times to pass it at once), the Phase 3 alerting engine (maintenance windows, dependency and storm control, escalation) and the edition's alert and ticket path all apply unchanged. When the rule has not matched on that device for `resolve_after_min` minutes (or the rule was disabled or deleted), housekeeping reports the check `ok` and the alert closes.

## What is built, what is verified, what remains

Built and tested on a scratch database (unit, integration with client-scoped principals for every new route, golden replay of the old agents unchanged): the job types and registry flags, `rmm.remote.manage`, the settings and switches, file transfer end to end on the server, the Mesh terminal and files views (the URL logic against the mock Mesh server), log ingest, retention and caps, search, rules and alerts, events, audit, read-model methods and the REST routes.

The Go agent: the Linux side is built and tested (including a real agent against a scratch server, see the build notes in the changelog); the **Windows code is cross-compiled and vetted only, never executed on a Windows host**: the service manager (service control API), the process manager (toolhelp and process APIs), the Windows path policy and the event-log reader (`wevtutil` with a validated query) have not run on Windows.

Remaining, honestly: the edition pages (terminal and files buttons, service and process tables, a transfer list, the log viewer and rule editor), per-policy or per-client log sources (collection is configured once for the instance), regular expressions in log rules (deliberately not offered), live tailing, a real MeshCentral server to confirm the `viewmode` values 12 and 13 (only MeshCentral's URL scheme and the mock server were used), a Windows host for the Windows agent code, and macOS (Phase 7).
