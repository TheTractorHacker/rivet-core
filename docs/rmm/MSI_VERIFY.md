# Verifying the Windows MSI on a real Windows PC (checklist for the owner)

Status: 1.0.0-rc.16. **Everything below the line "Run these" has never been run.** The MSI template is built on Linux by msitools `wixl`; what was checked there is
structure, not behaviour. Run this on a **disposable** Windows 10/11 x64 machine or VM (take a snapshot first), as an administrator, against a **test** RivetIT server
you can enroll devices into. Report what differs; the "expected" lines are what the package was designed to do, written from knowledge of Windows Installer logs, so the exact
wording may differ. Design and limits: [AGENT_BUILD.md](AGENT_BUILD.md) ("The Windows MSI template").

## What was only parse-checked (no Windows involved)

| Checked on Linux | How | Not shown by it |
|---|---|---|
| The package builds, and builds again byte-identically | `make msi` builds twice and compares | that Windows accepts it |
| Tables and key rows | `packaging/msi/build.sh check` dumps them with `msidump` and asserts: OLE signature, the tables, `ProductVersion`, per-machine (`ALLUSERS=1`), the constant `UpgradeCode`, `MsiHiddenProperties=ENROLL_TOKEN`, a 64-bit component under `ProgramFiles64Folder`, the major-upgrade and downgrade rows and the launch condition, the six custom actions with their types and command lines, their sequence numbers (inside `InstallInitialize`..`InstallFinalize`, stop before `InstallFiles`, setup after it, uninstall before `RemoveFiles`) and conditions | there is no ICE (the Microsoft validation suite) in msitools: run `Orca`/`MsiVal2` on Windows if you want the official one |
| The agent exe in the cabinet | `msiextract`, SHA-256 compared with the input exe | that it installs |
| The summary information | `msiinfo suminfo`: Template `x64;1033`, a derived package code | |
| The payload trailer on a COPY of the template | the Go reader (`internal/embed`, `TestRealWixlMSIRoundTrip`) parses it, strips it back byte for byte; the PHP side builds it (`InstallerStamp`) and `MsiInspector` reads the platform from the real template | that `msiexec` opens a file with bytes after the compound file |
| `setup --from-msi --payload-from`, property mode, upgrade without a payload | Go tests with fake system operations | the Windows service manager, elevation, the registry |

## Run these

Set up once (PowerShell, administrator):

```powershell
$work = 'C:\msi-test'; New-Item -ItemType Directory -Force $work | Out-Null; Set-Location $work
# 1. the files: the template from the release (rivetit-agent-windows-amd64.msi + SHA256SUMS.msi), and a stamped copy from the server (Administration > Add device > Format: msi)
Get-FileHash .\rivetit-agent-windows-amd64.msi -Algorithm SHA256       # must equal SHA256SUMS.msi
Get-FileHash .\RivetIT-Agent-*-x64.msi -Algorithm SHA256              # differs from the template (the payload is appended)
# the stamped copy must start like the template and end with the footer text:
$b = [IO.File]::ReadAllBytes((Get-Item .\RivetIT-Agent-*-x64.msi).FullName)
'{0:X2}{1:X2}{2:X2}{3:X2}' -f $b[0],$b[1],$b[2],$b[3]                  # D0CF11E0
[Text.Encoding]::ASCII.GetString($b[($b.Length-16)..($b.Length-1)])    # RIVETIT-EMBED-v1
```

Every `msiexec` below uses `/l*v` so the log is there to read. The exit codes that matter: `0` success, `3010` success and a reboot is pending, `1603` fatal error during
installation (a custom action failed: the log says which and with what code), `1638` another version is installed, `1618` another install is running.

### A. The stamped MSI installs and enrolls (the main path)

```powershell
msiexec /i .\RivetIT-Agent-<client>-x64.msi /qn /norestart /l*v $work\a-install.log; $LASTEXITCODE
```

Expected: exit `0`.

* In `a-install.log`: lines for `Action start ...: RivetStopServiceInstall`, then `RivetSetupStamped` after `InstallFiles`, then `Product: RivetIT Agent -- Installation completed successfully.` Look for the `CustomActionSchedule(Action=RivetSetupStamped,ActionType=3090, ... Target=setup --silent --from-msi --payload-from "<path>"` line: **write down `<path>`**. It should be the full path of the MSI you ran (that is what `[OriginalDatabase]` means), and a failing run would show `Action ... returned actual error code N` (`2` the payload is missing, expired or damaged, `3` the server rejected the token, `5` the service could not be installed, `6` not elevated).
* `Get-Service RivetITAgent` is `Running`, start type Automatic. `C:\Program Files\RivetIT\Agent\rivetit-agent.exe` exists; `C:\Program Files\RivetIT\Agent\rivetit-agent.exe version` prints the version.
* `Get-Content 'C:\ProgramData\RivetIT\Agent\install.log'` has `setup start` with `from_msi=true` and `payload_from=<the same path>`, `configuration saved`, `enrolled`, `service installed and started`, `not registering an Add/Remove Programs entry: the MSI package owns it`.
* **No token on disk:** `Select-String -Path 'C:\ProgramData\RivetIT\Agent\*','C:\Program Files\RivetIT\Agent\*' -Pattern 'rvte1\.' -List` finds nothing; `Select-String -Path $work\a-install.log -Pattern 'rvte1\.'` finds nothing either (the property is hidden; the token travels in the payload, not in a property, so it was never on a command line).
* **The installed exe carries no payload:** read its last 16 bytes as above; they are not `RIVETIT-EMBED-v1`.
* The device appears in the server enrolled and linked (or pending approval, per your settings).
* **Add/Remove Programs shows exactly ONE entry**, "RivetIT Agent", with the version. (The exe installer adds its own entry; the MSI must not add a second.) `Get-ItemProperty HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\* | ? DisplayName -eq 'RivetIT Agent' | Select DisplayVersion,WindowsInstaller,UninstallString`: `WindowsInstaller` is 1.
* **What does the cached copy hold?** `Get-ChildItem C:\Windows\Installer\*.msi | Sort LastWriteTime | Select -Last 1` is the copy Windows Installer cached. Does it still end with `RIVETIT-EMBED-v1` (so it still holds the one-shot token)? Record yes or no: it decides whether the token lingers in `C:\Windows\Installer`.

### B. The unstamped template refuses to install without data (negative)

```powershell
msiexec /i .\rivetit-agent-windows-amd64.msi /qn /norestart /l*v $work\b.log; $LASTEXITCODE
```

Expected: exit `1603`; `b.log` shows `RivetSetupStamped ... returned actual error code 2`; the product is rolled back: no `C:\Program Files\RivetIT\Agent`, no service. (No payload and nothing to keep.)

### C. The template with public properties (managed deployment)

```powershell
msiexec /i .\rivetit-agent-windows-amd64.msi /qn /norestart /l*v $work\c.log SERVER="https://<your test server>" ENROLL_TOKEN="<a fresh token>" [CA_PEM_FILE="C:\msi-test\ca.pem"]; $LASTEXITCODE
```

Expected: exit `0`, enrolled as in A. In `c.log` the `ENROLL_TOKEN` value is masked (`ENROLL_TOKEN = ********`, from `MsiHiddenProperties`); check that the token text appears **nowhere** in the log (note: the formatted command line of the deferred action is in the install script, not necessarily in the log; look anyway and report). Then repeat with `ENROLL_TOKEN_FILE=C:\msi-test\token.txt` (a file only Administrators and SYSTEM can read). Then with a bad value (`SERVER="http://..."`, a malformed token): exit `1603`, nothing installed, no token echoed in the log.

### D. The server is unreachable while installing

Block the server (hosts file entry to 127.0.0.1) and run A again with a fresh stamped MSI. Expected: exit `0` (the MSI must not roll back), the service is registered and running, `install.log` says the server could not be reached and the service will retry; remove the block and the device enrolls on its own within a few minutes. (A **rejected** token must still fail the install with `3`.)

### E. Upgrade

Build a second template with a higher version (`make msi VERSION=1.0.1`, or the next release), stamp or use properties, then:

```powershell
msiexec /i .\RivetIT-Agent-<client>-x64.msi /qn /norestart /l*v $work\e-upgrade.log; $LASTEXITCODE
```

Expected: exit `0` (or `3010`); the log shows `WIX_UPGRADE_DETECTED`, the old product removed (`RemoveExistingProducts`; **its `RivetUninstall` action must be skipped**, because `UPGRADINGPRODUCTCODE` is set), the new files installed, `RivetSetupStamped` run. Afterwards: one Add/Remove entry at the new version, the service Running from the new exe, the device **not re-enrolled** (same device id in the server, `state.json` install id unchanged), local state under `C:\ProgramData\RivetIT\Agent` kept. Then upgrade again with the **unstamped** newer template and no properties: still exit `0` (setup keeps the enrollment: `install.log` says "MSI upgrade or repair without a payload"). Watch for a "files in use" prompt or a pending reboot: the package stops the service before replacing the exe, and if `net stop RivetITAgent` did not finish in time the install may ask for a restart. **Downgrade:** run the older MSI over the newer: expected a refusal with the message "A newer version of the RivetIT Agent is already installed." and no change.

### F. Repair

`msiexec /fa .\RivetIT-Agent-<client>-x64.msi /qn /l*v $work\f.log`: the exe is restored if you deleted it first; the service is not re-enrolled (`Installed` is true, the setup actions are skipped). Note whether the service needs a manual start afterwards.

### G. Uninstall

```powershell
$pc = (Get-ItemProperty HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\* | ? DisplayName -eq 'RivetIT Agent').PSChildName
msiexec /x $pc /qn /norestart /l*v $work\g.log; $LASTEXITCODE
```

Expected: exit `0`; the log shows `RivetStopServiceRemove` then `RivetUninstall` (not `RivetUninstallMesh`) before `RemoveFiles`; the service is gone (`Get-Service RivetITAgent` errors), `C:\Program Files\RivetIT\Agent` is gone, the Add/Remove entry is gone, `C:\ProgramData\RivetIT\Agent` is **kept** (state), and a MeshCentral agent, if one is installed, is **untouched**. Install again and uninstall with `REMOVE_MESHAGENT=1` appended: `RivetUninstallMesh` runs, and the Mesh agent is removed (the flag `-fulluninstall` is itself unverified). Also try uninstalling from Settings > Apps.

### H. Install with the Mesh option

A stamped MSI issued with "Also install the MeshCentral agent" ticked: after the RivetIT agent is in place, `install.log` shows the Mesh download; the Mesh agent installs; the check-in reports it. The same unverified Mesh points as the exe path apply (agent ids, arguments, node-id location: [REMOTE_AND_LOGS.md](REMOTE_AND_LOGS.md)).

### I. The pushed Mesh agent (not MSI related, same machine)

On the enrolled device, from the technician API: `POST endpoint_devices/{id}/mesh/install`. Expected: a job, the Mesh agent installed from your Mesh server, the node id mapped. `POST .../mesh/uninstall {"confirm":true}` removes it. Wrong pin and unknown group should fail with a clear reason and leave the RivetIT agent running.

## What to send back

For each step: the exit code, the relevant lines of the `/l*v` log, what the checks found, and the answer to the three open questions: **(1)** does `msiexec` accept the stamped MSI at all; **(2)** what `[OriginalDatabase]` is for a first install and for a repair or upgrade (the `payload_from=` line); **(3)** does the cached copy in `C:\Windows\Installer` keep the trailer.
