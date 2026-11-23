//go:build !windows

package store

import (
	"errors"
	"fmt"
	"os"
	"path/filepath"
)

func ensureDir(dir string) error {
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return err
	}
	// CORE-AGENT-5: a directory that already existed is not trusted merely because it is there (Linux; perm_linux.go).
	if err := verifyOwnedDir(dir); err != nil {
		return fmt.Errorf("refusing to use the agent state directory: %w", err)
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

// EnsureProtectedDir creates the program (install) directory or verifies an existing one before the installer copies the binary into it: on Linux it
// must not be a symbolic link, must be owned by root (or the account running the installer) and must not be writable by other accounts, and neither may
// any folder above it (CORE-AGENT-5, the counterpart of the Windows owner and DACL check, CORE-DS-47). Other systems: create only.
func EnsureProtectedDir(dir string) error {
	if err := os.MkdirAll(dir, 0o755); err != nil {
		return err
	}
	if err := verifyOwnedDir(dir); err != nil {
		return fmt.Errorf("refusing to install into %s: %w", dir, err)
	}
	return nil
}
