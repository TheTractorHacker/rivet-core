# RMM endpoint agent (Windows and Linux): build, package, release, tests and measured footprint

Source: `endpoint-agent/` (see `endpoint-agent/README.md` for architecture, security model and install/uninstall policy).
Written 2026-10-06 against Go 1.27.1 for RivetIT; moved to RivetCore and extended for Linux on 2026-10-07 (issue #65). Edition-neutral: editions download the release binaries, they never build the agent.

## Verification status

| Layer | Status |
|---|---|
| Portable core (enroll, transport, check-in/seq/ring buffer, jobs verify + state machine, updater, checks logic, redaction, CLI) | unit/integration tested on Linux with `go test -race`; end-to-end against `e2e/fakeserver` |
| Windows layers (service wrapper, registry/CIM/IP Helper collectors, DPAPI + ACLs, PowerShell execution, install/uninstall, `install-windows.ps1`) | cross-compile + `go vet` clean for windows/amd64 and windows/arm64; **never executed** |
| Self-installing installer (`setup`, payload reader in `internal/embed`, exit codes, strip-token copy, idempotence) | unit/integration tested on Linux with `go test -race`, fuzzed, validated against the server's trailer vectors, and exercised end to end by `e2e/run_e2e.sh` (stamped binary against the fake server) |
| Installer's Windows layer (self-elevation via `ShellExecuteEx runas`, MessageBox, Add/Remove Programs registry entry, manifest + version info `.syso`) | cross-compile + `go vet` clean for windows/amd64 and windows/arm64; **never executed** |
| Linux (systemd): collectors, shell runner, install/uninstall, unit file, reboot, `install-linux.sh` | unit tests (parsers on any host, platform against a fake `/proc`/`/sys` tree and shim programs, real service layer with a recording `systemctl`) and an end-to-end run **as root in ubuntu:24.04** (`e2e/run_linux_container_e2e.sh`). plus an optional real-systemd pass in a privileged container (`e2e/run_linux_systemd_e2e.sh`). **Not yet run on a physical host** |
| Remote tools and log collection (RMM Phases 6 and 8; `internal/remote`, `internal/logs`) | Linux: unit tests (hostile names, pids and paths for both OS syntaxes, transfer against a fake server, journal and event-log fixtures, manager commit/rate/resume), `go test -race`, and `e2e/run_real_agent_proof.sh`: the release-built agent under a real systemd talking to RivetCore on `php -S` (scratch database) through a TLS proxy, 60 checks (39 at rc.11; rc.16 adds 21 for the pushed Mesh install, below): service list and restart (new MainPID), process list and name-checked kill, file download and upload with SHA-256 verification, deny-list refusal after symlink resolution, journald collection with a pattern alert, audit rows; and (1.0.0-rc.16) the pushed Mesh agent against a mock Mesh server: PHP-signed `mesh_install` jobs verified and run by the real agent (wrong pin, unknown group, oversize, the good case with the node id mapped by the server, idempotence, force, bulk, uninstall), the group id absent from the job row and every result. Windows: service manager, process APIs, path rules and the `wevtutil` reader are cross-compiled and vetted for amd64 and arm64, **never executed** |
| Windows MSI template (1.0.0-rc.16; `packaging/msi`, `make msi`) | built on Linux by msitools `wixl` 0.103 in a pinned image, **byte-identical across two builds**, **table-checked** (the build asserts the tables, the stable UpgradeCode and component GUID, the major-upgrade and downgrade rows, the custom actions and their sequence, and extracts the cabinet to compare the agent exe byte for byte), and read back by the Go trailer reader after a payload is appended. amd64 only. **Never installed on Windows**: whether `msiexec` accepts the appended payload, runs the custom actions as written, upgrades and uninstalls cleanly is UNVERIFIED: [MSI_VERIFY.md](MSI_VERIFY.md) lists exactly what to run |
| Authenticode signing | not done (documented); CI has a disabled, secret-gated signing step. Not done for the MSI either (below) |

`go vet ./...` (linux/amd64, linux/arm64, windows/amd64, windows/arm64, `-tags "devtools agenttest"`) and `go test -race ./...` pass; `staticcheck` (installed via `go install`) reports nothing in non-test code.

## Tests

168 top-level tests and fuzz targets (plus 95 subtests), 0 failures, with `-race`, measured on linux/amd64 (the Windows-only tests compile but have never run): main package 13 (`setup_test.go`, `install_linux_test.go`), agent 37, api 18 (+1 fuzz), buffer 4, collect 29, embed 12 (+1 fuzz), jobs 27 (+1 fuzz; includes the shared signing vectors), store 9, svc 4, update 15.
Baseline (RivetIT `4ba51d35`) to now, by package: main 8 to 13, agent 24 to 37, api 13 to 18, collect 13 to 29, jobs 21 to 27 (one obsolete test-mode test removed), svc 0 to 4, buffer/embed/store/update unchanged (120 to 168 in total). The additions cover the `/proc`/`/sys`/systemd parsers on any host, the platform against a fake tree and shim programs, the shell runner and `unsupported_platform`, the `module_disabled` back-off (bounds, once-per-hour log, no tight loop, legacy 403 unchanged), jitter, the platform/capabilities block, the Linux reboot scheduler, and the full install/uninstall path against the real service layer.
The job canonical-JSON/signature rule and the installer trailer are validated against the shared vector files in `endpoint-agent/testdata/vectors/` (one copy; the PHP tests read the same files, `SHA256SUMS` lists them).
Fuzz targets: `FuzzCanonical` (canonicaliser + signature path; 500k execs clean), `FuzzDecoders` (check-in/jobs/enroll decoders).

### Installer tests added with the self-installing installer

`internal/embed`: table tests (valid, unknown fields, 27 validation failures, expiry, truncated/wrong magic/length lies/huge length/hash mismatch/tampered payload/
junk after footer/oversize/bad JSON/invalid UTF-8), strip-copy tests, `FuzzEmbedded`, and the server's `agent_installer_trailer_vectors.json` (positive: byte-identical `Build`,
`Extract`, `UnstampedSize`; negative: all 14 rejected). Main package (`setup_test.go`, against an httptest TLS enrollment server): the stripped binary and token absence under the state dir,
idempotent re-run keeping the install id with a server that would reject a second exchange, every exit code 2/3/4/5/6, elevation passthrough and no relaunch loop, silent never prompts,
token echoed by a hostile server never reaches the log, CommandLineToArgvW round-trip of the elevated command line. The `devtools`-tagged `stamp` command exists only for tests.

### Pushed Mesh agent install and the MSI (1.0.0-rc.16)

`internal/mesh/job_test.go` (mock Mesh HTTPS server, the rc.12 helpers): the parameter parser (unknown members, http, queries, bad pin, bad group, hostile arguments, out-of-range ids, wrong types), the success path (pinned download, the Linux id and URL-form group in the address, the installer arguments, the node id waited for and returned, the saved state and the reporter told to look again, the group id absent from the result), idempotence with and without `force`, six failures (pin mismatch, 404, oversize, an HTML page, a non-program, an installer that exits 3) each reported and leaving no work directory, refusal before any network call, a panic in the installer, and `mesh_uninstall`. `internal/agent/mesh_test.go` and `internal/jobs/types_test.go`: the capabilities and `disable_remote`. `e2e/linux_container_scenario.sh`: an agent installed WITHOUT any Mesh option is sent `mesh_install` jobs by the fake server (`/_ctl?mesh_push=...`): refused parameters, pin mismatch, 404, an announced and a chunked oversize body, then the good case (the fake meshagent installed, the node id reported and the group id absent from the state and the result), `already_installed`, `force`, and `mesh_uninstall`, with the RivetIT agent checking in throughout and never restarted. Main package `msi_setup_test.go`: `setup --from-msi --payload-from` (payload from a synthetic `.msi`, the installed exe untouched, no second Add/Remove entry, unusable payloads refused, MSI-only flags refused elsewhere, property mode with its validation, an upgrade or repair without a usable payload keeping the enrollment, an unreachable server exiting 0 for the MSI and 4 for the exe, never relaunching elevated). `internal/embed/msi_test.go`: a synthetic OLE file and, through `make msi`, the real wixl-built template round trip (append, read, strip back byte for byte). **None of the Windows side ran on Windows.**

### Optional MeshCentral agent install (1.0.0-rc.12)

`internal/mesh`: payload validation (https only, group id, pin, id table, argument allow list), the download (pinned or system-root TLS against an `httptest` TLS server: success with SHA-384 and SHA-256 pins, a payload table overriding the agent ids, pin mismatch, self-signed without a pin, non-https and malformed sources, oversize by `Content-Length` and chunked, 404 and 500, an HTML page, an empty body, a wrong file type, a redirect to another host, installer failure, installer timeout, the Windows flow with a fake runner, an unknown platform), the group id never present in a result or an error, node-id parsing from fixtures (`testdata/`: `-nodeid` output, `reg query` text, `.msh` files with and without a `NodeID`), the reporter (one report, repeated daily, not on a machine without a Mesh agent, the override file). `internal/embed`: the additive vector file `agent_installer_mesh_vectors.json` read with the current struct and with a frozen copy of the pre-rc.12 struct (an old agent ignores the members), and the built-in tables pinned to the vectors. Main package (`mesh_setup_test.go`): the installer runs Mesh after the RivetIT agent, a failed or unusable Mesh part never changes the exit code, `--no-mesh-agent`, an installed Mesh agent is left alone, a rejected enrollment skips Mesh. `internal/agent/mesh_test.go`: the `mesh_agent` member and the `mesh:nodeid` capability. `e2e/run_linux_container_e2e.sh` adds a mock Mesh server (`e2e/fakeserver -mesh-listen`) serving a fake `meshagent` and drives the stamped installer through pin mismatch, wrong group, untrusted certificate, `--no-mesh-agent`, the good case, re-run, a plain uninstall (Mesh left alone) and `--remove-meshagent`. **None of this talks to a real MeshCentral server.**

## Toolchain without Go on the host (Docker)

The module needs Go 1.27 (`go.mod`). On a machine without Go run everything in the official image; mount a host directory as the module/build cache to avoid re-downloading:

```
mkdir -p /tmp/gocache /tmp/gobuild
alias gor='docker run --rm -u "$(id -u):$(id -g)" -v "$PWD/endpoint-agent":/src -v /tmp/gocache:/gocache -v /tmp/gobuild:/gobuild \
  -e GOCACHE=/gobuild -e GOMODCACHE=/gocache -e HOME=/tmp -w /src golang:1.27'
gor make vet                                   # vet: linux amd64+arm64, windows amd64+arm64, test tags
gor sh -c 'CGO_ENABLED=1 go test -race -count=1 ./...'     # -race needs cgo (the golang image has gcc)
gor go test -run xxx -fuzz=FuzzEmbedded -fuzztime=20s ./internal/embed
gor sh -c 'GO_BIN_DIR=/usr/local/go/bin ./e2e/run_e2e.sh'  # unprivileged Linux e2e against the Go fake server
gor make dist VERSION=0.1.0-beta.2 COMMIT=abc1234          # dist/ (twice: byte-identical)
```

`e2e/run_linux_container_e2e.sh` drives Docker itself (it builds in `golang:<go.mod version>` and runs the scenario as root in `ubuntu:24.04`; set `GO_IMAGE`/`UBUNTU_IMAGE` to override).

## Building on your own build host (BUILD_HOST)

Besides the GitHub Actions workflow, a server can ask a machine you control to build a version: `endpoint-agent/scripts/build-host.sh` polls the server (outbound https only), builds the requested allowlisted ref with the same `make dist VERSION= COMMIT=` and the MSI steps in the pinned Docker images, verifies the outputs and uploads them in 1 MiB chunks. Protocol: [BUILD_HOST_PROTOCOL.md](BUILD_HOST_PROTOCOL.md); setup: [BUILD_HOST.md](BUILD_HOST.md). The script runs `make dist` inside the Go container and the MSI steps (which start their own container) directly on the host, because `make msi` needs Docker and cannot run inside the Go container.
Proven so far (build host script only; the server side is a separate work item): `endpoint-agent/e2e/run_build_host_proof.sh` runs the real script against `e2e/mock_build_host_server.py` with a real Docker build of the committed HEAD (manual, minutes; results in the CHANGELOG), and `e2e/test_build_host.py` (CI job `build-host`, no Docker build) covers the mock's validation and the script's refusals and lock.

## Reproducible release build (CI and local)

`make dist VERSION=X.Y.Z COMMIT=<sha7>` (CI: `.github/workflows/endpoint-agent.yml`, tag `agent-vX.Y.Z` **in this repository**) produces in `dist/`:

| File | Notes |
|---|---|
| `rivetit-agent-windows-amd64.exe`, `rivetit-agent-windows-arm64.exe` | unsigned unless the Authenticode step is enabled |
| `rivetit-agent-linux-amd64`, `rivetit-agent-linux-arm64` | static, no cgo |
| `rivetit-agent-linux-amd64.tar.gz`, `-arm64.tar.gz` | `rivetit-agent` + `install-linux.sh`; deterministic tar (sorted, owner 0, fixed mtime) and `gzip -n` |
| `install-linux.sh` | the script from `scripts/` |
| `SHA256SUMS` | covers every file above |

All builds use `-trimpath -buildvcs=false`, `CGO_ENABLED=0`, `-ldflags "-s -w -X main.version=... -X main.commit=..."`. The Windows resources (manifest `asInvoker`, version info) are the committed static
`rsrc_windows_{amd64,arm64}.syso` (regenerate with `make syso`). Two builds with the same Go toolchain, `VERSION` and `COMMIT` are byte-identical; CI runs `make dist` a second time and `diff -r`s the two trees.
Pin the Go version (`go.mod` plus the image tag) in release pipelines: a different toolchain produces different bytes.

### Linux packages

`make packages VERSION=X.Y.Z` runs nfpm (`goreleaser/nfpm` image, `NFPM_IMAGE=` to change the tag) in Docker and writes `dist/packages/rivetit-agent_<ver>_<arch>.deb` and `.rpm` for amd64 and arm64
from `packaging/nfpm.yaml` and `packaging/{postinstall,preremove,postremove}.sh`. They install the binary (`/opt/rivetit-agent/rivetit-agent`) and an empty 0700 `/var/lib/rivetit-agent`; they never enroll or
start anything. An upgrade restarts the service when `install` created one. Packages are kept out of `SHA256SUMS`: with `SOURCE_DATE_EPOCH` fixed the `.deb` files were byte-identical across two builds, the `.rpm` files were not (rpm embeds build metadata). The binary inside every package
is the reproducible one from `dist/`.

## The Windows MSI template (1.0.0-rc.16)

The server can hand out the per-client installer as an `.msi` as well as the stamped `.exe` (`format` `exe` | `msi` on the administrator's download and on `agent_installer`). Nothing about the exe changes. The .deb and .rpm packages for Linux (above) are unchanged and remain the way to ship the Linux agent; there is no Linux MSI equivalent to build.

**What it is.** `packaging/msi/rivetit-agent.wxs` is a Windows Installer package that installs `rivetit-agent.exe` under `%ProgramFiles%\RivetIT\Agent`, then runs the agent's own `setup` as a deferred, elevated custom action (`rivetit-agent.exe setup --silent --from-msi --payload-from "[OriginalDatabase]"`): the same entry the exe installer uses, so enrollment, the service, the optional Mesh agent and every exit code are one code path. The per-client payload is appended to a COPY of the template by the server, in the SAME `payload || footer` trailer format as the stamped exe (PROTOCOL.md section 6; the frozen vectors are untouched), and `setup --payload-from` points the SAME Go reader at the `.msi` file. The stored template is never modified.

* **Properties for managed deployment** (an unstamped template, so IT passes the data instead): `msiexec /i rivetit-agent-windows-amd64.msi /qn SERVER=https://rmm.example.com ENROLL_TOKEN=rvte1... [CA_PEM_FILE=C:\path\ca.pem]` (or `ENROLL_TOKEN_FILE=<file>`). The values go through the payload's own validation (https only, token shape, PEM only). `ENROLL_TOKEN` is listed in `MsiHiddenProperties`, so verbose logs mask it. It is still a one-shot secret on a command line (a process listing, an RMM job log, a GPO script); prefer `ENROLL_TOKEN_FILE` with a file only SYSTEM and administrators can read, or the stamped MSI from the token-gated download.
* **Uninstall** is the standard one (Add/Remove Programs, or `msiexec /x`): the package stops the service and runs `rivetit-agent.exe uninstall --from-msi` (service removed; the files and the Add/Remove entry are the installer's). Local state under `%ProgramData%\RivetIT\Agent` is kept. The Mesh agent is **untouched** unless `REMOVE_MESHAGENT=1` is passed to msiexec.
* **Upgrade** is a Windows Installer major upgrade: a constant UpgradeCode, a constant component GUID (so the files are reference counted across versions), a new ProductCode per version, a block on downgrades. Only `major.minor.patch` counts (a pre-release suffix is dropped: 1.0.0-rc.16 and 1.0.0 are the same MSI version). The old product's removal stops the service but does NOT run `uninstall`, and the new package's `setup` keeps the existing enrollment when it has no usable payload (an expired or missing one). A fresh install with an unusable payload fails and rolls back.
* **A server that is unreachable while installing is not an MSI failure**: `setup --from-msi` exits 0 after registering the service (the one-shot token is kept protected and the service retries), because Windows Installer would otherwise roll the whole install back. A rejected token fails the install (exit 3 in the log line `returned actual error code 3`).

**How it is built, reproducibly.** `make msi VERSION=X.Y.Z` (Docker and Go; not part of `make dist`) builds the image `packaging/msi/Dockerfile` (Ubuntu 24.04 pinned by digest, `wixl`/`msitools` 0.103 and `faketime`/`libgcab` pinned), builds the MSI **twice** and compares them, runs the checks in `packaging/msi/build.sh check`, and reads a payload-stamped copy back with the Go reader (`TestRealWixlMSIRoundTrip`). A fixed clock (`faketime` at 2000-01-01), fixed file times and GUIDs derived from the version and the exe's SHA-256 make the result byte-identical; the ProductVersion, ProductCode and PackageCode follow the agent version. The result is `dist/rivetit-agent-windows-amd64.msi`; `make checksums` lists it in `SHA256SUMS` when it exists, and the CI `msi` job (image layers cached) uploads it with its own `SHA256SUMS.msi` and attaches both to an `agent-v*` release. wixl reports an unsupported element or attribute as a GLib CRITICAL or WARNING and builds anyway, so the build treats any such line as a failure.

**architectures.** amd64 only. msitools 0.103 refuses `wixl -a arm64` and ignores a `Platform="arm64"` attribute, so there is no arm64 MSI. The server stores templates for `amd64` and `arm64` (an arm64 package built with WiX on Windows is accepted), but the build makes only amd64. An ARM64 PC installs the stamped exe (`RivetIT-Agent-Setup-...-arm64.exe`). Do not give it the x64 MSI: Windows would install the x64 agent under emulation.

**Publishing a template.** Upload the file in the edition's administration area next to the agent binaries (module API `Admin\RmmAdmin::uploadMsiTemplate`, then `msiTemplateAction(make_current)`; list with `RmmReadModel::msiTemplates()`). It is validated before it is stored: an OLE compound file (`D0CF11E0A1B11AE1`; an exe is refused with a pointer to Agent binaries), the size window and cap, not already stamped, and the platform from the summary-information stream when it can be read (`x64` or `Arm64`; `Intel` is refused; a package that contradicts the declared architecture is refused; an unreadable summary is accepted on the uploader's word and the row says `arch_source: declared`). A template is a row of its own table (`endpoint_agent_msi_templates`, migration `0025_rmm_msi_templates`) and can never become an agent update.

**Honest limits.**

* **Unsigned.** The MSI and the exe inside are not Authenticode signed, so Windows SmartScreen and some policies will warn or block. Signing is a disabled step in CI.
* **Appended payload and signing (design note, UNVERIFIED).** Appending bytes to a signed file invalidates an embedded Authenticode signature of that file (the signature covers the file's hash). So the plan, if signing is ever switched on, is to sign the TEMPLATE once at release time and accept that the stamped copy no longer verifies as a whole; Windows Installer itself does not check the signature of a package it is asked to install, but SmartScreen and policy engines that do will see an invalid signature on the stamped copy. The alternatives are to sign the exe inside the package only (the template's signature stays out of the picture) or to deliver the payload outside the file (a transform or a property) at the cost of the single-file download. None of this was tried.
* **msiexec and trailing bytes are UNVERIFIED on a real Windows host.** The compound file format ignores bytes after its last sector, which is why the Go and PHP readers work on the stamped copy, but whether `msiexec.exe` opens it, whether the copy it caches in `C:\Windows\Installer` keeps the trailer, and what `[OriginalDatabase]` points at in each case were not observed. [MSI_VERIFY.md](MSI_VERIFY.md) is the checklist for a disposable Windows PC; the stamped MSI, the unstamped template with properties, upgrade, repair and uninstall are each a step.
* The cached copy of a stamped MSI in `C:\Windows\Installer` (and the downloaded file) holds the enrollment token until it expires or is used up, like the exe installer in a Downloads folder.

### Release steps

1. Merge to the default branch; CI must be green (vet x5, `go test -race`, fuzz, `run_e2e.sh`, `run_linux_container_e2e.sh`, reproducibility diff, packages).
2. Tag `agent-vX.Y.Z[-beta.N]` on that commit **in rivet-core** (`git tag agent-v0.1.0-beta.2 && git push origin agent-v0.1.0-beta.2`). A suffixed tag is published as a pre-release.
3. The `release` job attaches the Windows exes, the Windows amd64 MSI template (with `SHA256SUMS.msi`), Linux binaries, tarballs, packages, `install-linux.sh` and `SHA256SUMS` to that GitHub Release of rivet-core (title and notes are edition-neutral).
4. In each edition: Administration > Endpoint agent (RMM) > Agent binaries > upload the binaries for the architectures you use (the server signs the update manifest with its own ed25519 key; the agent trusts that
   key, not the release). Download per-department installers from there. Linux installers need a server with Linux support (RMM module).
5. Optional Authenticode signing: enable the gated step in the workflow *before* the checksums are computed.

### Real systemd pass

`e2e/run_linux_container_e2e.sh` replaces `systemctl` with a recording shim and supervises the agent with a loop that runs the unit's own `ExecStart` line. The optional
`e2e/run_linux_systemd_e2e.sh` (needs `docker run --privileged` and cgroup v2; not run in CI) boots `jrei/systemd-ubuntu:24.04` and runs the same install script against a REAL systemd: unit active and
enabled, `User=root`/`Restart=always`/`StateDirectory`, check-in, a signed shell job as root, journal output, `kill -9` is followed by a restart, self-update (exit 75 and restart into the new binary),
clean stop and uninstall. That container's `/tmp` is `noexec`, which is how the install script's "find a directory that allows executing" logic was found necessary. Run it after `run_linux_container_e2e.sh` (it reuses the
binaries in `e2e/work-linux/bin`).

### Windows and Linux binaries are separate (1.0.0-rc.30)

The hosted update channel keys a binary and its release on `(version, arch, os)`. Publish the Windows build and the Linux build of the same version as two binaries (`os` `windows`, the default, and `linux`); a device is only ever offered and served the one for its own OS, and the agent adds `os=<GOOS>` to its download request. The publish check refuses a file whose magic is not the declared OS's (PE `MZ` for windows; ELF for linux, which must also be a 64-bit little-endian executable or PIE for the declared architecture, within the size cap, and contain the version string the agent embeds with `-ldflags -X`). So `rivetit-agent-linux-amd64` is published with `os=linux, arch=amd64, version=<the -X version>`, never as a Windows binary. Linux binaries are never stamped into installers (installers are Windows only).

### Provenance of the source tree

`endpoint-agent/` was imported from RivetIT `origin/beta` at `4ba51d35` (agent tag `agent-v0.1.0-beta.1` lineage). The 9 commits that touched the Go tree there can be reconstructed with
`git subtree split -P endpoint-agent` in a RivetIT clone; the commit that adds the tree here cites the source SHA.

## Measured footprint (LINUX measurements; Windows numbers are unmeasured)

Host: 14-vCPU Linux VM, otherwise idle. Linux test build of the agent, running against `e2e/fakeserver` (TLS, loopback).
Reference workload: default intervals (collect 60 s, check-in 300 s), 2 server checks (`disk` on `/`, `pending_reboot`), no jobs, 11 minutes of wall time (66 samples of `/proc/<pid>`).

| Metric | Result |
|---|---|
| RSS | avg 15.1 MB, max 16.5 MB (9.4 MB before first enrollment, idle) |
| Threads | 12-13 |
| CPU | 0.11 CPU-seconds in 663 s = **~0.017 %** of one core (clock-tick resolution; the only visible activity was the 300 s check-in) |
| Binary size | windows/amd64 8.12 MB, windows/arm64 7.37 MB, linux/amd64 (as measured for the earlier test build) 7.79 MB (stripped, `-trimpath`); windows/amd64 gzips to 3.37 MB |
| Check-in request body | first (with inventory, 0 buffered) 1.1-1.5 KB; steady state with 1 sample 350 B; as run in the reference workload (4 buffered 60 s samples + latest) 1.9-2.3 KB; worst-case replay (99 buffered) 28.9 KB |
| Check-in response body | 289 B (fake server config) |
| Bytes on the wire, whole run | 4 TLS connections (enroll + 3 check-ins, no keep-alive reuse across the 300 s gap): 15.4 KB received + 11.4 KB sent by the server = about 6.7 KB per exchange including TLS 1.3 handshake and a self-signed ECDSA chain (real RSA chains cost more) |

Caveats: numbers are for the Linux collector (cheap `/proc` reads). On Windows the identity query spawns PowerShell/CIM once per process life and again for the logged-in user every 15 minutes; its cost is **unmeasured**. Ingestion capacity of the real server is not measured here (server work is separate).

## Reproduce

```
cd endpoint-agent
make vet test
make dist VERSION=1.0.0             # dist/* + SHA256SUMS
make msi VERSION=1.0.0              # dist/rivetit-agent-windows-amd64.msi (Docker; built twice and compared); then `make checksums`
./e2e/run_e2e.sh                    # self-contained end-to-end (fake server, unprivileged)
./e2e/run_linux_container_e2e.sh    # Linux as root in ubuntu:24.04 (Docker)
RIVETIT_E2E_SERVER_URL=https://scratch RIVETIT_E2E_TOKEN=... [RIVETIT_E2E_CA=ca.pem] ./e2e/run_e2e.sh   # against a real scratch server
```
