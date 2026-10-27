$ErrorActionPreference = 'Continue'
Write-Output '== Windows Firewall status =='
if (-not (Get-Command Get-NetFirewallProfile -ErrorAction SilentlyContinue)) { Write-Output 'NOT AVAILABLE: NetSecurity cmdlets are missing'; exit 0 }
try {
  (Get-NetFirewallProfile -ErrorAction Stop | Select-Object Name, Enabled, DefaultInboundAction, DefaultOutboundAction, AllowInboundRules, LogAllowed, LogBlocked | Format-Table -AutoSize | Out-String -Width 200).TrimEnd()
  $rules = @(Get-NetFirewallRule -ErrorAction Stop)
  Write-Output ("Total rules: {0}; enabled: {1}" -f $rules.Count, @($rules | Where-Object { $_.Enabled -eq 'True' }).Count)
  ($rules | Where-Object { $_.Enabled -eq 'True' } | Group-Object Direction, Action | Select-Object Count, Name | Format-Table -AutoSize | Out-String -Width 200).TrimEnd()
} catch {
  Write-Output ('ERROR: could not read firewall state: ' + $_.Exception.Message)
}
exit 0
