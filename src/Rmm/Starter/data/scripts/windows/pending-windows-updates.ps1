$ErrorActionPreference = 'Continue'
Write-Output '== Pending Windows updates =='
$job = Start-Job -ScriptBlock {
  $s = New-Object -ComObject Microsoft.Update.Session
  $r = $s.CreateUpdateSearcher().Search('IsInstalled=0 and IsHidden=0')
  foreach ($u in $r.Updates) {
    $kb = ''; if ($u.KBArticleIDs.Count -gt 0) { $kb = 'KB' + $u.KBArticleIDs.Item(0) }
    '{0} | {1} | {2:N0} MB | reboot={3}' -f $kb, $u.Title, ($u.MaxDownloadSize / 1MB), $u.RebootRequired
  }
}
if (-not (Wait-Job -Job $job -Timeout 360)) {
  Write-Output 'Search did not finish in time (Windows Update may be busy or unreachable). Try again later.'
  Stop-Job -Job $job -ErrorAction SilentlyContinue
  Remove-Job -Job $job -Force -ErrorAction SilentlyContinue
  exit 0
}
$out = @(Receive-Job -Job $job -ErrorAction SilentlyContinue 2>&1)
if ($job.State -eq 'Failed' -or $job.ChildJobs[0].Error.Count -gt 0) {
  Write-Output ('Search failed: ' + (($job.ChildJobs[0].Error | Select-Object -First 1) | Out-String).Trim())
} elseif ($out.Count -eq 0) {
  Write-Output 'No pending updates found.'
} else {
  Write-Output ("Pending updates: " + $out.Count)
  $out | Select-Object -First 50
  if ($out.Count -gt 50) { Write-Output '(truncated)' }
}
Remove-Job -Job $job -Force -ErrorAction SilentlyContinue
exit 0
