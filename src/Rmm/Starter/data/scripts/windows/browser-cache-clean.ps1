$ErrorActionPreference = 'Continue'
$DryRun = $true; if ($null -ne $dry_run -and $dry_run -is [bool]) { $DryRun = $dry_run }
$b = 'both'; if ($null -ne $browser -and [string]$browser -ne '') { $b = ([string]$browser).ToLower() }
if ($b -notin 'both', 'chrome', 'edge') { Write-Output 'invalid browser (both, chrome, edge)'; exit 2 }
Write-Output '== Browser cache clean (Windows) =='
$targets = @()
if ($b -ne 'edge') { $targets += , @('Chrome', 'chrome', 'Google\Chrome\User Data') }
if ($b -ne 'chrome') { $targets += , @('Edge', 'msedge', 'Microsoft\Edge\User Data') }
$subs = 'Cache', 'Code Cache', 'GPUCache'
$total = [long]0
foreach ($t in $targets) {
    if (Get-Process -Name $t[1] -ErrorAction SilentlyContinue) { Write-Output ("[SKIP] {0} is running; not touching its cache" -f $t[0]); continue }
    foreach ($u in (Get-ChildItem 'C:\Users' -Directory -ErrorAction SilentlyContinue)) {
        $root = Join-Path $u.FullName ('AppData\Local\' + $t[2])
        if (-not (Test-Path -LiteralPath $root)) { continue }
        foreach ($p in (Get-ChildItem -LiteralPath $root -Directory -ErrorAction SilentlyContinue | Where-Object { $_.Name -eq 'Default' -or $_.Name -like 'Profile *' -or $_.Name -eq 'Guest Profile' })) {
            foreach ($sub in $subs) {
                $d = Join-Path $p.FullName $sub
                if ($d.Length -lt 20 -or -not (Test-Path -LiteralPath $d)) { continue }
                $items = @(Get-ChildItem -LiteralPath $d -Recurse -File -Force -ErrorAction SilentlyContinue)
                $sz = [long]0; foreach ($i in $items) { $sz += $i.Length }
                if ($items.Count -eq 0) { continue }
                if ($DryRun) { Write-Output ("[DRY-RUN] would clear {0} {1}\{2}\{3}: {4} file(s), {5} bytes" -f $t[0], $u.Name, $p.Name, $sub, $items.Count, $sz); $total += $sz; continue }
                $freed = [long]0
                foreach ($i in $items) { try { $l = $i.Length; Remove-Item -LiteralPath $i.FullName -Force -ErrorAction Stop; $freed += $l } catch { } }
                $total += $freed
                Write-Output ("[DONE] cleared {0} {1}\{2}\{3}: {4} bytes freed" -f $t[0], $u.Name, $p.Name, $sub, $freed)
            }
        }
    }
}
if ($DryRun) { Write-Output ("Total that would be freed: {0} bytes" -f $total) } else { Write-Output ("Total freed: {0} bytes" -f $total) }
exit 0
