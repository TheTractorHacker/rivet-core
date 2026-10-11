# RMM remote tools and log collection (Phases 6 and 8)

Status: built in RivetCore after v1.0.0-rc.10 ([#74](https://github.com/TheTractorHacker/rivet-core/issues/74) remote tools, [#76](https://github.com/TheTractorHacker/rivet-core/issues/76) log collection); migration `0022_rmm_remote_and_logs`; not yet adopted by an edition. Everything here is **off by default** behind three sub-switches, and each switch has its own plain-language warning.

This page is for the person who runs the server. (Installing the MeshCentral agent together with the RivetIT agent, and mapping the node automatically, is described in its own section below: "Install the MeshCentral agent with the RivetIT agent".) The wire contract is [PROTOCOL.md](PROTOCOL.md) (3.2.4, 3.6 to 3.8), the routes are in [openapi-device.yaml](openapi-device.yaml), the engineering notes for editions are in [modules/rmm.md](../modules/rmm.md).

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

## Install the MeshCentral agent with the RivetIT agent

Status: 1.0.0-rc.12, migration `0023_rmm_mesh_agent`. **Optional and off by default.** Wire contract: [PROTOCOL.md](PROTOCOL.md) 3.2.5 and 6.

Remote desktop, terminal and files use your own MeshCentral server (above). This feature saves you a second install: when you issue a RivetIT installer you can tick **Also install the MeshCentral agent**, and the installer, after the RivetIT agent is in place, pulls the Mesh agent for **your** Mesh server and device group and runs it. Afterwards the device's Mesh node is mapped to it automatically, so "Remote" works without anyone pasting a node id.

What it does **not** do, on purpose:

* The RivetIT installer never contains a Mesh binary. A MeshCentral agent is specific to one Mesh server and one device group, so it comes from your server, over https, at install time.
* A failure never fails the RivetIT install. If the Mesh download or install fails (wrong group id, Mesh down, certificate mismatch, the installer errors) the RivetIT agent is installed and enrolled as usual, the installer exits 0, and the failure is recorded and shown in the device's Mesh state (`mesh_agent.error`) at the first check-in.
* Uninstalling the RivetIT agent leaves the Mesh agent alone. Add `--remove-meshagent` to remove it too.
* An existing Mesh agent on the machine is never reinstalled or touched by the installer.

### Set it up, step by step

1. **Create the device group in MeshCentral.** Sign in to your Mesh server, My Devices, **Add Device Group**, name it (for example "Acme laptops"), type **Manage using a software agent**. The devices will join this group.
2. **Get the group id (`meshid`).** Open the group, click **Add Agent**, choose an operating system and look at the download address or install command it shows: it contains `meshagents?id=4&meshid=<long hex>&installflags=0`. The long hex after `meshid=` is the id (usually 96 hex characters). MeshCentral's own `mesh//...` form (shown in the group's details and in its API) is accepted too and converted. **Treat the group id as a secret**: anyone who has it and can reach your Mesh server can download an agent that joins the group.
3. **Decide how the installer trusts your Mesh server.**
   * Your Mesh server has a certificate from a public CA (for example Let's Encrypt): leave the pin **empty**. The installer verifies it with the machine's trusted roots.
   * Your Mesh server has its own self-signed certificate (MeshCentral's default): pin it. Use **Read the certificate from the server** in the Mesh settings (it connects through the network policy and shows a SHA-384 hash; it is trust on first use, so compare it with the hash MeshCentral shows under My Server, or with `echo | openssl s_client -connect mesh.example.com:443 2>/dev/null | openssl x509 -noout -fingerprint -sha384` run from a machine you trust) and save it. The pin is the SHA-384 (96 hex) or SHA-256 (64 hex) hash of the server's certificate. The installer then accepts exactly that certificate and nothing else.
   * A pin on a certificate that is renewed every few months breaks at each renewal (the leaf hash changes). Pin long-lived self-signed certificates, not auto-renewed ones.
4. **Save the Mesh settings in RivetIT.** MeshCentral enabled, the address (`https://` only), the device group id, the pin if any. These are the existing MeshCentral settings plus `mesh_group_id`, `mesh_server_cert_pin` and `mesh_auto_map_overwrite` (below). REST: `PUT endpoint_devices/remote/mesh`.
5. **Issue an installer with the box ticked.** Add device, download installer or deployment commands, tick **Also install the MeshCentral agent**. If Mesh cannot support it (switched off, not https, no group id) RivetIT says so and creates nothing. The box is remembered for the enrollment token, so the scripted download (`agent_installer`) gives the same installer.
6. **Run it** like any RivetIT installer. On Windows, `RivetIT-Agent-Setup-....exe setup --silent` (or double-click): the RivetIT agent installs first, then `MeshAgent.exe -fullinstall` runs from the file downloaded from your Mesh server. On Linux, `install-linux.sh --fetch-installer ...` or a stamped installer; or with a plain agent `install-linux.sh --server ... --token-file ... --install-mesh-agent --mesh-url https://mesh.example.com --mesh-group-id <hex> [--mesh-cert-pin <hex>]`. `--no-mesh-agent` skips the Mesh part of a stamped installer.
7. **Check the result** on the device page: the Mesh state shows installed or the error, the node id and whether it was mapped. The audit log has `Mesh Agent Installed` / `Mesh Agent Install Failed` and `Mesh Node Mapped`. The webhook event is `rmm.mesh.agent_installed`.

### Where the node id comes from, and how it is mapped

The RivetIT agent reads the local node id after the install and then at every start and once a day (only on a machine with a Mesh agent, so nothing is run elsewhere): it asks the Mesh agent (`MeshAgent.exe -nodeid` / `meshagent -nodeid`), then looks for a `.msh` file beside it that records a `NodeID`, then (Windows) the registry; an operator can also set `mesh_node_id` in the agent's config or drop `mesh_node_id.txt` in its state directory. It normalises the result to `node//<id>` and reports it with the Mesh state. The server maps it **only while MeshCentral is enabled** and the id has the right shape:

| Situation | What happens |
|---|---|
| The device has no mapping | mapped (source `agent`), audit `Mesh Node Mapped` |
| Same node as already mapped | nothing |
| A different node the agent set earlier | replaced (the machine got a new Mesh identity), audited |
| A different node an administrator set by hand | **kept**; state `kept_manual`, audit `Mesh Node Differs` once. Turn on `mesh_auto_map_overwrite` to let the agent's node replace it (audited) |
| The node is already mapped to **another live device** | **refused**; state `conflict`, audit `Mesh Node Conflict`, event `rmm.mesh.node_conflict`. Nothing is moved, even with the overwrite switch on: check which machine really owns the node (a cloned image carries the same node id) |
| The node is mapped to a **retired or revoked** device | moved to the device that now reports it (a re-enrolled machine), audit `Mesh Node Moved` |

### Troubleshooting

| You see | Likely cause |
|---|---|
| `the Mesh server certificate does not match the pinned hash` | wrong pin, or the certificate was renewed: read it again and save the new pin |
| `x509: certificate signed by unknown authority` | no pin and a self-signed certificate: pin it |
| `the Mesh server answered HTTP 404` | wrong group id, or the agent id for that platform does not exist on your Mesh version (see below) |
| `the Mesh server sent a web page instead of an installer` | the group id is wrong or the address is a login page |
| `no Mesh agent id is known for windows/arm64` (or another platform) | add the id under the Mesh settings (`mesh_agent_ids`) |
| `the Mesh installer exited with code N` | the Mesh installer failed; the last lines of its output are in the agent's `install.log` (Windows `%ProgramData%\RivetIT\Agent\install.log`, Linux `/var/lib/rivetit-agent/install.log`) |
| installed, but no node id | the agent could not be asked; see "unverified" below, set `mesh_node_id.txt` as a stop-gap |

### What is unverified: check these against YOUR Mesh version

Everything about talking to MeshCentral was written from its documentation and source as remembered, and tested only against mocks and fixtures. Before a fleet roll-out, try one machine of each operating system and confirm:

1. **The download address.** `https://<mesh>/meshagents?id=<N>&meshid=<group hex>&installflags=0` returning the agent installer with the group settings embedded (`installflags` 0 = both interactive and background, 1 = background only, 2 = interactive only).
2. **The agent ids**, from MeshCentral's `meshAgentsArchitectureNumbers` (My Server, Mesh Agents lists them): 3 Windows x86-32 service, 4 Windows x86-64 service, 5 Linux x86-32, 6 Linux x86-64, 26 Linux ARM 64-bit (aarch64), and 36 for Windows ARM64, which is the **least certain**. A wrong id is corrected under the Mesh settings (`mesh_agent_ids`), from the server, without a new agent: it travels in the installer.
3. **The install arguments.** Windows `MeshAgent.exe -fullinstall`; Linux `./meshagent -install`. MeshCentral's own Linux script also passes `--installPath=/usr/local/mesh_services/meshagent` and may copy a `.msh` file; whether a binary downloaded with a `meshid` already embeds the group on your version, or needs a `.msh`, has to be confirmed. Correct them with `mesh_install_args`.
4. **The uninstall arguments** used by `--remove-meshagent`: Windows `-fulluninstall`, Linux `-uninstall`.
5. **The node id.** That `-nodeid` prints the id (the code accepts a bare id or `node//<id>`, alone on a line, optionally after `NodeID:`), that it is the same id MeshCentral shows for the device, and whether a registry value or the `.msh` ever holds it (the standard `.msh` holds the server and group, not the node; the registry key `HKLM\SOFTWARE\Open Source\MeshAgent2` is a guess). The default locations are `%ProgramFiles%\Mesh Agent\MeshAgent.exe` and `/usr/local/mesh_services/meshagent/meshagent`.
6. **The Windows agent code** was cross-compiled and vetted, never run on a Windows host.

What was proven: the whole flow on Linux in a container against a mock Mesh server that serves a fake `meshagent` (download, pin, run, node id report, no-fail, uninstall behaviour); the pinning, size, redirect and error handling and the parsers with unit tests; the server rules with integration tests. See `endpoint-agent/e2e/run_linux_container_e2e.sh` and `endpoint-agent/internal/mesh`.

## What is built, what is verified, what remains

Built and tested on a scratch database (unit, integration with client-scoped principals for every new route, golden replay of the old agents unchanged): the job types and registry flags, `rmm.remote.manage`, the settings and switches, file transfer end to end on the server, the Mesh terminal and files views (the URL logic against the mock Mesh server), log ingest, retention and caps, search, rules and alerts, events, audit, read-model methods and the REST routes.

The Go agent: the Linux side is built and tested, and was proven end to end with the release-built agent under a real systemd against a PHP scratch server through a TLS proxy (`endpoint-agent/e2e/run_real_agent_proof.sh`, 39 checks: a unit really restarted, a process killed only on a name match, files transferred in both directions with matching SHA-256, a symlink to /etc/shadow refused by the agent, a journald line shipped and turned into an alert). Not covered by that proof: journald paging beyond one batch of 500 entries; the **Windows code is cross-compiled and vetted only, never executed on a Windows host**: the service manager (service control API), the process manager (toolhelp and process APIs), the Windows path policy and the event-log reader (`wevtutil` with a validated query) have not run on Windows.

Remaining, honestly: the edition pages (terminal and files buttons, service and process tables, a transfer list, the log viewer and rule editor), per-policy or per-client log sources (collection is configured once for the instance), regular expressions in log rules (deliberately not offered), live tailing, a real MeshCentral server to confirm the `viewmode` values 12 and 13 (only MeshCentral's URL scheme and the mock server were used), a Windows host for the Windows agent code, and macOS (Phase 7).
