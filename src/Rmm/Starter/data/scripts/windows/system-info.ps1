$ErrorActionPreference = 'Continue'
Write-Output '== System information summary =='
function L($k, $v) { '{0,-16}: {1}' -f $k, $v }
try {
  $os = Get-CimInstance Win32_OperatingSystem -ErrorAction Stop
  $cs = Get-CimInstance Win32_ComputerSystem -ErrorAction Stop
  $bios = Get-CimInstance Win32_BIOS -ErrorAction SilentlyContinue | Select-Object -First 1
  $cpu = @(Get-CimInstance Win32_Processor -ErrorAction SilentlyContinue)
  $ubr = (Get-ItemProperty 'HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion' -ErrorAction SilentlyContinue).UBR
  L 'Computer name' $env:COMPUTERNAME
  L 'OS' ($os.Caption + ' ' + $os.OSArchitecture)
  L 'Version/build' ($os.Version + ' (build ' + $os.BuildNumber + $(if ($ubr) { '.' + $ubr } else { '' }) + ')')
  L 'Manufacturer' $cs.Manufacturer
  L 'Model' $cs.Model
  L 'Serial number' $bios.SerialNumber
  L 'BIOS' ($bios.Manufacturer + ' ' + $bios.SMBIOSBIOSVersion + ' (' + $bios.ReleaseDate + ')')
  L 'CPU' (($cpu | ForEach-Object { $_.Name.Trim() } | Select-Object -Unique) -join '; ')
  L 'Cores/threads' ((($cpu | Measure-Object NumberOfCores -Sum).Sum).ToString() + ' / ' + (($cpu | Measure-Object NumberOfLogicalProcessors -Sum).Sum).ToString())
  L 'RAM total' ('{0:N1} GB' -f ($cs.TotalPhysicalMemory / 1GB))
  L 'RAM free' ('{0:N1} GB' -f ($os.FreePhysicalMemory / 1MB))
  if ($cs.PartOfDomain) { L 'Domain' $cs.Domain } else { L 'Workgroup' $cs.Workgroup }
  L 'Last boot' $os.LastBootUpTime
  L 'Install date' $os.InstallDate
  $nics = @(Get-CimInstance Win32_NetworkAdapterConfiguration -Filter 'IPEnabled=True' -ErrorAction SilentlyContinue)
  foreach ($n in $nics) { L 'Network' ($n.Description + ' | ' + (@($n.IPAddress) -join ', ') + ' | ' + $n.MACAddress) }
} catch {
  Write-Output ('ERROR: could not read system information: ' + $_.Exception.Message)
}
exit 0
