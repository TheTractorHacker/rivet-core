//go:build windows

package deploy

import (
	"golang.org/x/sys/windows/registry"
)

const uninstallPath = `SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall`

const maxEntries = 20000

type winRegistry struct{}

func defaultRegistry() Registry { return winRegistry{} }

// Uninstall lists the keys of one view (the 32 bit view is the WOW6432Node branch, selected by the access flag).
func (winRegistry) Uninstall(v View) ([]UninstallEntry, error) {
	access := uint32(registry.ENUMERATE_SUB_KEYS | registry.QUERY_VALUE)
	if v == View32 {
		access |= registry.WOW64_32KEY
	} else {
		access |= registry.WOW64_64KEY
	}
	k, err := registry.OpenKey(registry.LOCAL_MACHINE, uninstallPath, access)
	if err != nil {
		return nil, err
	}
	defer k.Close()
	names, err := k.ReadSubKeyNames(-1)
	if err != nil {
		return nil, err
	}
	if len(names) > maxEntries {
		names = names[:maxEntries]
	}
	out := make([]UninstallEntry, 0, len(names))
	for _, n := range names {
		sk, err := registry.OpenKey(k, n, registry.QUERY_VALUE|registry.WOW64_64KEY&access)
		if err != nil {
			sk, err = registry.OpenKey(k, n, registry.QUERY_VALUE)
			if err != nil {
				continue
			}
		}
		e := UninstallEntry{KeyName: n}
		e.DisplayName, _, _ = sk.GetStringValue("DisplayName")
		e.DisplayVersion, _, _ = sk.GetStringValue("DisplayVersion")
		sk.Close()
		out = append(out, e)
	}
	return out, nil
}
