//go:build windows

// UNVERIFIED on real Windows: compiled and vetted only.
package main

import (
	"fmt"

	"golang.org/x/sys/windows/registry"
)

const arpKey = `Software\Microsoft\Windows\CurrentVersion\Uninstall\RivetITAgent`

// registerARPEntry creates the Add/Remove Programs entry (64-bit registry view).
func registerARPEntry(installDir, exe string) error {
	k, _, err := registry.CreateKey(registry.LOCAL_MACHINE, arpKey, registry.SET_VALUE|registry.WOW64_64KEY)
	if err != nil {
		return err
	}
	defer k.Close()
	for name, v := range map[string]string{
		"DisplayName":     "RivetIT Endpoint Agent",
		"DisplayVersion":  version,
		"Publisher":       arpPublisher,
		"InstallLocation": installDir,
		"DisplayIcon":     exe,
		"UninstallString": fmt.Sprintf(`"%s" uninstall`, exe),
	} {
		if err := k.SetStringValue(name, v); err != nil {
			return err
		}
	}
	for _, name := range []string{"NoModify", "NoRepair"} {
		if err := k.SetDWordValue(name, 1); err != nil {
			return err
		}
	}
	return nil
}

func removeARPEntry() error {
	err := registry.DeleteKey(registry.LOCAL_MACHINE, arpKey)
	if err == registry.ErrNotExist {
		return nil
	}
	return err
}
