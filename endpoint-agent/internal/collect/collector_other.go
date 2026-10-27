//go:build !windows && !linux

package collect

// NewPlatform for unsupported OSes returns a platform that reports nothing.
func NewPlatform() Platform { return unsupported{} }
