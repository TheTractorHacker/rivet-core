//go:build !windows && !linux

package store

// verifyOwnedDir: the owner check is implemented for Linux (perm_linux.go) and Windows (perm_windows.go), the two supported platforms.
func verifyOwnedDir(string) error { return nil }
