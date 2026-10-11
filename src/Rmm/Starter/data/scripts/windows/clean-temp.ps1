$ErrorActionPreference = 'Continue'
$DryRun = $true; if ($null -ne $dry_run -and $dry_run -is [bool]) { $DryRun = $dry_run }
$Days = 7; if ($null -ne $age_days) { try { $Days = [int]$age_days } catch { Write-Output "invalid age_days"; exit 2 } }
if ($Days -lt 1 -or $Days -gt 365) { Write-Output "age_days must be 1..365"; exit 2 }
function Fmt($b){ if($b -ge 1GB){ "{0:N2} GB" -f ($b/1GB) } elseif($b -ge 1MB){ "{0:N1} MB" -f ($b/1MB) } else { "{0:N0} KB" -f ($b/1KB) } }
Write-Output '== Clean temp files =='
$cut = (Get-Date).AddDays(-$Days)
Write-Output ("Mode: " + $(if ($DryRun) { 'dry run' } else { 'live' }) + "; files older than $Days day(s) (before $cut)")
function Get-Old([string]$root) {
  $files = New-Object System.Collections.ArrayList
  $dirs = New-Object System.Collections.ArrayList
  $st = New-Object System.Collections.Generic.Stack[string]
  $st.Push($root)
  while ($st.Count -gt 0) {
    $d = $st.Pop()
    try {
      foreach ($i in (New-Object System.IO.DirectoryInfo $d).EnumerateFileSystemInfos()) {
        if ($i.Attributes -band [IO.FileAttributes]::ReparsePoint) { continue }
        if ($i.Attributes -band [IO.FileAttributes]::Directory) { [void]$dirs.Add($i); $st.Push($i.FullName) }
        elseif ($i.LastWriteTime -lt $cut) { [void]$files.Add($i) }
      }
    } catch { }
  }
  return @{ Files = $files; Dirs = $dirs }
}
$locs = @()
$locs += (Join-Path $env:SystemRoot 'Temp')
foreach ($u in @(Get-ChildItem -LiteralPath (Join-Path $env:SystemDrive 'Users') -Directory -Force -ErrorAction SilentlyContinue)) {
  $locs += (Join-Path $u.FullName 'AppData\Local\Temp')
}
$total = [long]0
foreach ($loc in $locs) {
  if ($loc.Length -lt 8 -or $loc -match '^[A-Za-z]:\\?$' -or $loc -notmatch '\\Temp$') { Write-Output "[SKIP] unsafe path $loc"; continue }
  if (-not (Test-Path -LiteralPath $loc -PathType Container)) { continue }
  $it = Get-Item -LiteralPath $loc -Force -ErrorAction SilentlyContinue
  if ($null -eq $it -or ($it.Attributes -band [IO.FileAttributes]::ReparsePoint)) { Write-Output "[SKIP] $loc is a link"; continue }
  $r = Get-Old $loc
  $sum = [long]0
  foreach ($f in $r.Files) { $sum += $f.Length }
  if ($DryRun) {
    Write-Output ("[DRY-RUN] would remove {0} file(s), {1} from {2}" -f $r.Files.Count, (Fmt $sum), $loc)
    continue
  }
  $freed = [long]0; $ok = 0; $fail = 0
  foreach ($f in $r.Files) {
    try { Remove-Item -LiteralPath $f.FullName -Force -ErrorAction Stop; $freed += $f.Length; $ok++ } catch { $fail++ }
  }
  foreach ($d in ($r.Dirs | Sort-Object { $_.FullName.Length } -Descending)) {
    if ($d.LastWriteTime -lt $cut) { try { [System.IO.Directory]::Delete($d.FullName) } catch { } }
  }
  $total += $freed
  Write-Output ("[DONE] {0}: removed {1} file(s), freed {2}; {3} in use or denied" -f $loc, $ok, (Fmt $freed), $fail)
}
if (-not $DryRun) { Write-Output ("Total freed: " + (Fmt $total) + " ($total bytes)") }
exit 0
