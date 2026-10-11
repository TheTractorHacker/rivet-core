$ErrorActionPreference = 'Continue'
Write-Output '== .NET and Visual C++ runtimes (Windows) =='
Write-Output '-- .NET Framework --'
$rel = (Get-ItemProperty -Path 'HKLM:\SOFTWARE\Microsoft\NET Framework Setup\NDP\v4\Full' -Name Release -ErrorAction SilentlyContinue).Release
if ($rel) {
    $map = @((533320, '4.8.1'), (528040, '4.8'), (461808, '4.7.2'), (461308, '4.7.1'), (460798, '4.7'), (394802, '4.6.2'), (394254, '4.6.1'), (393295, '4.6'), (379893, '4.5.2'), (378675, '4.5.1'), (378389, '4.5'))
    $v = 'unknown (older or newer than the mapping)'
    foreach ($m in $map) { if ($rel -ge $m[0]) { $v = $m[1]; break } }
    Write-Output ("Release {0} = .NET Framework {1}" -f $rel, $v)
} else { Write-Output '4.5+ not found' }
Write-Output '-- .NET / .NET Core runtimes --'
$dn = Join-Path $env:ProgramFiles 'dotnet\dotnet.exe'
if (Test-Path -LiteralPath $dn) {
    $o = & $dn --list-runtimes 2>&1 | Out-String
    Write-Output $o.TrimEnd()
} else { Write-Output 'dotnet.exe not installed' }
Write-Output '-- Visual C++ Redistributables --'
$paths = 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\*', 'HKLM:\SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall\*'
$vc = Get-ItemProperty -Path $paths -ErrorAction SilentlyContinue | Where-Object { $_.DisplayName -like 'Microsoft Visual C++*Redistributable*' } | Sort-Object DisplayName -Unique
if ($vc) { $vc | Select-Object DisplayName, DisplayVersion | Format-Table -AutoSize | Out-String -Width 200 } else { Write-Output 'None found' }
exit 0
