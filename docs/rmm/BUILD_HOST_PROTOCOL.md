# Local agent builds: the build host protocol (spec, 2026-10-19)

Goal: build a new endpoint agent version (Windows exe and MSI template) from the Core source on a machine the owner controls, started from the web UI, with NO compiler or Docker on the web server and NO inbound connection to the build machine. GitHub Actions stays available as the other provider ("Build on GitHub"); this is "Build on my build host".

## Roles
* **Server** (an edition): stores build requests, authenticates the build host, accepts the uploaded files, verifies them, publishes them through the existing binary / MSI template code (same validators and audit as the upload form).
* **Build host**: any Linux machine with Docker (and git) that can reach the server over https. It runs `endpoint-agent/scripts/build-host.sh` from a systemd timer or cron every minute. It only makes outbound requests (pull model).

## Authentication
The server gives each build host a **build host token** (created in Administration > Endpoint agent > Agent updates > Local build > Add build host; shown once; stored hashed with a per-token salt; revocable; has a name, last seen, last IP, enabled flag). The token authenticates `Authorization: Bearer <token>` on the build endpoints ONLY and grants nothing else. Every call is rate limited and audited.

## Endpoints (flat names under the edition's existing API prefix; JSON in and out; all POST)
1. `poll` `{host_version, capabilities:{docker:true, msi:true}}` -> `{job:null}` or `{job:{id, version, source_ref, targets:["windows-amd64","windows-arm64","linux-amd64","linux-arm64"], msi:true, expires_at}}`. The server hands out at most one job per host and marks it `claimed` for a lease (15 min, renewable by `status`).
2. `status` `{job_id, state:"building"|"uploading"|"failed", note}` (note capped at 500 chars, escaped on output). A `failed` state carries a log tail (last 4 KiB, secrets masked by the script).
3. `upload` chunked so it works under PHP's default 2 MiB limits: `{job_id, name, index, total, sha256_chunk, data_b64}` with chunks of at most 1 MiB raw. `name` is one of the fixed file names `rivetit-agent-windows-amd64.exe`, `rivetit-agent-windows-arm64.exe`, `rivetit-agent-windows-amd64.msi`, `SHA256SUMS`. The server reassembles into a quarantine area owned by the job, never executes anything, enforces per-file and per-job size caps, and refuses names outside the list.
4. `complete` `{job_id, manifest:{files:{name:sha256,...}, commit, go_version, built_at}}`. The server recomputes every SHA-256 over the reassembled bytes, compares them with the manifest and `SHA256SUMS`, runs the existing PE / architecture / MSI-template validators, checks the version string inside the binary equals the requested version when the validators can read it, and only then publishes (ring and rollout from the request, or "publish when built" as chosen) through the existing publish code. Any mismatch stores nothing and marks the job failed.

## Server rules
* Version: same checks as GitHub builds (format X.Y.Z or X.Y.Z-suffix, not already published, higher than the latest, no second running build for the same version).
* `source_ref` is chosen by the administrator from a fixed list the BUILD HOST declares in its own config (default `main`; tags `v*`/`agent-v*` allowed); the server only forwards the label. The build host refuses any ref not in its own allowlist.
* Job states: queued, claimed, building, uploading, verifying, built, published, failed, expired, cancelled. An administrator can cancel. Jobs expire after 60 minutes without status.
* Nothing from the upload is ever interpreted by the server except hashing and the existing validators; display text is escaped; no path comes from the host (names are from the fixed list; storage names are random).
* The existing GitHub provider keeps working; the UI lets the admin choose the provider per build.

## Build host script (`endpoint-agent/scripts/build-host.sh` + `build-host.service` + `build-host.timer` + README)
* Config file (root or the service user only, mode 0600): `SERVER_URL`, `TOKEN`, `CORE_REPO` (local clone path or git URL), `ALLOWED_REFS`, `WORKDIR`, `GO_IMAGE` (default from go.mod), `MSI_IMAGE`.
* Each run: `poll`; on a job, `git fetch` and check out the ref into a clean temp worktree, run `make dist VERSION=<v> COMMIT=<sha>` and `make msi` in Docker as an unprivileged user (no host network needed after module download, `--read-only` where possible, CPU/memory limits, timeout 25 min), verify its own outputs, write `SHA256SUMS`, upload chunk by chunk with retries, then `complete`. Clean up on every exit path. Never log the token. Lock file so only one build runs at a time.
* Also `build-host.sh --register` (prints a one-line status), `--once` (single poll, for cron), `--dry-run` (builds, does not upload).
