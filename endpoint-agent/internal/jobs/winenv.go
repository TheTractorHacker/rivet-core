package jobs

import (
	"path/filepath"
	"strings"
)

// windowsPassEnv are the variables a Windows child inherits from the agent's own environment when they are set. They are the ones PowerShell and the
// command-line tools of Windows resolve things with (PATHEXT is how `whoami` becomes whoami.exe; without COMPUTERNAME `$env:COMPUTERNAME` is empty;
// without the processor variables some installers and architecture checks misbehave). None of them carries a secret and all are part of the
// machine-wide environment a Windows service always has. Anything else (the agent's own configuration, proxy credentials set for the service, ...)
// is NOT passed on.
var windowsPassEnv = []string{
	"COMPUTERNAME", "ComSpec", "PATHEXT", "PSModulePath", "ALLUSERSPROFILE", "PUBLIC", "DriverData",
	"ProgramFiles(x86)", "ProgramW6432", "CommonProgramFiles", "CommonProgramFiles(x86)", "CommonProgramW6432",
	"USERNAME", "USERDOMAIN", "USERPROFILE", "APPDATA", "LOCALAPPDATA", "HOMEDRIVE", "HOMEPATH",
	"PROCESSOR_ARCHITECTURE", "PROCESSOR_ARCHITEW6432", "PROCESSOR_IDENTIFIER", "PROCESSOR_LEVEL", "PROCESSOR_REVISION", "NUMBER_OF_PROCESSORS", "OS",
}

// defaultPathExt is Windows' own default PATHEXT, used when the agent's environment lacks it.
const defaultPathExt = ".COM;.EXE;.BAT;.CMD;.VBS;.VBE;.JS;.JSE;.WSF;.WSH;.MSC;.CPL"

// buildWindowsEnv builds the environment of a job's child process on Windows from a lookup function (os.LookupEnv in production). It is a pure
// function in an untagged file so the Linux test suite covers it: the first real Windows run (docs/rmm/WINDOWS_VERIFICATION.md) showed that a
// four-variable environment made `whoami`, `quser`, `w32tm` and other native tools unresolvable in PowerShell scripts (no PATHEXT) and left
// $env:COMPUTERNAME empty.
func buildWindowsEnv(lookup func(string) (string, bool)) []string {
	get := func(k string) string { v, _ := lookup(k); return v }
	sr := get("SystemRoot")
	if sr == "" {
		sr = get("windir")
	}
	if sr == "" {
		sr = `C:\Windows`
	}
	sys32 := sr + `\System32`
	// A fixed, machine-only PATH: the agent's own PATH may hold per-user or installer entries a job should not pick tools from.
	path := strings.Join([]string{sys32, sr, sys32 + `\Wbem`, sys32 + `\WindowsPowerShell\v1.0`}, ";")
	env := []string{"SystemRoot=" + sr, "windir=" + sr, "PATH=" + path}
	for _, k := range []string{"SystemDrive", "ProgramData", "ProgramFiles", "TEMP", "TMP"} {
		if v := get(k); v != "" {
			env = append(env, k+"="+v)
		}
	}
	seen := map[string]bool{}
	for _, k := range windowsPassEnv {
		if v, ok := lookup(k); ok && v != "" {
			env = append(env, k+"="+v)
			seen[strings.ToUpper(k)] = true
		}
	}
	if !seen["PATHEXT"] {
		env = append(env, "PATHEXT="+defaultPathExt)
	}
	if !seen["COMSPEC"] {
		env = append(env, "ComSpec="+filepath.Join(sys32, "cmd.exe"))
	}
	return env
}
