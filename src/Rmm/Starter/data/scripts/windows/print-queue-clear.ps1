$ErrorActionPreference = 'Continue'
$DryRun = $true; if ($null -ne $dry_run -and $dry_run -is [bool]) { $DryRun = $dry_run }
Write-Output '== Clear print queue (Windows) =='
$svc = Get-Service -Name Spooler -ErrorAction SilentlyContinue
if (-not $svc) { Write-Output 'NOT AVAILABLE: Spooler service not found'; exit 0 }
$dir = Join-Path $env:SystemRoot 'System32\spool\PRINTERS'
if ($dir.Length -lt 8 -or -not (Test-Path -LiteralPath $dir)) { Write-Output ("NOT AVAILABLE: spool folder missing ({0})" -f $dir); exit 0 }
$files = @(Get-ChildItem -LiteralPath $dir -File -Force -ErrorAction SilentlyContinue | Where-Object { $_.Extension -in '.SPL', '.SHD' })
$bytes = 0; foreach ($f in $files) { $bytes += $f.Length }
Write-Output ("Spooler state: {0}" -f $svc.Status)
Write-Output ("Spool files (*.SPL, *.SHD): {0}, {1} bytes" -f $files.Count, $bytes)
if ($DryRun) { Write-Output ("[DRY-RUN] would stop Spooler, delete {0} spool file(s), then start Spooler" -f $files.Count); exit 0 }
if ($files.Count -eq 0 -and $svc.Status -eq 'Running') { Write-Output '[SKIP] queue already empty'; exit 0 }
$deleted = 0; $failed = 0; $to = [TimeSpan]::FromSeconds(60)
try {
    try { if ($svc.Status -ne 'Stopped') { Stop-Service -Name Spooler -Force -ErrorAction Stop; $svc.WaitForStatus('Stopped', $to); Write-Output '[DONE] stopped Spooler' } }
    catch { Write-Output ("[SKIP] stop failed: {0}" -f $_.Exception.Message) }
    foreach ($f in $files) {
        try { Remove-Item -LiteralPath $f.FullName -Force -ErrorAction Stop; $deleted++ } catch { $failed++; Write-Output ("[SKIP] could not delete {0}: {1}" -f $f.Name, $_.Exception.Message) }
    }
    Write-Output ("[DONE] deleted {0} file(s), {1} failed" -f $deleted, $failed)
} finally {
    try { Start-Service -Name Spooler -ErrorAction Stop; $svc.WaitForStatus('Running', $to); Write-Output '[DONE] started Spooler' }
    catch { Write-Output ("[SKIP] start failed: {0}" -f $_.Exception.Message) }
}
$after = Get-Service -Name Spooler -ErrorAction SilentlyContinue
Write-Output ("Spooler state after: {0}" -f $after.Status)
if ($after.Status -ne 'Running') { exit 3 }
exit 0
