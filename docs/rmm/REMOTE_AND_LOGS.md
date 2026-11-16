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

## Security log presets (1.0.0-rc.22)

For Windows, `logs_collect.presets` names ready-made event selections (account changes 4720/4722/4724/4725/4726, group membership 4728/4729/4732/4733/4756/4757, repeated failed logons 4625, log cleared 1102 and System 104, scheduled task 4698/4702, new service System 7045 and Security 4697, audit policy 4719, new device 6416). They expand into ordinary sources (three, merged per channel), so the caps, retention, rate budget, redaction, rules and the `logs` switch above apply unchanged. Failed logons are aggregated on the device (one entry per five minutes and account or address, from five failures) and that source is offered only to an agent that announced `logs:presets`. The Windows audit policy must be on. Details and the list: [CHANGE_MONITOR.md](CHANGE_MONITOR.md) section 6.

### Compliance baseline (1.0.0-rc.54)

`SecurityLogPresets::complianceBaseline()` lists the presets recommended for compliance: every preset except `win.sec.devices`, which needs the extra audit subcategory "Audit PNP Activity". `frameworkReferences()` maps each preset to the controls it supports (HIPAA 164.312(b), PCI DSS 10.2, CIS Controls 8.2/8.5, NIST 800-171 3.3.1/3.3.2, SOC 2 CC7.2, ISO 27001 A.8.15, NIST CSF DE.CM; indicative, not a certification claim) and `auditPolicyNeeds()` names the Windows audit subcategory each one depends on. The baseline merges into at most the same three sources (Security, System, the aggregated failed logons), so it always fits `MAX_SOURCES` (4); a test pins that no event id is dropped.

**Never chosen is not chosen none.** The settings store a `presets` key only when an administrator saves presets, an empty list included. `RemoteSettings::logPresetsChosen()` reports it. `summary()` (the settings read model, also returned by the remote settings endpoint) gives each entry of `log_presets` the keys `recommended`, `selected`, `frameworks` and `audit_policy`, plus `log_presets_chosen`, `log_presets_baseline` and `log_presets_audit_note`:

| State | `enabled` (what agents are told) | `selected` (what a form shows ticked) |
|---|---|---|
| never chosen | none | the baseline (pre-ticked, not stored) |
| chosen (any list, even empty) | the stored list | the stored list |

Nothing here changes what an agent receives: `agentLogConfig()` follows the saved presets only, the `logs` switch is never turned on implicitly, and an install that saved presets (or none) keeps exactly that. Two ways to store the baseline explicitly: `PUT .../remote/settings` with `compliance_baseline_if_unset: true` (the first enable of log collection: stored only if no choice was ever saved, otherwise ignored) and `RemoteSettings::applyComplianceBaseline($userId, $userName)` / `RemoteActions::applyLogPresetBaseline()` (administrators; adds the baseline to the presets already chosen, keeps every source, idempotent, one `Remote Settings Changed` audit entry, does not touch the `logs` switch).

**Windows audit policy (known limitation).** A preset reads events; it cannot make Windows write them. If the matching Advanced Audit Policy subcategory is off on a device, that preset collects nothing from it. The device protocol does not report the audit policy and is frozen, so RivetIT cannot verify it; the compliance check (`security_log_presets`, requirement type `log_presets_baseline`) judges the saved presets and the `logs` switch only, always with that caveat, and its remediation text reads "Enable preset X (needs Windows audit policy Y)". Turning the subcategories on is a Group Policy or `auditpol` job; see [GPO_TEMPLATES_PLAN.md](GPO_TEMPLATES_PLAN.md) (audit policy subcategories are in its later, optional package).

## Log rules

A rule is a plain filter: severity or worse, a source substring, an optional event id, a phrase, a count within a window, and a severity for the alert. **It is never a regular expression**, so a careless pattern cannot cost the server more than a substring search. A match reports the check `log.rule_<id>` through the existing check evaluator: debounce (a log match is reported enough times to pass it at once), the Phase 3 alerting engine (maintenance windows, dependency and storm control, escalation) and the edition's alert and ticket path all apply unchanged. When the rule has not matched on that device for `resolve_after_min` minutes (or the rule was disabled or deleted), housekeeping reports the check `ok` and the alert closes.

## The Remote button: what the launch URL looks like (1.0.0-rc.17)

The Remote button (desktop), and the Terminal and Files buttons, mint a MeshCentral login token at the click and open

```
https://<mesh>[/<domain>]/?login=<token>&gotonode=<node id, 64 characters>&viewmode=<11|12|13>&hide=<mesh_hide>
```

for example `https://desktop.example.test/?login=<token>&gotonode=qq3fNZ@lJ8VQ$irRjMKB535hzB2i5mWVycToxyC@cF0WWDhnMwvhta9jvcrxaE@x&viewmode=11&hide=31` (a random id of the right shape, not a real device). The same value is in the address bar when you open the device in MeshCentral: `https://<mesh>/?viewmode=11&gotonode=<the 64 characters>`.

| Parameter | Meaning |
|---|---|
| `login` | the login token (AES-256-GCM, minted per click, never stored or logged) |
| `gotonode` | the mapped node's id in MeshCentral's URL form: 64 characters, **without** the `node//` prefix, `@` and `$` literal (see the next section) |
| `viewmode` | 11 = Desktop, 12 = Terminal, 13 = Files (10 = General, not offered) |
| `hide` | MeshCentral's interface bitmask, below. Omitted when the setting is 0, which gives exactly the URL of rc.16 and earlier |

## The node id: three spellings of one value (1.0.0-rc.18)

A MeshCentral node id is 48 bytes (the SHA-384 of the agent's certificate key). It shows up in three spellings, and **only the first one makes `gotonode=` find the device**:

| Spelling | Where you see it |
|---|---|
| 64 characters, base64 of the 48 bytes with `+` becoming `@` and `/` becoming `$`, no padding. MeshCentral's own database writes it `node/<domain>/<these 64 characters>`, `node//...` for the default domain | the address bar of the device page, `gotonode=`; MeshCentral's API |
| 96 hex characters, the same 48 bytes | what some agents print and report (`meshagent -nodeid`) |
| a whole MeshCentral address | what you copy from the browser |

Before rc.18 a hex id was sent to MeshCentral as it was, which MeshCentral does not recognise, so it opened its home page instead of the device. `RivetCore\Rmm\Mesh\MeshNodeId` now converts every spelling: `normalize()` returns the canonical stored form `node/<domain>/<64 characters>` (`node//<64 characters>` on the default domain; a domain in the id is kept), `toUrlForm()` the 64 characters for `gotonode=`, `toHex()` the hex. Input is strict: exactly 48 bytes, the alphabets above (URL-safe base64 is tolerated), no whitespace or control characters inside, at most 2048 characters. A pasted address is only read for its `gotonode` (and never fetched); an address without one is refused.

* **Everywhere an id enters** (the agent's `mesh_agent` report and inventory `mesh_node_id`, `PUT endpoint_devices/{id}/remote/node`, `DeviceRepository::setMeshNode`) it is stored canonical. Ids that only fit the pre-rc.18 rule (`node//` plus 16 to 100 characters of `A-Za-z0-9@$_-`, not 48 bytes) are still accepted and stored as they are.
* **At launch** the stored id is converted again, so a row written before rc.18 works without waiting for the migration. Anything outside `[A-Za-z0-9@$_-]` is percent-encoded; `@` and `$` stay literal, as MeshCentral prints them.
* **Conflicts** (one node claimed by two devices) compare the same node whatever the spelling, including rows still in the old hex form.
* **Migration `0027_rmm_mesh_node_ids`** rewrites `endpoint_agent_mesh_nodes.mesh_node_id` and `endpoint_agent_mesh_agents.node_id` to the canonical form. Unparseable values are left alone; if two devices now resolve to one node the manual mapping is kept and the automatic one is removed and flagged `conflict`; two manual mappings are both kept. Idempotent.
* **Finding the id in MeshCentral:** open the device and copy the address bar, or take the 64 characters after `gotonode=`. Paste either into the Mesh node field.
* **Not verified against a live MeshCentral server** with this exact URL shape; the shape is the one MeshCentral itself shows in its address bar.

**The `hide` bitmask.** Read from MeshCentral's own web page source (`views/default.handlebars`, `args.hide`, checked against the master branch in October 2026): 1 = the masthead (logo bar), 2 = the top bar, 4 = the footer, 8 = the page titles (the tab headings), 16 = the left bar and main menu (the device list), 32 = the back button. **31 = bits 1 to 16: only the device view is left.** That is the value Tactical RMM passes for "Take control", and the default here. Add 32 (63) to also hide the back button. `hide` is OR-ed with any server-wide `hide` of the MeshCentral domain.

**Settings.** Administration, RMM, MeshCentral card: *Hide MeshCentral interface elements* (`mesh_hide`, 0 to 255, default 31; 31 = show only the remote screen, 0 = full MeshCentral) and, optionally, the same for Terminal and Files (`mesh_hide_tools`; empty = same as the desktop; 0 allowed, for example to keep the file browser's surroundings). REST: `GET`/`PUT endpoint_devices/remote/mesh` carry both. Migration `0026_rmm_mesh_hide` adds the two columns; until it is applied the default 31 is used and a save says to run the migration. The launch response JSON is unchanged (`url`, `session_id`, ...); only the URL differs.

**New tab, not blocked.** The edition's JavaScript must open the tab inside the click (a browser blocks `window.open` after an asynchronous request), then navigate it once the launch answers: open `about:blank` synchronously, request the launch, set the tab's location to `url`; on failure close the blank tab and show the error; if the browser refused the tab, show an *Open remote session* link instead.

**Does MeshCentral connect by itself? No (from its source).** In `gotoStartViewPage` the page only selects the device and the view (`gotoDevice(node, viewmode)`); the `AutoConnect` buttons exist only in MeshCentral's debug mode and there is no URL parameter that connects. MeshCentral's own "open in a new tab" link uses the same `?node=...&viewmode=11&hide=16` and also lands on the Desktop tab with a **Connect** button. Expect to click **Connect** once in the new tab. Tactical RMM's "Take control" behaves the same on a plain MeshCentral.

**How to test on a real MeshCentral (UNVERIFIED so far: only a mock server and MeshCentral's source were used).** Sign in to RivetIT, open a mapped online device, click Remote. Expected: a new tab opens at once, ends at the URL above, shows the Desktop view with no MeshCentral masthead, top bar, footer, tab titles or device list, with a Connect button (click it). Then set the hide value to 0 and repeat: the full MeshCentral appears (the old behaviour). If your MeshCentral version differs in what a bit hides, change the number in the setting; nothing else depends on it.

## Install the MeshCentral agent with the RivetIT agent

Status: 1.0.0-rc.12, migration `0023_rmm_mesh_agent`; the group id in MeshCentral's own URL form and the pasted-address field came in 1.0.0-rc.15 (migration `0024_rmm_mesh_group_url`). **Optional and off by default.** Wire contract: [PROTOCOL.md](PROTOCOL.md) 3.2.5 and 6.

**Since 1.0.0-rc.27 the Mesh agent installs after the device is approved** (migration `0034`). The tick-box **Also install the MeshCentral agent (after the device is approved)** no longer puts anything of Mesh (address, device group id, pin) into the installer or the command: the server pushes the sealed `mesh_install` job once the device is linked (matched automatically, or approved by an administrator) and its agent has announced it can. The device page shows "Mesh agent: waiting for approval / queued / installing / installed / skipped: reason / failed: reason". It is skipped, with the reason, when remote tools are off or the Mesh settings are incomplete. Installers issued before rc.27 keep the old install-time behaviour described below (and still carry the group id); issue a new installer to get the new one. The manual `install-linux.sh --install-mesh-agent ...` flags still work for hand use.

Remote desktop, terminal and files use your own MeshCentral server (above). This feature saves you a second install: when you issue a RivetIT installer you can tick **Also install the MeshCentral agent**, and the installer, after the RivetIT agent is in place, pulls the Mesh agent for **your** Mesh server and device group and runs it. Afterwards the device reports its Mesh node id; since 1.0.0-rc.31 an administrator confirms it once on the device page (see "Where the node id comes from" below) before "Remote" opens a session on it.

What it does **not** do, on purpose:

* The RivetIT installer never contains a Mesh binary. A MeshCentral agent is specific to one Mesh server and one device group, so it comes from your server, over https, at install time.
* A failure never fails the RivetIT install. If the Mesh download or install fails (wrong group id, Mesh down, certificate mismatch, the installer errors) the RivetIT agent is installed and enrolled as usual, the installer exits 0, and the failure is recorded and shown in the device's Mesh state (`mesh_agent.error`) at the first check-in.
* Uninstalling the RivetIT agent leaves the Mesh agent alone. Add `--remove-meshagent` to remove it too.
* An existing Mesh agent on the machine is never reinstalled or touched by the installer.

### Set it up, step by step

1. **Create the device group in MeshCentral.** Sign in to your Mesh server, My Devices, **Add Device Group**, name it (for example "Acme laptops"), type **Manage using a software agent**. The devices will join this group.
2. **Get the group id (`meshid`).** Open the group, click **Add Agent**, choose **Windows 64-bit** (the installer) and copy the download address it shows. It has this shape (a sample: the `meshid` here is a placeholder, yours is a different 64-character string):

   ```
   https://mesh.example.com/meshagents?id=4&meshid=<<64 characters: letters, digits, @ and $>>&installflags=0
                                                     ^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^
   ```

   The highlighted part, `meshid`, is the **device group id**. It is 64 characters of base64 (48 raw bytes) in MeshCentral's URL spelling, where `@` stands for `+` and `$` for `/`. It is **not** hex. You can paste into the RivetIT **device group id** field any of these, and the same group results:

   * the **whole download address** exactly as MeshCentral shows it (RivetIT takes only the `meshid` out of it, and reads nothing from the network; if its host is not the MeshCentral address you saved, you get a warning, not an error);
   * the bare 64-character `meshid`;
   * MeshCentral's own `mesh//<64 characters>` form;
   * the 96-character hex form (older RivetIT versions only took this one).

   RivetIT stores the id as hex and also keeps the URL form you gave, and the installer sends the URL form back to your Mesh server exactly as MeshCentral printed it. **Treat the group id as semi-secret, like an invitation key**: anyone who has it and can reach your Mesh server can download an agent that joins the group. If it was exposed, recreate the device group (or its invitation) in MeshCentral to get a new id and save the new one here.
3. **Decide how the installer trusts your Mesh server.**
   * Your Mesh server has a certificate from a public CA (for example Let's Encrypt): **leave the pin empty** when the server certificate is issued by a public CA (the system roots verify it). A Let's Encrypt certificate is renewed about every 60 to 90 days and a pin of it would break at each renewal. "Read the certificate from the server" tells you: it answers *this certificate is publicly trusted: no pin needed* when the certificate verifies against the system roots.

   * Your Mesh server has a private CA or its own self-signed certificate (MeshCentral's default): pin it. Use a pin only in this case. Use **Read the certificate from the server** in the Mesh settings (it connects through the network policy and shows a SHA-384 hash; it is trust on first use, so compare it with the hash MeshCentral shows under My Server, or with `echo | openssl s_client -connect mesh.example.com:443 2>/dev/null | openssl x509 -noout -fingerprint -sha384` run from a machine you trust) and save it. The pin is the SHA-384 (96 hex) or SHA-256 (64 hex) hash of the server's certificate. The installer then accepts exactly that certificate and nothing else.
   * A pin on a certificate that is renewed every few months breaks at each renewal (the leaf hash changes). Pin long-lived self-signed certificates, not auto-renewed ones.
4. **Save the Mesh settings in RivetIT.** MeshCentral enabled, the address (`https://` only), the device group id (paste the address, see step 2), the pin if any. These are the existing MeshCentral settings plus `mesh_group_id` (answered back as the canonical hex `mesh_group_id` and the URL form `mesh_group_id_url`), `mesh_server_cert_pin` and `mesh_auto_map_overwrite` (below). REST: `PUT endpoint_devices/remote/mesh`.
5. **Issue an installer with the box ticked.** Add device, download installer or deployment commands, tick **Also install the MeshCentral agent (after the device is approved)**. If Mesh cannot support it (switched off, not https, no group id) RivetIT says so and creates nothing. The box is remembered for the enrollment token, so the scripted download (`agent_installer`) gives the same installer.
6. **Run it** like any RivetIT installer. On Windows, `RivetIT-Agent-Setup-....exe setup --silent` (or double-click): the RivetIT agent installs first, then `MeshAgent.exe -fullinstall` runs from the file downloaded from your Mesh server. On Linux, `install-linux.sh --fetch-installer ...` or a stamped installer; or with a plain agent `install-linux.sh --server ... --token-file ... --install-mesh-agent --mesh-url https://mesh.example.com --mesh-group-id '<meshid>' [--mesh-cert-pin <hex>]` (quote the `meshid`: it contains `$`). `--no-mesh-agent` skips the Mesh part of a stamped installer.
7. **Check the result** on the device page: the Mesh state shows installed or the error, the node id and whether it was mapped. The audit log has `Mesh Agent Installed` / `Mesh Agent Install Failed` and `Mesh Node Mapped`. The webhook event is `rmm.mesh.agent_installed`.

### Push the Mesh agent to a device that is already enrolled (1.0.0-rc.16)

Status: 1.0.0-rc.16, no migration. Wire contract: [PROTOCOL.md](PROTOCOL.md) 3.2.6. The Mesh options above live only in the stamped installer, so an agent that was installed without them (or that has since updated itself) never installs a Mesh agent. Two technician actions fix that:

| Action | REST | Needs |
|---|---|---|
| Install the Mesh agent on one device | `POST endpoint_devices/{id}/mesh/install` `{force?}` | `rmm.remote.manage`, the `remote_tools` switch, Mesh enabled with an https address and a device group, an agent that announced `job:mesh_install` |
| Install it on a target (tag, group, client, site, policy, `all`, or one device) | `POST endpoint_devices/mesh/install` `{target:{type,id}, force?, confirm:true}` | the same, per client; at most `bulk_run_max` devices |
| Remove it | `POST endpoint_devices/{id}/mesh/uninstall` `{confirm:true}` | `rmm.remote.manage` and the `remote_tools` switch (Mesh settings not needed) |

Why `rmm.remote.manage` and not `rmm.admin`: the same grant that starts the Mesh terminal and the service manager already lets a technician run commands as SYSTEM on the device, so installing the agent of your own Mesh server is no wider than that. The file-transfer upload keeps `rmm.admin` because it writes arbitrary bytes.

* **The caller sends nothing but `force`.** The address, the device group id, the pin and the agent-id and argument tables come from the stored Mesh settings **when the job is created**; a body with any other member is `422`. The signed job carries exactly what a stamped installer's Mesh block carries and no secret beyond it. The device group id is the one semi-secret member; it is kept out of the job row (sealed like a library script's secret parameters, put back only when the job is signed for the device, wiped when it has a result, scrubbed from reported output).
* **Same routine as at install time.** The agent downloads from your Mesh server over https (system roots, or exactly the pinned certificate), with the 64 MiB cap and a content check, runs the installer in a private directory and removes it. A wrong pin, a 404, an oversize body or an installer that fails ends as a failed job with the reason, plus the existing check-in Mesh state (`mesh_agent.error`); the RivetIT agent is never touched. Verified end to end against a mock Mesh server with a real Linux agent, both with the Go fake server (`endpoint-agent/e2e/linux_container_scenario.sh`, in CI) and with the real PHP server and a real systemd (`endpoint-agent/e2e/run_real_agent_proof.sh`, run by hand); not against a real MeshCentral, and the Windows side never ran.
* **Idempotent.** A device that already reports the Mesh agent answers `200` with `skipped: already_installed` (add `force` to run the installer again); a second click while one install is queued or running answers `200` with `skipped: already_queued`. The bulk call can be repeated safely; it counts `created`, `skipped_installed`, `skipped_queued`, `skipped_unsupported` (older agent), `skipped_denied` (no grant for that client) and `skipped_error`.
* **Approval.** The two-person approval queue only knows library script runs. A bulk install larger than `approval_bulk_threshold` (when that is set) is therefore **refused** (`409 approval_required`), never queued around the rule: install in batches by tag or client. With the default threshold of 0 there is no approval rule.
* **Result.** `GET endpoint_devices/{id}/remote/jobs/{job_id}`, the device detail (`mesh_agent`: installed, version, node id, map state, error; and `mesh_install`: `available`, `agent_supports`, `installed`, present only while the install is available), the audit log (`Remote Tool Queued`, `Mesh Agent Installed`, `Mesh Agent Install Failed`, `Mesh Agent Bulk Install`) and the existing webhook event `rmm.mesh.agent_installed`. The node id is mapped by the existing rules (above).
* **Removal** runs the Mesh agent's own uninstaller and nothing else (the RivetIT agent stays). The uninstall flag is `-fulluninstall` on both (as MeshCentral's own script runs it); not verified against a real MeshAgent.

### Where the node id comes from, and how it is mapped

The RivetIT agent reads the local node id at every start and once a day (only on a machine with a Mesh agent, so nothing is run elsewhere). **Until an id has been found it keeps looking**: every 60 seconds for the first 10 minutes after the agent starts, then every 5 minutes for the next 2 hours, then at the daily cadence; it stops as soon as an id is found and reported, and reports again if the id later changes. The loop is cut short to that schedule while a Mesh agent is installed but has no id. Each check-in loop also stats the known Mesh agent paths (nothing is executed), so an agent that appears after the RivetIT agent started (the one-line installer runs the Mesh installer a few seconds after the RivetIT agent starts) is read right away; a finished `mesh_install` job restarts the schedule. Linux locations: `/usr/local/mesh_services/meshagent/meshagent` (+ `.msh`), `/opt/meshagent/meshagent`, `/opt/meshagent*/meshagent` (real directories only), `/usr/local/mesh/meshagent`, plus an absolute path set as `mesh_agent_path` in the agent config (its file name must contain `meshagent`); nothing else is executed. `meshagent -nodeid` needs root (the agent runs as root) and may print banner text, which is ignored; `--nodeid` is tried if `-nodeid` gives no id. The check-in `mesh_agent` block carries an additive `state`: `ready`, `installed_no_node_id` ("Mesh agent installed, node id not read yet"), `not_found` or `error`; the server ignores it today and derives the same from `installed` and `node_id`. The agent asks the Mesh agent (`MeshAgent.exe -nodeid` / `meshagent -nodeid`), then looks for a `.msh` file beside it that records a `NodeID`, then (Windows) the registry; an operator can also set `mesh_node_id` in the agent's config or drop `mesh_node_id.txt` in its state directory. It normalises the result to `node//<id>` and reports it with the Mesh state. The server maps it **only while MeshCentral is enabled** and the id has the right shape:

| Situation | What happens |
|---|---|
| The device has no mapping, or reports a node other than the one it has | **not mapped**; state `needs_approval`, audit `Mesh Node Needs Approval` once. The node id is only what the device says: the server cannot ask MeshCentral who owns it, and a rogue device could otherwise claim another machine's node and receive the technician's remote session. Check the node in MeshCentral, then map it on the device page (a manual mapping); the next report confirms it (`mapped`). (Before 1.0.0-rc.31 this case was mapped automatically.) |
| Same node as already mapped | nothing (`mapped`). This is decided **before** the "another device reports it" test (1.0.0-rc.38): a device that merely reports a node some other device holds can flag itself, never the device that holds it |
| A different node an administrator set by hand | **kept**; state `kept_manual`, audit `Mesh Node Differs` once. With `mesh_auto_map_overwrite` on the report waits for approval like any other (the switch no longer lets a device's own claim replace a mapping) |
| The node is already mapped to **another live device**, or another live device reports it | **refused**; state `conflict`, audit `Mesh Node Conflict`, event `rmm.mesh.node_conflict`. Nothing is moved, whatever the settings say: check which machine really owns the node (a cloned image carries the same node id) |
| The node is mapped to a **retired or revoked** device | moved (audit `Mesh Node Moved`) only to a device of the **same client and asset** whose asset link an **administrator** made (`match_reason` `approved_by_<user>`). A link the server made by matching a serial or MAC the device reported about itself is not enough (1.0.0-rc.38): that device is `needs_approval`. Any other device is `conflict`: a retired device's node is never handed to a stranger |

The same rules apply to the older `mesh_node_id` member of a check-in inventory.

**Volume (1.0.0-rc.38).** A device that reports a new random node id at every check-in changes its stored state every time but may cause at most `MeshAgentService::EVENTS_PER_DAY` (12) audit rows and webhook events per UTC day (installed, install failed, needs approval, conflict, differs, combined). The first refusal of the day is audited once (`Mesh Events Suppressed`); the state itself is always kept. The `rmm.mesh.node_conflict` event names the other device only when it belongs to the same client (`other_device_id` is `null` otherwise; the audit row, an administrator record, keeps the id).

### Troubleshooting

| You see | Likely cause |
|---|---|
| `the Mesh server certificate does not match the pinned hash` | wrong pin, or the certificate was renewed: read it again and save the new pin |
| `x509: certificate signed by unknown authority` | no pin and a self-signed certificate: pin it |
| `the Mesh server answered HTTP 404` | wrong group id (re-copy the address from MeshCentral's Add Agent), or the agent id for that platform does not exist on your Mesh version (see below) |
| `the Mesh server sent a web page instead of an installer` | the group id is wrong or the address is a login page |
| `no Mesh agent id is known for windows/arm64` (or another platform) | add the id under the Mesh settings (`mesh_agent_ids`) |
| `the Mesh installer exited with code N` | the Mesh installer failed; the last lines of its output are in the agent's `install.log` (Windows `%ProgramData%\RivetIT\Agent\install.log`, Linux `/var/lib/rivetit-agent/install.log`) |
| installed, but no node id | the agent could not be asked; see "unverified" below, set `mesh_node_id.txt` as a stop-gap |

### What is verified, and what is unverified: check these against YOUR Mesh version

**Verified (1.0.0-rc.15), against an owner's real MeshCentral:** the download address shape `https://<mesh>/meshagents?id=4&meshid=<64-character URL form>&installflags=0`. It answered HTTP 200 with a 3.5 MB Windows x64 PE named `meshagent64-<GroupName>.exe`, over a valid public (Let's Encrypt) certificate. That confirms agent id 4 = Windows x64, the path, `installflags=0`, and that `meshid` is the 64-character URL form (48 raw bytes), not hex. Nothing was run on a Windows host and no agent was installed from it.

**Still unverified**, written from the MeshCentral documentation and source as remembered, and tested only against mocks and fixtures. Before a fleet roll-out, try one machine of each operating system and confirm:

1. **The other agent ids**, from MeshCentral's `meshAgentsArchitectureNumbers` (My Server, Mesh Agents lists them): 3 Windows x86-32 service, 5 Linux x86-32, 6 Linux x86-64, 26 Linux ARM 64-bit (aarch64), and 36 for Windows ARM64, which is the **least certain**. (4, Windows x86-64, is verified.) A wrong id is corrected under the Mesh settings (`mesh_agent_ids`), from the server, without a new agent: it travels in the installer. `installflags` 0 = both interactive and background, 1 = background only, 2 = interactive only.
2. **The install arguments.** Windows `MeshAgent.exe -fullinstall` (corrected with `mesh_install_args`). Linux: fixed, as in MeshCentral's own script (see "Linux install" below); the `linux` entry of `mesh_install_args` is ignored.
3. **The uninstall arguments** used by `--remove-meshagent`: `-fulluninstall` on both.
4. **The node id.** That `-nodeid` prints the id (the code accepts a bare id or `node//<id>`, alone on a line, optionally after `NodeID:`), that it is the same id MeshCentral shows for the device, and whether a registry value or the `.msh` ever holds it (the standard `.msh` holds the server and group, not the node; the registry key `HKLM\SOFTWARE\Open Source\MeshAgent2` is a guess). The default locations are `%ProgramFiles%\Mesh Agent\MeshAgent.exe` and `/usr/local/mesh_services/meshagent/meshagent`.
5. **The Windows agent code** was cross-compiled and vetted, never run on a Windows host.

### Linux install (rc.27, after a real failure on 2026-10-20)

The first Linux routine downloaded `meshagents?id=6&meshid=...` and ran `./meshagent -install`, treating exit 0 as success. On a Debian-based Proxmox host the log said "the Mesh agent installer finished", the installer printed "MeshCentral agent: installed." and no Mesh agent existed. The Linux install now does exactly what MeshCentral's own script (`GET <mesh>/meshagents?script=1`) does, and then **checks the result instead of trusting the exit code**:

1. Download the binary from `<mesh>/meshagents?id=<agent id>` (6 for x86-64, 26 aarch64, 5 x86-32; **no** `meshid`) and the settings from `<mesh>/meshsettings?id=<device group id>`, both over https with the same pinning/CA rules, size caps and timeouts, into a private 0700 directory.
2. Detect the init system as the script does (1 systemd, 2 upstart, 3 init.d, 0 unknown) and add or replace `StartupType=<n>` in `meshagent.msh`.
3. Run `./meshagent -fullinstall --copy-msh=1` (timeout, output capped at 4 KiB, the group id masked).
4. **Verify**: `/usr/local/mesh_services/meshagent/meshagent` and `meshagent.msh` exist; with systemd, `meshagent.service` is registered and `systemctl is-active meshagent` becomes `active` within 30 s; without systemd, an init script exists and the installed binary is running. Only then is the result "installed". The node id is read afterwards as before.

Messages (job result, check-in Mesh state, `install.log`, and the last line `install-linux.sh` prints; the RivetIT install itself still exits 0):

| Message | Meaning and fix |
|---|---|
| `download of meshsettings returned HTTP 404: check the device group id` | the device group id is wrong or the group was deleted; fix it in the Mesh settings |
| `download of the Mesh agent (id N) returned HTTP <code>` | the agent id is not served by your Mesh server (check Mesh Agents in My Server; correct `mesh_agent_ids`) |
| `installer exited 0 but meshagent is missing from /usr/local/mesh_services/meshagent` | the Mesh installer did nothing; run it by hand from `install.log`'s output, look for a missing library or an unsupported init system |
| `installer exited 0 but no meshagent service was registered` | files are there but no systemd unit (or init script) was created |
| `service installed but not active: <state>` | the unit exists but is `failed`, `inactive` or stuck `activating`; run `journalctl -u meshagent` |
| `the Mesh installer exited with code N` | the installer failed; its last output is in `install.log` |

Not tested against a real MeshCentral server: the flow and the checks are exercised against a mock server and a stub `meshagent` (`internal/mesh/install_linuxflow_test.go`, `e2e/linux_container_scenario.sh`). Whether `meshsettings` accepts the 64-character URL form of the group id (the script passes whatever it is given) is confirmed only by MeshCentral's script, not by a run.

What was proven: the whole flow on Linux in a container against a mock Mesh server that serves a fake `meshagent` (download, pin, run, node id report, no-fail, uninstall behaviour); the pinning, size, redirect and error handling and the parsers with unit tests; the server rules with integration tests. See `endpoint-agent/e2e/run_linux_container_e2e.sh` and `endpoint-agent/internal/mesh`.

## What is built, what is verified, what remains

Built and tested on a scratch database (unit, integration with client-scoped principals for every new route, golden replay of the old agents unchanged): the job types and registry flags, `rmm.remote.manage`, the settings and switches, file transfer end to end on the server, the Mesh terminal and files views (the URL logic against the mock Mesh server), log ingest, retention and caps, search, rules and alerts, events, audit, read-model methods and the REST routes.

The Go agent: the Linux side is built and tested, and was proven end to end with the release-built agent under a real systemd against a PHP scratch server through a TLS proxy (`endpoint-agent/e2e/run_real_agent_proof.sh`, 39 checks: a unit really restarted, a process killed only on a name match, files transferred in both directions with matching SHA-256, a symlink to /etc/shadow refused by the agent, a journald line shipped and turned into an alert). Not covered by that proof: journald paging beyond one batch of 500 entries; the **Windows code is cross-compiled and vetted only, never executed on a Windows host**: the service manager (service control API), the process manager (toolhelp and process APIs), the Windows path policy and the event-log reader (`wevtutil` with a validated query) have not run on Windows.

Remaining, honestly: the edition pages (terminal and files buttons, service and process tables, a transfer list, the log viewer and rule editor), per-policy or per-client log sources (collection is configured once for the instance), regular expressions in log rules (deliberately not offered), live tailing, a real MeshCentral server to confirm the `viewmode` values 12 and 13 (only MeshCentral's URL scheme and the mock server were used), a Windows host for the Windows agent code, and macOS (Phase 7).
