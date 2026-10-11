$ErrorActionPreference = 'Continue'
$nLimit = 200; if ($null -ne $limit) { $nLimit = [int]$limit }
if ($nLimit -lt 10 -or $nLimit -gt 500) { Write-Output 'invalid limit (10..500)'; exit 2 }
Write-Output '== Non-Microsoft scheduled tasks (Windows) =='
if (-not (Get-Command Get-ScheduledTask -ErrorAction SilentlyContinue)) { Write-Output 'NOT AVAILABLE: ScheduledTasks cmdlets missing'; exit 0 }
try {
    $tasks = Get-ScheduledTask -ErrorAction Stop | Where-Object { $_.TaskPath -notlike '\Microsoft\*' }
    $total = @($tasks).Count
    $rows = foreach ($t in ($tasks | Sort-Object TaskPath, TaskName | Select-Object -First $nLimit)) {
        $i = Get-ScheduledTaskInfo -TaskName $t.TaskName -TaskPath $t.TaskPath -ErrorAction SilentlyContinue
        [pscustomobject]@{
            Name = $t.TaskName; Path = $t.TaskPath; State = $t.State; RunAs = $t.Principal.UserId
            LastResult = if ($i) { '0x{0:X}' -f [uint32]$i.LastTaskResult } else { '' }
            LastRun = if ($i) { $i.LastRunTime } else { '' }
            NextRun = if ($i) { $i.NextRunTime } else { '' }
        }
    }
    Write-Output ("Tasks found: {0}" -f $total)
    $rows | Format-Table -AutoSize | Out-String -Width 250
    if ($total -gt $nLimit) { Write-Output '(truncated)' }
} catch { Write-Output ("NOT AVAILABLE: {0}" -f $_.Exception.Message) }
exit 0
