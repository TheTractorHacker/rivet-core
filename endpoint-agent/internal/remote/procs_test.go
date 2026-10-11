package remote

import (
	"context"
	"encoding/json"
	"errors"
	"strings"
	"testing"
	"time"
)

type fakeProcs struct {
	snaps    [][]ProcSample
	i        int
	byPID    map[int]ProcSample
	self     int
	parent   int
	killed   []int
	killErr  error
	killSig  string
	lookupEr error
}

func (f *fakeProcs) Snapshot(context.Context) ([]ProcSample, error) {
	s := f.snaps[min(f.i, len(f.snaps)-1)]
	f.i++
	return append([]ProcSample(nil), s...), nil
}
func (f *fakeProcs) Lookup(_ context.Context, pid int) (ProcSample, bool, error) {
	p, ok := f.byPID[pid]
	return p, ok, f.lookupEr
}
func (f *fakeProcs) Kill(pid int, force bool) (string, error) {
	if f.killErr != nil {
		return "", f.killErr
	}
	f.killed = append(f.killed, pid)
	if force {
		return "KILL", nil
	}
	return "TERM", nil
}
func (f *fakeProcs) Self() (int, int) { return f.self, f.parent }

func newFakeProcs() *fakeProcs {
	t0 := time.Date(2026, 10, 1, 0, 0, 0, 0, time.UTC)
	m := map[int]ProcSample{
		1:    {PID: 1, Name: "systemd", Started: t0},
		2:    {PID: 2, Name: "kthreadd", Kernel: true, Started: t0},
		50:   {PID: 50, PPID: 2, Name: "kworker", Kernel: true, Started: t0},
		700:  {PID: 700, PPID: 1, Name: "rivetit-agent", Started: t0},
		699:  {PID: 699, PPID: 1, Name: "supervisor", Started: t0},
		4242: {PID: 4242, PPID: 1, Name: "sleep", User: "root", Started: t0, Mem: 1000},
		4300: {PID: 4300, PPID: 1, Name: "Code Helper (Renderer)", Started: t0},
		4400: {PID: 4400, PPID: 1, Name: "dbus-daemon", Started: t0},
	}
	return &fakeProcs{byPID: m, self: 700, parent: 699}
}

func TestKillSafetyChecks(t *testing.T) {
	type c struct {
		body, detail string
		killed       bool
	}
	for _, x := range []c{
		{`{"pid":4242,"name":"sleep"}`, "", true},
		{`{"pid":4242,"name":"sleep","force":true}`, "", true},
		{`{"pid":4300,"name":"Code Helper (Renderer)"}`, "", true},
		{`{"pid":4242,"name":"definitely-not-sleep"}`, DetailNameMismatch, false},
		{`{"pid":4242,"name":"SLEEP"}`, DetailNameMismatch, false}, // case matters on Linux
		{`{"pid":1,"name":"systemd"}`, DetailInvalidPID, false},
		{`{"pid":0,"name":"x"}`, DetailInvalidPID, false},
		{`{"pid":-4,"name":"x"}`, DetailInvalidPID, false},
		{`{"pid":4242.0,"name":"sleep"}`, DetailInvalidPID, false},
		{`{"pid":"4242","name":"sleep"}`, DetailInvalidPID, false},
		{`{"pid":99999999999,"name":"sleep"}`, DetailInvalidPID, false},
		{`{"pid":2,"name":"kthreadd"}`, DetailRefusedProtect, false},
		{`{"pid":50,"name":"kworker"}`, DetailKernelThread, false},
		{`{"pid":700,"name":"rivetit-agent"}`, DetailRefusedSelf, false},
		{`{"pid":699,"name":"supervisor"}`, DetailRefusedSelf, false},
		{`{"pid":4400,"name":"dbus-daemon"}`, DetailRefusedProtect, false},
		{`{"pid":4242,"name":"sleep;reboot"}`, DetailInvalidName, false},
		{`{"pid":4242,"name":""}`, DetailInvalidName, false},
		{`{"pid":4242}`, DetailInvalidName, false},
		{`{"pid":9999,"name":"ghost"}`, DetailNotFound, false},
	} {
		f := newFakeProcs()
		r := Procs{GOOS: "linux", B: f}.Kill(context.Background(), json.RawMessage(x.body), 4096)
		if (r.Detail == "") != r.OK || r.Detail != x.detail {
			t.Errorf("%s: OK=%v detail=%q; want detail %q", x.body, r.OK, r.Detail, x.detail)
		}
		if (len(f.killed) == 1) != x.killed {
			t.Errorf("%s: killed=%v", x.body, f.killed)
		}
		var m map[string]any
		if err := json.Unmarshal([]byte(r.Output), &m); err != nil {
			t.Errorf("%s: output not JSON %q", x.body, r.Output)
		}
	}
	f := newFakeProcs()
	if r := (Procs{GOOS: "linux", B: f}).Kill(context.Background(), json.RawMessage(`{"pid":4242,"name":"sleep","force":true}`), 4096); !strings.Contains(r.Output, `"signal":"KILL"`) {
		t.Errorf("force: %s", r.Output)
	}
	f = newFakeProcs()
	f.killErr = ErrProcDenied
	if r := (Procs{GOOS: "linux", B: f}).Kill(context.Background(), json.RawMessage(`{"pid":4242,"name":"sleep"}`), 4096); r.OK || r.Detail != "permission_denied" {
		t.Errorf("%+v", r)
	}
	f = newFakeProcs()
	f.killErr = ErrProcNotFound
	if r := (Procs{GOOS: "linux", B: f}).Kill(context.Background(), json.RawMessage(`{"pid":4242,"name":"sleep"}`), 4096); r.Detail != DetailNotFound {
		t.Errorf("%+v", r)
	}
	f = newFakeProcs()
	f.lookupEr = errors.New("boom")
	if r := (Procs{GOOS: "linux", B: f}).Kill(context.Background(), json.RawMessage(`{"pid":4242,"name":"sleep"}`), 4096); r.OK || len(f.killed) != 0 {
		t.Errorf("a failed lookup must not kill: %+v %v", r, f.killed)
	}
	if r := (Procs{GOOS: "linux", B: newFakeProcs()}).Kill(context.Background(), json.RawMessage(`not json`), 4096); !r.Cannot {
		t.Errorf("%+v", r)
	}
}

func TestKillOnWindowsComparesNamesCaseInsensitively(t *testing.T) {
	f := &fakeProcs{self: 900, parent: 800, byPID: map[int]ProcSample{
		5000: {PID: 5000, Name: "Notepad.exe"}, 4: {PID: 4, Name: "System"}, 600: {PID: 600, Name: "lsass.exe"}, 601: {PID: 601, Name: "LSASS.EXE"},
	}}
	p := Procs{GOOS: "windows", B: f}
	if r := p.Kill(context.Background(), json.RawMessage(`{"pid":5000,"name":"notepad.EXE"}`), 4096); !r.OK {
		t.Errorf("%+v", r)
	}
	if r := p.Kill(context.Background(), json.RawMessage(`{"pid":4,"name":"System"}`), 4096); r.Detail != DetailInvalidPID {
		t.Errorf("pid 4 on windows: %+v", r)
	}
	if r := p.Kill(context.Background(), json.RawMessage(`{"pid":600,"name":"lsass.exe"}`), 4096); r.Detail != DetailRefusedProtect {
		t.Errorf("lsass: %+v", r)
	}
	if r := p.Kill(context.Background(), json.RawMessage(`{"pid":601,"name":"lsass.exe"}`), 4096); r.Detail != DetailRefusedProtect {
		t.Errorf("LSASS.EXE: %+v", r)
	}
	if len(f.killed) != 1 || f.killed[0] != 5000 {
		t.Errorf("killed %v", f.killed)
	}
}

func TestProcListParamsAndOutput(t *testing.T) {
	for _, b := range []string{`{"limit":0}`, `{"limit":501}`, `{"limit":-1}`, `{"limit":"5"}`, `{"sort":"name"}`, `{"sort":5}`, `[1]`} {
		if _, _, ok := ParseListParams(json.RawMessage(b)); ok {
			t.Errorf("%s accepted", b)
		}
	}
	if l, s, ok := ParseListParams(nil); !ok || l != 200 || s != "cpu" {
		t.Errorf("defaults %d %s %v", l, s, ok)
	}
	t0 := time.Date(2026, 10, 1, 0, 0, 0, 0, time.UTC)
	a := []ProcSample{{PID: 10, Name: "idle", Started: t0, CPU: 0, Mem: 100}, {PID: 11, Name: "busy", Started: t0, CPU: 1 * time.Second, Mem: 50}, {PID: 12, Name: "fat", Started: t0, CPU: 0, Mem: 9000}}
	b := []ProcSample{{PID: 10, Name: "idle", Started: t0, CPU: 0, Mem: 100}, {PID: 11, Name: "busy", Started: t0, CPU: 1*time.Second + 250*time.Millisecond, Mem: 50}, {PID: 12, Name: "fat", Started: t0, CPU: 0, Mem: 9000}, {PID: 13, Name: "new", Started: t0, CPU: 5 * time.Second}}
	clock := t0
	p := Procs{GOOS: "linux", B: &fakeProcs{snaps: [][]ProcSample{a, b}}, SampleWait: 500 * time.Millisecond,
		Now:   func() time.Time { v := clock; return v },
		Sleep: func(_ context.Context, d time.Duration) error { clock = clock.Add(d); return nil }}
	r := p.List(context.Background(), json.RawMessage(`{"limit":3,"sort":"cpu"}`), 1<<20)
	if !r.OK {
		t.Fatalf("%+v", r)
	}
	var out struct {
		Count     int  `json:"count"`
		Truncated bool `json:"truncated"`
		Sampled   int  `json:"sampled_ms"`
		Processes []struct {
			PID     int     `json:"pid"`
			Name    string  `json:"name"`
			CPU     float64 `json:"cpu_pct"`
			Mem     uint64  `json:"mem_bytes"`
			Start   string  `json:"started"`
			Cmdline string  `json:"cmdline"`
		} `json:"processes"`
	}
	if err := json.Unmarshal([]byte(r.Output), &out); err != nil {
		t.Fatal(err)
	}
	if out.Count != 3 || !out.Truncated || out.Sampled != 500 {
		t.Errorf("%+v", out)
	}
	if out.Processes[0].PID != 11 || out.Processes[0].CPU != 50 {
		t.Errorf("busy first with 50%% of a core: %+v", out.Processes[0])
	}
	if out.Processes[1].PID != 12 { // equal cpu: more memory first
		t.Errorf("tie broken by memory: %+v", out.Processes)
	}
	for _, pr := range out.Processes {
		if pr.Cmdline != "" {
			t.Error("command lines must never be collected")
		}
	}
	if strings.Contains(r.Output, "cmdline") {
		t.Error("no command line member")
	}
	m := p.List(context.Background(), json.RawMessage(`{"limit":4,"sort":"mem"}`), 1<<20)
	if !strings.Contains(m.Output, `"pid":12`) || strings.Index(m.Output, `"pid":12`) > strings.Index(m.Output, `"pid":10`) {
		t.Errorf("sorted by memory: %s", m.Output)
	}
	if bad := p.List(context.Background(), json.RawMessage(`{"limit":0}`), 1<<20); !bad.Cannot {
		t.Errorf("%+v", bad)
	}
}

func TestProcListFitsTheCap(t *testing.T) {
	t0 := time.Date(2026, 10, 1, 0, 0, 0, 0, time.UTC)
	var s []ProcSample
	for i := 0; i < 500; i++ {
		s = append(s, ProcSample{PID: 100 + i, PPID: 1, Name: "worker-process-" + time.Duration(i).String(), User: "service-account", Started: t0, Mem: uint64(i * 1000)})
	}
	p := Procs{GOOS: "linux", B: &fakeProcs{snaps: [][]ProcSample{s, s}}, SampleWait: time.Millisecond, Sleep: func(context.Context, time.Duration) error { return nil }}
	for _, cap := range []int{200000, 20000, 3000, 400} {
		r := p.List(context.Background(), json.RawMessage(`{"limit":500}`), cap)
		if !r.OK || len(r.Output) > cap {
			t.Fatalf("cap %d: ok=%v len=%d", cap, r.OK, len(r.Output))
		}
		var m map[string]any
		if err := json.Unmarshal([]byte(r.Output), &m); err != nil {
			t.Fatalf("cap %d: %v", cap, err)
		}
		if int(m["count"].(float64)) < 500 && m["truncated"] != true {
			t.Errorf("cap %d shortened without saying so", cap)
		}
	}
}
