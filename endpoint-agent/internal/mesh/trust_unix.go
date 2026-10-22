//go:build !windows

package mesh

import (
	"fmt"
	"os"
	"path/filepath"
	"syscall"
)

// trustedExe reports whether the agent, which runs as root, may execute path (security review F10): the file and every directory above it must be a real file or
// directory (not a symlink), owned by root and writable by no one else. A candidate that a non-root user could have planted or replaced is never run.
func trustedExe(path string) bool { return checkTrusted(path, 0) == nil }

// checkTrusted is trustedExe with the owner it expects (0 in production; the tests use their own uid because they cannot create root-owned files).
func checkTrusted(path string, owner uint32) error {
	p := filepath.Clean(path)
	if !filepath.IsAbs(p) {
		return fmt.Errorf("%s is not an absolute path", p)
	}
	for first := true; ; first = false {
		fi, err := os.Lstat(p)
		if err != nil {
			return err
		}
		if fi.Mode()&os.ModeSymlink != 0 {
			return fmt.Errorf("%s is a symbolic link", p)
		}
		if first && !fi.Mode().IsRegular() {
			return fmt.Errorf("%s is not a regular file", p)
		}
		if !first && !fi.IsDir() {
			return fmt.Errorf("%s is not a directory", p)
		}
		st, ok := fi.Sys().(*syscall.Stat_t)
		if !ok {
			return fmt.Errorf("%s: owner unknown", p)
		}
		if st.Uid != owner {
			return fmt.Errorf("%s is not owned by root (uid %d)", p, st.Uid)
		}
		if fi.Mode().Perm()&0o022 != 0 {
			return fmt.Errorf("%s is writable by group or others", p)
		}
		if parent := filepath.Dir(p); parent == p {
			return nil
		} else {
			p = parent
		}
	}
}
