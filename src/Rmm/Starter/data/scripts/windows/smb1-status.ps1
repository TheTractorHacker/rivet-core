$ErrorActionPreference = 'Continue'
Write-Output '== SMB1 status (Windows) =='
$enabledAny = $false
$known = $false
foreach ($fn in 'SMB1Protocol', 'SMB1Protocol-Server', 'SMB1Protocol-Client') {
    try {
        $f = Get-WindowsOptionalFeature -Online -FeatureName $fn -ErrorAction Stop
        $known = $true
        Write-Output ("Feature {0}: {1}" -f $fn, $f.State)
        if ($f.State -eq 'Enabled') { $enabledAny = $true }
    } catch { Write-Output ("Feature {0}: NOT AVAILABLE ({1})" -f $fn, $_.Exception.Message.Split("`n")[0]) }
}
$cfg = $null
try {
    $cfg = Get-SmbServerConfiguration -ErrorAction Stop
    $known = $true
    Write-Output ("SMB server EnableSMB1Protocol: {0}" -f $cfg.EnableSMB1Protocol)
    Write-Output ("SMB server EnableSMB2Protocol: {0}" -f $cfg.EnableSMB2Protocol)
    if ($cfg.EnableSMB1Protocol) { $enabledAny = $true }
} catch { Write-Output 'SMB server configuration: NOT AVAILABLE' }
try {
    $d = (Get-ItemProperty -Path 'HKLM:\SYSTEM\CurrentControlSet\Services\mrxsmb10' -Name Start -ErrorAction Stop).Start
    Write-Output ("SMB1 client driver (mrxsmb10) Start value: {0} (4 = disabled)" -f $d)
    if ($d -ne 4) { $enabledAny = $true }
} catch { Write-Output 'SMB1 client driver: not present' }
Write-Output ''
if (-not $known) { Write-Output 'Verdict: could not determine SMB1 state on this machine.' }
elseif ($enabledAny) { Write-Output 'Verdict: SMB1 appears ENABLED on this machine. It is obsolete and insecure; disable it unless a legacy device needs it.' }
else { Write-Output 'Verdict: SMB1 is disabled. Good.' }
exit 0
