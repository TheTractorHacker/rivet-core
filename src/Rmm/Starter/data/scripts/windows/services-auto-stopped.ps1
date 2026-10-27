$ErrorActionPreference = 'Continue'
Write-Output '== Automatic services that are stopped (Windows) =='
$benign = @('sppsvc', 'RemoteRegistry', 'gupdate', 'gupdatem', 'edgeupdate', 'edgeupdatem', 'MapsBroker', 'TrustedInstaller', 'cdpsgshims', 'WbioSrvc')
try {
    $svcs = Get-CimInstance Win32_Service -Filter "StartMode='Auto' AND State<>'Running'" -ErrorAction Stop
} catch { Write-Output ("NOT AVAILABLE: {0}" -f $_.Exception.Message); exit 0 }
$rows = @()
foreach ($s in $svcs) {
    if ($benign -contains $s.Name) { continue }
    if ($s.Name -like 'clr_optimization*') { continue }
    $delayed = (Get-ItemProperty -Path ("HKLM:\SYSTEM\CurrentControlSet\Services\{0}" -f $s.Name) -Name DelayedAutostart -ErrorAction SilentlyContinue).DelayedAutostart
    $trig = Test-Path -Path ("HKLM:\SYSTEM\CurrentControlSet\Services\{0}\TriggerInfo" -f $s.Name)
    if ($delayed -eq 1 -and $trig) { continue }
    if ($trig) { continue }
    $rows += [pscustomobject]@{ Name = $s.Name; Display = $s.DisplayName; State = $s.State; ExitCode = $s.ExitCode; DelayedStart = [bool]($delayed -eq 1) }
}
Write-Output ("Stopped automatic services (excluding trigger-start and known benign): {0}" -f $rows.Count)
if ($rows.Count -gt 0) { $rows | Sort-Object Name | Select-Object -First 100 | Format-Table -AutoSize | Out-String -Width 200 }
if ($rows.Count -gt 100) { Write-Output '(truncated)' }
exit 0
