$ErrorActionPreference = 'Continue'
$PingCount = 4; if ($null -ne $count) { try { $PingCount = [int]$count } catch { Write-Output "invalid count"; exit 2 } }
if ($PingCount -lt 1 -or $PingCount -gt 10) { Write-Output "count must be 1..10"; exit 2 }
$t = [string]$target
if ([string]::IsNullOrEmpty($t) -or $t -match '[\r\n]' -or -not ($t -match '^[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$' -or $t -match '^[0-9A-Fa-f:.]{2,45}$')) { Write-Output 'invalid target'; exit 2 }
Write-Output "== Network diagnostics for $t =="
Write-Output '-- DNS --'
try { (Resolve-DnsName -Name $t -ErrorAction Stop | Select-Object Name, Type, TTL, IPAddress, NameHost | Format-Table -AutoSize | Out-String -Width 200).TrimEnd() } catch { Write-Output ('DNS lookup failed: ' + $_.Exception.Message) }
Write-Output "-- Ping ($PingCount packets) --"
(& ping.exe -n $PingCount $t 2>&1 | Out-String -Width 200).TrimEnd()
Write-Output '-- Traceroute (max 15 hops) --'
(& tracert.exe -d -h 15 -w 1000 $t 2>&1 | Out-String -Width 200).TrimEnd()
exit 0
