# Security review of rc.21 to rc.30: findings and what rc.31 did about them

Status of every finding of the review below (reviewed `git diff v1.0.0-rc.21..v1.0.0-rc.30`, static reading only), as of `1.0.0-rc.31`. **fixed** = changed in Core in this release with a regression test; **partly** = the Core-side part is fixed, the rest is named; **deferred** = needs a decision or a protocol change, nothing was changed; **edition** = lives in an edition (or needs one), nothing in Core. The review text follows unchanged after the table.

| # | Severity | Status | What rc.31 did | What is left |
|---|---|---|---|---|
| F1 | Medium | **fixed** (behaviour change) | A device's own claim never maps a Mesh node. New claims are `needs_approval`; a node held by another live device, or reported by two live devices, is a `conflict`; a node of a retired or revoked device moves only to a device of the same client and asset, otherwise `conflict`. The inventory `mesh_node_id` path follows the same rules. | **Edition:** show `needs_approval`, offer an Approve button (`setMeshNode`). **Decision:** the automatic mapping is gone by default; a setting to bring it back would need a column. The review's other options (ask MeshCentral's own API who owns the node; check the Mesh device group against the client) are not implemented: Core has no Mesh API client. |
| F2 | Low | **fixed** | Agent and server redaction extended (PASS/PW/_KEY names, switch values, `-p`, `Environment=`/`export` values, long hex/base64), shared vectors. | A bare `NAME=value` line (`/etc/environment`) is caught only by the name rules; free text with a secret and no marker stays visible (as documented). |
| F3 | Low | **fixed** (agent) | Keyed hash for redacted files, custom watches, files without kept lines, big files, redacted link targets. | One extra `modified` change per affected file at the first poll after the agent update. |
| F4 | Low | **fixed** (Core) | `alertText()` on the alert detail and on both webhook events; `text()` strips bidi, zero-width and C1 characters. | **Edition:** escape stored values on output. |
| F5 | Low | **fixed** | `AutoInstallActions::uploadGate()` before the body is read; test counts bytes read. | The web server's body limit is still the edition's job. |
| F6 | Low | **partly** | Client lists and client/location parameter scopes cut to the caller's clients (`software_autoinstall`, `software_params`); timeline needs `rmm.software.view` for software history. | `software_files` (the hosted installer list: names, hashes) is the shared catalog and is still shown to every `rmm.software.view` holder. **Decision** if it should be per client. `RmmReadModel::timeline()` (the edition-facing read model) still includes software history; the edition authorizes that call. |
| F7 | Low | **partly** | (b) The installer hash is computed once per file version. | (a) The 24 hour address token needs the agent to authenticate the download (protocol change; **deferred**). (c) **Edition:** pass the real client address as `clientIp`. |
| F8 | Low | **partly** | `install-linux.sh --installer-sha256` (and a warning when it is absent). | **Deferred:** the stamped installer is unique per token, so its hash is not knowable in advance; a real fix is a server signature (Ed25519, the update key) over the stamped installer that the script, the PowerShell snippet and the Mesh download verify. That is a new, additive piece of protocol. The Mesh download still needs a pin or a hash from the signed `mesh_install` parameters (pin is optional today). |
| F9 | Low | **fixed** | No `exec`; the work directory is removed; test in CI. | |
| F10 | Low | **fixed** (Linux) | Found Mesh agent must be root-owned, not a symlink, not group/other-writable, with the same rule for every parent directory. | Windows relies on the Program Files ACL (no check added). |
| F11 | Low | **partly** | Stored monotonic counter (migration 0038) for the change and hypervisor configs; the hypervisor manager persists the highest seq. | **Deferred:** binding a signed config to a device id or an expiry (protocol change). |
| F12 | Low | **deferred** | Nothing. | Needs a **decision**: bounding the clock correction (the clock-skew feature deliberately allows up to 24 h for devices with a wrong clock) and requiring `device_id` and a nonce in every signed job (an agent that requires it breaks against a server that does not set it; the server always does today). |
| F13 | Low | **partly** | Tags win over same-named branches; `BUILD_HOST.md` explains ref patterns, `GO_IMAGE` digest pinning and the module cache; `__pycache__` removed and ignored. | **Not done:** pinning `golang:` by digest by default, a per-job module cache or `go mod verify` in the script, pinning `softprops/action-gh-release` to a commit (needs lookups that were not possible offline). |
| F14 | Info | **fixed** (the listed places) | Absolute System32 paths in the change monitor and the hypervisor fallback; `psQuote()` handles typographic quotes and C1/U+2028/2029. | Other bare-name Windows programs outside the reviewed range remain (`powershell.exe` in `collect/checks_ext_more.go`, `wevtutil.exe` in `logs/eventlog.go`, `reg.exe` in `mesh/nodeid.go`). |
| F15 | Info | **fixed** | k3s/kubernetes/.kube credentials and `token`/`api_key`/`kubeconfig`/`admin.conf`/`*.token` files denied (PHP, Go, vectors); a symlinked `/home/<user>/.ssh` is not read. | Credential protection is still a deny list. |
| F16 | Info | **no change** | Design notes. | **Edition/decision:** second approval for the first release of a build-host upload; keep `auto_create` off where installers are widely distributed. |

**Not in this repository (from the review's scope notes):** the build host server API (token hashing, quarantine, chunk paths, rate limits) must be reviewed in each edition against `docs/rmm/BUILD_HOST_PROTOCOL.md`.

---

# Security review: RivetCore v1.0.0-rc.21 .. v1.0.0-rc.30

Reviewed: `git diff v1.0.0-rc.21..v1.0.0-rc.30` on `origin/main` (ab9ac13), 309 files. Method: static reading of every code path end to end from untrusted source to sink. Read-only: nothing was run against any host, database, or production system.

## Scope notes and limits (read first)

- **Executed:** nothing. No Go toolchain, Semgrep, Gitleaks or PHP test run was available in this environment (`go`, `semgrep`, `gitleaks` not installed). Findings are from code reading only. Where a regex or flow was reasoned about rather than run, the finding says so.
- **Not in this repository:** the server side of the build host API (token hashing, `poll/status/upload/complete`, quarantine, reassembly) lives in the editions. Only the protocol document and the client script (`build-host.sh`) are in Core, so token hashing, timing safety, rate limits and chunk path handling for build hosts could not be reviewed. Editions must be reviewed against `docs/rmm/BUILD_HOST_PROTOCOL.md`.
- **Unchanged in the range:** the patching agent (`endpoint-agent/internal/patch`), the software-deploy agent (`internal/deploy`) and the job executor (`internal/jobs/executor.go`) have no changes between rc.21 and rc.30 (only `internal/jobs/verify.go` gained `VerifyDomain`). They were read for the paths the new server code reaches but were not re-audited.
- Confidence labels: Confirmed (reproduced), High (full path read, not executed), Possible (pattern, incomplete evidence). Nothing here is "Confirmed" because nothing was executed.

## Summary

| Severity | Count |
|---|---|
| Critical | 0 |
| High | 0 |
| Medium | 1 |
| Low | 12 |
| Informational | 3 |

No finding gives an unauthenticated or low-privilege attacker code execution on devices or on the server. The signed-job, signed-config, hash-pinned install and argument-template designs held up under review (see "Checked, no finding"). The one Medium is a cross-tenant remote-session mix-up through a device-supplied Mesh node id.

---

## Findings

### F1. Medium (High confidence): a device can claim an unmapped or retired device's MeshCentral node, so a technician launching "its" session lands on another machine

- **CWE:** CWE-639 (authorization bypass through user-controlled key), CWE-345.
- **Where:** `src/Rmm/Mesh/MeshAgentService.php:150-195` (`report()`, node id taken from the device's check-in), `:197-240` (`mapNode()`, especially the branch at `:229` that moves a node away from a retired or revoked device), consumed at `src/Rmm/Mesh/MeshService.php:98-135` (`launch()` builds `?login=<token>&gotonode=<node>` from the mapped node). (Not changed in rc.21..rc.30, but in scope as "Mesh login-token URL generation"; the new rc.27 after-approval flow in `MeshAfterApproval.php` makes the report path more common.)
- **Root cause:** the node id is whatever the enrolled device says it is. `mapNode()` refuses only when the id is already mapped to another *live* device. An id that no device has reported yet (a Mesh node of a server or non-RivetIT machine), or one mapped only to a retired or revoked device, is silently re-mapped to the reporting device. The technician's MeshCentral login token (`MeshCookie::login`, account from the template) is not scoped to a node, so the session opens on whichever node the URL names.
- **Preconditions:** attacker controls any enrolled device (any client) with `mesh_enabled=1`; knows a 48-byte node id of a victim node that is not live-mapped (ids appear in MeshCentral URLs, in the "Mesh Node Conflict/Moved" audit text, and to anyone in the Mesh group); a technician later opens remote control on the attacker's device. The Mesh account must have rights to the victim node, which is normal when clients share a device group.
- **Exploit scenario:** the attacker's rogue device reports `mesh_agent: {installed:true, node_id:<id of client B's file server>}`. `mapNode()` finds no live owner and calls `setMeshNode(attackerDevice, node, 'agent')`. The attacker opens a ticket from that device; the technician clicks Remote on it and is dropped into client B's server session (and may type credentials there).
- **Fix:** do not auto-map a node id the server has not seen before from MeshCentral itself. Verify ownership out of band: ask the Mesh server (its API) which node has the device's reported hostname or agent id, or require an admin approval for the first mapping of an id; never move a node from a retired device without approval; refuse mapping when the reported node's Mesh-side name does not match the device hostname. Also add the client-scope check: only map nodes whose Mesh device group belongs to the device's client.
- **Regression test:** a device in client A reports a node id already seen in an audit row, unmapped, and one mapped to a retired device in client B; assert both end `conflict` or `needs_approval` and `launch()` still returns `unmapped`.

### F2. Low (High confidence): change-monitor redaction misses common secret names, so secrets reach the database, alerts and webhooks

- **CWE:** CWE-532, CWE-312.
- **Where:** `endpoint-agent/internal/change/redact.go:69` (`reKeySecret`), `src/Rmm/Crypto/Redactor.php:31` and `src/Rmm/Change/ChangeProtocol.php:77-97` (server re-redaction uses the same list), applied to preset-watched files `src/Rmm/Change/ChangePresets.php:87-108` (`/etc/systemd/system` depth 2, `/etc/cron.d`, `/var/spool/cron`, `/etc/profile.d`, `/etc/environment`).
- **Root cause:** the key=value rule only matches names containing `password|passwd|pwd|passphrase|secret|token|api_key|apikey|access_key|private_key|client_secret|credential`. By reading the regex (not executed): `DB_PASS=hunter2`, `SMTP_PASS=...`, `REDIS_PASS=...`, `ENCRYPTION_KEY=...`, `AUTH_KEY=...`, `LICENSE_KEY=...`, `MASTER_KEY=...` are not matched; space-separated `-p hunter2` / `--pass hunter2` are not matched; a bare `--token abc` is caught only by the server's PowerShell-style `-token` rule, not by the agent's. Lines of preset files (units, crontabs, `/etc/environment`) are kept and sent, then stored, shown to `rmm.change.view` holders, and emitted in `rmm.change.detected` webhooks and rule alerts.
- **Exploit scenario:** an admin enables the "systemd units" preset. A unit has `Environment="DB_PASS=s3cret"`. A change to that unit stores `old_value`/`new_value`/`summary` with the password in clear and sends it to every configured webhook.
- **Fix:** match `pass(?![a-z])` and `pass_?` names, `_key` suffixes (`[A-Za-z0-9_.-]*_key\b`), `auth`, `bearer`, and any `Environment=` line value whose name is not on an allow-list; also mask by value shape (long base64/hex). Prefer storing a hash for any line that the redactor altered (the agent already flags `redacted`).
- **Regression test:** a vector file of positive and negative lines run by both the Go and PHP redactors (the repo already shares vectors).

### F3. Low (High confidence): files are hashed with plain SHA-256 even when their text was redacted; hash-only custom watches hash whole files

- **CWE:** CWE-328, CWE-200.
- **Where:** `endpoint-agent/internal/change/files.go:205` (`it.Hash = f.e.hasher.Plain(data)`), versus `registry.go:255` and `tasks.go:174`, which switch to the device-keyed HMAC when a secret pattern was seen. Custom watches are "hash only" (`files.go:31`), also plain.
- **Root cause:** `old_hash` / `new_hash` of a file that holds a redacted secret are plain SHA-256 of the whole file. Anyone with database access, or an exported webhook, can brute-force a low-entropy secret offline when the rest of a standard config file is known (for example a stock `sshd_config` or crontab with one password). The deny list also misses files that are essentially a single secret value (`/etc/<app>/token`, `api_key`), whose entire content is then hashed in the clear.
- **Fix:** use the keyed hash whenever the snapshot was flagged redacted, and for every custom (hash-only) watch; keep plain hashes only for preset files known to hold no secrets.

### F4. Low (Possible, depends on the edition's rendering): attacker-influenced change text is placed unsanitised in alert and webhook text

- **CWE:** CWE-79 (second order), CWE-116.
- **Where:** `src/Rmm/Change/ChangeRuleService.php:292` and `:305-315` (`sample()`), `ChangeProtocol::text()` at `src/Rmm/Change/ChangeProtocol.php:100-105` (strips control characters only).
- **Root cause:** target, item, old and new values, and the summary of a change record come from the device and are concatenated into the alert `detail` and the `rmm.change.rule_matched` event. `<`, `>`, quotes, backticks and Unicode bidi/zero-width characters survive. The hypervisor module already solved this (`HypervisorProtocol::alertText()` strips them and `text()` strips bidi), the change module did not.
- **Exploit scenario:** a standard user writes `<img src=x onerror=...>` as a value under a watched `HKU\*\...\Run` key. The alert is created and, if an edition renders alert text as HTML or Markdown (tickets, email, chat), it executes in a technician's browser. Core itself renders nothing, so impact depends on the edition.
- **Fix:** run `sample()` and all webhook text fields through an `alertText()`-style filter (markup characters, backticks, bidi/zero-width), and escape on output in editions.

### F5. Low (High confidence): hosted-installer upload is streamed to disk before the ability check

- **CWE:** CWE-400, CWE-862 (ordering).
- **Where:** `src/Rmm/Http/AutoInstallApi.php:129-147` (`upload()` copies up to `maxUploadBytes` (default 512 MiB) to a temp file), and only then `AutoInstallActions::uploadFile()` (`src/Rmm/AutoInstall/AutoInstallActions.php:294`) checks `SOFTWARE_MANAGE`, unrestricted-client scope and the feature switch. The fleet route is reachable with `rmm.device.view` alone (`TechnicianApi.php:681`).
- **Impact:** any read-only technician or API token can make the server write 512 MiB per request to the system temp directory (several in parallel), exhausting disk or I/O, with a 403 returned only afterwards. The edition's web-server body limit may reduce this.
- **Fix:** call `uploadFile`'s gate (role, unrestricted, feature) before opening the body stream, then stream.

### F6. Low (High confidence): cross-client metadata visible to client-restricted roles

- **CWE:** CWE-200, CWE-639.
- **Where:** `AutoInstallActions::overview()` (`src/Rmm/AutoInstall/AutoInstallActions.php:86`) returns `AutoInstallService::packages()` (`AutoInstallService.php:155`), which includes every package's `auto.clients` (client and location ids of other tenants) and `PackageParams::status()` (`PackageParams.php:95`: scope and `scope_id` of every client override). `paramsOf()` (`:209`) does the same. Only `rmm.software.view` is required, with no filtering by the caller's visible clients. `files()` lists all hosted installers (names, hashes). Separately, `ChangeActions::timeline()` (`src/Rmm/Change/ChangeActions.php:100`, `ChangeService::timeline()` at `ChangeService.php:354`) returns the device's software inventory history to a caller who has `rmm.change.view` but not `rmm.software.view`.
- **Impact:** a technician limited to client A learns which other client ids exist, which have vendor parameters or auto-install targeting, and the catalog's installer hashes. No values or names of other clients, and no secrets, are returned.
- **Fix:** filter the client lists and parameter scopes by `visibleClientIds`; require `software.view` for the software part of the timeline.

### F7. Low (High confidence): hosted installer download secret is a reusable URL token with a 24 h life

- **CWE:** CWE-598, CWE-532.
- **Where:** `src/Rmm/Deploy/HostedFiles.php:33` (`GRANT_TTL_S = 86400`), `:179-204` (`grant()`), `:206-230` (`open()`), `src/Rmm/Http/HostedFileApi.php:41`.
- **Facts verified:** secret is 192 random bits, only its SHA-256 is stored, compare is `hash_equals`, the grant works only for its own file while the job is queued or running, every failure is the same 404, grants are pruned. Good.
- **Residual:** (a) the secret is in the URL path, so reverse-proxy and CDN access logs hold it, and it can be replayed by anyone who sees a log line for up to 24 h while the job is still queued or running (a queued job can wait that long); (b) the file's SHA-256 is recomputed over up to 512 MiB on every request (`:225`), so a valid URL holder can cost the server 120 hashes of a 512 MiB file per minute; (c) the rate limit key is `$req->clientIp`; behind a proxy that does not pass the real client ip all devices share one 120/min budget, and one bad actor can lock out hosted downloads.
- **Fix:** authenticate with the device bearer token (the agent already sends it to its own server) bound to the job, or set the TTL to the job timeout; record file mtime/inode and verify the hash once per job, not per request; document that the edition must give `clientIp` the real address.

### F8. Low (High confidence): root-executed downloads have no integrity check beyond TLS

- **CWE:** CWE-494.
- **Where:** `endpoint-agent/scripts/install-linux.sh:129-140` (`--fetch-installer` posts the enrollment token and runs the returned file; `:157` runs `"$BINARY" version` before anything else; `:181` execs it), the equivalent PowerShell snippet (`src/Rmm/Installer/InstallerService.php:280-302`, no hash or signature before `Start-Process`), and the Mesh agent download for both OSes (`endpoint-agent/internal/mesh/install.go:116`, `install_linuxflow.go:93-155`: ELF/MZ magic only, then run as root/SYSTEM). The certificate pin for Mesh (`PinMatches`) is optional.
- **Impact:** if TLS trust to the server or the Mesh host is subverted (corporate inspection proxy, rogue CA, compromised Mesh host without a pin), the endpoint executes the attacker's file as root. The `--url` path of the same script does enforce `--sha256`, and the in-agent updater enforces sha256 plus an Ed25519 signature, so the weakest paths are the ones above.
- **Fix:** have the server return the stamped installer's SHA-256 and an Ed25519 signature (same key the agent pins for updates) in a header or a second file and verify before executing; for Mesh, require a pin or a hash from the signed `mesh_install` job parameters.

### F9. Low (High confidence): `install-linux.sh` leaves a token-bearing stamped installer in `/tmp`

- **CWE:** CWE-459.
- **Where:** `endpoint-agent/scripts/install-linux.sh:85` (`trap 'rm -rf "$WORK"' EXIT`), `:169` and `:181` (`exec "$BINARY" ...`). `exec` replaces the shell, so the EXIT trap never runs. With `--fetch-installer` the downloaded stamped installer (`$WORK/installer`, embedded one-shot enrollment token and CA) is left in `/tmp/rivetit-install.XXXXXX/` (mode 0700, root) after every install.
- **Impact:** root-only exposure on the machine, token remains valid until its TTL or use count ends. Low.
- **Fix:** do not `exec`; run the binary, capture the status, remove `$WORK`, then `exit "$status"`.

### F10. Low (High confidence): the agent, as root/SYSTEM, executes a Mesh agent found on disk without checking who owns it

- **CWE:** CWE-427, CWE-269.
- **Where:** `endpoint-agent/internal/mesh/nodeid.go:130-150` (rc.30 adds every directory `/opt/meshagent*`, `optDirRe`), `:193` (`Found()` takes the first existing path), `:212` (runs it with `-nodeid`/`--nodeid`), and `cfg.MeshAgentPath` (`internal/store/store.go`, name only has to contain `meshagent`).
- **Impact:** if any candidate directory or file is writable by a non-root user (for example `/opt/meshagent-test` created by a user, or `/usr/local/mesh` from a non-root installer), that user gets code execution as root on the next check-in; the lookup repeats every 60 s for ten minutes after start. Needs a pre-existing weak directory, so Low.
- **Fix:** before running, `Lstat` the file and each parent: root-owned, not group/other writable, not a symlink; ignore otherwise.

### F11. Low (High confidence): config anti-replay counter is fragile

- **Where:** `src/Rmm/Change/ChangeSettings.php:258` and `src/Rmm/Hypervisor/HypervisorSettings.php:56` set `seq = max(1, updated_at)` of the shared settings row. The change engine persists the highest accepted seq (`endpoint-agent/internal/change/engine.go:144,322`) and rejects lower ones as replays; the hypervisor manager keeps it only in memory (`internal/hypervisor/manager.go:87`).
- **Impact:** (a) after a server database restore or a clock step backwards, every agent that already accepted a higher `seq` rejects all new change configurations as "a replay" and keeps the old watches and the old (possibly wider or narrower) policy until the clock catches up. This is an availability and control-plane risk, not a bypass. (b) the hypervisor config has no persisted replay protection, so an older signed hypervisor config can be re-sent after an agent restart or revoke (impact is limited to polling intervals and caps). Neither config carries an expiry or a device id (they are valid for every device of the platform).
- **Fix:** use a dedicated monotonic counter that survives restore (increment on every config change, stored in its own column), persist it in the hypervisor manager, and bind the signed config to a device id or an expiry.

### F12. Low (High confidence): signed-job expiry and `issued_at` are judged on a clock the server and any proxy can steer

- **Where:** `endpoint-agent/internal/agent/agent.go:225-230` (`now()` adds `skew`), `internal/agent/clock.go:25` (`clockMaxOffset = 24h`), `observeResponse()` also accepts the HTTP `Date` header of any response; `internal/jobs/verify.go` uses `a.now` for `ErrExpired`.
- **Impact:** the server clock value and the `Date` header are not signed. Anything that can alter responses (a TLS-terminating inspection proxy or CDN, or a server whose clock is wrong) can move the agent's clock by up to 24 h and so keep an expired signed job inside its window or reject live ones. Job replay is limited by the persisted per-job-id record in the executor, and a malicious server can sign new jobs anyway, so the practical impact is small.
- **Related, pre-existing:** `verify.go:136` accepts a signed job that has no `device_id` on any device. The server always sets it today, but the agent could require it.
- **Fix:** judge expiry on the local clock plus a bounded (for example ±10 min) correction, or require `device_id` and a nonce in every signed job.

### F13. Low (High confidence): build-host and CI supply-chain hardening

- **Where:** `endpoint-agent/scripts/build-host.sh:261` (`GO_IMAGE="golang:$gov"`, a moving tag), `:276` (MSI image built on the host from `packaging/msi`, which uses apt over the network), `:263-271` (`$WORKDIR/gomod` module cache is mounted read-write into every build container and reused across refs and jobs), `:169-178` (`ref_allowed` patterns such as `v*` also match *branches* named `v...`, because resolution tries `refs/remotes/origin/$ref` before tags), `.github/workflows/endpoint-agent.yml:215` (`softprops/action-gh-release@v2`, a write-permission job, pinned by tag not commit), committed `endpoint-agent/e2e/__pycache__/*.pyc` (bytecode with a local worktree path).
- **Verified good:** the script refuses a config file readable by others, writes the token to a private curl config (never argv), masks the token in the log tail, validates job id, version, targets and ref against strict patterns, builds in a no-capability read-only unprivileged container, `--network none` for MSI and version run, checks PE/ELF headers, size caps and version strings, builds the MSI twice and compares, locks against concurrent runs, and cleans up on every exit.
- **Fix:** pin images by digest, give each job a fresh module cache or verify it with `go mod verify`, document that `ALLOWED_REFS` patterns apply to branches too (prefer `refs/tags/v*` only), pin Actions to commit SHAs, delete the `.pyc` files and add `__pycache__/` to `.gitignore`.

### F14. Informational: Windows helper programs are resolved by bare name as SYSTEM

`endpoint-agent/internal/change/group.go:88` (`net.exe`), `:99` (`powershell.exe`), `actor.go:53` (`wevtutil.exe`), and the fallback `hypervisor/hyperv.go:42` (`powershell.exe` when `SystemRoot` is unset). The hypervisor collector otherwise builds `%SystemRoot%\System32\...` paths; do the same everywhere to remove PATH-hijack exposure when a system PATH directory is writable. `InstallerService::psQuote()` (`src/Rmm/Installer/InstallerService.php:259`) doubles ASCII single quotes only; PowerShell also treats U+2018, U+2019, U+201A and U+201B as quotes. Today every quoted value (server URL, generated token) is admin-controlled, so no exploit, but a hardening gap.

### F15. Informational: Linux file-watch limits

`endpoint-agent/internal/change/files.go:68-100` uses `Lstat` and `O_NOFOLLOW` on the final path component (good, FIFOs and swaps handled), but intermediate components are followed: `/home/<user>/.ssh/authorized_keys` (allowed by `reHomeAuthKeys`, `policy.go:151`) can pass through a user-controlled `.ssh` symlink. Credential protection is a deny list (`LinuxDenied`), so files like `/etc/rancher/k3s/k3s.yaml`, `/etc/kubernetes/*.conf`, or an app file called `token` are not denied; custom watches hash only (see F3). The server and agent both enforce the list and a compromised admin already controls what is watched, so Informational.

### F16. Informational: design-level trust to record

- A binary that an administrator (or a build host via `complete` with "publish when built") publishes is signed by the server (`Signer::signManifest`, signature over the SHA-256 text only) and pushed to every device of that OS: `rmm.binary.publish` and a build-host token are fleet-wide code-execution grants. Editions should treat the build host as part of the trusted computing base and ask for a second approval before the first release of a build-host upload.
- Server-signed jobs and configs mean a compromised server (or its signing key) controls every endpoint by design. Domain-separated signatures (`rivetit-change-config-v1`, `rivetit-hypervisor-config-v1`) correctly stop a config being accepted as a job or the reverse.
- `unmatched_policy = auto_create` links a device with no human approval; with required auto-install apps (`AutoInstallService::advance()`), a holder of a leaked installer token then receives sealed vendor parameters and hosted installers. Keep `auto_create` off where installers are widely distributed.

---

## Checked, no finding

- **Argument and command injection:** `ArgsTemplate` (placeholder only after `=` or `:`, closed value alphabet with no space/quote/brace, validated at save, build and again by the agent), `PackageParams` (sealed with SecretBox, values never returned or audited, scope checks), `DeployService::paramsFor` (job row holds only the marker; real values added when signed; `JobExtras::scrub` redacts JSON-escaped forms), the hypervisor collectors (fixed argv, absolute tool paths, node/VM/storage names validated by `reNode`, `reVMID`, `reStor`; fixed PowerShell with no interpolation; output size and time bounded), the Mesh spec (strict `argRe`, pinned https host, same-host redirects only), the Windows `net localgroup` call (group name regex), `wevtutil` queries built only from validated integers, `journalctl` names validated.
- **Path traversal / file overwrite:** hosted files and agent binaries use server-generated random names matched by strict regexes before any path is built; `X-Filename` is only a display label (sanitised); build-host upload names come from a fixed list; no archive is unpacked; `is_link` and regular-file checks on store and open.
- **SQL injection:** every new query uses placeholders; the interpolated fragments are bound-placeholder lists or `(int)`-cast limits (`ChangeService::search/timeline/stats`, `HypervisorAlerts`, `HypervisorReader`, `BinaryStore::publishRelease`, `PackageCatalog::deletePackage` table loop over a constant list).
- **Authorization:** `ChangeActions`, `HypervisorActions`, `AutoInstallActions` check the role, then the device or client scope (same 404 for out of scope), then the specific ability per client; fleet reads filter by visible clients (empty list returns empty, never "all"); rules and settings that apply fleet-wide require unrestricted access; admin-only switches go through `rmm.admin`; `MeshAfterApproval` acts as a system principal only after a human or automatic link and honours the remote-tools and Mesh-configuration gates; two-person approval is not bypassed by auto-install.
- **Device input handling:** `ChangeService::cleanChange` binds records to watches the device was told to read, re-applies the path policy, re-redacts, caps volume per minute/device/fleet and dedupes; `HypervisorService::cleanReport` and `DeviceValidator::cleanHardware` use closed vocabularies and caps; `agent_changes` is device-authenticated, rate limited and shed-aware; device id always comes from the credential.
- **Signature verification:** agent verifies config signatures against the pinned key with a domain line before acting, fails closed with no key, applies its own bounds and policy on top of a valid signature, refuses configs for another platform; update manifest requires https, allowed host, higher version, sha256 and Ed25519 signature, then ELF/PE sanity and a `selftest` whose version must equal the manifest's, which defeats cross-version and cross-OS substitution.
- **OS discriminator:** `AgentOs`, `UpdateService::hostedBinaryFor`, `openBinary` (size, SHA-256 and magic checked before streaming; wrong-platform file deactivated) and `BinaryInspector` (PE vs ELF, machine, type, footer) are consistent; an `os` query value that is not a string or not windows/linux is rejected or ignored.
- **Clock-skew handling:** offsets are bounded to 24 h, implausible server times ignored, a time-refusal retry happens once per sequence number, and the 422 only reveals the server time to an authenticated device.
- **Secrets and tokens:** hosted-grant secrets are hashed and compared in constant time; the build-host script never puts its token on a command line or in logs; stamped installers use token-over-stdin; the state file of the change monitor is 0600 and its HMAC key never leaves the device; no real credentials in the diff (only fixture strings).
- **Install script TOCTOU:** `install-linux.sh` uses a private 0700 work dir, verifies and executes a private copy, writes the token only through a root-only temp file; `--url` enforces SHA-256.
- **Reaching external hosts:** no new server-side outbound HTTP in the reviewed code (no SSRF sink); download URLs in jobs are normalised https DNS names (no IP, port or userinfo) and allow-listed.
- **Denial of service from device input:** body caps (256 KiB changes, 1 MiB check-in with a hypervisor deferral rule), per-device rate limits, list and string caps, and glob matching in linear time (`globMatch`).

## Suggested order of work

1. F1 (Medium): approval or Mesh-side verification before auto-mapping a node.
2. F2, F3, F4: harden the redaction and hashing of change records and sanitise alert text; add shared vectors.
3. F5, F6: reorder the upload gate, filter client lists.
4. F8, F9: signature on the stamped installer; fix the `exec` leftover.
5. F7, F10 to F13: hardening as listed.
6. Review the editions' build-host endpoints, `clientIp` handling for the hosted-download rate limit, and alert rendering against this report.
