package remote

import (
	"context"
	"encoding/json"
	"errors"
	"math"
	"runtime"
	"sort"
	"strings"
	"time"
)

// ProcSample is one process in a snapshot. CPU is cumulative CPU time.
type ProcSample struct {
	PID, PPID, Threads int
	Name, User         string
	CPU                time.Duration
	Mem                uint64
	Started            time.Time
	Kernel             bool // Linux kernel thread (never listed as killable)
}

// Errors a ProcBackend returns from Kill.
var (
	ErrProcNotFound = errors.New("process not found")
	ErrProcDenied   = errors.New("permission denied")
)

// ProcBackend is the operating-system side of the process tools.
type ProcBackend interface {
	Snapshot(ctx context.Context) ([]ProcSample, error)
	// Lookup re-reads one process NOW (used by proc_kill right before the signal).
	Lookup(ctx context.Context, pid int) (ProcSample, bool, error)
	Kill(pid int, force bool) (signal string, err error)
	// Self returns the agent's own pid and its parent's.
	Self() (pid, ppid int)
}

// UserResolver is an optional ProcBackend capability: fill ProcSample.User for
// the processes that are actually reported (Windows token lookups are costly).
type UserResolver interface {
	ResolveUsers(ctx context.Context, ps []ProcSample)
}

// Procs implements the proc_list and proc_kill jobs.
type Procs struct {
	GOOS       string
	B          ProcBackend
	Now        func() time.Time
	Sleep      func(ctx context.Context, d time.Duration) error
	SampleWait time.Duration // gap between the two CPU samples (default 500 ms)
}

func (p Procs) goos() string {
	if p.GOOS == "" {
		return runtime.GOOS
	}
	return p.GOOS
}

func (p Procs) now() time.Time {
	if p.Now != nil {
		return p.Now()
	}
	return time.Now()
}

func (p Procs) sleep(ctx context.Context, d time.Duration) error {
	if p.Sleep != nil {
		return p.Sleep(ctx, d)
	}
	t := time.NewTimer(d)
	defer t.Stop()
	select {
	case <-ctx.Done():
		return ctx.Err()
	case <-t.C:
		return nil
	}
}

type procListParams struct {
	Limit *int   `json:"limit"`
	Sort  string `json:"sort"`
}

type procRow struct {
	PID      int     `json:"pid"`
	PPID     int     `json:"ppid"`
	Name     string  `json:"name"`
	User     string  `json:"user"`
	CPUPct   float64 `json:"cpu_pct"`
	MemBytes uint64  `json:"mem_bytes"`
	Threads  int     `json:"threads"`
	Started  string  `json:"started"`
}

type procListOut struct {
	V         int       `json:"v"`
	SampledMS int64     `json:"sampled_ms"`
	Count     int       `json:"count"`
	Truncated bool      `json:"truncated"`
	Processes []procRow `json:"processes"`
}

// ParseListParams validates the proc_list params: limit 1..500 (default 200),
// sort cpu|mem (default cpu).
func ParseListParams(raw json.RawMessage) (limit int, sortBy string, ok bool) {
	var q procListParams
	if err := decodeParams(raw, &q); err != nil {
		return 0, "", false
	}
	limit, sortBy = 200, "cpu"
	if q.Limit != nil {
		if *q.Limit < 1 || *q.Limit > 500 {
			return 0, "", false
		}
		limit = *q.Limit
	}
	switch q.Sort {
	case "":
	case "cpu", "mem":
		sortBy = q.Sort
	default:
		return 0, "", false
	}
	return limit, sortBy, true
}

func round1(f float64) float64 { return math.Round(f*10) / 10 }

// List is proc_list: two snapshots ~500 ms apart give the CPU percentage (of one
// core, like top). No command lines are ever collected.
func (p Procs) List(ctx context.Context, raw json.RawMessage, maxOut int) Result {
	limit, sortBy, ok := ParseListParams(raw)
	if !ok {
		return cannotLaunch(DetailBadParams)
	}
	wait := p.SampleWait
	if wait <= 0 {
		wait = 500 * time.Millisecond
	}
	s1, err := p.B.Snapshot(ctx)
	if err != nil {
		return failure("snapshot_failed: "+err.Error(), nil)
	}
	t1 := p.now()
	if err := p.sleep(ctx, wait); err != nil {
		return failure("cancelled", nil)
	}
	s2, err := p.B.Snapshot(ctx)
	if err != nil {
		return failure("snapshot_failed: "+err.Error(), nil)
	}
	elapsed := p.now().Sub(t1)
	if elapsed <= 0 {
		elapsed = wait
	}
	base := make(map[int]ProcSample, len(s1))
	for _, s := range s1 {
		base[s.PID] = s
	}
	type scored struct {
		s   ProcSample
		cpu float64
	}
	all := make([]scored, 0, len(s2))
	for _, s := range s2 {
		var cpu float64
		if b, ok := base[s.PID]; ok && b.Started.Equal(s.Started) && s.CPU >= b.CPU {
			cpu = float64(s.CPU-b.CPU) / float64(elapsed) * 100
		}
		all = append(all, scored{s, round1(cpu)})
	}
	sort.SliceStable(all, func(i, j int) bool {
		a, b := all[i], all[j]
		if sortBy == "mem" {
			if a.s.Mem != b.s.Mem {
				return a.s.Mem > b.s.Mem
			}
		} else if a.cpu != b.cpu {
			return a.cpu > b.cpu
		}
		if a.s.Mem != b.s.Mem {
			return a.s.Mem > b.s.Mem
		}
		return a.s.PID < b.s.PID
	})
	trunc := false
	if len(all) > limit {
		all, trunc = all[:limit], true
	}
	picked := make([]ProcSample, len(all))
	for i, a := range all {
		picked[i] = a.s
	}
	if ur, ok := p.B.(UserResolver); ok {
		ur.ResolveUsers(ctx, picked)
	}
	rows := make([]procRow, len(all))
	for i, a := range all {
		r := picked[i]
		started := ""
		if !r.Started.IsZero() {
			started = r.Started.UTC().Format(time.RFC3339)
		}
		rows[i] = procRow{PID: r.PID, PPID: r.PPID, Name: clip(r.Name, 128), User: clip(r.User, 128), CPUPct: a.cpu, MemBytes: r.Mem, Threads: r.Threads, Started: started}
	}
	out, fits := fitList(len(rows), trunc, maxOut, func(n int, t bool) string {
		return marshal(procListOut{V: 1, SampledMS: wait.Milliseconds(), Count: n, Truncated: t, Processes: nonNil(rows[:n])})
	})
	if !fits {
		return failure("output_cap_too_small", nil)
	}
	return Result{OK: true, Output: out}
}

var linuxProtectedProcs = map[string]bool{
	"systemd": true, "init": true, "kthreadd": true, "systemd-journald": true, "dbus-daemon": true,
	"dbus-broker": true, "systemd-logind": true, "systemd-udevd": true,
}

var windowsProtectedProcs = map[string]bool{
	"system": true, "smss.exe": true, "csrss.exe": true, "wininit.exe": true, "winlogon.exe": true,
	"services.exe": true, "lsass.exe": true, "registry": true, "memory compression": true,
}

// ProtectedProcess reports whether a process name is on the never-kill list.
func ProtectedProcess(goos, name string) bool {
	if goos == "windows" {
		return windowsProtectedProcs[strings.ToLower(name)]
	}
	return linuxProtectedProcs[name]
}

type procKillParams struct {
	PID   json.RawMessage `json:"pid"`
	Name  string          `json:"name"`
	Force bool            `json:"force"`
}

type procKillOut struct {
	V      int    `json:"v"`
	PID    int    `json:"pid"`
	Name   string `json:"name"`
	Signal string `json:"signal"`
	OK     bool   `json:"ok"`
	Detail string `json:"detail"`
}

func namesEqual(goos, a, b string) bool {
	if goos == "windows" {
		return strings.EqualFold(a, b)
	}
	return a == b
}

// Kill is proc_kill. Every check must hold or nothing is signalled.
func (p Procs) Kill(ctx context.Context, raw json.RawMessage, _ int) Result {
	var q procKillParams
	if err := decodeParams(raw, &q); err != nil {
		return cannotLaunch(DetailBadParams)
	}
	goos := p.goos()
	pid, pidOK := ParsePID(q.PID, goos)
	out := procKillOut{V: 1, PID: pid, Name: clip(q.Name, 128)}
	refuse := func(detail string) Result {
		out.Detail = detail
		return Result{OK: false, Detail: detail, Output: marshal(out)}
	}
	if !pidOK {
		return refuse(DetailInvalidPID)
	}
	if !ValidProcName(q.Name) {
		return refuse(DetailInvalidName)
	}
	self, parent := p.B.Self()
	if pid == self || pid == parent {
		return refuse(DetailRefusedSelf)
	}
	cur, found, err := p.B.Lookup(ctx, pid)
	if err != nil {
		return refuse("lookup_failed: " + err.Error())
	}
	if !found {
		return refuse(DetailNotFound)
	}
	if !namesEqual(goos, cur.Name, q.Name) {
		return refuse(DetailNameMismatch)
	}
	if ProtectedProcess(goos, cur.Name) || ProtectedProcess(goos, q.Name) {
		return refuse(DetailRefusedProtect)
	}
	if cur.Kernel {
		return refuse(DetailKernelThread)
	}
	sig, err := p.B.Kill(pid, q.Force)
	out.Signal = sig
	switch {
	case err == nil:
		out.OK = true
		return Result{OK: true, Output: marshal(out)}
	case errors.Is(err, ErrProcNotFound):
		return refuse(DetailNotFound)
	case errors.Is(err, ErrProcDenied):
		return refuse("permission_denied")
	}
	return refuse("kill_failed: " + err.Error())
}
