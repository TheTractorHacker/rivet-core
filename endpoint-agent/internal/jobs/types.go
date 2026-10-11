package jobs

import (
	"fmt"
	"runtime"
	"sort"
)

// Job types. "powershell" and "shell" are the platform script runners: the
// server signs whichever one fits the device (it learns the platform and the
// capability list from the check-in); an agent that is handed the other one
// answers with a failed result whose reason is ReasonUnsupportedPlatform.
// "python" runs a Python 3 script (Linux); the capability "job:python" is only
// announced when python3 is installed.
const (
	TypePowerShell = "powershell"
	TypeShell      = "shell"
	TypePython     = "python"
	TypeCollect    = "collect"
	TypeReboot     = "reboot"

	// Remote tools (RMM Phase 6). They are implemented by handlers the agent
	// injects (Executor.Remote) and run on every OS the agent supports.
	TypeSvcList    = "svc_list"
	TypeSvcControl = "svc_control"
	TypeProcList   = "proc_list"
	TypeProcKill   = "proc_kill"
	TypeFileGet    = "file_get"
	TypeFilePut    = "file_put"
)

// RemoteTypes are the remote tool job types.
var RemoteTypes = []string{TypeSvcList, TypeSvcControl, TypeProcList, TypeProcKill, TypeFileGet, TypeFilePut}

// IsRemoteType reports whether typ is one of the remote tool job types.
func IsRemoteType(typ string) bool {
	for _, t := range RemoteTypes {
		if t == typ {
			return true
		}
	}
	return false
}

// ReasonUnsupportedPlatform is the local reason (and the output prefix) of a
// job that is authentic but cannot run on this OS.
const ReasonUnsupportedPlatform = "unsupported_platform"

// supportedOn reports whether a job type can run on the given GOOS.
func supportedOn(goos, typ string) bool {
	switch typ {
	case TypeCollect, TypeReboot, TypeSvcList, TypeSvcControl, TypeProcList, TypeProcKill, TypeFileGet, TypeFilePut:
		return true
	case TypePowerShell:
		return goos == "windows"
	case TypeShell, TypePython:
		return goos != "windows"
	}
	return false
}

// SupportsType reports whether this agent can run the job type.
func SupportsType(typ string) bool { return supportedOn(runtime.GOOS, typ) }

// ScriptType is the script job type native to this OS.
func ScriptType() string {
	if runtime.GOOS == "windows" {
		return TypePowerShell
	}
	return TypeShell
}

// JobTypes lists the job types this agent runs, sorted.
func JobTypes() []string {
	var out []string
	for _, t := range append([]string{TypePowerShell, TypeShell, TypePython, TypeCollect, TypeReboot}, RemoteTypes...) {
		if t == TypePython && !pythonAvailable() {
			continue
		}
		if SupportsType(t) {
			out = append(out, t)
		}
	}
	sort.Strings(out)
	return out
}

func unsupportedMessage(typ string) string {
	return fmt.Sprintf("%s: job type %q cannot run on %s/%s; nothing was executed", ReasonUnsupportedPlatform, typ, runtime.GOOS, runtime.GOARCH)
}

// ScriptCmd is a ready-to-run interpreter invocation. Cleanup (may be nil)
// removes any temporary file and must be called after the process ended.
type ScriptCmd struct {
	Name    string
	Args    []string
	Env     []string
	Cleanup func()
}
