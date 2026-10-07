<#
.SYNOPSIS
  Unattended install of the RivetIT endpoint agent (GPO startup script, Intune
  Win32 app / platform script, or an RMM "run script" task).

.DESCRIPTION
  Downloads the agent for this CPU architecture, VERIFIES its SHA-256 (and
  optionally its Authenticode signature), then runs `rivetit-agent.exe install`,
  which enrolls the device and registers + starts the Windows service.
  Idempotent: re-running upgrades the binary and leaves the device identity
  alone; pass a new token only when you want to rotate the credential.

  NOTE: administrators can instead download a per-department, self-installing
  rivetit-agent-<department>.exe from RivetIT (Administration > Endpoint agent);
  it carries its server URL, CA and one-shot token and needs no script. Run it as
  `rivetit-agent-<department>.exe setup --silent` from SYSTEM for unattended use
  (exit codes: 0 ok, 2 bad/expired embedded config, 3 token rejected, 4 network
  retryable, 5 install failure, 6 not elevated). This script stays supported for
  deployments that prefer a verified download plus a token passed separately.

  UNVERIFIED: this script has not been executed on a real Windows host (none was
  available when it was written). Test it on a pilot machine first.

.PARAMETER ServerUrl     RivetIT base URL, e.g. https://rivetit.example.com
.PARAMETER Token         Short-lived enrollment token (prefer -TokenFile or env RIVETIT_ENROLL_TOKEN).
.PARAMETER TokenFile     File holding the enrollment token (deleted by the script if -DeleteTokenFile).
.PARAMETER BaseDownloadUrl  Directory URL that holds rivetit-agent-windows-amd64.exe / -arm64.exe
.PARAMETER Sha256Amd64 / Sha256Arm64  Expected SHA-256 of the exe for each architecture (REQUIRED).
.PARAMETER CaFile        Extra CA certificate (PEM) when RivetIT uses an internal CA.
.PARAMETER PinSpki       Optional hex SHA-256 of the server public key to pin.
.PARAMETER Department    Informational label.
.PARAMETER RequireSignature  Also require a valid Authenticode signature on the exe.

.EXAMPLE
  $env:RIVETIT_ENROLL_TOKEN = '<token>'
  .\install-windows.ps1 -ServerUrl https://rivetit.example.com `
     -BaseDownloadUrl https://rivetit.example.com/downloads/agent `
     -Sha256Amd64 <hash> -Sha256Arm64 <hash>
#>
[CmdletBinding()]
param(
  [Parameter(Mandatory)][string]$ServerUrl,
  [string]$Token,
  [string]$TokenFile,
  [Parameter(Mandatory)][string]$BaseDownloadUrl,
  [Parameter(Mandatory)][string]$Sha256Amd64,
  [Parameter(Mandatory)][string]$Sha256Arm64,
  [string]$CaFile,
  [string]$PinSpki,
  [string]$Department,
  [switch]$RequireSignature,
  [switch]$DeleteTokenFile
)
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

function Fail([string]$m, [int]$code = 1) { Write-Error $m; exit $code }

# --- preconditions -----------------------------------------------------------
$id = [Security.Principal.WindowsIdentity]::GetCurrent()
if (-not (New-Object Security.Principal.WindowsPrincipal $id).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
  Fail 'Run as Administrator / SYSTEM.' 5
}
if ($ServerUrl -notmatch '^https://') { Fail 'ServerUrl must be https://' 2 }
if ($BaseDownloadUrl -notmatch '^https://') { Fail 'BaseDownloadUrl must be https://' 2 }
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12 -bor [Net.SecurityProtocolType]::Tls13

# Architecture: honour a 32-bit PowerShell host on a 64-bit OS.
$arch = if ($env:PROCESSOR_ARCHITEW6432) { $env:PROCESSOR_ARCHITEW6432 } else { $env:PROCESSOR_ARCHITECTURE }
switch ($arch.ToUpper()) {
  'AMD64' { $file = 'rivetit-agent-windows-amd64.exe'; $want = $Sha256Amd64 }
  'ARM64' { $file = 'rivetit-agent-windows-arm64.exe'; $want = $Sha256Arm64 }
  default { Fail "Unsupported architecture '$arch' (supported: AMD64, ARM64)." 3 }
}
if ($want -notmatch '^[0-9a-fA-F]{64}$') { Fail 'Expected SHA-256 must be 64 hex characters.' 2 }

# Token: parameter, file, or environment (the environment is preferred; it does not appear in process listings).
if (-not $Token -and $TokenFile) { $Token = (Get-Content -LiteralPath $TokenFile -Raw).Trim() }
if (-not $Token) { $Token = $env:RIVETIT_ENROLL_TOKEN }
$existing = Test-Path -LiteralPath (Join-Path $env:ProgramData 'RivetIT\Agent\device.token')
if (-not $Token -and -not $existing) { Fail 'An enrollment token is required for a first install.' 2 }

# --- private work dir (SYSTEM + Administrators only) ----------------------------
$work = Join-Path $env:ProgramData ('RivetIT\Setup\' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $work -Force | Out-Null
& icacls.exe $work /inheritance:r /grant:r 'SYSTEM:(OI)(CI)F' 'Administrators:(OI)(CI)F' | Out-Null
try {
  $exe = Join-Path $work $file
  $url = $BaseDownloadUrl.TrimEnd('/') + '/' + $file
  Write-Host "Downloading $url"
  Invoke-WebRequest -Uri $url -OutFile $exe -UseBasicParsing -MaximumRedirection 0

  # --- verify BEFORE running anything -----------------------------------------
  $got = (Get-FileHash -LiteralPath $exe -Algorithm SHA256).Hash
  if ($got -ne $want.ToUpper()) { Fail "SHA-256 mismatch for $file (got $got). Refusing to install." 4 }
  if ($RequireSignature) {
    $sig = Get-AuthenticodeSignature -LiteralPath $exe
    if ($sig.Status -ne 'Valid') { Fail "Authenticode signature is $($sig.Status). Refusing to install." 4 }
  }

  # --- install -----------------------------------------------------------------
  $argv = @('install', '--server', $ServerUrl)
  if ($CaFile)     { $argv += @('--ca', $CaFile) }
  if ($PinSpki)    { $argv += @('--pin-spki', $PinSpki) }
  if ($Department) { $argv += @('--department', $Department) }
  if ($Token)      { $env:RIVETIT_ENROLL_TOKEN = $Token }
  $p = Start-Process -FilePath $exe -ArgumentList $argv -Wait -PassThru -NoNewWindow
  if ($p.ExitCode -ne 0) { Fail "rivetit-agent install failed with exit code $($p.ExitCode)." $p.ExitCode }
  Write-Host 'RivetIT agent installed.'
}
finally {
  Remove-Item Env:\RIVETIT_ENROLL_TOKEN -ErrorAction SilentlyContinue
  if ($DeleteTokenFile -and $TokenFile) { Remove-Item -LiteralPath $TokenFile -Force -ErrorAction SilentlyContinue }
  Remove-Item -LiteralPath $work -Recurse -Force -ErrorAction SilentlyContinue
}
