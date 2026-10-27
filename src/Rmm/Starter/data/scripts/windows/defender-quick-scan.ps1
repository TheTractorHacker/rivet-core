$ErrorActionPreference = 'Continue'
$DryRun = $true; if ($null -ne $dry_run -and $dry_run -is [bool]) { $DryRun = $dry_run }
Write-Output '== Defender quick scan =='
if (-not (Get-Command Start-MpScan -ErrorAction SilentlyContinue)) { Write-Output 'NOT AVAILABLE: Defender cmdlets are missing'; exit 0 }
$st = $null
try { $st = Get-MpComputerStatus -ErrorAction Stop } catch { Write-Output 'NOT AVAILABLE: Defender is not active (another antivirus may be installed)'; exit 0 }
if (-not $st.AMServiceEnabled) { Write-Output 'NOT AVAILABLE: Defender antimalware service is not enabled'; exit 0 }
if ($DryRun) { Write-Output '[DRY-RUN] would start a Defender QuickScan'; exit 0 }
try {
  $job = Start-Job -ScriptBlock { Start-MpScan -ScanType QuickScan -ErrorAction Stop }
  Write-Output '[DONE] quick scan started'
  if (Wait-Job -Job $job -Timeout 540) {
    Receive-Job -Job $job -ErrorAction SilentlyContinue | Out-Null
    if ($job.State -eq 'Completed') { Write-Output '[DONE] quick scan finished' } else { Write-Output ('[SKIP] scan job ended with state ' + $job.State) }
  } else {
    Write-Output '[SKIP] wait limit reached; the scan may still be running, check Defender status later'
  }
  Remove-Job -Job $job -Force -ErrorAction SilentlyContinue
  exit 0
} catch {
  Write-Output ('Failed to start scan: ' + $_.Exception.Message)
  exit 3
}
