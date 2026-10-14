$ErrorActionPreference = 'Continue'
Write-Output '== Microsoft Defender status =='
if (-not (Get-Command Get-MpComputerStatus -ErrorAction SilentlyContinue)) { Write-Output 'NOT AVAILABLE: Defender cmdlets are missing'; exit 0 }
try {
  $s = Get-MpComputerStatus -ErrorAction Stop
  function L($k, $v) { '{0,-28}: {1}' -f $k, $v }
  if (-not $s.AMServiceEnabled -and -not $s.AntivirusSignatureVersion) { Write-Output 'NOTE: the Defender antimalware service is not running; another antivirus product is probably active (Defender then reports no signatures).' }
  L 'Antimalware service' $s.AMServiceEnabled
  L 'Real-time protection' $s.RealTimeProtectionEnabled
  L 'On-access protection' $s.OnAccessProtectionEnabled
  L 'Behavior monitor' $s.BehaviorMonitorEnabled
  L 'Tamper protection' $s.IsTamperProtected
  L 'Product version' $s.AMProductVersion
  L 'Signature version' $s.AntivirusSignatureVersion
  L 'Signature last updated' $s.AntivirusSignatureLastUpdated
  $age = $s.AntivirusSignatureAge; if ($age -ge 65535 -or -not $s.AntivirusSignatureVersion) { $age = 'n/a' }
  L 'Signature age (days)' $age
  L 'Last quick scan' $s.QuickScanEndTime
  L 'Last full scan' $s.FullScanEndTime
} catch {
  Write-Output ('NOT AVAILABLE: Defender status could not be read (' + $_.Exception.Message + ')')
}
exit 0
