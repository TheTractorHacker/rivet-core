//go:build linux

package remote

import (
	"bytes"
	"context"
	"errors"
	"io/fs"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"syscall"
	"time"
)

// ProcFS is the Linux ProcBackend, reading /proc. Root and PasswdPath are
// overridable so the tests run against a fake /proc tree.
type ProcFS struct {
	Root       string // default /proc
	PasswdPath string // default /etc/passwd
}

func (p ProcFS) root() string {
	if p.Root == "" {
		return "/proc"
	}
	return p.Root
}

func (p ProcFS) bootTime() time.Time {
	b, err := os.ReadFile(filepath.Join(p.root(), "stat"))
	if err != nil {
		return time.Time{}
	}
	for _, line := range strings.Split(string(b), "\n") {
		if v, ok := strings.CutPrefix(line, "btime "); ok {
			if n, err := strconv.ParseInt(strings.TrimSpace(v), 10, 64); err == nil {
				return time.Unix(n, 0).UTC()
			}
		}
	}
	return time.Time{}
}

func (p ProcFS) users() map[string]string {
	path := p.PasswdPath
	if path == "" {
		path = "/etc/passwd"
	}
	if b, err := os.ReadFile(path); err == nil {
		return ParsePasswd(string(b))
	}
	return nil
}

func (p ProcFS) read(pid int, boot time.Time, users map[string]string) (ProcSample, error) {
	dir := filepath.Join(p.root(), strconv.Itoa(pid))
	b, err := os.ReadFile(filepath.Join(dir, "stat"))
	if err != nil {
		return ProcSample{}, err
	}
	st, err := ParseProcStat(string(b))
	if err != nil {
		return ProcSample{}, err
	}
	s := ProcSample{PID: pid, PPID: st.PPID, Name: st.Comm, Threads: st.Threads, CPU: st.CPUTime(), Mem: uint64(max(st.RSSPages, 0)) * uint64(os.Getpagesize())}
	if !boot.IsZero() {
		s.Started = boot.Add(time.Duration(st.StartTick) * time.Second / ClockTicks)
	}
	if fi, err := os.Stat(dir); err == nil {
		if sys, ok := fi.Sys().(*syscall.Stat_t); ok {
			uid := strconv.FormatUint(uint64(sys.Uid), 10)
			s.User = uid
			if n, ok := users[uid]; ok {
				s.User = n
			}
		}
	}
	return s, nil
}

// Snapshot implements ProcBackend.
func (p ProcFS) Snapshot(ctx context.Context) ([]ProcSample, error) {
	ents, err := os.ReadDir(p.root())
	if err != nil {
		return nil, err
	}
	boot, users := p.bootTime(), p.users()
	var out []ProcSample
	for _, e := range ents {
		pid, err := strconv.Atoi(e.Name())
		if err != nil || pid <= 0 {
			continue
		}
		if ctx.Err() != nil {
			return nil, ctx.Err()
		}
		if s, err := p.read(pid, boot, users); err == nil { // a process may exit mid-scan
			out = append(out, s)
		}
	}
	return out, nil
}

// Lookup implements ProcBackend. The name is /proc/<pid>/comm (read at kill time).
func (p ProcFS) Lookup(ctx context.Context, pid int) (ProcSample, bool, error) {
	s, err := p.read(pid, p.bootTime(), nil)
	if err != nil {
		if errors.Is(err, fs.ErrNotExist) || errors.Is(err, syscall.ESRCH) {
			return ProcSample{}, false, nil
		}
		return ProcSample{}, false, err
	}
	dir := filepath.Join(p.root(), strconv.Itoa(pid))
	if b, err := os.ReadFile(filepath.Join(dir, "comm")); err == nil {
		if c := strings.TrimRight(string(b), "\n"); c != "" {
			s.Name = c
		}
	}
	// Kernel threads: children of kthreadd (pid 2), or no executable and no command line.
	if pid == 2 || s.PPID == 2 {
		s.Kernel = true
	} else if _, err := os.Readlink(filepath.Join(dir, "exe")); err != nil {
		if cl, rerr := os.ReadFile(filepath.Join(dir, "cmdline")); rerr == nil && len(bytes.TrimRight(cl, "\x00")) == 0 {
			s.Kernel = true
		}
	}
	return s, true, nil
}

// Kill implements ProcBackend: SIGTERM, or SIGKILL when force.
func (ProcFS) Kill(pid int, force bool) (string, error) {
	sig, name := syscall.SIGTERM, "TERM"
	if force {
		sig, name = syscall.SIGKILL, "KILL"
	}
	err := syscall.Kill(pid, sig)
	switch {
	case err == nil:
		return name, nil
	case errors.Is(err, syscall.ESRCH):
		return name, ErrProcNotFound
	case errors.Is(err, syscall.EPERM):
		return name, ErrProcDenied
	}
	return name, err
}

// Self implements ProcBackend.
func (ProcFS) Self() (int, int) { return os.Getpid(), os.Getppid() }

// NewProcBackend is the production backend of this OS.
func NewProcBackend() ProcBackend { return ProcFS{} }

// NewServiceBackend is the production backend of this OS.
func NewServiceBackend(r Runner) ServiceBackend { return Systemd{R: r} }
