//go:build windows

package change

import (
	"errors"
	"strings"

	"golang.org/x/sys/windows"
	"golang.org/x/sys/windows/registry"
)

// winRegistry reads the real registry. CROSS-COMPILED AND VETTED ONLY: it has
// never run on a Windows host. The 64-bit view is always used, so a key under
// WOW6432Node is named explicitly in the watch.
type winRegistry struct{}

// SystemRegistry is the registry of this machine.
func SystemRegistry() Registry { return winRegistry{} }

func splitRoot(path string) (registry.Key, string, bool) {
	i := strings.IndexByte(path, '\\')
	if i < 0 {
		return 0, "", false
	}
	switch hiveOf(path[:i]) {
	case "HKLM":
		return registry.LOCAL_MACHINE, path[i+1:], true
	case "HKU":
		return registry.USERS, path[i+1:], true
	}
	return 0, "", false
}

func mapErr(err error) error {
	switch {
	case err == nil:
		return nil
	case errors.Is(err, windows.ERROR_FILE_NOT_FOUND), errors.Is(err, windows.ERROR_PATH_NOT_FOUND), errors.Is(err, registry.ErrNotExist):
		return ErrNotExist
	case errors.Is(err, windows.ERROR_ACCESS_DENIED):
		return ErrAccess
	}
	return err
}

func openKey(path string) (registry.Key, error) {
	root, rest, ok := splitRoot(path)
	if !ok || !RegistryKeyAllowed(path) {
		return 0, ErrAccess // the policy is applied again at the last moment
	}
	k, err := registry.OpenKey(root, rest, registry.QUERY_VALUE|registry.ENUMERATE_SUB_KEYS|registry.WOW64_64KEY)
	return k, mapErr(err)
}

func (winRegistry) SubKeys(path string) ([]string, error) {
	k, err := openKey(path)
	if err != nil {
		return nil, err
	}
	defer k.Close()
	names, err := k.ReadSubKeyNames(-1)
	if len(names) > maxNamesPerKey {
		names = names[:maxNamesPerKey] // a key with more subkeys than this is cut: the watch is partial, never unbounded
	}
	return names, mapErr(err)
}

// maxBigValue is the largest value whose data is read in full (to hash the first
// RegReadCap bytes); anything bigger is described by its size.
const maxBigValue = 64 << 10

// maxNamesPerKey bounds the names read from one key (a user-owned Run key can be filled with millions of values) and, with maxValuesPerKey, the memory one poll can take.
const (
	maxNamesPerKey  = 20000
	maxValuesPerKey = 5000
)

func (winRegistry) Values(path string, want func(string) bool) ([]RegValue, error) {
	k, err := openKey(path)
	if err != nil {
		return nil, err
	}
	defer k.Close()
	names, err := k.ReadValueNames(-1)
	if err != nil {
		return nil, mapErr(err)
	}
	if len(names) > maxNamesPerKey {
		names = names[:maxNamesPerKey]
	}
	var scratch [RegReadCap]byte
	var out []RegValue
	for _, name := range names {
		if !want(name) {
			continue
		}
		if len(out) >= maxValuesPerKey {
			break // the engine's item limit would cut the watch anyway; stop before allocating more
		}
		if SecretName(name) {
			// size and type only: the data of a value that looks like a secret is never read
			n, typ, err := k.GetValue(name, nil)
			if err != nil && !errors.Is(err, registry.ErrShortBuffer) {
				continue
			}
			out = append(out, RegValue{Name: name, Type: typ, Size: n, Secret: true})
			continue
		}
		buf := scratch[:]
		n, typ, err := k.GetValue(name, buf)
		switch {
		case err == nil:
			out = append(out, RegValue{Name: name, Type: typ, Raw: append([]byte(nil), buf[:n]...), Size: n}) // an exact-size copy: the scratch buffer is reused
		case errors.Is(err, registry.ErrShortBuffer):
			if n > maxBigValue {
				out = append(out, RegValue{Name: name, Type: typ, Size: n})
				continue
			}
			big := make([]byte, n)
			n2, typ2, err2 := k.GetValue(name, big)
			if err2 != nil || n2 > len(big) {
				continue
			}
			out = append(out, RegValue{Name: name, Type: typ2, Raw: append([]byte(nil), big[:min(n2, RegReadCap)]...), Size: n2})
		default:
			// a value that vanished or cannot be read is simply not listed this round
		}
	}
	return out, nil
}

func (winRegistry) Users() ([]string, error) {
	k, err := registry.OpenKey(registry.USERS, ``, registry.ENUMERATE_SUB_KEYS|registry.WOW64_64KEY)
	if err != nil {
		return nil, mapErr(err)
	}
	defer k.Close()
	names, err := k.ReadSubKeyNames(-1)
	if err != nil {
		return nil, mapErr(err)
	}
	var out []string
	for _, n := range names {
		if strings.HasSuffix(strings.ToLower(n), "_classes") {
			continue
		}
		if strings.HasPrefix(n, "S-1-5-21-") || strings.HasPrefix(n, "S-1-12-1-") {
			out = append(out, n)
		}
	}
	return out, nil
}
