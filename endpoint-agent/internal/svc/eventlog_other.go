//go:build !windows

package svc

// ReportFatal is a no-op off Windows: systemd captures stderr in the journal.
func ReportFatal(string) {}

// ReportInfo is a no-op off Windows.
func ReportInfo(string) {}
