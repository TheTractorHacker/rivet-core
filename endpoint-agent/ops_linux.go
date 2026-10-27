//go:build linux

package main

import (
	"errors"
	"os"

	"rivetit-agent/internal/svc"
)

// allowNonRootEnv lets the unit/e2e tests run `setup --no-service --state-dir ...`
// as an ordinary user. It only skips the root check: every write still needs the
// file permissions the user has, and it never touches systemd.
const allowNonRootEnv = "RIVETIT_AGENT_ALLOW_NONROOT"

// realOps on Linux: root is the only "elevated" (there is nothing to elevate
// into; the caller must already use sudo), services are systemd units.
func realOps() sysOps {
	return sysOps{
		isElevated: func() bool { return os.Geteuid() == 0 || os.Getenv(allowNonRootEnv) == "1" },
		elevate: func([]string) (int, error) {
			return 0, errors.New("run this command as root (for example with sudo)")
		},
		executable:   os.Executable,
		stopService:  svc.StopService,
		installSvc:   svc.InstallService,
		startService: svc.StartService,
		registerARP:  func(string, string) error { return nil },
		removeARP:    func() error { return nil },
		message:      func(string, string, bool) {},
	}
}
