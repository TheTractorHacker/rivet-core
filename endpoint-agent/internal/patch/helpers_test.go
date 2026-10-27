package patch

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"

	"rivetit-agent/internal/remote"
)

func fixture(t testing.TB, name string) string {
	t.Helper()
	b, err := os.ReadFile(filepath.Join("testdata", name))
	if err != nil {
		t.Fatal(err)
	}
	return string(b)
}

// fakeRunner answers commands from a router and records every argv.
type fakeRunner struct {
	mu    sync.Mutex
	calls [][]string // name, then args
	fn    func(prog string, args []string) (string, string, int, error)
}

// real strips a leading /usr/bin/env VAR=value... so the router sees the program and its arguments.
func real(name string, args []string) (string, []string) {
	if filepath.Base(name) != "env" {
		return name, args
	}
	for i, a := range args {
		if strings.HasPrefix(a, "/") {
			return a, args[i+1:]
		}
	}
	return name, args
}

func (f *fakeRunner) Run(ctx context.Context, name string, args []string, maxOut int, timeout time.Duration) (remote.RunResult, error) {
	f.mu.Lock()
	f.calls = append(f.calls, append([]string{name}, args...))
	f.mu.Unlock()
	prog, a := real(name, args)
	out, errs, code, err := f.fn(prog, a)
	return remote.RunResult{Stdout: []byte(out), Stderr: []byte(errs), ExitCode: code}, err
}

func (f *fakeRunner) called(sub string) bool {
	f.mu.Lock()
	defer f.mu.Unlock()
	for _, c := range f.calls {
		if strings.Contains(strings.Join(c, " "), sub) {
			return true
		}
	}
	return false
}

// fakeFS is an in-memory FS.
type fakeFS map[string]string

func (f fakeFS) Exists(p string) bool { _, ok := f[p]; return ok }
func (f fakeFS) ReadTail(p string, max int64) ([]byte, error) {
	s, ok := f[p]
	if !ok {
		return nil, os.ErrNotExist
	}
	if int64(len(s)) > max {
		s = s[int64(len(s))-max:]
	}
	return []byte(s), nil
}

func aptFS(extra map[string]string) fakeFS {
	fs := fakeFS{}
	for _, b := range []string{"apt-get", "dpkg-query", "apt", "apt-cache", "apt-mark", "env"} {
		fs["/usr/bin/"+b] = ""
	}
	for k, v := range extra {
		fs[k] = v
	}
	return fs
}

func rpmFS(mgr string, extra map[string]string) fakeFS {
	fs := fakeFS{"/usr/bin/" + mgr: "", "/usr/bin/rpm": "", "/usr/bin/env": ""}
	for k, v := range extra {
		fs[k] = v
	}
	return fs
}
