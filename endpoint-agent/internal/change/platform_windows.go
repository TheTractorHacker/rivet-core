//go:build windows

package change

// Supported reports whether change monitoring exists on this OS.
func Supported() bool { return true }

// Platform is the platform name the server uses.
func Platform() string { return "windows" }
