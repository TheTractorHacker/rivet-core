# RMM endpoint agent (Windows and Linux)

The endpoint agent of the RivetCore RMM module ([rivet-core issue #59](https://github.com/TheTractorHacker/rivet-core/issues/59), moved here from RivetIT, where it lived as `endpoint-agent/`;
history of the Go tree is preserved separately, see `docs/rmm/AGENT_BUILD.md`). The tree lives in this repository at `endpoint-agent/`, has its own CI
(`.github/workflows/endpoint-agent.yml`) and is released with tags `agent-v*` **of this repository**. Editions (RivetIT, RivetMSP) download the binaries from the
Core release and upload them to their server; they never build the agent. Product and identifier names (`rivetit-agent`, `RivetIT Agent`, `RIVETIT-EMBED-v1`, Go module `rivetit-agent`)
are kept on purpose: a self-updating agent replaces itself by name, so renaming them would break enrolled devices.

A single static Go binary (`rivetit-agent`, no CGO, no runtime) that runs as a Windows service or a Linux systemd service and:

- enrolls with a short-lived enrollment token and receives a unique per-device credential,
- reports inventory, health metrics and check results to the server (`/api/v1/agent_checkin`), together with its platform, architecture and capabilities,
- executes **signed** maintenance jobs (`powershell` on Windows, `shell` on Linux, `reboot`, `collect`) fetched from `/api/v1/agent_jobs`,
- updates itself from a **hash- and signature-verified** manifest, with automatic rollback,
- backs off cleanly when the server's RMM module is switched off (`503 module_disabled`),
- reads (never installs or manages) an existing MeshCentral agent's node id.

> **Read this first: verification status.** The Linux agent is verified by unit tests and an end-to-end run as root in an `ubuntu:24.04` container (details in
> `docs/rmm/AGENT_BUILD.md`), and the unit has run under a real systemd inside a privileged container; it has not yet run on a physical host. The Windows agent was built on Linux without any Windows host. The portable
> core (enrollment, transport, check-in/seq/buffering, job verification and state machine, updater, checks logic,
> redaction, CLI) is unit/integration-tested on Linux and exercised end-to-end against a fake server. The thin
> Windows layers are **cross-compiled and `go vet`-clean for windows/amd64 and windows/arm64 but have never been
> executed**: see [What is Windows-only and UNVERIFIED](#what-is-windows-only-and-unverified).
> Do not roll this out fleet-wide before a pilot on real Windows machines.

## Supported platforms

Product support target (to be confirmed by a Windows pilot, see above):

| OS | Architectures |
|---|---|
| Windows 10 21H2 and later | amd64, arm64 |
| Windows 11 | amd64, arm64 |
| Windows Server 2019 / 2022 / 2025 | amd64 (arm64 where Microsoft ships it) |

What the toolchain guarantees (verified against Go's own documentation, not guessed):

- The Go wiki *MinimumRequirements* page states: "For Go 1.21 and later: Windows 10 and higher or Windows Server 2016 and higher."
  The agent is built with Go 1.27.1, so the hard floor is Windows 10 / Server 2016. The product target above is intentionally narrower
  (Server 2016 is not claimed or tested).
- The Go 1.27 release notes (go.dev/doc/go1.27) list no Windows-specific changes or new minimum OS requirement; the only minimum-OS change
  in 1.27 is for macOS (13+), which this project does not build for.
- The produced PE files report subsystem version 10.0 (`file dist/*.exe`), consistent with the above.
- arm64 binaries target baseline ARMv8.0 (`GOARM64` default `v8.0`).

Linux (systemd), supported:

| Distribution family | Architectures | Package |
|---|---|---|
| Any systemd distribution with glibc or musl (static binary): Debian/Ubuntu, RHEL/Rocky/Alma/Fedora, SUSE, Arch | amd64, arm64 | tarball, `.deb`, `.rpm`, `install-linux.sh` |

macOS is not built (deferred). Linux specifics are in [Linux](#linux).

## Linux

**Install (root).** From the release tarball (`rivetit-agent-linux-<arch>.tar.gz` contains `rivetit-agent` and `install-linux.sh`) or with a package:

```
sudo ./install-linux.sh --server https://rmm.example.com --token-file /root/enroll.token [--ca ca.pem]      # plain agent + token
sudo ./install-linux.sh --binary ./rivetit-agent-sales                                                      # per-department stamped installer
sudo ./install-linux.sh --url https://mirror/rivetit-agent-linux-amd64 --sha256 <64 hex> --server ... --token-file ...
sudo ./install-linux.sh --fetch-installer --server https://rmm.example.com --token-file /root/enroll.token  # server must offer a Linux installer
sudo ./install-linux.sh --uninstall [--purge]
```

The script picks the CPU build, verifies the binary (SHA-256 for downloads, ELF header and CPU, and a `version` run), works on a private 0700 copy, and runs
`rivetit-agent setup --silent` (stamped installer) or `rivetit-agent install` (plain). `rivetit-agent install|setup|uninstall` can also be run directly as root
(`--no-service` installs and enrolls without creating the unit). The token is only read from `--token-file`, `RIVETIT_ENROLL_TOKEN` or the stamped payload, never from
a URL, and the script never puts it on a command line. Exit codes: the `setup` table below plus 64 (usage) and 65 (download/verify failure).

**Layout.**

| Item | Location |
|---|---|
| Binary | `/opt/rivetit-agent/rivetit-agent` (root, 0755; the previous version is kept as `.prev` after a self-update) |
| State (config, `device.token` 0600, buffers, `jobs.json`, `install.log`) | `/var/lib/rivetit-agent` (0700); override with `--state-dir` or `RIVETIT_AGENT_STATE_DIR` |
| Unit | `/etc/systemd/system/rivetit-agent.service` (written by `install`; `systemctl status rivetit-agent`, `journalctl -u rivetit-agent`) |

**The unit** runs as `User=root` on purpose (administrator-signed jobs run as root, like SYSTEM on Windows) with `Restart=always`, `RestartSec=10`, `KillMode=control-group`,
`StateDirectory=rivetit-agent` (mode 0700, default state dir only), `LimitCORE=0` and `LockPersonality=yes`. `ProtectSystem`, `NoNewPrivileges`, `PrivateTmp`, `ProtectHome` and
syscall filters are deliberately **not** set: they would silently break arbitrary maintenance jobs while giving a false impression of containment. The real protections are
the root-owned binary and unit, the 0700 state directory and the signed-job model. The exit code 75 after a self-update or rollback is simply restarted by systemd.

**Jobs.** Job type `shell`: the script is written to a 0700 file in a fresh 0700 temp directory and run as `bash <file>` (`sh` when bash is missing) in its own process group with
a minimal environment (`PATH`, `LANG`, `HOME`), `RIVETIT_JOB_PARAMS` holding the JSON params, a hard timeout (the whole group is killed), the same output cap and redaction as on Windows,
and the file removed afterwards. The script never appears in argv (visible to all users in `/proc`) and is not fed through stdin (so `apt`/`ssh` cannot swallow it). A job type this OS cannot run
(`powershell` on Linux, `shell` on Windows) is reported `failed` with output beginning `unsupported_platform:` and nothing is executed. `reboot` uses a transient systemd timer
(`systemd-run --on-active=Ns systemctl reboot`, which survives an agent restart) and falls back to `shutdown -r +N`.

**Inventory and metrics** (all from `/proc`, `/sys`, `statfs`, no helper programs): `/etc/os-release`, CPU model (`/proc/cpuinfo`, device tree on ARM boards), memory, mounted block
file systems (bind mounts of one device counted once, `\040`-escaped paths decoded), non-virtual network interfaces (loopback, docker, veth, bridges excluded from the rate counters),
uptime, logged-in users (`/var/run/utmp`), manufacturer/model/serial from `/sys/class/dmi/id` with fallbacks (board and chassis serial, device tree) and placeholders such as
"To Be Filled By O.E.M." turned into `null`, `/etc/machine-id`, pending reboot (`/var/run/reboot-required` with its package list, `needs-restarting -r` where installed).
Checks: `service` (`systemctl show`: active state and enablement), `disk`, `pending_reboot`, `script` (signed, same runner as jobs).

**Updates.** The same signed-manifest flow as Windows: download to `rivetit-agent.new`, size/SHA-256/ed25519 checks, ELF machine check, `selftest`, permission bits copied from the
installed binary, atomic `rename` swap (a running executable can be renamed on Linux), exit 75, systemd restarts the new binary, probation (health deadline / 3 start attempts) with
automatic rollback to `.prev`.

**Packages.** `make packages VERSION=X` builds `.deb` and `.rpm` for amd64 and arm64 with nfpm in Docker (`packaging/nfpm.yaml`). They install the binary only; enrolling is still
`rivetit-agent install ...`. Removal stops and disables the unit and deletes the unit file; `purge` also deletes the state directory.

**Check-in additions.** Every check-in carries `platform` (`windows|linux`), `arch` and `capabilities` (`job:shell`, `check:disk`, ...). They are additive: servers that do not know them ignore
them. Agents older than 0.1.0-beta.2 never send them.

**Server switched off.** On `503` with code `module_disabled` (or `feature_disabled`) the agent keeps its credential, the in-flight check-in and the ring buffer, does not count it as a failure,
polls no jobs and no updates, and waits the server's `Retry-After` (floor 15 min, 24 h cap; without a header 15 min doubling per consecutive answer) with +/-20 % jitter, logging the state at most once an hour.
A `403` from a server that predates the switch keeps the old handling. Check-in intervals carry +/-10 % jitter so a fleet does not synchronise.

## Architecture

```
main.go / cmd_*.go           CLI: run install uninstall enroll rotate status version selftest
internal/agent               orchestration: enroll, sample + check-in loop, jobs poll, update, dormant state
internal/api                 wire types, HTTPS client (TLS verify, SPKI pin, caps, no redirects), backoff/jitter, Retry-After
internal/store               config.json, state.json, device token (DPAPI/0600), atomic writes, cross-process lock
internal/buffer              bounded on-disk ring of unacknowledged samples
internal/collect             Platform interface; Collector (null-never-zero), checks; collector_windows.go / collector_linux.go
internal/jobs                canonical JSON + ed25519 verify, durable job state, executor, bounded exec, redaction
internal/update              version rules, download+verify, stage/swap/rollback, probation (health check)
internal/svc                 Windows service wrapper (service_windows.go) / stubs
internal/logx                size-rotated log file
e2e/fakeserver, e2e/run_e2e.sh   contract-shaped test server and the end-to-end harness
scripts/install-windows.ps1  unattended deployment script (GPO / Intune / RMM)
```

Loop (one goroutine; jobs run on separate workers):

1. Every `collect_interval_s` (default 60 s): sample metrics + run due checks, append the sample to the on-disk ring.
2. Every `next_check_in_s` (default 300 s, server-controlled, clamped 10 s - 1 h): build **one** request from the newest sample
   (+ up to 100 older unacknowledged samples as `buffered`, oldest first), allocate `seq`, persist the exact request body, send it.
   On any failure the identical bytes (same `seq`) are re-sent after backoff; the ring keeps filling (bounded) meanwhile.
3. On success: drop the acknowledged samples, apply server config (checks, intervals), poll `agent_jobs` when `jobs_pending > 0`
   (plus a safety poll every 10th check-in), consider the update manifest.

### Identity and enrollment

- `install_id` (UUIDv4) is generated once, stored in `state.json`, and can never be overwritten by later code paths.
- `machine_guid` = `HKLM\SOFTWARE\Microsoft\Cryptography\MachineGuid`; serial/manufacturer/model from one bounded
  `Get-CimInstance Win32_BIOS/Win32_ComputerSystem` call (fixed text, 25 s cap, cached); MACs from the OS interface list.
  Anything that cannot be determined is `null`; placeholder values such as "To be filled by O.E.M." are treated as unknown.
- Re-enrolling (`rotate`/`enroll`/`install --token`) sends the **same** `install_id`, replaces the device credential and keeps `seq`.
- `status` = `pending_approval` / `ambiguous`: the credential is stored, the agent checks in at a low rate (>= 300 s) and `rivetit-agent status`
  explains what an administrator must do. `linked` = normal rates.
- **Revoked** (`401` with `code: revoked`, on check-in, jobs poll or job report): job execution is halted (running jobs are killed),
  the device token is overwritten and deleted, the local ring/in-flight data are dropped, `state.json` records `dormant`, a single clear
  ERROR is logged, and the agent makes **no further network calls** (it re-reads local state once a minute so a human `enroll` revives it).
- `invalid_token`/`expired` on a device credential: logged, long backoff, credential **not** wiped (it may be a server-side glitch);
  re-enroll with a new token.

## Build

```
make vet test                       # go vet (linux + windows/amd64 + windows/arm64) and go test -race ./...
make build VERSION=1.0.0            # dist/rivetit-agent-windows-{amd64,arm64}.exe and dist/rivetit-agent-linux-{amd64,arm64}
make tarballs                       # reproducible dist/rivetit-agent-linux-<arch>.tar.gz (binary + install-linux.sh)
make dist VERSION=0.1.0             # clean dist/: Windows exes, Linux binaries, tarballs, install-linux.sh and SHA256SUMS (what CI builds)
make packages VERSION=0.1.0         # .deb/.rpm in dist/packages/ (nfpm in Docker; not part of SHA256SUMS)
make checksums                      # dist/SHA256SUMS only
make syso                           # regenerate rsrc_windows_*.syso (needs goversioninfo; see winres/gen.sh)
make fuzz                           # FuzzCanonical (job canonical JSON/signature path), FuzzDecoders (check-in/jobs/enroll decoders)
make e2e                            # self-contained end-to-end run against e2e/fakeserver (Linux, unprivileged)
./e2e/run_linux_container_e2e.sh    # Linux end-to-end as root in ubuntu:24.04 (install script, unit, update swap, offline buffering, module_disabled); needs Docker only
./e2e/run_linux_systemd_e2e.sh      # optional: the same unit under a REAL systemd (privileged container; run the previous script first)
```

No Go on the host? Everything runs in Docker: `docker run --rm -v "$PWD":/src -w /src golang:1.27 make vet test dist VERSION=...` (see `docs/rmm/AGENT_BUILD.md`).

Needs Go >= the version in `go.mod` (built and tested with 1.27.1) and network access for `golang.org/x/sys` on first build
(`go.sum` is committed). Only one third-party module is used: `golang.org/x/sys` (Windows registry/service/DPAPI/ACL APIs).
If your network does TLS inspection, point `SSL_CERT_FILE` at a bundle that includes the inspection CA for `go mod download`.

**Reproducible build notes.** `-trimpath -buildvcs=false`, `CGO_ENABLED=0`, version/commit injected with `-ldflags -X`, `-s -w` stripping,
no timestamps. Two builds with the same Go toolchain, source and `VERSION`/`COMMIT` produced byte-identical Windows executables on this machine
(checked with `sha256sum`). Pin the toolchain version in your release pipeline; a different Go version produces different bytes.

**CI.** `.github/workflows/endpoint-agent.yml` (repository root) runs vet, `go test -race`, a short fuzz, the fake-server e2e and the `make dist` builds on every push/PR touching
`endpoint-agent/`, checks a second `make dist` is byte-identical, builds the packages, runs the Linux container e2e, uploads the artifacts for 30 days, and on tags `agent-v*` attaches them to a GitHub Release of this repository. Its Authenticode step is present but disabled.

**Signing (documented, NOT performed here).** Authenticode-sign the Windows executables in your release pipeline *before* computing the
checksums/ed25519 signature, e.g.:

```
signtool sign /fd SHA256 /td SHA256 /tr http://timestamp.digicert.com /a dist\rivetit-agent-windows-amd64.exe
signtool verify /pa /v dist\rivetit-agent-windows-amd64.exe
```

`install-windows.ps1 -RequireSignature` refuses an exe without a valid Authenticode signature. **Self-update artifacts are authenticated
by the instance ed25519 key, not by Authenticode** (see Updates). Unsigned binaries trigger SmartScreen/EDR reputation friction;
code signing is strongly recommended before production.

## Install, uninstall, retirement

### Install (Windows, run as Administrator/SYSTEM)

```
set RIVETIT_ENROLL_TOKEN=<short-lived token>
rivetit-agent.exe install --server https://rivetit.example.com [--ca internal-ca.pem] [--pin-spki <hex sha256 of server SPKI>] [--department "Sales"]
```

`install` is unattended and idempotent: it saves the config (CA is copied into the protected state dir), enrolls (skipped when already
enrolled and no new token is given), copies the binary to `%ProgramFiles%\RivetIT\Agent\rivetit-agent.exe`, creates/updates the
service `RivetITAgent` ("RivetIT Agent", LocalSystem, automatic delayed start), sets recovery actions (restart after 5 s / 30 s / 60 s,
reset after 24 h, also on non-crash exits so update restarts work), and starts it.
If the machine is offline during install the one-shot enrollment token is kept (DPAPI-protected) and the service finishes enrollment,
deleting the token afterwards. A *rejected* token (invalid/expired) fails the install with exit code 3.
Prefer `RIVETIT_ENROLL_TOKEN` or `--token-file` over `--token` (command lines are visible to other processes).

`scripts/install-windows.ps1` wraps this for GPO / Intune / RMM: picks the right architecture, downloads over TLS, **verifies the SHA-256**
(required parameter) and optionally the Authenticode signature before running anything, passes the token through the environment, and
cleans up. MSI packaging is a documented follow-up; the script approach needs no WiX.

### Self-installing per-department installer

A RivetIT administrator downloads `rivetit-agent-<department>.exe` from the server. It is the same agent exe with a small payload appended by the
server at download time; double-clicking it (or running it from a GPO/Intune/RMM task) installs the agent for that department with no
arguments to type. `install --server --token` and `scripts/install-windows.ps1` keep working and share the same code path (`performInstall`).

**File format (fixed contract, `internal/embed`).**

```
stamped_exe = <original exe bytes> || payload || footer
payload     = UTF-8 JSON, at most 16384 bytes:
              {"version":1,"installer_id":"<uuid>","server_url":"https://host[/prefix]","enrollment_token":"rvte1.<selector>.<secret>",
               "department":"<name>","ca_pem":null|"<PEM text>","created_at":"RFC3339 UTC","expires_at":"RFC3339 UTC"}
footer      = 52 bytes: uint32 big-endian payload length || 32-byte raw SHA-256 of the payload || 16 ASCII bytes "RIVETIT-EMBED-v1"
```

The agent reads only the **last 52 bytes** of its own exe (`os.Executable()`), checks the magic, bounds the length (`<= 16384` and `<= file size - 52`),
verifies the SHA-256, then validates the JSON strictly: `version == 1`, `installer_id` a UUID, `server_url` `https` only (plain `http` to loopback only in
`-tags agenttest` builds), no userinfo/query/fragment, token shape `rvte1.<selector>.<secret>`, department 1-255 characters without control characters,
`ca_pem` (when present) only valid PEM certificates, `created_at`/`expires_at` RFC3339 with expiry after creation. Unknown fields are ignored. An expired
payload is a specific error. Bytes appended after the footer (for example by a tool that re-signs the file) make the file read as "not stamped". The server's
shared test vectors (`tests/fixtures/agent_installer_trailer_vectors.json`, copied to `internal/embed/testdata/`) run in `go test`; `FuzzEmbedded` fuzzes the parser.
The enrollment token is never printed or logged: `Payload.String()` redacts it, error values never contain payload text, and every log line passes through a
`rvte1.*` scrubber.

**Commands and exit codes.**

```
rivetit-agent.exe                 # no arguments: runs setup when a payload is present (double-click), prints help otherwise
rivetit-agent.exe setup [--silent] [--state-dir D] [--install-dir D] [--no-service]
```

| Exit | Meaning |
|---|---|
| 0 | installed and enrolled (or already enrolled on this server: identity kept) |
| 2 | embedded configuration missing, invalid or expired (also bad flags) |
| 3 | the server rejected the token (invalid, expired or used up); nothing is installed |
| 4 | network/transient: the agent **is installed and running**, enrollment is pending (the one-shot token is kept DPAPI-protected and the service retries); re-running is safe |
| 5 | install or service failure (or enrollment failed locally) |
| 6 | not elevated and cannot elevate |

`--silent` never shows UI, never relaunches and reports only through the exit code and the log: run it elevated (GPO startup script, Intune, RMM run as SYSTEM).
Interactive runs print short progress text and end with a MessageBox (success or failure, with the log path; the token is never shown).
`--no-service` skips service registration and the Add/Remove Programs entry (tests and e2e). The Linux default install directory is `/opt/rivetit-agent`; pass `--install-dir` to stage elsewhere (non-root test runs also need `RIVETIT_AGENT_ALLOW_NONROOT=1`).

**Elevation.** The manifest says `asInvoker`, so the service (SYSTEM), the CLI and the updater's selftest are unaffected. When `setup` (or `uninstall`)
runs without elevation on Windows it validates the payload first (a bad installer fails before any prompt), then relaunches itself with
`ShellExecuteEx` verb `runas`, waits and returns the child's exit code. The child command line is rebuilt from the *parsed* options only
(allowlist: `setup --elevated [--no-service]`; `uninstall --elevated [--purge] [--remove-meshagent]`), each argument quoted with CommandLineToArgvW rules.
`--silent` is never forwarded, `--elevated` stops a child that is still not elevated from relaunching again (exit 6), and a custom `--state-dir`/`--install-dir`
is refused with exit 6 unless the process is already elevated, so an unelevated user cannot aim an administrator-approved process at a path of their choosing.

**What setup leaves behind.** `%ProgramData%\RivetIT\Agent\` (config, `ca.pem` when embedded, device credential DPAPI-protected, `install.log`, `agent.log`),
`%ProgramFiles%\RivetIT\Agent\rivetit-agent.exe`, the `RivetITAgent` service, and an Add/Remove Programs entry
(`HKLM\Software\Microsoft\Windows\CurrentVersion\Uninstall\RivetITAgent`: DisplayName, DisplayVersion, Publisher, InstallLocation, DisplayIcon,
`UninstallString` = `"...\rivetit-agent.exe" uninstall`, NoModify, NoRepair). `uninstall` removes the entry (and elevates itself, because Add/Remove Programs starts
it unelevated). `install.log` is redacted and rotated at 1 MiB. Not-elevated runs and payload errors detected before elevation cannot write it (only the console and
MessageBox show the reason).

**Idempotence.** Running the same installer twice upgrades the binary (old one kept as `.old` until the next run) and keeps the device identity: a device that is already
enrolled on the same server skips the enrollment exchange (a used-up one-shot token would otherwise fail the re-run).

**The installed copy never contains the token.** `setup` copies only the original exe bytes (everything before the payload) to the program directory through a
temporary file, verifies the result is exactly that length and carries no footer, and refuses to install a copy that still does (or to run from an installed path
that is itself stamped). Nothing has to be overwritten because the token is never written there. **The downloaded installer is different:** a stamped exe left in
Downloads (or on a share, or attached to a ticket) still contains the enrollment token until it expires or is used up. Treat the file as a credential, use short lifetimes
and limited uses when creating the token on the server, and delete the installer after the rollout. `setup` does not delete itself.

**Signing caveat.** The server appends the payload to the exe after any Authenticode signature, so the signature covers the original bytes only; some
tools and policies (for example the Windows `EnableCertPaddingCheck` strictness) treat data after the signature as tampering. Test that on a pilot before relying on signing.

**TEST/DEV ONLY stamp tool.** `go build -tags devtools` adds `rivetit-agent stamp --in EXE --out F --server U --token T --department D [--ca PEM] [--ttl 1h]`. It is compiled
out of every `make dist` / CI release build. `e2e/run_e2e.sh` uses it to stamp the Linux binary and run `setup --silent --no-service` against the fake server
(enrollment, strip-token, idempotence, exit 2 for an unstamped exe); set `RIVETIT_E2E_STAMPED_TOKEN` to repeat that against a real scratch server.

**Windows-only and UNVERIFIED** (compiled and vetted for windows/amd64 and windows/arm64, **never run on Windows**): the `ShellExecuteEx` relaunch and exit-code
propagation (`elevate_windows.go`), the MessageBox, the Add/Remove Programs registry writes (`arp_windows.go`), the manifest/version resources (`rsrc_windows_*.syso`: the Go linker
accepted them and the strings are present in the PE, but no Windows loader has read them), UAC behaviour with a double-clicked exe, and the service/DPAPI parts described elsewhere. The
Linux-tested portion is everything else: payload parsing, the install flow, exit codes, idempotence, the strip-token copy and argument quoting.

### Uninstall and retirement ownership policy

| Thing | `uninstall` | `uninstall --purge` | `uninstall --remove-meshagent` |
|---|---|---|---|
| RivetIT agent service + binaries | removed | removed | removed |
| Local state (`%ProgramData%\RivetIT\Agent`: config, token, buffers) | **kept** | deleted | kept (unless `--purge`) |
| Separately managed MeshCentral agent | **untouched** | untouched | removed via its own uninstaller (explicit opt-in only) |
| Other RMM / AV / EDR / backup agents | never touched | never touched | never touched |

Device retirement in RivetIT is a **server-side** operation: it should revoke the device credential (the next check-in gets
`401 revoked` and the agent goes dormant), cancel pending jobs for the device, and stop monitoring. The agent does not call the server
on uninstall. Uninstalling the agent without retiring the device in RivetIT leaves a device that simply stops checking in (it will show
stale/offline).
`uninstall` stops the service, deletes it, removes `.prev/.new/.failed/.old` leftovers and the binary; because the running exe cannot delete
itself, a detached `cmd.exe` removes it a few seconds after exit.

### Coexistence

The agent does not install, configure, update or remove any other RMM, MeshCentral, antivirus/EDR or backup software, and has no
code path that does so (the only action on another product is the explicit `--remove-meshagent` opt-in). Default schedule: one HTTPS request
every 5 minutes plus a 60 s local sample; checks default to 5 minutes. Heavy collectors (WMI/CIM) run at most once per process life
for static data. Add the agent exe/service to your EDR allow-listing by publisher (after signing) rather than path.

## Files and configuration

State directory: `/var/lib/rivetit-agent` on Linux (0700); `%ProgramData%\RivetIT\Agent` on Windows (protected DACL: SYSTEM + Administrators full control, inheritance removed).
On Linux the default is `/var/lib/rivetit-agent` (override with `--state-dir DIR` or `RIVETIT_AGENT_STATE_DIR`); directory `0700`, files `0600`.

| File | Contents |
|---|---|
| `config.json` | operator config: `server_url`, `ca_file`, `pin_spki_sha256`, `department`, `mesh_node_id` (override), `max_concurrent_jobs` (default 1), `disable_jobs`, `disable_script_checks`, `update_hosts`, `buffer_max_samples` (100), `buffer_max_bytes` (1 MiB) |
| `state.json` | `install_id`, `device_id`, status, `seq`, pinned `signing_public_key`, server config (checks/intervals), last check-in/error, update failure |
| `device.token` | the device credential: `v1:dpapi:<base64>` on Windows (`CryptProtectData`, machine scope + entropy), `v1:plain:<token>` `0600` on Linux |
| `enroll.token` | one-shot enrollment token, only between an offline install and the first successful enrollment |
| `inflight.json` | the exact unacknowledged check-in (replayed byte for byte with the same `seq`) |
| `buffer.json` | bounded ring of unacknowledged samples |
| `jobs.json` | durable job table (`job_id`, attempt, state, reason, unreported terminal output) |
| `update.json` | update probation state |
| `agent.log`, `agent.log.1` | service log (5 MiB x 2, rotated); no secrets or job output are logged |

Local kill switches (set in `config.json` by an administrator of the endpoint): `disable_jobs` (never execute server jobs) and
`disable_script_checks`.

## Monitoring and checks

Metrics: `cpu_pct` (delta of GetSystemTimes), `mem_pct` (GlobalMemoryStatusEx), `disk[].used_pct` (GetDiskFreeSpaceEx, fixed drives),
`net_rx_bps`/`net_tx_bps` (IP Helper `GetIfEntry2` octet deltas, **bits** per second). A metric that cannot be collected, or whose
rate needs a previous sample or hit a counter reset, is `null` — never `0`. Every collector call has a hard timeout (15 s; a hung
collector is abandoned and further calls fail fast until it returns, so goroutines cannot pile up).

Checks come from the server config (`config.checks[]`: `key`, `type`, `params`, `interval_s`, default 300 s, min 30 s). The latest result of
every check is included in every check-in. A failing collector gives `unknown`, not a crash or a fake `ok`.

| type | params | result |
|---|---|---|
| `service` | `name`, `expected` (default `running`), optional `startup` | ok / fail (wrong state or missing service) / warn (startup mismatch) |
| `disk` | optional `mount` (all fixed disks when omitted), `warn_free_pct`, `fail_free_pct`, `warn_free_gb`, `fail_free_gb` (defaults 20% / 10% free) | worst matching mount |
| `pending_reboot` | none | warn when CBS `RebootPending`, WU `RebootRequired` or `PendingFileRenameOperations` is set |
| `script` | `script`, `timeout_s` (<= 120, default 30), `max_output_bytes` (<= 4096) | exit 0 ok, 1 warn, other fail, timeout/launch error unknown |

`agent_update` is always reported: `ok` ("agent 1.0.0") or `fail` with the last update failure.

Script checks run with the same machinery and restrictions as jobs (bounded time and output, redaction, same account) and are treated as code
execution: **a `script` check is only run when its definition carries a `signature`** (detached ed25519 over the canonical JSON of the check object
minus `signature`, the same rule as jobs; vector `check_definition` in the server fixture) that verifies with the pinned key. Unsigned or badly signed
script checks, and any other check whose signature is present but invalid, report `unknown` ("refused") and are never executed. `disable_script_checks`
in `config.json` is a local kill switch. Note that a server holding the signing key can still sign malicious script checks: the server's signing key is
the root of trust for script execution (jobs and checks alike).

## Jobs

Types: `powershell` (Windows) or `shell` (Linux) (`script`, `params`), `reboot` (`params.delay_s`, 5-3600, default 30), `collect` (returns the inventory as output).

**Verification before anything runs** (`internal/jobs/verify.go`): ed25519 signature over the canonical JSON of the job (keys sorted
lexicographically at every level, no insignificant whitespace, `signature` excluded, numbers as written, no HTML escaping; duplicate keys
and trailing data are rejected) using the `signing_public_key` pinned at enrollment; then `expires_at` (against the skew-corrected clock),
`issued_at` not in the future, `device_id` match when the job carries one, a known `type`, size limits. Timeout is clamped to 1-3600 s,
output cap to <= 1 MiB.

**At most once.** A `running` record is fsynced to `jobs.json` *before* the process is launched. A job found `running` after a restart is
reported `failed` (`agent_restarted`) and never re-run. A known `job_id` is never executed again whatever its `attempt` number; if its final
report was not acknowledged the stored result is re-sent. Terminal state is persisted before reporting; reports retry on transient failure
(5 attempts, jittered backoff) and are re-flushed after each check-in.

**Reboot** persists `succeeded`, reports it, and only then schedules `shutdown.exe /r /t <delay>`; it is never retried.

**Execution** (`powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -EncodedCommand <base64 UTF-16LE>`): the absolute
`System32\WindowsPowerShell\v1.0` path is used, the script and `params` (as a `$Params` object) travel inside the encoded command so no
server text is ever interpolated into a command line; minimal environment; stdin closed; no console window. Timeout kills the
process tree (`taskkill /T /F`; on Linux the whole process group is killed). stdout+stderr are merged and capped at `max_output_bytes` with a
truncation marker. Concurrency is `max_concurrent_jobs` (default 1 = strictly serial). Scripts longer than ~10,000 characters do not fit
`-EncodedCommand` (command line limit) and fail with `cannot_launch`.

**Redaction** (`internal/jobs/redact.go`) removes, before output leaves the machine: `Bearer`/`Basic` credentials, `password|secret|token|api key|...=value`
assignments, JWTs, GitHub/Slack/AWS/OpenAI-style tokens, private key blocks, `user:pass@` URLs, and the literal device and enrollment tokens.
It is a safety net, not a guarantee: scripts must not print secrets.

**Account and privilege.** Jobs run as the service account, `LocalSystem`. That is a **high-privilege capability**: anyone who can submit a
`powershell` job runs code as SYSTEM on the endpoint. It must be gated server-side by its own permission, separate from inventory viewing and
remote access (rivet-core #59), and every submission audited. Least-privilege options: run the service as a dedicated virtual account
(`NT SERVICE\RivetITAgent`, enable with `sc config RivetITAgent obj= "NT SERVICE\RivetITAgent"`) and grant it only what your jobs need — then
inventory/service/pending-reboot checks work but most maintenance does not; or split a low-privilege collector from an on-demand elevated
executor (follow-up). The default is SYSTEM because maintenance jobs generally need it.

## Updates and rollback

Check-in response `update` = `{version, url, sha256, signature, min_version}`. The agent:

1. validates (no network): version parses and is **strictly newer** (equal = no-op, older = refused as downgrade); the running version is
   >= `min_version` (else "intermediate update required"); URL is `https`, no credentials, host is the RivetIT host or listed in `update_hosts`;
   `sha256` is 64 hex; **ed25519 signature over the lower-case sha256 hex string verifies with the pinned key**;
2. downloads (<= 128 MiB, TLS, no redirects; the bearer token is only sent to the RivetIT host) into the fixed path `rivetit-agent.exe.new`
   beside the binary (never a path derived from the manifest/URL), checks SHA-256 (constant time); the file is deleted on any failure;
3. checks it is a PE (Windows) executable for this CPU (`debug/pe` Machine field) and runs `rivetit-agent.exe selftest`, which must report
   `version=<manifest version>` (this also binds the unsigned `version` field to the signed binary: a replayed old binary cannot claim a newer version);
4. writes `update.json` (probation, deadline 10 min), renames `current -> .prev` and `.new -> current` (Windows allows renaming a running exe),
   and exits with code 75; the service wrapper turns this into a non-zero service exit so the SCM recovery action restarts the *new* binary.

Health check and rollback: the new process increments a start counter in `update.json`. It is **confirmed** by its first successful
check-in (`.prev` stays as the last known good). If it restarts more than 3 times without confirming, or the 10-minute deadline passes without
a successful check-in (watchdog in the running process, or on the next start), it swaps `.prev` back, records the failure, and exits 75; the
restored binary reports the failure once, which appears as check `agent_update` = `fail` ("update to X failed: ..."). A failed version is not retried.
Residual risk: a new binary that cannot even start the Go runtime cannot roll itself back (the pre-swap `selftest` makes this unlikely);
SCM recovery restarts it but does not downgrade it. Mitigation: pilot rings (server-side staged rollout) and keeping `.prev`.

The Windows rename dance and SCM restart are exercised only by the Linux equivalent (pure functions on paths, tested).

## Server contract details (reconciled with the RMM protocol docs (`docs/rmm/`))

- `device_id` is a JSON **integer** on the real server (enroll response, and inside signed jobs where the canonical JSON keeps it as written);
  the agent accepts a number or a string everywhere and compares by text. Number and string forms are not interchangeable under the signature.
- `signing_key_id` (enroll and check-in responses) is stored; if a check-in reports a different id the agent logs an error and shows
  "signing key rotated; re-enroll required" in `status` (jobs and updates are refused until it re-enrolls, as designed).
- Every check-in response carries `status` and `matched_asset_id`; the agent applies them, so a pending device leaves low-rate mode once approved.
- The optional check-in field `update_result {version, state ok|failed|rolled_back, detail}` is sent once after an update attempt and cleared on acknowledgement.
- `426 tls_required` is treated as a configuration error: the in-flight check-in is kept and retried with a long backoff (use the https URL).
- Reboot jobs: `params.delay_s` 5-3600 (default 30; smaller values are raised to 5).
- Config checks carry `signature`; script checks require a valid one (see Monitoring).
- Testing against a real scratch server: the Linux agent reports `os=linux`; a server that predates Linux support (RivetIT before the RMM module) needs `EA_ALLOW_NON_WINDOWS = true` in
  `config.php` (scratch only, never in production). `rivetit-agent run` also has TEST-ONLY flags `--pending-interval SECONDS` and `--min-interval SECONDS` (shorten the pending re-check
  rate, default 300 s, and the 10 s interval clamp). `e2e/run_e2e.sh` documents the approval step and supports `E2E_APPROVE_CMD`.

## Transport and security model

- **TLS verification is always on.** There is no insecure flag. `https` is mandatory (plain `http` is accepted only in builds made with
  `-tags agenttest`, loopback hosts only, for developer scratch servers). TLS >= 1.2. Optional extra CA (`--ca`, added to the system pool) and
  optional SPKI pin (`--pin-spki`, checked after normal chain validation). Redirects are never followed. System proxy variables are honoured.
- Request <= 1 MiB, response <= 4 MiB, timeouts on dial/TLS/headers/total. Backoff is exponential with **full jitter** (5 s base, 15 min cap);
  `Retry-After` (seconds or HTTP date, capped at 1 h) is a floor; 4xx payload rejections drop the poisoned in-flight request rather than wedging.
- Ring buffer: <= 100 samples and <= 1 MiB of JSON, oldest dropped first (the newest sample is always kept); persisted atomically.
- Clock skew: the offset to `server_time` is applied to `collected_at` and to job expiry decisions.
- Device token: DPAPI (machine scope, entropy) inside a directory whose DACL only admits SYSTEM and Administrators; the token is never
  logged or placed on a command line, and is added to the job-output redaction list. Linux: `0600` file in a `0700` directory (re-tightened on read).
- Enrollment tokens are not persisted unless an offline install needs them (protected, deleted after use).
- No shell string is ever built from server-provided fields (see Jobs); `shutdown.exe`, `taskkill.exe`, PowerShell are invoked by absolute path with fixed argv.
- Update path traversal: staged/previous/failed names are derived only from the installed binary path; manifest version strings are strictly parsed
  (no separators). The agent downloads a raw executable; there is no archive extraction and therefore no zip-slip surface.
- The signing key is trusted on first use at enrollment over validated TLS (or the pinned SPKI/CA); rotating it requires re-enrollment.

## MeshCentral

The agent never installs or configures MeshCentral. It reports `inventory.mesh_node_id` from, in order: `config.json` `mesh_node_id`; a
`mesh_node_id.txt` file in the state dir (for deployment tooling); then the output of `"<Program Files>\Mesh Agent\MeshAgent.exe" -nodeid` (10 s cap).
Evidence and limits: MeshCentral's agent documentation (docs.meshcentral.com/meshcentral/agents) documents `C:\Program Files\Mesh Agent\` with
`MeshAgent.exe`, `meshagent.msh`, `meshagent.db` and `meshagent.log`, but **does not document where the node id is stored**; the `-nodeid` CLI option
is known from the MeshAgent README only as summarised by a web search, and was **not run here**. The `.msh` file holds the *mesh (device group) id*, not the
node id, so it is deliberately not parsed. If `-nodeid` does not behave as assumed, the field stays `null` (safe) and the override file/config keys remain.

## What is Windows-only and UNVERIFIED

(The Linux layers are covered by the Linux section above and its tests; they have not run under a real systemd on a physical host.)

Everything below compiles and vets for windows/amd64 and windows/arm64 and has **never run**:

- `internal/svc/service_windows.go` (service handler, create/update/start/stop/delete, recovery actions, non-crash failure actions)
- `internal/collect/collector_windows.go` (registry reads: MachineGuid, OS version, CPU name, pending-reboot keys; GetSystemTimes, GlobalMemoryStatusEx,
  GetDiskFreeSpaceEx/GetVolumeInformation, GetIfEntry2Ex, GetTickCount64; `mgr` service state; the PowerShell CIM identity query; MeshAgent dir lookup)
- `internal/store/perm_windows.go` (directory DACL via SDDL `D:PAI(A;OICI;FA;;;SY)(A;OICI;FA;;;BA)`, DPAPI protect/unprotect)
- `internal/jobs/exec_windows.go`, `shell_windows.go` (PowerShell `-EncodedCommand` execution, `taskkill /T /F` tree kill, minimal environment)
- `internal/agent/reboot_windows.go` (`shutdown.exe /r /t`), `cmd_install_windows.go` (install/uninstall, self-copy, delayed self-delete, `MeshAgent.exe -fulluninstall`)
- `scripts/install-windows.ps1`
- The self-installing installer's elevation, MessageBox, Add/Remove Programs entry and manifest (see "Self-installing per-department installer")
- The update rename/restart sequence on a real SCM, and the whole system under EDR/AV
- Authenticode signing (not performed), MSI packaging (not built)

Windows 11 detection from the registry (`ProductName` still says "Windows 10") uses build >= 22000 and is likewise untested.

## Testing

`go test -race ./...` (see `docs/rmm/AGENT_BUILD.md` for counts and measurements). Highlights: enroll flow (new/pending/ambiguous/invalid/expired/revoked/429),
install_id stability and credential rotation, seq idempotency across restart, null-never-zero, ring bounds/replay order, backoff bounds, TLS/CA/pin/redirect/caps,
job signature (valid/tampered/wrong key/expired/wrong device/unsigned), crash-mid-job and lost-ack (no re-execution), reboot-reports-before-acting,
process-tree kill on timeout, output cap + redaction, concurrent job serialisation, updater (hash mismatch, bad signature, downgrade, min_version, selftest failure,
crash-loop and deadline rollback, last good kept, fixed staging path), credential file permissions, revoked dormancy (no traffic), and fuzzing of the canonical-JSON/signature parser and the check-in/jobs/enroll decoders.

Linux additions: table tests of every `/proc`/`/sys`/systemd parser (they run on any host), the platform against a fake root tree and shim programs, the unit file and systemd
calls (with a recording `systemctl`), the whole install/uninstall path against the real service layer, the shell runner (file modes, no script in argv, process-group kill, cleanup),
`unsupported_platform`, reboot scheduling, `module_disabled` back-off, jitter bounds and the platform/capabilities block.

End-to-end: `e2e/run_linux_container_e2e.sh` (Linux as root in a container, see above) and `e2e/run_e2e.sh` (self-contained against `e2e/fakeserver`, or against a real scratch server with `RIVETIT_E2E_SERVER_URL`/`RIVETIT_E2E_TOKEN`/`RIVETIT_E2E_CA`).
