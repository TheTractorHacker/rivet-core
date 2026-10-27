package hypervisor

// The fixed PowerShell of the Hyper-V collector. They contain no text from the
// server, the network or a VM, use single quotes only (so the argv quoting of
// Windows cannot alter them) and only READ: Get-VM, Get-VMSnapshot,
// Get-VMHardDiskDrive, Get-VHD, Get-VMReplication, Get-ClusterNode and
// Get-ClusterGroup. No Get-VMHost secret, no VM configuration file, no guest
// data is touched.

// psDetect runs after sc.exe found the vmms service: it
// confirms that the Hyper-V PowerShell module is installed.
const psDetect = `[Console]::OutputEncoding=[Text.Encoding]::UTF8;if(Get-Command Get-VM -ErrorAction SilentlyContinue){'yes'}else{'no'}`

const psStates = `$ErrorActionPreference='Stop';[Console]::OutputEncoding=[Text.Encoding]::UTF8;` +
	`$r=@(Get-VM | Select-Object -First 1000 | ForEach-Object { @{id=[string]$_.Id;state=[string]$_.State} });` +
	`ConvertTo-Json -InputObject $r -Compress -Depth 3`

const psFull = `$ErrorActionPreference='Stop';$ProgressPreference='SilentlyContinue';[Console]::OutputEncoding=[Text.Encoding]::UTF8;` +
	`$err=New-Object System.Collections.ArrayList;` +
	`function UnixTs($d){ if($d -and $d.Year -gt 1971){ [int64]([DateTimeOffset]$d).ToUnixTimeSeconds() } else { $null } };` +
	`if(-not (Get-Command Get-VM -ErrorAction SilentlyContinue)){ ConvertTo-Json -InputObject @{ok=$false;error='the Hyper-V PowerShell module is not installed'} -Compress; exit 0 };` +
	`$repl=@{};` +
	`try{ foreach($r in @(Get-VMReplication -ErrorAction Stop)){ $repl[[string]$r.VMId]=[string]$r.Health } }catch{ [void]$err.Add('replication: '+$_.Exception.Message) };` +
	`$sw=[Diagnostics.Stopwatch]::StartNew();` +
	`$ver='';try{ $ver=[string][Environment]::OSVersion.Version }catch{};` +
	`$vms=New-Object System.Collections.ArrayList;$drives=@{};$n=0;` +
	`foreach($v in @(Get-VM)){` +
	`if($n -ge 1000){ break };$n++;` +
	`$x=[ordered]@{id=[string]$v.Id;name=[string]$v.Name;state=[string]$v.State;gen=[int]$v.Generation;ver=[string]$v.Version;cpus=[int]$v.ProcessorCount;cpu=[int]$v.CPUUsage;` +
	`memA=[int64]$v.MemoryAssigned;memD=[int64]$v.MemoryDemand;memMax=[int64]$(if($v.DynamicMemoryEnabled){$v.MemoryMaximum}else{$v.MemoryStartup});` +
	`up=[int64]$v.Uptime.TotalSeconds;hb=[string]$v.Heartbeat;repl=$repl[[string]$v.Id]};` +
	`if($v.Path -and ([string]$v.Path) -match '^[A-Za-z]:'){ $drives[([string]$v.Path).Substring(0,1).ToUpper()]=1 };` +
	`if($sw.Elapsed.TotalSeconds -lt 100){` +
	`try{ $s=@(Get-VMSnapshot -VM $v -ErrorAction Stop);$x.snaps=$s.Count;if($s.Count -gt 0){ $x.oldest=UnixTs(($s | Sort-Object CreationTime | Select-Object -First 1).CreationTime) } }catch{ };` +
	`if($n -le 200){ try{ $sz=[int64]0;$fs=[int64]0;$k=0;foreach($d in @(Get-VMHardDiskDrive -VM $v -ErrorAction Stop)){ $k++;if($k -gt 8){ break };` +
	`if($d.Path){ if(([string]$d.Path) -match '^[A-Za-z]:'){ $drives[([string]$d.Path).Substring(0,1).ToUpper()]=1 };` +
	`try{ $h=Get-VHD -Path $d.Path -ErrorAction Stop;$sz+=[int64]$h.Size;$fs+=[int64]$h.FileSize }catch{ } } };` +
	`if($sz -gt 0){ $x.disk=$sz;$x.diskUsed=$fs } }catch{ } } };` +
	`[void]$vms.Add($x) };` +
	`$vols=New-Object System.Collections.ArrayList;` +
	`foreach($l in $drives.Keys){ try{ $di=New-Object System.IO.DriveInfo($l);if($di.IsReady){ [void]$vols.Add(@{name=($l+':');total=[int64]$di.TotalSize;free=[int64]$di.AvailableFreeSpace}) } }catch{ } };` +
	`ConvertTo-Json -InputObject @{ok=$true;version=$ver;vms=$vms;vols=$vols;errors=$err} -Compress -Depth 5`

// psCluster runs only when the ClusSvc service exists.
const psCluster = `$ErrorActionPreference='Stop';[Console]::OutputEncoding=[Text.Encoding]::UTF8;` +
	`try{ Import-Module FailoverClusters;$c=Get-Cluster;$nodes=@(Get-ClusterNode);$groups=@(Get-ClusterGroup);` +
	`$nl=@($nodes | ForEach-Object { @{name=[string]$_.Name;state=[string]$_.State} });` +
	`$failed=@($groups | Where-Object { [string]$_.State -eq 'Failed' }).Count;` +
	`ConvertTo-Json -InputObject @{ok=$true;name=[string]$c.Name;nodes=$nl;failed=$failed} -Compress -Depth 4 }` +
	`catch{ ConvertTo-Json -InputObject @{ok=$false;error=[string]$_.Exception.Message} -Compress }`
