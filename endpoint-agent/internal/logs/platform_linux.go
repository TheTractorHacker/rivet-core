//go:build linux

package logs

import "rivetit-agent/internal/remote"

// PlatformReaders are the readers of this OS: journald on Linux.
func PlatformReaders(r remote.Runner) map[string]Reader {
	return map[string]Reader{"journal": JournalReader{R: r}}
}

// Supported reports whether log collection exists on this OS.
func Supported() bool { return true }
