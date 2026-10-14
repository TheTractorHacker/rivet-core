$ErrorActionPreference = 'Continue'
Write-Output '== Battery report (Windows) =='
$bat = @(Get-CimInstance Win32_Battery -ErrorAction SilentlyContinue)
if ($bat.Count -eq 0) { Write-Output 'No battery present'; exit 0 }
$st = @{ 1 = 'Discharging'; 2 = 'AC power'; 3 = 'Fully charged'; 4 = 'Low'; 5 = 'Critical'; 6 = 'Charging'; 7 = 'Charging and high'; 8 = 'Charging and low'; 9 = 'Charging and critical'; 10 = 'Undefined'; 11 = 'Partially charged' }
foreach ($b in $bat) {
    $s = [string]$b.BatteryStatus; if ($st.ContainsKey([int]$b.BatteryStatus)) { $s = $st[[int]$b.BatteryStatus] }
    # 71582788 (0x4444444) is Windows' "unknown" marker, reported while the charger is connected
    $rt = [string]$b.EstimatedRunTime; if ($b.EstimatedRunTime -eq 71582788 -or $null -eq $b.EstimatedRunTime) { $rt = 'unknown (on AC power)' }
    Write-Output ("Battery: {0} | status: {1} | charge: {2}% | est. runtime min: {3}" -f $b.Name, $s, $b.EstimatedChargeRemaining, $rt)
}
$tmp = Join-Path $env:TEMP ('batt-{0}.xml' -f [guid]::NewGuid().ToString('N'))
try {
    $null = & powercfg.exe /batteryreport /xml /output $tmp 2>&1
    if (Test-Path -LiteralPath $tmp) {
        [xml]$x = Get-Content -LiteralPath $tmp -Raw
        $ns = New-Object System.Xml.XmlNamespaceManager($x.NameTable)
        $ns.AddNamespace('b', $x.DocumentElement.NamespaceURI)
        foreach ($n in $x.SelectNodes('//b:Battery', $ns)) {
            $dc = [double]$n.DesignCapacity; $fc = [double]$n.FullChargeCapacity
            Write-Output ("Report: {0} | design {1} mWh | full charge {2} mWh | cycles {3}" -f $n.Id, $dc, $fc, $n.CycleCount)
            if ($dc -gt 0) {
                $h = [math]::Round(100 * $fc / $dc, 1)
                Write-Output ("Health: {0}% of design capacity" -f $h)
            }
        }
    } else { Write-Output 'NOT AVAILABLE: powercfg did not produce a battery report' }
} catch { Write-Output ("NOT AVAILABLE: could not parse battery report ({0})" -f $_.Exception.Message) }
finally { if (Test-Path -LiteralPath $tmp) { Remove-Item -LiteralPath $tmp -Force -ErrorAction SilentlyContinue } }
exit 0
