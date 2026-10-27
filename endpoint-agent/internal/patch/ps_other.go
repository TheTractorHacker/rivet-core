//go:build !windows

package patch

// defaultPS: there is no PowerShell runner off Windows (the Windows code path is only reachable with an injected runner in tests).
func defaultPS() PSRunner { return nil }
