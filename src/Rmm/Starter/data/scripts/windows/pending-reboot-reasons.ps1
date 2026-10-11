$ErrorActionPreference = 'Continue'
Write-Output '== Pending reboot signals (Windows) =='
$any = $false
function Report($label, $flag) {
    if ($flag) { Write-Output ("{0}: YES" -f $label) } else { Write-Output ("{0}: no" -f $label) }
}
$cbs = Test-Path 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Component Based Servicing\RebootPending'
$cbs2 = Test-Path 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Component Based Servicing\RebootInProgress'
$wu = Test-Path 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\WindowsUpdate\Auto Update\RebootRequired'
Report 'Component Based Servicing (RebootPending)' $cbs
Report 'Component Based Servicing (RebootInProgress)' $cbs2
Report 'Windows Update (RebootRequired)' $wu
$pfro = (Get-ItemProperty -Path 'HKLM:\SYSTEM\CurrentControlSet\Control\Session Manager' -Name PendingFileRenameOperations -ErrorAction SilentlyContinue).PendingFileRenameOperations
$pf = $false
if ($pfro) { $pf = $true }
Report 'PendingFileRenameOperations' $pf
if ($pf) {
    $e = @($pfro | Where-Object { $_ -ne '' })
    $e | Select-Object -First 10 | ForEach-Object { Write-Output ("    {0}" -f $_) }
    if ($e.Count -gt 10) { Write-Output ("    ... ({0} entries total, truncated)" -f $e.Count) }
}
$a = (Get-ItemProperty -Path 'HKLM:\SYSTEM\CurrentControlSet\Control\ComputerName\ActiveComputerName' -Name ComputerName -ErrorAction SilentlyContinue).ComputerName
$p = (Get-ItemProperty -Path 'HKLM:\SYSTEM\CurrentControlSet\Control\ComputerName\ComputerName' -Name ComputerName -ErrorAction SilentlyContinue).ComputerName
$ren = ($a -and $p -and $a -ne $p)
Report 'Computer rename pending' $ren
$nl = Test-Path 'HKLM:\SYSTEM\CurrentControlSet\Services\Netlogon\JoinDomain'
$nl2 = Test-Path 'HKLM:\SYSTEM\CurrentControlSet\Services\Netlogon\AvoidSpnSet'
Report 'Domain join pending' ($nl -or $nl2)
$sccm = $false
try {
    $r = Invoke-CimMethod -Namespace 'root\ccm\ClientSDK' -ClassName 'CCM_ClientUtilities' -MethodName 'DetermineIfRebootPending' -ErrorAction Stop
    if ($r.RebootPending -or $r.IsHardRebootPending) { $sccm = $true }
    Report 'SCCM client' $sccm
} catch { Write-Output 'SCCM client: not installed' }
$any = $cbs -or $cbs2 -or $wu -or $pf -or $ren -or $nl -or $nl2 -or $sccm
Write-Output ''
if ($any) { Write-Output 'REBOOT PENDING: yes' } else { Write-Output 'REBOOT PENDING: no' }
exit 0
