$ErrorActionPreference = 'Continue'
$DryRun = $true; if ($null -ne $dry_run -and $dry_run -is [bool]) { $DryRun = $dry_run }
$Delay = 15; if ($null -ne $delay_minutes) { try { $Delay = [int]$delay_minutes } catch { Write-Output "invalid delay_minutes"; exit 2 } }
if ($Delay -lt 1 -or $Delay -gt 1440) { Write-Output "delay_minutes must be 1..1440"; exit 2 }
Write-Output '== Restart if pending =='
$reasons = @()
if (Test-Path 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Component Based Servicing\RebootPending') { $reasons += 'CBS RebootPending' }
if (Test-Path 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\WindowsUpdate\Auto Update\RebootRequired') { $reasons += 'Windows Update RebootRequired' }
$pf = (Get-ItemProperty 'HKLM:\SYSTEM\CurrentControlSet\Control\Session Manager' -Name PendingFileRenameOperations -ErrorAction SilentlyContinue).PendingFileRenameOperations
if ($pf) { $reasons += 'PendingFileRenameOperations' }
try {
  $r = Invoke-CimMethod -Namespace 'root\ccm\ClientSDK' -ClassName CCM_ClientUtilities -MethodName DetermineIfRebootPending -ErrorAction Stop
  if ($r.RebootPending -or $r.IsHardRebootPending) { $reasons += 'SCCM client reboot pending' }
} catch { }
if ($reasons.Count -eq 0) { Write-Output 'No reboot is pending. Nothing to do.'; exit 0 }
Write-Output ('Reboot pending: ' + ($reasons -join '; '))
$dir = Join-Path $env:ProgramData 'RivetIT\starter'
$marker = Join-Path $dir 'reboot-scheduled.txt'
$now = Get-Date
if (Test-Path -LiteralPath $marker) {
  try {
    $lines = @(Get-Content -LiteralPath $marker -ErrorAction Stop)
    $sched = [datetime]::Parse(($lines[0] -replace '^scheduled=', ''), [Globalization.CultureInfo]::InvariantCulture, [Globalization.DateTimeStyles]::RoundtripKind)
    $due = [datetime]::Parse(($lines[1] -replace '^due=', ''), [Globalization.CultureInfo]::InvariantCulture, [Globalization.DateTimeStyles]::RoundtripKind)
    $boot = (Get-CimInstance Win32_OperatingSystem -ErrorAction SilentlyContinue).LastBootUpTime
    if ($boot -and $boot -gt $sched) { Write-Output 'Old marker ignored (machine restarted since).' }
    elseif ($now -lt $due) { Write-Output ("[SKIP] a restart was already scheduled for " + $due.ToString('yyyy-MM-dd HH:mm:ss')); exit 0 }
  } catch { Write-Output 'Unreadable marker ignored.' }
}
$secs = $Delay * 60
if ($DryRun) { Write-Output "[DRY-RUN] would schedule a restart in $Delay minute(s) with shutdown.exe /r /t $secs"; exit 0 }
$msg = "RivetIT: a restart is needed to finish updates. The computer will restart in $Delay minute(s). Please save your work."
& shutdown.exe /r /t $secs /c $msg 2>&1 | Out-Null
$code = $LASTEXITCODE
if ($code -eq 0 -or $code -eq 1190) {
  try {
    if (-not (Test-Path -LiteralPath $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    Set-Content -LiteralPath $marker -Value @(('scheduled=' + $now.ToString('o')), ('due=' + $now.AddMinutes($Delay).ToString('o'))) -Encoding ASCII
  } catch { Write-Output ('Note: could not write marker: ' + $_.Exception.Message) }
}
if ($code -eq 0) { Write-Output "[DONE] restart scheduled in $Delay minute(s)"; exit 0 }
if ($code -eq 1190) { Write-Output '[SKIP] a shutdown is already scheduled on this machine'; exit 0 }
Write-Output "shutdown.exe failed with exit code $code"
exit 3
