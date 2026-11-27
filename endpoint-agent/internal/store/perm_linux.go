//go:build linux

package store

import (
	"fmt"
	"os"
	"path/filepath"
	"syscall"
)

// verifyOwnedDir is the Linux owner check of the state and install directories (CORE-AGENT-5, red team 2026-11-21): the directory itself must be a real
// directory (lstat: never a symbolic link) owned by root or by the account the agent runs as, and neither it nor any folder above it may be owned by
// another account or writable by other accounts. Whoever can write a directory can rename or replace the root-owned files inside it (the agent binary,
// config.json, device.token, ca.pem), whatever those files' own owner and mode. A world-writable folder ABOVE it is accepted only with the sticky bit
// (/tmp), where others cannot rename an entry they do not own; group write is accepted for the root group and for the invoking account's own group
// (non-root test runs on systems with user private groups). The account the agent runs as is allowed so tests and non-root runs keep working.
func verifyOwnedDir(dir string) error {
	abs, err := filepath.Abs(dir)
	if err != nil {
		return err
	}
	fi, err := os.Lstat(abs)
	if err != nil {
		return err
	}
	if fi.Mode()&os.ModeSymlink != 0 {
		return fmt.Errorf("%s is a symbolic link; use the real directory", abs)
	}
	if !fi.IsDir() {
		return fmt.Errorf("%s is not a directory", abs)
	}
	euid, egid := uint32(os.Geteuid()), uint32(os.Getegid())
	if err := dirTrusted(abs, fi, euid, egid, false); err != nil {
		return err
	}
	for p := filepath.Dir(abs); ; p = filepath.Dir(p) {
		pi, err := os.Stat(p) // a folder above may be a link (/var -> /data/var): what matters is who can write the folder it points to
		if err != nil {
			return err
		}
		if err := dirTrusted(p, pi, euid, egid, true); err != nil {
			return err
		}
		if p == filepath.Dir(p) {
			return nil
		}
	}
}

func dirTrusted(path string, fi os.FileInfo, euid, egid uint32, above bool) error {
	st, ok := fi.Sys().(*syscall.Stat_t)
	if !ok {
		return nil
	}
	if st.Uid != 0 && st.Uid != euid {
		return fmt.Errorf("%s is owned by uid %d, not by root or the account the agent runs as (uid %d): that account could replace the agent's files. "+
			"Remove it or give it to root (chown root:root, chmod 755), or choose another directory", path, st.Uid, euid)
	}
	perm := fi.Mode().Perm()
	world := perm&0o002 != 0
	group := perm&0o020 != 0 && st.Gid != 0 && st.Gid != egid
	if world && above && fi.Mode()&os.ModeSticky != 0 {
		world = false // /tmp-style: others cannot rename or remove what they do not own
	}
	if world || group {
		return fmt.Errorf("%s is writable by other accounts (mode %#o): they could replace the agent's files. Remove group/other write (chmod go-w) or choose another directory", path, perm)
	}
	return nil
}
