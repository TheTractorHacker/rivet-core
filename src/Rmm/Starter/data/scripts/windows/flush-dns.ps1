$ErrorActionPreference = 'Continue'
$DryRun = $true; if ($null -ne $dry_run -and $dry_run -is [bool]) { $DryRun = $dry_run }
Write-Output '== Flush DNS cache =='
if ($DryRun) { Write-Output '[DRY-RUN] would clear the DNS client cache'; exit 0 }
try {
  Clear-DnsClientCache -ErrorAction Stop
  Write-Output '[DONE] DNS client cache cleared'
  exit 0
} catch {
  Write-Output ('Clear-DnsClientCache failed (' + $_.Exception.Message + '), trying ipconfig')
}
& ipconfig.exe /flushdns | Out-Null
if ($LASTEXITCODE -eq 0) { Write-Output '[DONE] DNS resolver cache flushed with ipconfig'; exit 0 }
Write-Output '[SKIP] could not flush the DNS cache'
exit 3
