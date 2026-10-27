$ErrorActionPreference = 'Continue'
$RootDir = 'C:\'; if (-not [string]::IsNullOrEmpty($root_dir)) { $RootDir = [string]$root_dir }
$N = 15; if ($null -ne $top_n) { try { $N = [int]$top_n } catch { Write-Output "invalid top_n"; exit 2 } }
if ($N -lt 1 -or $N -gt 50) { Write-Output "top_n must be 1..50"; exit 2 }
if ($RootDir -notmatch '^[A-Za-z]:\\[^<>|?*"\x00-\x1f]{0,240}$' -or $RootDir -match '[\r\n]' -or $RootDir -match '(^|\\)\.\.(\\|$)') { Write-Output 'invalid root_dir'; exit 2 }
if (-not (Test-Path -LiteralPath $RootDir -PathType Container)) { Write-Output "root_dir not found: $RootDir"; exit 2 }
Write-Output "== Largest folders under $RootDir =="
$sw = [System.Diagnostics.Stopwatch]::StartNew()
$limit = 420
$script:cut = $false
function Get-DirSize([string]$p) {
  $t = [long]0
  $st = New-Object System.Collections.Generic.Stack[string]
  $st.Push($p)
  while ($st.Count -gt 0) {
    if ($sw.Elapsed.TotalSeconds -gt $limit) { $script:cut = $true; break }
    $d = $st.Pop()
    try {
      foreach ($i in (New-Object System.IO.DirectoryInfo $d).EnumerateFileSystemInfos()) {
        if ($i.Attributes -band [IO.FileAttributes]::ReparsePoint) { continue }
        if ($i.Attributes -band [IO.FileAttributes]::Directory) { $st.Push($i.FullName) } else { $t += $i.Length }
      }
    } catch { }
  }
  return $t
}
$rows = @()
$loose = [long]0
try {
  foreach ($i in (New-Object System.IO.DirectoryInfo $RootDir).EnumerateFileSystemInfos()) {
    if ($i.Attributes -band [IO.FileAttributes]::ReparsePoint) { continue }
    if ($i.Attributes -band [IO.FileAttributes]::Directory) {
      if ($script:cut) { break }
      $rows += New-Object PSObject -Property @{ Folder = $i.FullName; Bytes = (Get-DirSize $i.FullName) }
    } else { $loose += $i.Length }
  }
} catch { Write-Output ('Could not enumerate: ' + $_.Exception.Message) }
$rows | Sort-Object Bytes -Descending | Select-Object -First $N Folder, @{n='SizeGB';e={[math]::Round($_.Bytes/1GB,2)}}, @{n='SizeMB';e={[math]::Round($_.Bytes/1MB,0)}} | Format-Table -AutoSize | Out-String -Width 200
Write-Output ('Files directly in root: {0:N1} MB' -f ($loose/1MB))
if ($rows.Count -gt $N) { Write-Output "(truncated to top $N of $($rows.Count) folders)" }
if ($script:cut) { Write-Output "NOTE: time budget of $limit s reached; sizes are partial." }
Write-Output ('Scan took {0:N0} s' -f $sw.Elapsed.TotalSeconds)
exit 0
