$ErrorActionPreference = 'Continue'
Write-Output '== Outdated packages (winget, list only) =='
$w = $null
$cand = @(Get-ChildItem -Path "$env:ProgramFiles\WindowsApps\Microsoft.DesktopAppInstaller_*\winget.exe" -ErrorAction SilentlyContinue | Sort-Object LastWriteTime -Descending)
if ($cand.Count -gt 0) { $w = $cand[0].FullName }
if (-not $w) { $c = Get-Command winget.exe -ErrorAction SilentlyContinue; if ($c) { $w = $c.Source } }
if (-not $w) { Write-Output 'NOT AVAILABLE: winget.exe was not found on this machine'; exit 0 }
Write-Output "Using $w"
$job = Start-Job -ScriptBlock { param($exe) & $exe upgrade --include-unknown --accept-source-agreements --disable-interactivity 2>&1 | Out-String -Width 250 } -ArgumentList $w
if (-not (Wait-Job -Job $job -Timeout 240)) {
  Write-Output 'winget did not finish in time.'
  Stop-Job -Job $job -ErrorAction SilentlyContinue
  Remove-Job -Job $job -Force -ErrorAction SilentlyContinue
  exit 0
}
$txt = Receive-Job -Job $job -ErrorAction SilentlyContinue
Remove-Job -Job $job -Force -ErrorAction SilentlyContinue
$lines = @(($txt -split "`r?`n") | Where-Object { $_.Trim() -and $_.Trim() -notmatch '^[-\\|/ ]+$' })
$lines | Select-Object -First 120
if ($lines.Count -gt 120) { Write-Output '(truncated)' }
exit 0
