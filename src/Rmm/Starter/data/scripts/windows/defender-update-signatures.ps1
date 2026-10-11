$ErrorActionPreference = 'Continue'
$DryRun = $true; if ($null -ne $dry_run -and $dry_run -is [bool]) { $DryRun = $dry_run }
Write-Output '== Defender signature update =='
if (-not (Get-Command Update-MpSignature -ErrorAction SilentlyContinue)) { Write-Output 'NOT AVAILABLE: Defender cmdlets are missing'; exit 0 }
$st = $null
try { $st = Get-MpComputerStatus -ErrorAction Stop } catch { Write-Output 'NOT AVAILABLE: Defender is not active (another antivirus may be installed)'; exit 0 }
Write-Output ('Current signature version: ' + $st.AntivirusSignatureVersion + ' (age ' + $st.AntivirusSignatureAge + ' days)')
if ($DryRun) { Write-Output '[DRY-RUN] would run Update-MpSignature'; exit 0 }
try {
  Update-MpSignature -ErrorAction Stop
  $n = Get-MpComputerStatus -ErrorAction SilentlyContinue
  Write-Output ('[DONE] signatures updated; version now ' + $n.AntivirusSignatureVersion)
  exit 0
} catch {
  Write-Output ('[SKIP] signature update failed: ' + $_.Exception.Message)
  exit 1
}
