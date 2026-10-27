$ErrorActionPreference = 'Continue'
Write-Output '== Hosts file (Windows) =='
$f = Join-Path $env:SystemRoot 'System32\drivers\etc\hosts'
if (-not (Test-Path -LiteralPath $f)) { Write-Output ("NOT AVAILABLE: {0} not found" -f $f); exit 0 }
$i = Get-Item -LiteralPath $f
Write-Output ("File: {0}" -f $f)
Write-Output ("Size: {0} bytes, last write: {1}" -f $i.Length, $i.LastWriteTime.ToString('yyyy-MM-dd HH:mm:ss'))
try { Write-Output ("SHA256: {0}" -f (Get-FileHash -LiteralPath $f -Algorithm SHA256 -ErrorAction Stop).Hash) } catch { Write-Output 'SHA256: NOT AVAILABLE' }
$lines = @(Get-Content -LiteralPath $f -ErrorAction SilentlyContinue | Where-Object { $_.Trim() -ne '' -and -not $_.TrimStart().StartsWith('#') })
Write-Output ("Active (non-comment) entries: {0}" -f $lines.Count)
$lines | Select-Object -First 200 | ForEach-Object { Write-Output ("  {0}" -f $_.Trim()) }
if ($lines.Count -gt 200) { Write-Output '(truncated)' }
exit 0
