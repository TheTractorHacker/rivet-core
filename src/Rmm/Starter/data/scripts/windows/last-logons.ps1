$ErrorActionPreference = 'Continue'
$nDays = 7; if ($null -ne $days) { $nDays = [int]$days }
$nLimit = 100; if ($null -ne $limit) { $nLimit = [int]$limit }
if ($nDays -lt 1 -or $nDays -gt 30) { Write-Output 'invalid days (1..30)'; exit 2 }
if ($nLimit -lt 10 -or $nLimit -gt 500) { Write-Output 'invalid limit (10..500)'; exit 2 }
Write-Output '== Recent interactive logons (Windows) =='
Write-Output ("Window: last {0} day(s), limit {1}" -f $nDays, $nLimit)
$types = @{ 2 = 'Interactive'; 10 = 'RemoteInteractive (RDP)'; 11 = 'CachedInteractive' }
try {
    $start = (Get-Date).AddDays(-$nDays)
    $ev = Get-WinEvent -FilterHashtable @{ LogName = 'Security'; Id = 4624; StartTime = $start } -MaxEvents 20000 -ErrorAction Stop
    $rows = @()
    foreach ($e in $ev) {
        $x = [xml]$e.ToXml()
        $d = @{}
        foreach ($n in $x.Event.EventData.Data) { $d[$n.Name] = $n.'#text' }
        $lt = 0
        [void][int]::TryParse([string]$d['LogonType'], [ref]$lt)
        if (-not $types.ContainsKey($lt)) { continue }
        if ($d['TargetUserName'] -match '^(DWM-|UMFD-)' -or $d['TargetUserName'] -like '*$') { continue }
        $rows += [pscustomobject]@{
            Time = $e.TimeCreated.ToString('yyyy-MM-dd HH:mm:ss')
            User = ('{0}\{1}' -f $d['TargetDomainName'], $d['TargetUserName'])
            Type = $types[$lt]
            Source = $d['IpAddress']
        }
        if ($rows.Count -ge $nLimit) { break }
    }
    Write-Output ("Logon events shown: {0}" -f $rows.Count)
    if ($rows.Count -gt 0) { $rows | Format-Table -AutoSize | Out-String -Width 200 }
    if ($rows.Count -ge $nLimit) { Write-Output '(truncated)' }
} catch {
    if ($_.Exception.Message -match 'No events were found') { Write-Output 'No matching logon events found.' }
    else { Write-Output ("Security log not readable: {0}" -f $_.Exception.Message) }
}
Write-Output ''
Write-Output '-- Last use per local profile (Win32_UserProfile) --'
try {
    $p = Get-CimInstance Win32_UserProfile -ErrorAction Stop | Where-Object { -not $_.Special -and $_.LocalPath } | ForEach-Object {
        $sid = $_.SID
        $name = $sid
        try { $name = (New-Object System.Security.Principal.SecurityIdentifier($sid)).Translate([System.Security.Principal.NTAccount]).Value } catch { }
        [pscustomobject]@{ Account = $name; LastUse = $_.LastUseTime; Loaded = $_.Loaded; Path = $_.LocalPath }
    } | Sort-Object LastUse -Descending
    $p | Format-Table -AutoSize | Out-String -Width 200
} catch { Write-Output ("Could not read user profiles: {0}" -f $_.Exception.Message) }
exit 0
