//go:build !windows

package deploy

func defaultRegistry() Registry { return noRegistry{} }

type noRegistry struct{}

func (noRegistry) Uninstall(View) ([]UninstallEntry, error) { return nil, errNoRegistry }
