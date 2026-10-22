//go:build !windows

package mesh

import (
	"os"
	"path/filepath"
	"testing"
)

// Security review F10: the agent (root) never runs a Mesh agent that someone else could have planted or replaced.
func TestOnlyAnOwnedNonWritableNonLinkedExecutableIsTrusted(t *testing.T) {
	me := uint32(os.Getuid())
	// the temp dir itself may be group/other-writable on a shared /tmp: build the tree below a private directory and check from there by trusting only that subtree
	base := t.TempDir()
	if err := os.Chmod(base, 0o755); err != nil {
		t.Fatal(err)
	}
	mk := func(rel string, mode os.FileMode) string {
		p := filepath.Join(base, rel)
		if err := os.MkdirAll(filepath.Dir(p), 0o755); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(p, []byte("#!/bin/sh\n"), 0o755); err != nil {
			t.Fatal(err)
		}
		if err := os.Chmod(p, mode); err != nil {
			t.Fatal(err)
		}
		return p
	}
	// trustedBelow checks the file and its parents only up to base (the parents above are the test machine's, not under test)
	trustedBelow := func(p string) error {
		if err := checkTrusted(p, me); err == nil {
			return nil
		} else if !stopsAbove(err, base) {
			return err
		}
		return nil
	}
	ok := mk("opt/meshagent/meshagent", 0o755)
	if err := trustedBelow(ok); err != nil {
		t.Errorf("a plain owned file in owned, non-writable directories must be trusted: %v", err)
	}
	if err := checkTrusted(mk("opt/mesh2/meshagent", 0o775), me); err == nil {
		t.Error("a group-writable executable was trusted")
	}
	if err := checkTrusted(mk("opt/mesh3/meshagent", 0o757), me); err == nil {
		t.Error("an other-writable executable was trusted")
	}
	// a writable parent directory
	w := mk("opt/mesh4/meshagent", 0o755)
	if err := os.Chmod(filepath.Dir(w), 0o777); err != nil {
		t.Fatal(err)
	}
	if err := checkTrusted(w, me); err == nil {
		t.Error("an executable in a world-writable directory was trusted")
	}
	// a symbolic link, to a good file, as the executable and as a directory
	link := filepath.Join(base, "opt", "meshagent-link")
	if err := os.Symlink(ok, link); err != nil {
		t.Fatal(err)
	}
	if err := checkTrusted(link, me); err == nil {
		t.Error("a symlinked executable was trusted")
	}
	dirLink := filepath.Join(base, "opt", "meshdir")
	if err := os.Symlink(filepath.Dir(ok), dirLink); err != nil {
		t.Fatal(err)
	}
	if err := checkTrusted(filepath.Join(dirLink, "meshagent"), me); err == nil {
		t.Error("an executable below a symlinked directory was trusted")
	}
	// the wrong owner (the real check expects root; this process is not root unless the tests run as root)
	if os.Getuid() != 0 {
		if err := checkTrusted(ok, 0); err == nil {
			t.Error("a file owned by a non-root user was trusted as root-owned")
		}
		if trustedExe(ok) {
			t.Error("trustedExe accepted a non-root-owned file")
		}
	}
	// a directory is not an executable; a missing file is not trusted
	if err := checkTrusted(filepath.Dir(ok), me); err == nil {
		t.Error("a directory was trusted as an executable")
	}
	if err := checkTrusted(filepath.Join(base, "nope"), me); err == nil {
		t.Error("a missing file was trusted")
	}
}

func stopsAbove(err error, base string) bool {
	// a failure that names a directory above base (the machine's own tree) is not what this test is about
	msg := err.Error()
	return len(msg) > 0 && !containsPath(msg, base)
}

func containsPath(msg, base string) bool {
	for i := 0; i+len(base) <= len(msg); i++ {
		if msg[i:i+len(base)] == base {
			return true
		}
	}
	return false
}

func TestFoundSkipsAnUntrustedCandidateAndTakesTheNextTrustedOne(t *testing.T) {
	bad, good := "/opt/meshagent-test/meshagent", "/usr/local/mesh/meshagent"
	p := Probe{GOOS: "linux", Paths: []string{bad, good}, Exists: func(string) bool { return true }, Trusted: func(s string) bool { return s == good }}
	if got := p.Found(); got != good {
		t.Errorf("Found = %q, want the trusted one", got)
	}
	p.Trusted = func(string) bool { return false }
	if got := p.Found(); got != "" {
		t.Errorf("Found = %q with nothing trusted", got)
	}
	// fixtures that do not set Trusted keep working
	p.Trusted = nil
	if got := p.Found(); got != bad {
		t.Errorf("Found = %q", got)
	}
}
