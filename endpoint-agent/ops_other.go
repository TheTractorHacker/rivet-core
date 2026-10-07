//go:build !windows && !linux

package main

import (
	"fmt"
	"os"

	"rivetit-agent/internal/svc"
)

// realOps on unsupported unix flavours: no services, no elevation.
// `setup --no-service` is the only supported mode.
func realOps() sysOps {
	return sysOps{
		isElevated:   func() bool { return true },
		elevate:      func([]string) (int, error) { return 0, fmt.Errorf("elevation is Windows-only") },
		executable:   os.Executable,
		stopService:  svc.StopService,
		installSvc:   svc.InstallService,
		startService: svc.StartService,
		registerARP:  func(string, string) error { return nil },
		removeARP:    func() error { return nil },
		message:      func(string, string, bool) {},
	}
}
