$ErrorActionPreference = 'Continue'
Write-Output '== Remote Desktop status (Windows) =='
$ts = 'HKLM:\SYSTEM\CurrentControlSet\Control\Terminal Server'
$deny = (Get-ItemProperty -Path $ts -Name fDenyTSConnections -ErrorAction SilentlyContinue).fDenyTSConnections
if ($null -eq $deny) { Write-Output 'fDenyTSConnections: not set' }
elseif ($deny -eq 0) { Write-Output 'RDP: ENABLED (fDenyTSConnections=0)' }
else { Write-Output 'RDP: DISABLED (fDenyTSConnections=1)' }
$wp = 'HKLM:\SYSTEM\CurrentControlSet\Control\Terminal Server\WinStations\RDP-Tcp'
$nla = (Get-ItemProperty -Path $wp -Name UserAuthentication -ErrorAction SilentlyContinue).UserAuthentication
if ($null -eq $nla) { Write-Output 'NLA required: unknown' }
elseif ($nla -eq 1) { Write-Output 'NLA required: yes' }
else { Write-Output 'NLA required: NO (less secure)' }
$port = (Get-ItemProperty -Path $wp -Name PortNumber -ErrorAction SilentlyContinue).PortNumber
if ($null -eq $port) { $port = 'unknown' }
Write-Output ("RDP port: {0}" -f $port)
$svc = Get-Service -Name TermService -ErrorAction SilentlyContinue
if ($svc) { Write-Output ("TermService: {0} ({1})" -f $svc.Status, $svc.StartType) } else { Write-Output 'TermService: not found' }
try {
    $rules = Get-NetFirewallRule -DisplayGroup 'Remote Desktop' -ErrorAction Stop
    foreach ($r in $rules) { Write-Output ("Firewall rule: {0} | enabled={1} | profile={2} | action={3}" -f $r.DisplayName, $r.Enabled, $r.Profile, $r.Action) }
} catch { Write-Output 'Firewall rules: NOT AVAILABLE (NetSecurity cmdlets missing or group not found)' }
Write-Output ''
Write-Output '-- Sessions (quser) --'
$q = Get-Command quser.exe -ErrorAction SilentlyContinue
if ($q) {
    $o = & $q.Source 2>&1 | Out-String
    if ($o.Trim()) { Write-Output $o.TrimEnd() } else { Write-Output 'No user sessions reported.' }
} else { Write-Output 'quser not present on this edition.' }
exit 0
