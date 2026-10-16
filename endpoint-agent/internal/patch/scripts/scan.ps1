# RivetIT patch scan. STRICTLY READ-ONLY: it asks the Windows Update Agent which updates are available and reads a few state values.
# It prints exactly ONE line of JSON. Optional input (environment): RIVETIT_PATCH_CATEGORIES (csv), RIVETIT_PATCH_MAX_ITEMS (1..1000).
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
function Clip($s, $n) { $s = [string]$s; if ($s.Length -gt $n) { $s.Substring(0, $n) } else { $s } }
function Json($o) {
    $s = ConvertTo-Json -InputObject $o -Compress -Depth 6
    [regex]::Replace($s, '[^\x00-\x7F]', { param($m) '\u' + ([int][char]$m.Value).ToString('x4') })
}
function Fail($code, $msg) {
    Write-Output (Json ([ordered]@{ v = 1; ok = $false; detail = $code; message = (Clip $msg 200) }))
    exit 0
}
$known = 'security', 'critical', 'important', 'definition', 'feature', 'driver', 'optional', 'other'
$want = @()
if ($env:RIVETIT_PATCH_CATEGORIES) {
    $want = @($env:RIVETIT_PATCH_CATEGORIES.ToLower().Split(',') | ForEach-Object { $_.Trim() } | Where-Object { $_ })
    foreach ($w in $want) { if ($known -notcontains $w) { Fail 'invalid_params' 'unknown category' } }
}
$max = 500
if ($env:RIVETIT_PATCH_MAX_ITEMS -match '^\d{1,4}$') { $max = [int]$env:RIVETIT_PATCH_MAX_ITEMS }
if ($max -lt 1) { $max = 1 }
if ($max -gt 1000) { $max = 1000 }

try {
    $session = New-Object -ComObject Microsoft.Update.Session
    $searcher = $session.CreateUpdateSearcher()
    $found = $searcher.Search('IsInstalled=0 and IsHidden=0')
} catch {
    Fail 'wu_unavailable' $_.Exception.Message
}

$state = 'ok'
$list = New-Object System.Collections.ArrayList
try {
    foreach ($u in $found.Updates) {
        $names = @()
        foreach ($c in $u.Categories) { $names += [string]$c.Name }
        $msrc = ([string]$u.MsrcSeverity).ToLower()
        $sev = 'unspecified'
        if ('critical', 'important', 'moderate', 'low' -contains $msrc) { $sev = $msrc }
        if ($names -contains 'Security Updates') { $kind = 'security' }
        elseif ($names -contains 'Critical Updates') { $kind = 'critical' }
        elseif (($names -contains 'Drivers') -or ($u.Type -eq 2)) { $kind = 'driver' }
        elseif (($names -contains 'Update Rollups') -or ($names -contains 'Service Packs')) { $kind = 'important' }
        elseif ($names -contains 'Definition Updates') { $kind = 'other' }
        else { $kind = 'optional' }
        $tags = @($kind)
        if ($names -contains 'Definition Updates') { $tags += 'definition' }
        if (($names -contains 'Feature Packs') -or ($names -contains 'Upgrades')) { $tags += 'feature' }
        if ($want.Count -gt 0) {
            $hit = $false
            foreach ($w in $want) { if ($tags -contains $w) { $hit = $true } }
            if (-not $hit) { continue }
        }
        $kb = ''
        foreach ($k in $u.KBArticleIDs) { if (-not $kb) { $kb = 'KB' + [string]$k } }
        $rel = $null
        try { $rel = $u.LastDeploymentChangeTime.ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ') } catch { $rel = $null }
        $size = 0
        try { $size = [int64]$u.MaxDownloadSize } catch { $size = 0 }
        $rb = $false
        try { $rb = ([int]$u.InstallationBehavior.RebootBehavior) -ne 0 } catch { $rb = $false }
        [void]$list.Add([ordered]@{
            id = ([string]$u.Identity.UpdateID).ToLower(); kb = $kb; title = (Clip $u.Title 200); kind = $kind; severity = $sev
            size = $size; reboot_required = $rb; released_at = $rel; eula_accepted = [bool]$u.EulaAccepted
            categories = @($names); current = ''; version = ''
        })
    }
} catch {
    Fail 'wu_unavailable' $_.Exception.Message
}

$sorted = @($list | Sort-Object -Property @{ Expression = { if ('security', 'critical' -contains $_.kind) { 0 } else { 1 } } }, @{ Expression = { $_.title } })
$total = $sorted.Count
$trunc = $false
if ($total -gt $max) { $sorted = @($sorted[0..($max - 1)]); $trunc = $true }

$pending = $false
try { if ((New-Object -ComObject Microsoft.Update.SystemInfo).RebootRequired) { $pending = $true } } catch { $state = 'partial' }
foreach ($key in 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\WindowsUpdate\Auto Update\RebootRequired', 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Component Based Servicing\RebootPending') {
    if (Test-Path -LiteralPath $key) { $pending = $true }
}

$wu = 'n/a'
try {
    $svc = Get-Service -Name wuauserv
    if ($svc.Status -eq 'Running') { $wu = 'running' } else { $wu = 'stopped' }
    try { if ([string]$svc.StartType -eq 'Disabled') { $wu = 'disabled' } } catch { }
} catch { }

$last = $null
try {
    $n = $searcher.GetTotalHistoryCount()
    if ($n -gt 0) {
        foreach ($h in $searcher.QueryHistory(0, [Math]::Min($n, 200))) {
            if (($h.Operation -eq 1) -and ($h.ResultCode -eq 2)) {
                $d = [DateTime]::SpecifyKind([DateTime]$h.Date, [DateTimeKind]::Utc)
                if (($null -eq $last) -or ($d -gt $last)) { $last = $d }
            }
        }
    }
} catch { $state = 'partial' }
$lastText = $null
if ($null -ne $last) { $lastText = $last.ToString('yyyy-MM-ddTHH:mm:ssZ') }

Write-Output (Json ([ordered]@{
    v = 1; ok = $true; manager = 'wua'; scanned_at = [DateTime]::UtcNow.ToString('yyyy-MM-ddTHH:mm:ssZ'); scan_state = $state; wu_state = $wu
    reboot_pending = $pending; last_install_at = $lastText; truncated = $trunc; total = $total; updates = @($sorted)
}))
