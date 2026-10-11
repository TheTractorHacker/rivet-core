$ErrorActionPreference = 'Continue'
$DryRun = $true; if ($null -ne $dry_run -and $dry_run -is [bool]) { $DryRun = $dry_run }
Write-Output '== Force time resync (Windows) =='
$w = Get-Command w32tm.exe -ErrorAction SilentlyContinue
if (-not $w) { Write-Output 'NOT AVAILABLE: w32tm.exe not found'; exit 0 }
$svc = Get-Service -Name W32Time -ErrorAction SilentlyContinue
if (-not $svc) { Write-Output 'NOT AVAILABLE: W32Time service not found'; exit 0 }
function Get-Off {
    $s = & $w.Source /query /status 2>&1 | Out-String
    $m = [regex]::Match($s, '(?im)^\s*Phase Offset:\s*(.+)$')
    $l = [regex]::Match($s, '(?im)^\s*Last Successful Sync Time:\s*(.+)$')
    $o = 'unknown'; if ($m.Success) { $o = $m.Groups[1].Value.Trim() }
    $t = 'unknown'; if ($l.Success) { $t = $l.Groups[1].Value.Trim() }
    return ('offset {0}, last sync {1}' -f $o, $t)
}
Write-Output ("W32Time before: {0} ({1})" -f $svc.Status, $svc.StartType)
if ($svc.StartType -eq 'Disabled') { Write-Output '[SKIP] W32Time is disabled; not changing its configuration'; exit 0 }
if ($svc.Status -eq 'Running') { Write-Output ("Before: {0}" -f (Get-Off)) }
if ($DryRun) {
    if ($svc.Status -ne 'Running') { Write-Output '[DRY-RUN] would start the W32Time service' }
    Write-Output '[DRY-RUN] would run: w32tm /resync /force'
    exit 0
}
if ($svc.Status -ne 'Running') {
    try { Start-Service -Name W32Time -ErrorAction Stop; $svc.WaitForStatus('Running', [TimeSpan]::FromSeconds(30)); Write-Output '[DONE] started W32Time'; Write-Output ("Before: {0}" -f (Get-Off)) }
    catch { Write-Output ("[SKIP] could not start W32Time: {0}" -f $_.Exception.Message); exit 3 }
}
$o = & $w.Source /resync /force 2>&1 | Out-String
$rc = $LASTEXITCODE
Write-Output ($o.TrimEnd())
if ($rc -eq 0) { Write-Output '[DONE] resync requested' } else { Write-Output ("[SKIP] w32tm /resync returned {0} (no reachable time source?)" -f $rc) }
Start-Sleep -Seconds 3
Write-Output ("After: {0}" -f (Get-Off))
exit 0
