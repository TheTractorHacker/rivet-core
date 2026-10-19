# Setting up a build host

A **build host** is a Linux computer that you control and that builds a new version of the endpoint agent when your server asks for it. Your web server never needs a compiler or Docker, and nothing connects INTO the build host: the build host asks the server once a minute "is there a job for me?" (outbound https only), builds it, and sends the finished files back. The protocol is in [BUILD_HOST_PROTOCOL.md](BUILD_HOST_PROTOCOL.md); GitHub Actions remains the other way to build.

The script is `endpoint-agent/scripts/build-host.sh`. It runs from a systemd timer; there is nothing to leave running.

## What you need

| Requirement | Notes |
|---|---|
| Linux, x86-64 or arm64, 2 CPUs, 4 GB RAM, 5 GB free disk | an old spare PC or a small VM is enough; a build takes a few minutes |
| Docker | the build runs inside Docker, so the host needs no Go. The user that runs the script must be allowed to use Docker |
| `git`, `curl`, `python3`, `flock`, `timeout`, `sha256sum`, `base64` | all standard on Ubuntu/Debian (`sudo apt install git curl python3`) |
| Outbound https to your RivetIT/RMM server, to GitHub (or wherever `CORE_REPO` lives), and to Docker Hub / Go module servers during builds | no inbound ports, no public address |

## Install

1. **Create a user** that runs the builds. It must not be root:
   ```
   sudo useradd --system --create-home --home-dir /var/lib/rivetit-build-host --shell /usr/sbin/nologin rivetit-build
   sudo usermod -aG docker rivetit-build
   ```
   Being in the `docker` group is effectively root on that machine. Use a machine that does nothing else important (see Security notes).
2. **Copy the script and the units** (from a checkout of RivetCore):
   ```
   sudo install -d /opt/rivetit-build-host /etc/rivetit
   sudo install -m 755 endpoint-agent/scripts/build-host.sh /opt/rivetit-build-host/
   sudo install -m 644 endpoint-agent/scripts/build-host.service endpoint-agent/scripts/build-host.timer /etc/systemd/system/
   sudo install -o rivetit-build -g rivetit-build -m 600 endpoint-agent/scripts/build-host.conf.example /etc/rivetit/build-host.conf
   ```
3. **Get a token.** In the server go to Administration > Endpoint agent > Agent updates > Local build > **Add build host**, give it a name and copy the token. It is shown **once**; if you lose it, revoke it and add the host again. One token per build host.
4. **Edit `/etc/rivetit/build-host.conf`** (`sudoedit`): set `SERVER_URL` (shown on the same server page), `TOKEN`, `CORE_REPO` and `ALLOWED_REFS` (the source versions this machine is willing to build, for example `main v* agent-v*`). The script refuses to start if the file can be read by other users.
5. **Test it by hand** as the build user:
   ```
   sudo -u rivetit-build /opt/rivetit-build-host/build-host.sh --register
   ```
   It prints `OK build host ...` when the token works and Docker is usable. It never takes a job.
6. **Try a build that uploads nothing:**
   ```
   sudo -u rivetit-build /opt/rivetit-build-host/build-host.sh --dry-run
   ```
   The first run pulls the Go image and builds the MSI image, which takes a while. It does not contact your server and prints the folder with the result.
7. **Start the timer:**
   ```
   sudo systemctl daemon-reload
   sudo systemctl enable --now build-host.timer
   ```
   Then press **Build on my build host** in the server. The job appears within a minute. Follow it with `journalctl -u build-host.service -f`.

## The three modes

| Command | What it does |
|---|---|
| `build-host.sh --once` (also the default) | asks the server once; if a job is handed out: build, check, upload in pieces, finish. Exits quietly when there is no job or another run is still going |
| `build-host.sh --register` | checks the token and Docker. Announces "no capabilities" so the server can never give it a job |
| `build-host.sh --dry-run [--ref R] [--version V]` | builds locally with a test version (`0.0.0-dryrun`), contacts no server, uploads nothing, leaves the files in `$WORKDIR/dry-run-out` |

What a job does: the script checks that the version and the source ref are acceptable (the ref must match `ALLOWED_REFS`, otherwise the job is reported as failed and nothing is built), makes a clean temporary checkout of exactly that commit, runs `make dist VERSION=... COMMIT=...` and the MSI build in the pinned Docker images (unprivileged user, read-only container, all capabilities dropped, CPU and memory limits, 25 minute total timeout), checks its own results (Windows executable headers for the right CPU, the version string inside, the MSI file type, the agent's own `version` output), writes `SHA256SUMS`, uploads each file in pieces of at most 1 MiB with a checksum per piece (retrying with backoff), and finally tells the server the hashes. The server checks everything again before publishing. Files built: Windows amd64 and arm64 executables and the Windows amd64 MSI template; Linux targets are built by `make dist` too but the protocol uploads only the Windows files and `SHA256SUMS`.

Temporary files, containers and checkouts are removed however the run ends. Builds that are byte-reproducible (the executables and the MSI) are byte-identical to a direct `make dist` of the same commit, version and Go image (tested); a GitHub-hosted comparison has not been done.

## Troubleshooting

| Symptom | Likely cause and fix |
|---|---|
| `config ... must not be readable by group/others` | `sudo chmod 600 /etc/rivetit/build-host.conf` and make the build user the owner |
| `SERVER_URL must be https://` | use the https address. `BUILD_HOST_ALLOW_HTTP=1` exists for tests only |
| `the server did not accept the token` | token revoked or mistyped, wrong `SERVER_URL`, or the clock/URL points at a different server. Add a new build host and paste the new token |
| `docker is not usable by this user` | `sudo usermod -aG docker rivetit-build`, then restart the unit (group changes need a new login) |
| Job shows failed: `source ref ... is not in this build host's ALLOWED_REFS` | add the ref pattern to `ALLOWED_REFS` or pick another ref in the server |
| `ref '...' does not exist in the source repository` | `CORE_REPO` does not have that branch or tag; if it is a local clone make sure it has an `origin` remote |
| `build timed out after 25 minutes` | the machine is too slow or the network is stuck. Raise `BUILD_CPUS`/`BUILD_MEMORY` or check Docker Hub / `proxy.golang.org` access |
| `another run holds the lock` | normal while a build is running. If it never clears, check for a stuck process: `ps -u rivetit-build`; the lock file is released automatically |
| Job stuck as claimed in the server | the host stopped without reporting; the server expires it after 60 minutes without status. Cancel it in the UI and run again |
| MSI step fails on first run | the MSI image builds from `packaging/msi` and needs network for apt; retry with `--dry-run` and read the output |

Logs: `journalctl -u build-host.service`. On a failed build the last 4 KiB of the build output (secrets masked) is also sent to the server and shown on the job.

## Security notes

* The token only authorises the four build endpoints. Keep the config file mode 600. The script passes the token to `curl` through a private temporary file (not on the command line), never writes it to the log, and masks it and anything that looks like a bearer token or `password=`/`token=` value in the log tail it sends.
* Only https is accepted. Redirects to http are refused.
* The server cannot make the host build arbitrary code: the source ref must match your own `ALLOWED_REFS`, versions must look like `X.Y.Z[-suffix]`, and the repository is the one you configured. A compromised server could still ask for any allowed ref and fake a version number; it cannot choose other code.
* Docker group membership is root-equivalent. Dedicate the machine (or a VM) to building, keep it patched, and do not put other secrets on it. Builds run unprivileged in containers with no extra capabilities, a read-only root filesystem, process/CPU/memory limits and no access to the host except the temporary source tree and the Go module cache (`$WORKDIR/gomod`, checked by `go.sum`). The MSI step runs without network access.
* Authenticode signing is not part of this: the files are unsigned like the GitHub builds without the signing secret.
* To retire a host, revoke its token in the server and `sudo systemctl disable --now build-host.timer`.
