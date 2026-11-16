//go:build windows

package patch

import (
	"errors"

	"golang.org/x/sys/windows/registry"
)

// winRegistry is the real registry (HKLM, 64 bit view). NOT RUN ON WINDOWS yet: compiled and vetted only (see docs/rmm/WINDOWS_UPDATE_CONTROL_TEST.md).
type winRegistry struct{}

func defaultRegistry() Registry { return winRegistry{} }

const view = registry.WOW64_64KEY

func (winRegistry) Get(key, name string) (RegVal, bool, error) {
	k, err := registry.OpenKey(registry.LOCAL_MACHINE, key, registry.QUERY_VALUE|view)
	if err != nil {
		if errors.Is(err, registry.ErrNotExist) {
			return RegVal{}, false, nil
		}
		return RegVal{}, false, err
	}
	defer k.Close()
	_, typ, err := k.GetValue(name, nil)
	if err != nil {
		if errors.Is(err, registry.ErrNotExist) {
			return RegVal{}, false, nil
		}
		return RegVal{}, false, err
	}
	switch typ {
	case registry.DWORD:
		n, _, err := k.GetIntegerValue(name)
		if err != nil {
			return RegVal{}, false, err
		}
		return RegVal{Kind: KindDWord, N: uint32(n)}, true, nil
	case registry.SZ, registry.EXPAND_SZ:
		s, _, err := k.GetStringValue(name)
		if err != nil {
			return RegVal{}, false, err
		}
		return RegVal{Kind: KindString, S: s}, true, nil
	}
	// a value of another type (QWORD, binary): present but not something Core writes. Reported as a string-less value of an impossible kind so callers see "present".
	return RegVal{Kind: "other"}, true, nil
}

func (winRegistry) Names(key string) ([]string, bool, error) {
	k, err := registry.OpenKey(registry.LOCAL_MACHINE, key, registry.QUERY_VALUE|view)
	if err != nil {
		if errors.Is(err, registry.ErrNotExist) {
			return nil, false, nil
		}
		return nil, false, err
	}
	defer k.Close()
	names, err := k.ReadValueNames(-1)
	return names, true, err
}

func (winRegistry) Set(key, name string, v RegVal) error {
	k, _, err := registry.CreateKey(registry.LOCAL_MACHINE, key, registry.SET_VALUE|registry.QUERY_VALUE|view)
	if err != nil {
		return err
	}
	defer k.Close()
	if v.Kind == KindDWord {
		return k.SetDWordValue(name, v.N)
	}
	return k.SetStringValue(name, v.S)
}

func (winRegistry) Delete(key, name string) error {
	k, err := registry.OpenKey(registry.LOCAL_MACHINE, key, registry.SET_VALUE|view)
	if err != nil {
		if errors.Is(err, registry.ErrNotExist) {
			return nil
		}
		return err
	}
	defer k.Close()
	if err := k.DeleteValue(name); err != nil && !errors.Is(err, registry.ErrNotExist) {
		return err
	}
	return nil
}
