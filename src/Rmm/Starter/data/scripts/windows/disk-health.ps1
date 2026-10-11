$ErrorActionPreference = 'Continue'
Write-Output '== Physical disk health (Windows) =='
if (-not (Get-Command Get-PhysicalDisk -ErrorAction SilentlyContinue)) { Write-Output 'NOT AVAILABLE: Get-PhysicalDisk cmdlet missing'; exit 0 }
try {
    $disks = @(Get-PhysicalDisk -ErrorAction Stop)
} catch { Write-Output ("NOT AVAILABLE: {0}" -f $_.Exception.Message); exit 0 }
if ($disks.Count -eq 0) { Write-Output 'No physical disks reported (virtual machine or storage stack unavailable).'; exit 0 }
$disks | Select-Object DeviceId, FriendlyName, MediaType, BusType, HealthStatus, OperationalStatus, @{n='SizeGB';e={[math]::Round($_.Size / 1GB, 1)}} | Format-Table -AutoSize | Out-String -Width 200
Write-Output '-- Reliability counters --'
foreach ($d in $disks) {
    Write-Output ("Disk {0} ({1})" -f $d.DeviceId, $d.FriendlyName)
    $c = $null
    try { $c = $d | Get-StorageReliabilityCounter -ErrorAction Stop } catch { }
    if (-not $c) { Write-Output '  NOT AVAILABLE: no reliability counters for this disk'; continue }
    Write-Output ("  Temperature (C): {0} (max {1})" -f $c.Temperature, $c.TemperatureMax)
    Write-Output ("  Wear (percent used): {0}" -f $c.Wear)
    Write-Output ("  Read errors total/uncorrected: {0}/{1}" -f $c.ReadErrorsTotal, $c.ReadErrorsUncorrected)
    Write-Output ("  Write errors total/uncorrected: {0}/{1}" -f $c.WriteErrorsTotal, $c.WriteErrorsUncorrected)
    Write-Output ("  Power-on hours: {0}" -f $c.PowerOnHours)
}
exit 0
