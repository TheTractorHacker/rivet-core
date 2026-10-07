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
| Authenticode signing, MSI | not done (documented); CI has a disabled, secret-gated signing step |

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

### Release steps

1. Merge to the default branch; CI must be green (vet x5, `go test -race`, fuzz, `run_e2e.sh`, `run_linux_container_e2e.sh`, reproducibility diff, packages).
2. Tag `agent-vX.Y.Z[-beta.N]` on that commit **in rivet-core** (`git tag agent-v0.1.0-beta.2 && git push origin agent-v0.1.0-beta.2`). A suffixed tag is published as a pre-release.
3. The `release` job attaches the Windows exes, Linux binaries, tarballs, packages, `install-linux.sh` and `SHA256SUMS` to that GitHub Release of rivet-core (title and notes are edition-neutral).
4. In each edition: Administration > Endpoint agent (RMM) > Agent binaries > upload the binaries for the architectures you use (the server signs the update manifest with its own ed25519 key; the agent trusts that
   key, not the release). Download per-department installers from there. Linux installers need a server with Linux support (RMM module).
5. Optional Authenticode signing: enable the gated step in the workflow *before* the checksums are computed.

### Real systemd pass

`e2e/run_linux_container_e2e.sh` replaces `systemctl` with a recording shim and supervises the agent with a loop that runs the unit's own `ExecStart` line. The optional
`e2e/run_linux_systemd_e2e.sh` (needs `docker run --privileged` and cgroup v2; not run in CI) boots `jrei/systemd-ubuntu:24.04` and runs the same install script against a REAL systemd: unit active and
enabled, `User=root`/`Restart=always`/`StateDirectory`, check-in, a signed shell job as root, journal output, `kill -9` is followed by a restart, self-update (exit 75 and restart into the new binary),
clean stop and uninstall. That container's `/tmp` is `noexec`, which is how the install script's "find a directory that allows executing" logic was found necessary. Run it after `run_linux_container_e2e.sh` (it reuses the
binaries in `e2e/work-linux/bin`).

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
./e2e/run_e2e.sh                    # self-contained end-to-end (fake server, unprivileged)
./e2e/run_linux_container_e2e.sh    # Linux as root in ubuntu:24.04 (Docker)
RIVETIT_E2E_SERVER_URL=https://scratch RIVETIT_E2E_TOKEN=... [RIVETIT_E2E_CA=ca.pem] ./e2e/run_e2e.sh   # against a real scratch server
```
