$ErrorActionPreference = 'Continue'
Write-Output '== BitLocker status =='
if (-not (Get-Command Get-BitLockerVolume -ErrorAction SilentlyContinue)) { Write-Output 'NOT AVAILABLE: BitLocker cmdlets are missing'; exit 0 }
try {
  $vols = @(Get-BitLockerVolume -ErrorAction Stop)
  $rows = foreach ($v in $vols) {
    New-Object PSObject -Property @{
      Mount = $v.MountPoint
      Type = $v.VolumeType
      Status = $v.VolumeStatus
      Protection = $v.ProtectionStatus
      Percent = $v.EncryptionPercentage
      Method = $v.EncryptionMethod
      Protectors = (@($v.KeyProtector | ForEach-Object { [string]$_.KeyProtectorType }) -join ', ')
    }
  }
  ($rows | Select-Object Mount, Type, Status, Protection, Percent, Method, Protectors | Format-Table -AutoSize | Out-String -Width 200).TrimEnd()
  Write-Output '(recovery keys are intentionally not shown)'
} catch {
  Write-Output ('NOT AVAILABLE: BitLocker could not be queried (' + $_.Exception.Message + ')')
}
exit 0
