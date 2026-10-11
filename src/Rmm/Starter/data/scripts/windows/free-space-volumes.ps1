$ErrorActionPreference = 'Continue'
Write-Output '== Free space by volume =='
try {
  $v = @(Get-CimInstance Win32_Volume -Filter 'DriveType=3' -ErrorAction Stop | Where-Object { $_.Capacity -gt 0 -and $_.Name -notlike '\\?\Volume*' })
  if ($v.Count -eq 0) { Write-Output 'No fixed volumes with a mount point found.' }
  else {
    ($v | Sort-Object Name | Select-Object Name, Label, FileSystem, @{n='SizeGB';e={[math]::Round($_.Capacity/1GB,1)}}, @{n='FreeGB';e={[math]::Round($_.FreeSpace/1GB,1)}}, @{n='Free%';e={[math]::Round(100*$_.FreeSpace/$_.Capacity,1)}} | Format-Table -AutoSize | Out-String -Width 200).TrimEnd()
  }
} catch {
  Write-Output ('ERROR: could not read volumes: ' + $_.Exception.Message)
}
exit 0
