//go:build !windows

package patch

// defaultRegistry: there is no registry off Windows (the Windows code path is only reachable with an injected Registry in tests).
func defaultRegistry() Registry { return nil }
