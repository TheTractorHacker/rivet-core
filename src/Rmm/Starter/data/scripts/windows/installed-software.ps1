$ErrorActionPreference = 'Continue'
$Max = 300; if ($null -ne $limit) { try { $Max = [int]$limit } catch { Write-Output "invalid limit"; exit 2 } }
if ($Max -lt 10 -or $Max -gt 1000) { Write-Output "limit must be 10..1000"; exit 2 }
Write-Output '== Installed software =='
$paths = @(
  'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\*',
  'HKLM:\SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall\*',
  'Registry::HKEY_USERS\S-1-5-21-*\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\*'
)
$apps = @(Get-ItemProperty -Path $paths -ErrorAction SilentlyContinue | Where-Object { $_.DisplayName -and $_.SystemComponent -ne 1 -and -not $_.ParentKeyName })
$u = @($apps | Sort-Object DisplayName, DisplayVersion -Unique | Select-Object @{n='Name';e={([string]$_.DisplayName).Trim()}}, @{n='Version';e={$_.DisplayVersion}}, @{n='Publisher';e={$_.Publisher}})
$u = @($u | Sort-Object Name, Version -Unique)
Write-Output ("Programs found: " + $u.Count)
($u | Select-Object -First $Max | Format-Table -AutoSize | Out-String -Width 200).TrimEnd()
if ($u.Count -gt $Max) { Write-Output "(truncated to $Max)" }
exit 0
