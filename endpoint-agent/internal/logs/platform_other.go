//go:build !linux && !windows

package logs

import "rivetit-agent/internal/remote"

// PlatformReaders: there are none on this OS.
func PlatformReaders(remote.Runner) map[string]Reader { return map[string]Reader{} }

// Supported reports whether log collection exists on this OS.
func Supported() bool { return false }
