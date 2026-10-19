//go:build !windows

package change

// SystemRegistry is nil off Windows: registry watches are refused in the
// configuration, and the tests use a fake.
func SystemRegistry() Registry { return nil }

// StartNotifier does nothing off Windows (polling only).
func StartNotifier(roots []NotifyRoot, wake func()) (stop func()) { return func() {} }
