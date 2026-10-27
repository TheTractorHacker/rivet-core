$ErrorActionPreference = 'Continue'
$nHours = 24; if ($null -ne $hours) { $nHours = [int]$hours }
if ($nHours -lt 1 -or $nHours -gt 168) { Write-Output 'invalid hours (1..168)'; exit 2 }
Write-Output '== Critical/Error events (Windows) =='
Write-Output ("Window: last {0} hour(s), logs: System, Application" -f $nHours)
$start = (Get-Date).AddHours(-$nHours)
$all = @()
foreach ($log in 'System', 'Application') {
    try {
        $all += Get-WinEvent -FilterHashtable @{ LogName = $log; Level = 1, 2; StartTime = $start } -MaxEvents 5000 -ErrorAction Stop
    } catch {
        if ($_.Exception.Message -match 'No events were found') { Write-Output ("{0}: no matching events" -f $log) }
        else { Write-Output ("{0}: NOT AVAILABLE ({1})" -f $log, $_.Exception.Message.Split("`n")[0]) }
    }
}
Write-Output ("Events found: {0}" -f $all.Count)
if ($all.Count -gt 0) {
    $groups = $all | Group-Object LogName, ProviderName, Id | Sort-Object Count -Descending | Select-Object -First 40
    $rows = foreach ($g in $groups) {
        $n = $g.Group | Sort-Object TimeCreated -Descending | Select-Object -First 1
        $m = [string]$n.Message
        if ($null -eq $n.Message) { $m = '' }
        $m = ($m -replace '\s+', ' ').Trim()
        if ($m.Length -gt 160) { $m = $m.Substring(0, 160) }
        [pscustomobject]@{ Count = $g.Count; Log = $n.LogName; Source = $n.ProviderName; EventId = $n.Id; Newest = $n.TimeCreated.ToString('MM-dd HH:mm'); Message = $m }
    }
    $rows | Format-Table -AutoSize -Wrap | Out-String -Width 250
    Write-Output '(top 40 groups by count)'
}
exit 0
