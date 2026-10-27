//go:build !windows

package remote

import "path/filepath"

// hostResolve is filepath.EvalSymlinks off Windows (Windows has its own, see resolve_windows.go).
func hostResolve(path string) (string, error) { return filepath.EvalSymlinks(path) }
