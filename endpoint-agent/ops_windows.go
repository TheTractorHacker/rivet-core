//go:build windows

package main

import (
	"os"

	"rivetit-agent/internal/svc"
)

const arpPublisher = "RivetIT"

func realOps() sysOps {
	return sysOps{
		isElevated:   isElevatedWindows,
		elevate:      relaunchElevated,
		executable:   os.Executable,
		stopService:  svc.StopService,
		installSvc:   svc.InstallService,
		startService: svc.StartService,
		registerARP:  registerARPEntry,
		removeARP:    removeARPEntry,
		message:      showMessage,
	}
}
