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
const (
	TypePowerShell = "powershell"
	TypeShell      = "shell"
	TypeCollect    = "collect"
	TypeReboot     = "reboot"
)

// ReasonUnsupportedPlatform is the local reason (and the output prefix) of a
// job that is authentic but cannot run on this OS.
const ReasonUnsupportedPlatform = "unsupported_platform"

// supportedOn reports whether a job type can run on the given GOOS.
func supportedOn(goos, typ string) bool {
	switch typ {
	case TypeCollect, TypeReboot:
		return true
	case TypePowerShell:
		return goos == "windows"
	case TypeShell:
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
	for _, t := range []string{TypePowerShell, TypeShell, TypeCollect, TypeReboot} {
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
