# RivetIT patch install. Input (environment, validated by the agent AND here): RIVETIT_PATCH_IDS (csv of lower-case UpdateID GUIDs),
# RIVETIT_PATCH_MODE (dry_run | install, default dry_run), RIVETIT_PATCH_MAX_MINUTES (1..240, default 60).
# It never reboots the machine and never accepts a license agreement: an update whose EULA is not accepted is reported as eula_required.
# It prints exactly ONE line of JSON.
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
function Clip($s, $n) { $s = ([string]$s) -replace '[\r\n\t]+', ' '; if ($s.Length -gt $n) { $s.Substring(0, $n) } else { $s } }
function Json($o) {
    $s = ConvertTo-Json -InputObject $o -Compress -Depth 6
    [regex]::Replace($s, '[^\x00-\x7F]', { param($m) '\u' + ([int][char]$m.Value).ToString('x4') })
}
function Fail($code, $msg) {
    Write-Output (Json ([ordered]@{ v = 1; ok = $false; detail = $code; message = (Clip $msg 200) }))
    exit 0
}
function Hex($hr) { '0x{0:X8}' -f [int]$hr }

$mode = $env:RIVETIT_PATCH_MODE
if (-not $mode) { $mode = 'dry_run' }
if (('dry_run', 'install') -notcontains $mode) { Fail 'invalid_params' 'bad mode' }
$ids = @()
if ($env:RIVETIT_PATCH_IDS) { $ids = @($env:RIVETIT_PATCH_IDS.Split(',')) }
if ($ids.Count -lt 1 -or $ids.Count -gt 40) { Fail 'invalid_params' 'bad ids' }
foreach ($i in $ids) { if ($i -cnotmatch '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$') { Fail 'invalid_params' 'bad id' } }
$minutes = 60
if ($env:RIVETIT_PATCH_MAX_MINUTES -match '^\d{1,3}$') { $minutes = [int]$env:RIVETIT_PATCH_MAX_MINUTES }
if ($minutes -lt 1) { $minutes = 1 }
if ($minutes -gt 240) { $minutes = 240 }
$deadline = (Get-Date).AddMinutes($minutes)

try {
    $session = New-Object -ComObject Microsoft.Update.Session
    $session.ClientApplicationID = 'RivetIT'
    $found = $session.CreateUpdateSearcher().Search('IsInstalled=0 and IsHidden=0')
} catch {
    Fail 'wu_unavailable' $_.Exception.Message
}
$byId = @{}
foreach ($u in $found.Updates) { $byId[([string]$u.Identity.UpdateID).ToLower()] = $u }

$results = New-Object System.Collections.ArrayList
$anyReboot = $false
function Add-Result($id, $title, $result, $code, $detail, $reboot) {
    [void]$results.Add([ordered]@{ id = $id; title = (Clip $title 200); result = $result; code = [string]$code; detail = (Clip $detail 200); reboot_required = [bool]$reboot })
}

foreach ($id in $ids) {
    $u = $byId[$id]
    if ($null -eq $u) { Add-Result $id '' 'not_applicable' '' 'no longer offered by Windows Update' $false; continue }
    $title = [string]$u.Title
    if (-not $u.EulaAccepted) { Add-Result $id $title 'eula_required' '' 'the license terms of this update are not accepted' $false; continue }
    if ($mode -eq 'dry_run') { Add-Result $id $title 'would_install' '' '' $false; continue }
    $ui = $false
    try { $ui = [bool]$u.InstallationBehavior.CanRequestUserInput } catch { }
    if ($ui) { Add-Result $id $title 'skipped' '' 'user_input_required' $false; continue }
    if ((Get-Date) -gt $deadline) { Add-Result $id $title 'skipped' '' 'time_budget_exhausted' $false; continue }
    try {
        $one = New-Object -ComObject Microsoft.Update.UpdateColl
        [void]$one.Add($u)
        $dl = $session.CreateUpdateDownloader()
        $dl.Updates = $one
        $dr = $dl.Download()
        if ($dr.ResultCode -ne 2) { Add-Result $id $title 'failed' (Hex $dr.HResult) 'download failed' $false; continue }
        $inst = $session.CreateUpdateInstaller()
        $inst.Updates = $one
        $inst.AllowSourcePrompts = $false
        $inst.ForceQuiet = $true
        $ir = $inst.Install()
        $one0 = $ir.GetUpdateResult(0)
        $rb = [bool]$one0.RebootRequired
        if ($rb) { $anyReboot = $true }
        if ($one0.ResultCode -eq 2) {
            if ($rb) { Add-Result $id $title 'needs_reboot' (Hex $one0.HResult) 'installed, restart required' $true }
            else { Add-Result $id $title 'installed' (Hex $one0.HResult) '' $false }
        } else {
            Add-Result $id $title 'failed' (Hex $one0.HResult) ('result code ' + [string]$one0.ResultCode) $rb
        }
    } catch {
        Add-Result $id $title 'failed' (Hex $_.Exception.HResult) $_.Exception.Message $false
    }
}

try { if ((New-Object -ComObject Microsoft.Update.SystemInfo).RebootRequired) { $anyReboot = $true } } catch { }
$bad = @($results | Where-Object { 'failed', 'eula_required', 'not_found', 'locked' -contains $_.result })
Write-Output (Json ([ordered]@{ v = 1; ok = ($bad.Count -eq 0); mode = $mode; manager = 'wua'; results = @($results); reboot_required = $anyReboot; truncated = $false }))
