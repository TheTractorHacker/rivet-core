package deploy

import "errors"

// View selects the 64 or the 32 bit registry view.
type View int

const (
	View64 View = 64
	View32 View = 32
)

// UninstallEntry is one subkey of HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall.
type UninstallEntry struct {
	KeyName        string // the subkey name (an MSI product code is {GUID})
	DisplayName    string
	DisplayVersion string
}

// Registry reads the Add/Remove Programs keys of one view. Injected by the tests; the real one is in registry_windows.go.
type Registry interface {
	Uninstall(v View) ([]UninstallEntry, error)
}

var errNoRegistry = errors.New("no registry on this platform")
