$ErrorActionPreference = 'Continue'
Write-Output '== Startup items (Windows) =='
function Show-Run($path, $label) {
    $k = Get-Item -Path $path -ErrorAction SilentlyContinue
    if (-not $k) { return }
    foreach ($n in $k.GetValueNames()) {
        if ($n -eq '') { continue }
        Write-Output ("[{0}] {1} = {2}" -f $label, $n, $k.GetValue($n))
    }
}
Write-Output '-- HKLM Run keys --'
foreach ($p in 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Run', 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\RunOnce', 'HKLM:\SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\Run') { Show-Run $p ($p -replace '^HKLM:\\SOFTWARE\\', 'HKLM ') }
Write-Output '-- Loaded user hives (HKU) --'
if (-not (Test-Path 'HKU:\')) { New-PSDrive -Name HKU -PSProvider Registry -Root HKEY_USERS -ErrorAction SilentlyContinue | Out-Null }
$sids = Get-ChildItem -Path 'HKU:\' -ErrorAction SilentlyContinue | Where-Object { $_.PSChildName -match '^S-1-5-21-' -and $_.PSChildName -notmatch '_Classes$' }
foreach ($s in $sids) {
    foreach ($sub in 'Run', 'RunOnce') { Show-Run ("HKU:\{0}\SOFTWARE\Microsoft\Windows\CurrentVersion\{1}" -f $s.PSChildName, $sub) ("HKU {0} {1}" -f $s.PSChildName, $sub) }
}
Write-Output '-- Startup folders --'
$dirs = @((Join-Path $env:ProgramData 'Microsoft\Windows\Start Menu\Programs\Startup'))
$dirs += Get-ChildItem 'C:\Users' -Directory -ErrorAction SilentlyContinue | ForEach-Object { Join-Path $_.FullName 'AppData\Roaming\Microsoft\Windows\Start Menu\Programs\Startup' }
foreach ($d in $dirs) {
    if (-not (Test-Path -LiteralPath $d)) { continue }
    Get-ChildItem -LiteralPath $d -File -Force -ErrorAction SilentlyContinue | Where-Object { $_.Name -ne 'desktop.ini' } | ForEach-Object { Write-Output ("[Folder] {0}" -f $_.FullName) }
}
Write-Output '-- Win32_StartupCommand --'
try {
    Get-CimInstance Win32_StartupCommand -ErrorAction Stop | Select-Object -First 100 Name, Command, Location, User | Format-Table -AutoSize | Out-String -Width 220
} catch { Write-Output ("NOT AVAILABLE: {0}" -f $_.Exception.Message) }
exit 0
