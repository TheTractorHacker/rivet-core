# Manual test plan: Windows state directory hardening (CORE-16)

**Status: NOT RUN.** The CORE-16 change (state directory ownership/ACL checks, in-place repair, Mesh executable policy) has only been built and `go vet`ed for Windows; its decision logic is unit-tested on Linux. It is unverified on Windows until someone executes this plan and records the results.

Use a disposable Windows 10/11 VM with a local administrator `admin1` and a standard user `user1`. Use an elevated PowerShell for everything marked (admin). Binary: the agent built from branch `security-pentest-agent`, called `rivetit-agent.exe`. Service name `RivetITAgent`; state `C:\ProgramData\RivetIT\Agent`.

Read the log with:

    Get-WinEvent -FilterHashtable @{LogName='Application'; ProviderName='RivetIT Agent'} -MaxEvents 5 | Format-List TimeCreated,Id,Message
    Get-Content C:\ProgramData\RivetIT\Agent\agent.log -Tail 30

Starting point for every case unless stated: `Stop-Service RivetITAgent -EA SilentlyContinue; rivetit-agent.exe uninstall --purge; Remove-Item -Recurse -Force C:\ProgramData\RivetIT -EA SilentlyContinue` (admin).

Inspect owner and ACL with: `Get-Acl C:\ProgramData\RivetIT\Agent | Format-List Owner,AccessToString` and `icacls C:\ProgramData\RivetIT`.

## 1. Fresh install
1. (admin) `rivetit-agent.exe install --server https://<server> --token-file <file>`.
2. Expect: exit 0, service Running. Owner of `RivetIT` and `Agent` is `BUILTIN\Administrators`; ACL is exactly `NT AUTHORITY\SYSTEM:(OI)(CI)(F)` and `BUILTIN\Administrators:(OI)(CI)(F)`, inheritance off. No event with id 1 or 2.
3. As `user1`: `dir C:\ProgramData\RivetIT\Agent` is denied.

## 2. Upgrade over an old agent's loose ACL (legacy shape, expect in-place repair)
1. (admin) Recreate what older versions left: `mkdir C:\ProgramData\RivetIT\Agent` (inherits the ProgramData ACL, owner Administrators), then give Agent the old protected ACL: `icacls C:\ProgramData\RivetIT\Agent /inheritance:r /grant:r "SYSTEM:(OI)(CI)F" "Administrators:(OI)(CI)F"`. Leave `RivetIT` itself as created (inherited, Users can add entries). Put a `config.json` in Agent as admin (`'{"server_url":"https://<server>"}' | Set-Content C:\ProgramData\RivetIT\Agent\config.json`).
2. Check the parent is loose: `icacls C:\ProgramData\RivetIT` shows `BUILTIN\Users` entries.
3. (admin) `Start-Process rivetit-agent.exe -ArgumentList 'run' -Wait` (or install over it).
4. Expect on stderr/event log: `notice: repaired the permissions of C:\ProgramData\RivetIT in place (...); owner is SYSTEM/Administrators and all N entries inside are owned by SYSTEM/Administrators` (event id 2, source `RivetIT Agent`). Afterwards `icacls C:\ProgramData\RivetIT` shows only SYSTEM and Administrators. The agent starts; no re-enrollment.

## 3. Directory owned by a named administrator (expect repair and re-own)
1. As `admin1` (named account, member of Administrators, default owner = creator): `mkdir C:\ProgramData\RivetIT\Agent` after setting the policy "System objects: Default owner for objects created by members of the Administrators group = Object creator", or run `icacls C:\ProgramData\RivetIT /setowner admin1 /T`.
2. `(Get-Acl C:\ProgramData\RivetIT\Agent).Owner` shows `<HOST>\admin1`.
3. Start the agent (admin, `rivetit-agent.exe run`).
4. Expect the same repair notice (event id 2) and `(Get-Acl ...).Owner` now `BUILTIN\Administrators`.
5. Negative: make `user1` a standard user owner instead (`icacls C:\ProgramData\RivetIT /setowner user1 /T`): expect case 4 below, not a repair.

## 4. Planted config.json / standard-user owner (expect refusal)
1. As `user1`: `mkdir C:\ProgramData\RivetIT\Agent` (works because ProgramData allows it), then `'{"mesh_agent_path":"C:\\Users\\Public\\meshagent.exe"}' | Set-Content C:\ProgramData\RivetIT\Agent\config.json`. Copy any exe to `C:\Users\Public\meshagent.exe`.
2. (admin) install the agent as in case 1.
3. Expect: install/start **fails**, nothing executed from the Public folder (check with Process Monitor or `Get-Process meshagent`). stderr and, for the service, Application event id 1 (source `RivetIT Agent`) say: `refusing to use the agent state directory: C:\ProgramData\RivetIT is owned by S-1-5-21-...-<user1 rid>, not SYSTEM or Administrators ... The directory (or a parent) was probably created by a standard user ... takeown /F ... Remove-Item ...`.
4. Variant: as admin create a correctly owned Agent directory, then as `user1` plant a file while its ACL is loose (`icacls C:\ProgramData\RivetIT\Agent /grant Users:(OI)(CI)M` as admin, then `user1` creates `config.json`; `icacls ... /remove Users`). Expect refusal naming `config.json` and its owner: `... config.json inside the directory is owned by ..., not SYSTEM, Administrators or a member of the Administrators group (planted by a standard user?)`.
5. Run the printed repair; reinstall; expect case 1.

## 5. Junction at the state directory (expect refusal)
1. (admin) `mkdir C:\Temp\real; cmd /c mklink /J C:\ProgramData\RivetIT C:\Temp\real` (or a junction for `Agent`).
2. Start the agent. Expect refusal: `... is a reparse point (symbolic link or junction)` in stderr and event id 1; nothing written under `C:\Temp\real`.

## 6. mesh_agent_path outside Program Files (expect not executed)
1. Fresh install (case 1). (admin) edit `config.json`: `"mesh_agent_path": "C:\\Tools\\meshagent.exe"`; place a harmless `meshagent.exe` there that creates `C:\Tools\ran.txt`. Restart the service.
2. Expect no `ran.txt`, and in `agent.log`: `refusing to run the Mesh agent executable ... path=C:\Tools\meshagent.exe reason="... is not below an allowed install location"`, once.
3. Move it to `C:\Program Files\Mesh Agent\MeshAgent.exe` (admin-created, default ACL): expect it is accepted. Then `icacls "C:\Program Files\Mesh Agent" /grant Users:(OI)(CI)M` (admin): expect refusal `grants ... write access`.

Record for each case: date, agent build, pass/fail, the exact log lines seen. Only after all six pass can CORE-16 be called verified.
