$ErrorActionPreference = 'Continue'
$DryRun = $true; if ($null -ne $dry_run -and $dry_run -is [bool]) { $DryRun = $dry_run }
$Skip = $true; if ($null -ne $skip_running -and $skip_running -is [bool]) { $Skip = $skip_running }
Write-Output '== Teams cache clean (Windows) =='
$running = @(Get-Process -Name 'Teams', 'ms-teams', 'MSTeams' -ErrorAction SilentlyContinue)
if ($running.Count -gt 0 -and $Skip) { Write-Output '[SKIP] Teams is running and skip_running is true; nothing changed'; exit 0 }
if ($running.Count -gt 0) { Write-Output 'WARNING: Teams is running; some files may be locked and will be skipped.' }
$total = [long]0
foreach ($u in (Get-ChildItem 'C:\Users' -Directory -ErrorAction SilentlyContinue)) {
    $dirs = @()
    foreach ($s in 'Cache', 'blob_storage', 'Code Cache', 'GPUCache', 'IndexedDB', 'Local Storage', 'tmp') { $dirs += Join-Path $u.FullName ('AppData\Roaming\Microsoft\Teams\' + $s) }
    $dirs += Join-Path $u.FullName 'AppData\Local\Packages\MSTeams_8wekyb3d8bbwe\LocalCache'
    foreach ($d in $dirs) {
        if ($d.Length -lt 20 -or -not (Test-Path -LiteralPath $d)) { continue }
        $items = @(Get-ChildItem -LiteralPath $d -Recurse -File -Force -ErrorAction SilentlyContinue)
        $sz = [long]0; foreach ($i in $items) { $sz += $i.Length }
        if ($items.Count -eq 0) { continue }
        if ($DryRun) { Write-Output ("[DRY-RUN] would clear {0}: {1} file(s), {2} bytes" -f $d, $items.Count, $sz); $total += $sz; continue }
        $freed = [long]0
        foreach ($i in $items) { try { $l = $i.Length; Remove-Item -LiteralPath $i.FullName -Force -ErrorAction Stop; $freed += $l } catch { } }
        $total += $freed
        Write-Output ("[DONE] cleared {0}: {1} bytes freed" -f $d, $freed)
    }
}
if ($DryRun) { Write-Output ("Total that would be freed: {0} bytes" -f $total) } else { Write-Output ("Total freed: {0} bytes" -f $total) }
exit 0
