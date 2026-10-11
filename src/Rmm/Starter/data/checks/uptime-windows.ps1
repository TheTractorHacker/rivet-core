$b = (Get-CimInstance Win32_OperatingSystem).LastBootUpTime
$d = [int]((Get-Date) - $b).TotalDays
if ($d -ge {{uptime_fail_days}}) { "Up $d days (limit {{uptime_fail_days}} days): restart soon"; exit 2 }
if ($d -ge {{uptime_warn_days}}) { "Up $d days (limit {{uptime_warn_days}} days)"; exit 1 }
"Up $d days"
exit 0
