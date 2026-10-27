$ErrorActionPreference = 'Continue'
$DryRun = $true; if ($null -ne $dry_run -and $dry_run -is [bool]) { $DryRun = $dry_run }
Write-Output '== Reset Windows Update components =='
$svcs = 'wuauserv', 'bits', 'cryptsvc'
$was = @{}
foreach ($s in $svcs) { $o = Get-Service -Name $s -ErrorAction SilentlyContinue; if ($o) { $was[$s] = ($o.Status -eq 'Running') } }
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$items = @(
  @{ Parent = (Join-Path $env:SystemRoot ''); Base = 'SoftwareDistribution' },
  @{ Parent = (Join-Path $env:SystemRoot 'System32'); Base = 'catroot2' }
)
if ($DryRun) {
  foreach ($s in $svcs) { if ($was.ContainsKey($s)) { Write-Output "[DRY-RUN] would stop service $s (currently running: $($was[$s]))" } }
  foreach ($i in $items) {
    $p = Join-Path $i.Parent $i.Base
    foreach ($old in @(Get-ChildItem -LiteralPath $i.Parent -Directory -Filter ($i.Base + '.bak-*') -ErrorAction SilentlyContinue)) { Write-Output "[DRY-RUN] would remove old backup $($old.FullName)" }
    if (Test-Path -LiteralPath $p) { Write-Output "[DRY-RUN] would rename $p to $($i.Base).bak-$stamp" } else { Write-Output "[DRY-RUN] $p not present, nothing to rename" }
  }
  foreach ($s in $svcs) { if ($was.ContainsKey($s) -and $was[$s]) { Write-Output "[DRY-RUN] would start service $s again" } }
  exit 0
}
$rc = 0
try {
  $stopped = $true
  foreach ($s in $svcs) {
    if (-not $was.ContainsKey($s)) { continue }
    try {
      $o = Get-Service -Name $s
      if ($o.Status -ne 'Stopped') { Stop-Service -Name $s -Force -ErrorAction Stop; $o.WaitForStatus('Stopped', [TimeSpan]::FromSeconds(60)) }
      Write-Output "[DONE] service $s stopped"
    } catch { Write-Output ("[SKIP] could not stop service " + $s + ": " + $_.Exception.Message); $stopped = $false }
  }
  if (-not $stopped) { Write-Output 'Services did not stop; no folders were renamed.'; $rc = 3 }
  else {
    foreach ($i in $items) {
      $p = Join-Path $i.Parent $i.Base
      foreach ($old in @(Get-ChildItem -LiteralPath $i.Parent -Directory -Filter ($i.Base + '.bak-*') -ErrorAction SilentlyContinue)) {
        if ($old.FullName.Length -lt 12 -or $old.Name -notlike ($i.Base + '.bak-*')) { continue }
        try { Remove-Item -LiteralPath $old.FullName -Recurse -Force -ErrorAction Stop; Write-Output "[DONE] removed old backup $($old.FullName)" } catch { Write-Output "[SKIP] could not remove $($old.FullName)" }
      }
      if (Test-Path -LiteralPath $p) {
        try { Rename-Item -LiteralPath $p -NewName ($i.Base + '.bak-' + $stamp) -ErrorAction Stop; Write-Output "[DONE] renamed $p to $($i.Base).bak-$stamp" } catch { Write-Output ("[SKIP] could not rename " + $p + ": " + $_.Exception.Message); $rc = 3 }
      } else { Write-Output "[SKIP] $p not present" }
    }
  }
} finally {
  foreach ($s in @('cryptsvc', 'bits', 'wuauserv')) {
    if ($was.ContainsKey($s) -and $was[$s]) {
      try { Start-Service -Name $s -ErrorAction Stop; Write-Output "[DONE] service $s started" } catch { Write-Output ("[SKIP] could not start " + $s + ": " + $_.Exception.Message) }
    }
  }
}
exit $rc
