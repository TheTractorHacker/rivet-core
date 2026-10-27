$ErrorActionPreference = 'Continue'
Write-Output '== Time synchronisation status (Windows) =='
$svc = Get-Service -Name W32Time -ErrorAction SilentlyContinue
if ($svc) { Write-Output ("W32Time service: {0} ({1})" -f $svc.Status, $svc.StartType) } else { Write-Output 'W32Time service: not found' }
$tz = Get-TimeZone -ErrorAction SilentlyContinue
if ($tz) { Write-Output ("Time zone: {0} (UTC offset {1})" -f $tz.Id, $tz.BaseUtcOffset) }
Write-Output ("Local time: {0}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'))
$w = Get-Command w32tm.exe -ErrorAction SilentlyContinue
if (-not $w) { Write-Output 'NOT AVAILABLE: w32tm.exe not found'; exit 0 }
if ($svc -and $svc.Status -ne 'Running') { Write-Output 'NOTE: W32Time is not running; w32tm output below may be an error.' }
Write-Output ''
Write-Output '-- w32tm /query /status --'
$s = (& $w.Source /query /status 2>&1 | Out-String).TrimEnd()
Write-Output $s
$off = [regex]::Match($s, '(?im)^\s*Phase Offset:\s*(.+)$')
if ($off.Success) { Write-Output ("Offset: {0}" -f $off.Groups[1].Value.Trim()) }
$ls = [regex]::Match($s, '(?im)^\s*Last Successful Sync Time:\s*(.+)$')
if ($ls.Success) { Write-Output ("Last sync: {0}" -f $ls.Groups[1].Value.Trim()) }
Write-Output ''
Write-Output '-- Time source --'
Write-Output ((& $w.Source /query /source 2>&1 | Out-String).TrimEnd())
Write-Output ''
Write-Output '-- Configuration (Type, NtpServer) --'
$cfg = & $w.Source /query /configuration 2>&1 | Out-String
foreach ($l in ($cfg -split "`r?`n")) { if ($l -match '^\s*(Type|NtpServer|Enabled|SpecialPollInterval|AnnounceFlags)\b') { Write-Output $l.TrimEnd() } }
exit 0
