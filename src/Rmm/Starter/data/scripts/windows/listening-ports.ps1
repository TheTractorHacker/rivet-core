$ErrorActionPreference = 'Continue'
Write-Output '== Listening ports =='
$names = @{}
foreach ($p in @(Get-Process -ErrorAction SilentlyContinue)) { $names[[int]$p.Id] = $p.ProcessName }
function PN($id) { if ($names.ContainsKey([int]$id)) { $names[[int]$id] } else { '?' } }
if (Get-Command Get-NetTCPConnection -ErrorAction SilentlyContinue) {
  $tcp = @(Get-NetTCPConnection -State Listen -ErrorAction SilentlyContinue | Sort-Object LocalPort)
  Write-Output "-- TCP listening ($($tcp.Count)) --"
  ($tcp | Select-Object -First 200 LocalAddress, LocalPort, @{n='PID';e={$_.OwningProcess}}, @{n='Process';e={PN $_.OwningProcess}} | Format-Table -AutoSize | Out-String -Width 200).TrimEnd()
  if ($tcp.Count -gt 200) { Write-Output '(truncated)' }
  $udp = @(Get-NetUDPEndpoint -ErrorAction SilentlyContinue | Sort-Object LocalPort)
  Write-Output "-- UDP endpoints ($($udp.Count)) --"
  ($udp | Select-Object -First 200 LocalAddress, LocalPort, @{n='PID';e={$_.OwningProcess}}, @{n='Process';e={PN $_.OwningProcess}} | Format-Table -AutoSize | Out-String -Width 200).TrimEnd()
  if ($udp.Count -gt 200) { Write-Output '(truncated)' }
} else {
  Write-Output '(NetTCPIP cmdlets missing, using netstat)'
  (& netstat.exe -ano | Select-String -Pattern 'LISTENING|UDP' | Select-Object -First 200 | Out-String -Width 200).TrimEnd()
}
exit 0
