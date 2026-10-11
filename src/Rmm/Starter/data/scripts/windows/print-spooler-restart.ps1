$ErrorActionPreference = 'Continue'
$DryRun = $true; if ($null -ne $dry_run -and $dry_run -is [bool]) { $DryRun = $dry_run }
Write-Output '== Restart Print Spooler (Windows) =='
$svc = Get-Service -Name Spooler -ErrorAction SilentlyContinue
if (-not $svc) { Write-Output 'NOT AVAILABLE: Spooler service not found'; exit 0 }
Write-Output ("State before: {0}, start type: {1}" -f $svc.Status, $svc.StartType)
if ($svc.StartType -eq 'Disabled') { Write-Output '[SKIP] Spooler is disabled; leaving it alone'; exit 0 }
if ($DryRun) { Write-Output '[DRY-RUN] would restart the Print Spooler service (active print jobs may be interrupted)'; exit 0 }
$to = [TimeSpan]::FromSeconds(60)
try {
    if ($svc.Status -ne 'Stopped') { Stop-Service -Name Spooler -Force -ErrorAction Stop; $svc.WaitForStatus('Stopped', $to); Write-Output '[DONE] stopped Spooler' }
} catch { Write-Output ("[SKIP] stop failed: {0}" -f $_.Exception.Message) }
try {
    Start-Service -Name Spooler -ErrorAction Stop; $svc.WaitForStatus('Running', $to); Write-Output '[DONE] started Spooler'
} catch { Write-Output ("[SKIP] start failed: {0}" -f $_.Exception.Message) }
$after = Get-Service -Name Spooler -ErrorAction SilentlyContinue
Write-Output ("State after: {0}" -f $after.Status)
if ($after.Status -ne 'Running') { exit 3 }
exit 0
