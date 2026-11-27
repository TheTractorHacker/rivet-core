//go:build linux

package store

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// CORE-AGENT-5: the Linux agent refuses a state or install directory that someone else could have prepared (red team PoC t_statedir.sh: root
// installed into /home/nobody/st and /home/nobody/bin, which nobody created first, and nobody then replaced the root-owned binary and config).
func TestLinuxAgentDirectoriesMustBeOwnedAndNotWritableByOthers(t *testing.T) {
	base := t.TempDir()
	must := func(err error) {
		t.Helper()
		if err != nil {
			t.Fatal(err)
		}
	}
	refused := func(name, path, want string, open func(string) error) {
		t.Helper()
		err := open(path)
		if err == nil || !strings.Contains(err.Error(), want) {
			t.Errorf("%s: %v (want %q)", name, err, want)
		}
	}
	openState := func(p string) error { _, err := Open(p); return err }

	// fresh directories are created and accepted (also below a /tmp-style sticky world-writable folder)
	if _, err := Open(filepath.Join(base, "state")); err != nil {
		t.Fatalf("fresh state dir: %v", err)
	}
	must(EnsureProtectedDir(filepath.Join(base, "inst")))
	sticky := filepath.Join(base, "tmp")
	must(os.Mkdir(sticky, 0o755))
	must(os.Chmod(sticky, 0o777|os.ModeSticky))
	if _, err := Open(filepath.Join(sticky, "state")); err != nil {
		t.Fatalf("state dir below a sticky world-writable folder: %v", err)
	}

	// a symbolic link
	real := filepath.Join(base, "real")
	must(os.Mkdir(real, 0o700))
	must(os.Symlink(real, filepath.Join(base, "link")))
	refused("symlinked state dir", filepath.Join(base, "link"), "symbolic link", openState)
	refused("symlinked install dir", filepath.Join(base, "link"), "symbolic link", EnsureProtectedDir)

	// a world-writable directory, and one below a world-writable folder without the sticky bit
	ww := filepath.Join(base, "ww")
	must(os.Mkdir(ww, 0o755))
	must(os.Chmod(ww, 0o777))
	refused("world-writable state dir", ww, "writable by other accounts", openState)
	refused("world-writable install dir", ww, "writable by other accounts", EnsureProtectedDir)
	refused("state dir below a world-writable folder", filepath.Join(ww, "state"), "writable by other accounts", openState)
	refused("install dir below a world-writable folder", filepath.Join(ww, "bin"), "writable by other accounts", EnsureProtectedDir)

	// owned by another account (needs root to arrange)
	if os.Geteuid() != 0 {
		t.Log("not root: the foreign-owner case is covered by the PoC run as root (t_statedir.sh)")
		return
	}
	foreign := filepath.Join(base, "foreign")
	must(os.MkdirAll(filepath.Join(foreign, "st"), 0o700))
	must(os.Chown(foreign, 65534, 65534))
	must(os.Chown(filepath.Join(foreign, "st"), 65534, 65534))
	refused("state dir owned by another account", filepath.Join(foreign, "st"), "owned by uid 65534", openState)
	refused("install dir below a folder another account owns", filepath.Join(foreign, "bin"), "owned by uid 65534", EnsureProtectedDir)
}
