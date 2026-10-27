$ErrorActionPreference = 'Continue'
Write-Output '== Local user accounts =='
try {
  $u = @(Get-LocalUser -ErrorAction Stop)
  ($u | Sort-Object Name | Select-Object Name, Enabled, LastLogon, PasswordLastSet, PasswordRequired, PasswordExpires | Format-Table -AutoSize | Out-String -Width 200).TrimEnd()
} catch {
  Write-Output ('Local account cmdlets failed (' + $_.Exception.Message + '), using WMI')
  try {
    (Get-CimInstance Win32_UserAccount -Filter 'LocalAccount=True' -ErrorAction Stop | Sort-Object Name | Select-Object Name, @{n='Enabled';e={-not $_.Disabled}}, PasswordRequired, Lockout | Format-Table -AutoSize | Out-String -Width 200).TrimEnd()
  } catch { Write-Output ('NOT AVAILABLE: ' + $_.Exception.Message) }
}
exit 0
