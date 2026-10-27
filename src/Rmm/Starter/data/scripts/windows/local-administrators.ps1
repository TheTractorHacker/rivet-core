$ErrorActionPreference = 'Continue'
Write-Output '== Local Administrators group members =='
$gn = 'Administrators'
try { $gn = ((New-Object Security.Principal.SecurityIdentifier 'S-1-5-32-544').Translate([Security.Principal.NTAccount]).Value -split '\\')[-1] } catch { }
Write-Output "Group: $gn"
$done = $false
try {
  $m = @(Get-LocalGroupMember -SID 'S-1-5-32-544' -ErrorAction Stop)
  ($m | Select-Object Name, ObjectClass, PrincipalSource | Format-Table -AutoSize | Out-String -Width 200).TrimEnd()
  $done = $true
} catch {
  Write-Output ('Get-LocalGroupMember failed (' + $_.Exception.Message + '), using ADSI')
}
if (-not $done) {
  try {
    $g = [ADSI]"WinNT://./$gn,group"
    $rows = foreach ($x in @($g.Invoke('Members'))) {
      $path = $x.GetType().InvokeMember('ADsPath', 'GetProperty', $null, $x, $null)
      $cls = $x.GetType().InvokeMember('Class', 'GetProperty', $null, $x, $null)
      New-Object PSObject -Property @{ Name = ($path -replace '^WinNT://', ''); ObjectClass = $cls }
    }
    ($rows | Select-Object Name, ObjectClass | Format-Table -AutoSize | Out-String -Width 200).TrimEnd()
  } catch { Write-Output ('NOT AVAILABLE: could not read the group (' + $_.Exception.Message + ')') }
}
exit 0
