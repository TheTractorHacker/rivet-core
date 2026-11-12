# Agent state directory and Mesh executable trust (Windows, CORE-16)

Pentest 2026-11-08, CORE-16. Applies to the Windows endpoint agent from 1.0.0-rc.62. Not yet run on a real Windows host: the decision logic is unit-tested on Linux, the Windows calls are built and vetted only.

## Why

On Windows a standard user may create folders under `C:\ProgramData` and owns what they create; an owner can always rewrite the permissions. A user could therefore create `C:\ProgramData\RivetIT\Agent\config.json` (for example with `mesh_agent_path`, `server_url`, `ca_file`) before the agent is installed, and the SYSTEM service would read it. The agent now refuses to use a state directory it cannot verify.

## What the agent checks

* `%ProgramData%\RivetIT` and `%ProgramData%\RivetIT\Agent` (or the `--state-dir` you chose; for a custom path only that directory) must not be a reparse point (junction or link), must be owned by SYSTEM, Administrators or TrustedInstaller, and must not grant write, delete, change-permissions or take-ownership to anyone else.
* A missing directory is created atomically with owner Administrators and a protected DACL (SYSTEM and Administrators full control, nobody else). A directory that passes is re-protected (idempotent).
* The Mesh agent executable (`mesh_agent_path`, or the default `Program Files\Mesh Agent\MeshAgent.exe`) must lie below `%ProgramFiles%`, `%ProgramW6432%` or `%ProgramFiles(x86)%`, and every folder below that root and the file itself must pass the same ownership and permission test. A `mesh_agent_path` outside Program Files is no longer run. The agent has no Authenticode verification, so location plus ownership is the whole policy.

## Upgrading an existing install (no re-enrollment needed in the normal case)

What earlier versions produced: `ensureDir` did `MkdirAll` and then set a protected DACL (SYSTEM and Administrators only) on the leaf `Agent` folder, so `Agent` is already tight. The parent `%ProgramData%\RivetIT` was created by the same `MkdirAll` and inherited the ProgramData ACL (Users may create files and folders there); `install-windows.ps1` did the same through `New-Item`. The MSI creates no ProgramData folder itself: its setup action runs the agent, which creates the directory, so going forward every route (MSI, setup, `install`, the script) gets the protected DACL and an Administrators owner.

The upgraded agent therefore tells the benign legacy shape from the attack by the owner, not by the DACL (a folder a user pre-creates is owned by that user; one created by SYSTEM or an elevated installer is owned by SYSTEM or Administrators even with a broad inherited ACL):

| Shape | Result |
| --- | --- |
| Owner SYSTEM/Administrators/TrustedInstaller, DACL tight | used as is |
| Owner trusted, DACL looser than wanted (inherited ProgramData ACL, NULL DACL) | the agent, running as SYSTEM, applies the protected DACL itself, then verifies every file and folder inside is owned by a trusted SID and none is a reparse point; one notice line says it repaired (stderr and, for the service, Application event log source `RivetIT Agent`, event id 2) |
| Owner is not trusted, or the directory is a junction/link | refused, loudly, with the repair command |
| Owner trusted but a file or folder inside is owned by someone else (planted while the directory was still loose) or is a link | refused, loudly, naming the entry; never adopted |

The tree check runs on every start, so a planted file is caught even after the DACL has been tightened. Because it refuses on any foreign-owned entry, a user-created subfolder in the legacy `RivetIT` parent also needs the repair below.

## What an operator sees on a refusal

If the directory is fine (created by the installer, which runs elevated) nothing changes. If it fails the check, the agent does not start and does not read anything from it. The reason and the repair command are written

* to stderr (`rivetit-agent run`, `install`, `enroll`), and
* for the service, to the Windows Application event log, source `RivetIT Agent`, event id 1 (Event Viewer, Windows Logs, Application), because a service has no console and its usual log file lives in the refused directory.

The message is `refusing to use the agent state directory: <what is wrong>. ...` followed by the repair. Typical causes: the folder is owned by a user account (created before the agent was installed, or left behind by `uninstall --purge` and re-created by a user), or an administrator's `icacls` change granted Users write access.

Repair (elevated PowerShell). Recommended, because the contents cannot be trusted; the device must be enrolled again:

    Stop-Service RivetITAgent -ErrorAction SilentlyContinue
    takeown /F "C:\ProgramData\RivetIT" /R /D Y
    Remove-Item -Recurse -Force "C:\ProgramData\RivetIT"
    # then run the installer / "rivetit-agent install" again

Only if you have inspected every file yourself and want to keep the enrollment, take ownership and reset the permissions instead:

    takeown /F "C:\ProgramData\RivetIT" /R /D Y
    icacls "C:\ProgramData\RivetIT" /setowner "*S-1-5-18" /T /C
    icacls "C:\ProgramData\RivetIT" /inheritance:r /grant:r "*S-1-5-18:(OI)(CI)F" "*S-1-5-32-544:(OI)(CI)F" /T /C

A false refusal is possible where a policy makes the object creator (a named administrator account) the default owner of folders instead of the Administrators group; the repair above fixes that too.

For the Mesh executable a refusal is logged once per path and reason at error level in the agent log (`refusing to run the Mesh agent executable ...`); Mesh features stay off until the file is under Program Files with normal permissions.

`scripts/install-windows.ps1` applies the same owner test to `RivetIT`, `RivetIT\Setup` and `RivetIT\Agent` before it downloads and runs anything.

# Agent file_put on Linux (CORE-21)

No operator action. The temporary file is now created, chmodded and moved relative to a directory file descriptor, so a local user who can write the destination folder can no longer redirect the agent's chmod or rename with a symbolic link. A swap is reported as `commit_failed`; the protocol and result strings are unchanged.

# Go toolchain floor (CORE-25)

`endpoint-agent/go.mod` now says `go 1.27.4`; `make` refuses an older Go (`check-go`), CI uses `check-latest`, and `scripts/build-host.sh` builds in `golang:1.27.4` (it already pulled its image tag). Agent binaries built before this change (go1.27.1) are unchanged: rebuild and republish them to get the standard-library fixes.
