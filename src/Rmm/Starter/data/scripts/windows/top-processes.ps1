$ErrorActionPreference = 'Continue'
$N = 10; if ($null -ne $top_n) { try { $N = [int]$top_n } catch { Write-Output "invalid top_n"; exit 2 } }
if ($N -lt 1 -or $N -gt 50) { Write-Output "top_n must be 1..50"; exit 2 }
Write-Output '== Top processes =='
try {
  $perf = @(Get-CimInstance Win32_PerfFormattedData_PerfProc_Process -ErrorAction Stop | Where-Object { $_.Name -ne '_Total' -and $_.Name -ne 'Idle' })
  Write-Output "-- Top $N by CPU (percent of one core, current sample) --"
  ($perf | Sort-Object PercentProcessorTime -Descending | Select-Object -First $N @{n='PID';e={$_.IDProcess}}, Name, @{n='CPU%';e={$_.PercentProcessorTime}}, @{n='PrivateMB';e={[math]::Round($_.WorkingSetPrivate/1MB,1)}} | Format-Table -AutoSize | Out-String -Width 200).TrimEnd()
} catch {
  Write-Output ('CPU sample unavailable: ' + $_.Exception.Message)
}
try {
  Write-Output "-- Top $N by memory (working set) --"
  (Get-Process -ErrorAction Stop | Sort-Object WorkingSet64 -Descending | Select-Object -First $N Id, ProcessName, @{n='MemMB';e={[math]::Round($_.WorkingSet64/1MB,1)}}, @{n='CPUsec';e={if($_.CPU){[math]::Round($_.CPU,1)}else{0}}} | Format-Table -AutoSize | Out-String -Width 200).TrimEnd()
} catch {
  Write-Output ('Memory listing unavailable: ' + $_.Exception.Message)
}
exit 0
