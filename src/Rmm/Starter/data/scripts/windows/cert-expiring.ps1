$ErrorActionPreference = 'Continue'
$nDays = 30; if ($null -ne $days) { $nDays = [int]$days }
if ($nDays -lt 1 -or $nDays -gt 3650) { Write-Output 'invalid days (1..3650)'; exit 2 }
$st = 'My'; if ($null -ne $store -and [string]$store -ne '') { $st = [string]$store }
if ($st -notin 'My', 'WebHosting', 'Remote Desktop') { Write-Output 'invalid store (My, WebHosting, Remote Desktop)'; exit 2 }
Write-Output '== Expiring certificates (Windows) =='
Write-Output ("Store: LocalMachine\{0}, expiring within {1} day(s) or already expired" -f $st, $nDays)
$s = New-Object System.Security.Cryptography.X509Certificates.X509Store($st, 'LocalMachine')
try { $s.Open('ReadOnly') } catch { Write-Output ("NOT AVAILABLE: cannot open store ({0})" -f $_.Exception.Message); exit 0 }
$now = Get-Date; $limitDate = $now.AddDays($nDays)
$rows = @()
foreach ($c in $s.Certificates) {
    if ($c.NotAfter -le $limitDate) {
        $left = [math]::Floor(($c.NotAfter - $now).TotalDays)
        $status = 'expiring'; if ($c.NotAfter -lt $now) { $status = 'EXPIRED' }
        $rows += [pscustomobject]@{ Status = $status; DaysLeft = $left; NotAfter = $c.NotAfter.ToString('yyyy-MM-dd'); Subject = $c.Subject; Thumbprint = $c.Thumbprint }
    }
}
$total = $s.Certificates.Count
$s.Close()
Write-Output ("Certificates in store: {0}; expired or expiring: {1}" -f $total, $rows.Count)
if ($rows.Count -gt 0) { $rows | Sort-Object DaysLeft | Select-Object -First 100 | Format-Table -AutoSize | Out-String -Width 250 }
if ($rows.Count -gt 100) { Write-Output '(truncated)' }
exit 0
