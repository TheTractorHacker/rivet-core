//go:build windows

package logs

import "rivetit-agent/internal/remote"

// PlatformReaders are the readers of this OS: the event log on Windows (wevtutil.exe).
// CROSS-COMPILED AND VETTED ONLY; the Windows path has never been executed here.
func PlatformReaders(r remote.Runner) map[string]Reader {
	return map[string]Reader{"eventlog": EventLogReader{R: r}}
}

// Supported reports whether log collection exists on this OS.
func Supported() bool { return true }
