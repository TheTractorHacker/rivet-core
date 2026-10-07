//go:build !windows

package store

import (
	"errors"
	"os"
	"path/filepath"
)

func ensureDir(dir string) error {
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return err
	}
	return os.Chmod(dir, 0o700)
}

func syncDir(dir string) {
	if d, err := os.Open(dir); err == nil {
		_ = d.Sync()
		d.Close()
	}
}

func tightenPerms(path string) {
	if fi, err := os.Stat(path); err == nil && fi.Mode().Perm()&0o077 != 0 {
		_ = os.Chmod(path, 0o600)
	}
}

func looseFiles(dir string, names []string) []string {
	var out []string
	for _, n := range names {
		if fi, err := os.Stat(filepath.Join(dir, n)); err == nil && fi.Mode().Perm()&0o077 != 0 {
			out = append(out, n)
		}
	}
	return out
}

// protect on non-Windows is a plain 0600 file; the scheme says so explicitly
// so a file can never be mistaken for a DPAPI blob.
func protect(b []byte) (string, string, error) { return schemePlain, string(b), nil }

func unprotect(scheme, blob string) ([]byte, error) {
	if scheme != schemePlain {
		return nil, errors.New("credential protected with a scheme unavailable on this OS: " + scheme)
	}
	return []byte(blob), nil
}
