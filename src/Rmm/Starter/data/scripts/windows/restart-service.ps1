$ErrorActionPreference = 'Continue'
$DryRun = $true; if ($null -ne $dry_run -and $dry_run -is [bool]) { $DryRun = $dry_run }
$Wait = 60; if ($null -ne $wait_seconds) { $Wait = [int]$wait_seconds }
if ($Wait -lt 5 -or $Wait -gt 300) { Write-Output 'invalid wait_seconds (5..300)'; exit 2 }
Write-Output '== Restart a service (Windows) =='
$name = [string]$service_name
if ($name -notmatch '^[A-Za-z0-9_][A-Za-z0-9_.@:-]{0,99}$') { Write-Output 'invalid service_name'; exit 2 }
$deny = @('RpcSs', 'DcomLaunch', 'LSM', 'SamSs', 'lsass', 'wininit', 'services')
if ($deny -contains $name -or $name -match '(?i)rivet') { Write-Output ("REFUSED: {0} is protected and cannot be restarted by this script" -f $name); exit 2 }
$svc = Get-Service -Name $name -ErrorAction SilentlyContinue
if (-not $svc) { Write-Output ("NOT AVAILABLE: service '{0}' not found" -f $name); exit 0 }
if ($svc.DisplayName -match '(?i)rivet') { Write-Output ("REFUSED: {0} looks like the agent service" -f $name); exit 2 }
Write-Output ("Service: {0} ({1})" -f $svc.Name, $svc.DisplayName)
Write-Output ("State before: {0}, start type: {1}" -f $svc.Status, $svc.StartType)
if ($svc.StartType -eq 'Disabled') { Write-Output '[SKIP] service is disabled; not starting it'; exit 0 }
$act = 'restart'; if ($svc.Status -eq 'Stopped') { $act = 'start (it is stopped)' }
if ($DryRun) { Write-Output ("[DRY-RUN] would {0} service {1}, waiting up to {2}s" -f $act, $svc.Name, $Wait); exit 0 }
$to = [TimeSpan]::FromSeconds($Wait)
try {
    if ($svc.Status -ne 'Stopped') {
        Stop-Service -Name $svc.Name -Force -ErrorAction Stop
        $svc.WaitForStatus('Stopped', $to)
        Write-Output ("[DONE] stopped {0}" -f $svc.Name)
    }
} catch { Write-Output ("[SKIP] stop failed: {0}" -f $_.Exception.Message) }
try {
    Start-Service -Name $svc.Name -ErrorAction Stop
    $svc.WaitForStatus('Running', $to)
    Write-Output ("[DONE] started {0}" -f $svc.Name)
} catch { Write-Output ("[SKIP] start failed: {0}" -f $_.Exception.Message) }
$after = Get-Service -Name $name -ErrorAction SilentlyContinue
Write-Output ("State after: {0}" -f $after.Status)
if ($after.Status -ne 'Running') { exit 3 }
exit 0
