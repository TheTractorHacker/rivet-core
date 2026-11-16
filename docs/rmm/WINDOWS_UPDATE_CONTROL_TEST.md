# Windows Update control: manual test plan (run on a real Windows 10 and Windows 11 machine)

**Status: NOT RUN.** The registry writes of the `patch_configure` job (RivetCore 1.0.0-rc.65, issue #126) have been compiled for Windows (`GOOS=windows go vet`) and their decision logic is unit tested on Linux against a fake registry. They have **never been executed on a Windows machine**. This page is what must be done, with the exact values to expect, before any policy with the `windows_*` settings is assigned beyond one test machine. Write the results (date, build, agent version, pass/fail per step, anything unexpected) at the bottom, like [WINDOWS_VERIFICATION.md](WINDOWS_VERIFICATION.md). Design and the value table: [PATCHING.md section 12](PATCHING.md#12-windows-update-control-manage-windows-update-hold-the-feature-version).

## 0. Setup

* One **non-domain** test PC per OS (Windows 10 22H2 and Windows 11, ideally one on 24H2 and one on 25H2), a snapshot or system restore point taken first, local administrator, the agent build that contains `patch_configure` enrolled and linked, a server with `patching` and `jobs` on. Keep `patch_live_install` at 0 until step 2 is done.
* An administrator PowerShell on the PC, to read the registry (never to write it, except where a step says so):

```powershell
$wu = 'HKLM:\SOFTWARE\Policies\Microsoft\Windows\WindowsUpdate'
function Show { 'WU';  if (Test-Path $wu) { Get-ItemProperty $wu | Format-List * }; 'AU'; if (Test-Path "$wu\AU") { Get-ItemProperty "$wu\AU" | Format-List * }
                'MARKER'; Get-ItemProperty 'HKLM:\SOFTWARE\RivetIT\WindowsUpdateControl' -ErrorAction SilentlyContinue | Format-List State }
(Get-ItemProperty 'HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion') | Select DisplayVersion, CurrentBuildNumber, InstallationType
Show
```

* In the server (as an administrator): create a patch policy `wu-test` assigned to the **device** only. Use the patch policies API or the edition page. To trigger a job by hand: `POST endpoint_devices/{id}/patches/scan` (reads state), `POST endpoint_devices/{id}/patches/configure` (`{"mode":"dry_run"}` or `{"mode":"apply","confirm":true}`), and `GET endpoint_devices/{id}/patches` to read `summary.windows_update`.

**Baseline (before anything):** `Show` prints no `NoAutoUpdate`, `AUOptions`, `TargetReleaseVersion*`, `ProductVersion` or `DeferQualityUpdates*` and no MARKER. If the PC already has any value in those two keys (a GPO, a security baseline), that is the "externally managed" case: use step 9 for it and a clean PC for the others.

## 1. The scan reports the state

1. Scan the device. Expect `summary.windows_update`: `feature_version` equal to `DisplayVersion` (for example `24H2`), `build` equal to `CurrentBuildNumber`, `product` `Windows 11` (build 22000 and later) or `Windows 10`, `external` empty, `policy` empty, `core_keys` empty, `status` `off`, `capable` true.
2. Windows Server or a build with no `DisplayVersion` (2004 and older): `feature_version` is null.

## 2. Dry run changes nothing

1. Set the policy `windows_update_mode` = `managed`, `windows_feature_target` = `25H2`, `windows_quality_defer_days` = 7. Scan again. `status` `pending`.
2. `POST .../patches/configure {"mode":"dry_run"}`. Wait for the check-in. Expect `last_configure.outcome` `would_change` with seven changes (`no_auto_update` null to 0, `au_options` null to 2, `target_release_version` null to 1, `target_release_version_info` null to `25H2`, `product_version` null to the product, `defer_quality_updates` null to 1, `defer_quality_days` null to 7).
3. `Show` again: **identical to the baseline** (nothing written, no MARKER).

## 3. Managed mode (apply)

Policy: `windows_update_mode` `managed`, the other two at defaults. Set `patch_live_install` to 1 (or `apply` with `confirm`). Run `configure` apply.

* Expect `outcome` `changed`, two changes. `Show`: AU key holds `NoAutoUpdate` = 0 and `AUOptions` = 2 **and nothing else**; WU key empty; MARKER present with those two entries (`wrote`, no `prev`).
* Settings > Windows Update shows "Some settings are managed by your organization" and updates are not downloaded by themselves. Click "Check for updates": Windows should only notify (**record what it actually shows**: this is the medium-confidence part).
* After the next scan: `status` `in_sync`, `core_keys` `["au_options","no_auto_update"]`.
* Approve one update in the Approvals page and run a real `patch_install` (or wait for the scheduler): **it must still install** through Core while Windows Update is in notify mode. (If Windows refuses to install an approved update through the API in notify mode, this design is wrong: stop and report.)
* Check that Microsoft Defender definitions still update (`Get-MpComputerStatus | Select AntivirusSignatureLastUpdated`) and that Microsoft Store apps and Office still update by their own means. Record the result.
* Run `configure` apply again: `outcome` `unchanged`, no write (Process Monitor, or the registry's last-write time of the key, unchanged).

## 4. Pin to a named version (25H2)

On a PC running 24H2 (or older), policy `windows_feature_target` `25H2`, mode `leave`. Apply.

* `Show`: WU key holds `TargetReleaseVersion` = 1, `TargetReleaseVersionInfo` = `25H2`, `ProductVersion` = `Windows 11` (on Windows 10: `Windows 10` and a version such as `22H2`); AU key is **not** created or touched; MARKER lists the three values and `pinned` `25H2`, no `held`.
* Check for updates: Windows must offer 25H2 (and nothing newer such as 26H2). Do **not** install it unless you want to.
* Change the target to `26H2` and apply: `outcome` `changed`, one change (`target_release_version_info` 25H2 to 26H2). A PC that cannot reach the named version simply stays where it is.
* Target a version **older** than the one the PC runs (for example `24H2` on a 25H2 PC): Windows does not downgrade; after the next scan the alert `patch.feature_version` is `fail` ("runs Windows 25H2 but its patch policy holds it at 24H2"); it recovers when the target is raised to `25H2`.

## 5. Hold

On a PC running 25H2, policy `windows_feature_target` `hold`. Apply.

* `Show`: `TargetReleaseVersionInfo` = `25H2` (what the PC runs), `TargetReleaseVersion` = 1, `ProductVersion`; MARKER `pinned` `25H2`, `held` true. Scan: `core.pinned` `25H2`, `target_version` `25H2`.
* Apply again: `unchanged`. Check for updates: Windows must not offer a newer feature version, only quality updates.
* On a PC with no `DisplayVersion` (build 19041 or older, or a build that reports no named version): `outcome` `cannot_hold`, `detail` `unknown_feature_version`, nothing written.
* On Windows Server: `cannot_hold`, `feature_target_needs_a_client_windows`; `managed` alone still works.

## 6. Quality deferral

Policy `windows_quality_defer_days` 7. Apply: `DeferQualityUpdates` = 1, `DeferQualityUpdatesPeriodInDays` = 7 in the WU key, nothing in AU. Settings > Windows Update > Advanced options should not show a pause. A value of 0: both removed (`restored`).

## 7. Restore

With some of steps 3 to 6 applied (say managed + 25H2 + 7 days), first create a **decoy** value that is not Core's: `New-ItemProperty "$wu\DecoyOther" -Name X -Value 1 -Force` (a sub key) and note `Show`. Set the three policy settings back to `leave` / `none` / 0. Expect `status` `restore_pending`, then `configure` apply: `outcome` `restored`, changes with `to` null, **the seven values gone**, MARKER gone, the decoy untouched, the key itself still there (empty). Settings > Windows Update no longer says "managed by your organization". Apply again: `unchanged`.

## 8. Dry run of a restore, and a changed value

1. Apply managed again; with the policy back at `leave`, run a **dry run**: `would_restore`, registry unchanged.
2. Apply managed. Then, as a stand-in for a group policy refresh, change **by hand** `AUOptions` to 4. Scan: `external` `["changed_since"]`, `status` `externally_managed`, no job is queued. Run `configure` (apply): `externally_managed`, **no write** (`NoAutoUpdate` and `AUOptions` = 4 unchanged). Set the policy to `leave` and apply: `restored`, `NoAutoUpdate` removed, **`AUOptions` stays 4** (somebody else's value), MARKER gone.

## 9. A machine something else manages (GPO / WSUS / MDM)

Use a PC that has (or on which you set with `gpedit.msc`, **Local Group Policy**, Computer Configuration > Administrative Templates > Windows Components > Windows Update) one of:

1. **"Configure Automatic Updates" = Enabled** (writes `AU\NoAutoUpdate`, `AUOptions`): scan shows `external` `["gpo"]`; `configure` apply with policy `managed` answers `externally_managed`, **no value changes**, the audit text says `[managed by: gpo]`; the server queues no further job for a day.
2. **"Specify intranet Microsoft update service location"** (`UseWUServer` = 1, `WUServer`): `external` `["wsus"]`.
3. Any other Windows Update policy value, for example "Select when Preview Builds and Feature Updates are received": `external` `["gpo"]`.
4. **MDM (Intune):** a machine enrolled with an Update ring: `external` `["mdm"]` (**record what is under `HKLM\SOFTWARE\Microsoft\PolicyManager\current\device\Update` on a machine that is not enrolled**: if it carries `_ProviderSet` values anyway, the detection is wrong and must be fixed).
5. Remove the local policy (`gpedit.msc` back to Not configured, `gpupdate /force`, delete the leftover values), scan: `external` empty; the next job proceeds.

## 10. Safety and failure paths

* **Kill switch:** set `disable_patching` in the agent configuration: `patch:configure`, `patch:scan`, `patch:install` and `job:patch_*` are no longer announced, a queued `patch_configure` ends `disabled_locally`.
* **Locked key:** deny the SYSTEM account write on the WU key (ACL), apply: the job ends `failed` (`registry_error`), nothing is left behind (no marker entry for a value that was not written is acceptable; no half set of values), the audit row says `Patch Configure Failed`. Remove the ACL change.
* **Unreadable marker:** set the MARKER `State` value to `not json`: with a clean policy area apply `managed`: marker replaced, values written; with `leave`: the marker is removed and nothing else touched.
* **Reboot:** none of the steps may restart the PC or the agent.

## 11. Results

| Date | Windows (build) | Agent | Steps | Result | Notes |
|---|---|---|---|---|---|
| (not run) | | | | | |
