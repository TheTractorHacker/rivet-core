$ErrorActionPreference = 'Continue'
Write-Output '== Uptime and last boot =='
try {
  $os = Get-CimInstance Win32_OperatingSystem -ErrorAction Stop
  $boot = $os.LastBootUpTime
  $up = (Get-Date) - $boot
  Write-Output ('Last boot : ' + $boot.ToString('yyyy-MM-dd HH:mm:ss'))
  Write-Output ('Uptime    : {0} days, {1} hours, {2} minutes' -f $up.Days, $up.Hours, $up.Minutes)
  $fs = (Get-ItemProperty 'HKLM:\SYSTEM\CurrentControlSet\Control\Session Manager\Power' -ErrorAction SilentlyContinue).HiberbootEnabled
  if ($fs -eq 1) { Write-Output 'Note      : Fast Startup is enabled; a normal shutdown does not reset uptime, only a restart does.' }
} catch {
  Write-Output ('ERROR: could not read boot time: ' + $_.Exception.Message)
}
exit 0
