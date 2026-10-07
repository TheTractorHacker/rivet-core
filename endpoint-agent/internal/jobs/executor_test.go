//go:build !windows

package jobs

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"syscall"
	"testing"
	"time"

	"rivetit-agent/internal/api"
)

func start(t *testing.T, e *Executor) {
	ctx, cancel := context.WithCancel(context.Background())
	e.Start(ctx)
	t.Cleanup(func() { cancel(); e.Wait() })
}

func TestRunsJobAndReports(t *testing.T) {
	k, dir := newKeys(t), t.TempDir()
	rep := &fakeReporter{}
	e := newExec(t, k, OpenStore(filepath.Join(dir, "jobs.json")), rep)
	start(t, e)
	e.Submit([]api.Job{k.signJob(t, baseJob("j1", "echo hello; exit 0"))})
	waitIdle(t, e)
	got := rep.states("j1")
	if len(got) != 2 || got[0] != "running" || got[1] != "succeeded" {
		t.Fatalf("states %v", got)
	}
	last := rep.reports[len(rep.reports)-1]
	if !strings.Contains(last.Output, "hello") || last.ExitCode == nil || *last.ExitCode != 0 || last.StartedAt == nil {
		t.Fatalf("bad report %+v", last)
	}
	rec, _ := e.Store.Get("j1")
	if !rec.Reported {
		t.Fatal("not marked reported")
	}
}

func TestFailedExitCode(t *testing.T) {
	k := newKeys(t)
	rep := &fakeReporter{}
	e := newExec(t, k, OpenStore(""), rep)
	start(t, e)
	e.Submit([]api.Job{k.signJob(t, baseJob("j1", "echo boom >&2; exit 7"))})
	waitIdle(t, e)
	last := rep.reports[len(rep.reports)-1]
	if last.State != "failed" || *last.ExitCode != 7 || !strings.Contains(last.Output, "boom") {
		t.Fatalf("%+v", last)
	}
}

func TestInvalidJobsNeverRun(t *testing.T) {
	k, other, dir := newKeys(t), newKeys(t), t.TempDir()
	marker := filepath.Join(dir, "ran")
	rep := &fakeReporter{}
	e := newExec(t, k, OpenStore(""), rep)
	start(t, e)
	script := "touch " + marker
	bad := other.signJob(t, baseJob("j1", script)) // wrong key
	exp := baseJob("j2", script)
	exp["expires_at"] = time.Now().Add(-time.Hour).UTC().Format(time.RFC3339)
	dev := baseJob("j3", script)
	dev["device_id"] = "someone-else"
	good := k.signJob(t, baseJob("j4", "echo ok"))
	tampered := k.signJob(t, baseJob("j5", script))
	tampered.Raw = []byte(strings.Replace(string(tampered.Raw), "touch", "Touch", 1))
	e.Submit([]api.Job{bad, k.signJob(t, exp), k.signJob(t, dev), tampered, good})
	waitIdle(t, e)
	if _, err := os.Stat(marker); err == nil {
		t.Fatal("an unverified job executed")
	}
	if len(rep.reports) != 2 || rep.reports[0].JobID != "j4" {
		t.Fatalf("only j4 should report: %+v", rep.reports)
	}
}

// Crash while running: the new process must report failed/agent_restarted
// and NEVER run the job again, even if the server redelivers it.
func TestCrashMidJobNoReexecution(t *testing.T) {
	k, dir := newKeys(t), t.TempDir()
	jp := filepath.Join(dir, "jobs.json")
	marker := filepath.Join(dir, "count")
	job := k.signJob(t, baseJob("j1", "echo x >> "+marker))
	// the crashed process had durably recorded `running`:
	if err := OpenStore(jp).Put(Record{JobID: "j1", Attempt: 1, Type: "powershell", State: StateRunning, StartedAt: ts(time.Now())}); err != nil {
		t.Fatal(err)
	}
	rep := &fakeReporter{}
	e := newExec(t, k, OpenStore(jp), rep) // restart
	e.Recover()
	start(t, e)
	e.Flush(context.Background())
	e.Submit([]api.Job{job}) // server redelivers (attempt 2)
	job.Attempt = 2
	waitIdle(t, e)
	if _, err := os.Stat(marker); err == nil {
		t.Fatal("job re-executed after crash")
	}
	got := rep.states("j1")
	if len(got) != 1 || got[0] != "failed" {
		t.Fatalf("states %v", got)
	}
	if !strings.Contains(rep.reports[0].Output, "agent_restarted") {
		t.Fatalf("reason missing: %q", rep.reports[0].Output)
	}
}

// Lost acknowledgement: the report fails, the job is redelivered (and the
// agent even restarts); it must be re-REPORTED but never re-RUN.
func TestLostAckNoReexecution(t *testing.T) {
	k, dir := newKeys(t), t.TempDir()
	jp := filepath.Join(dir, "jobs.json")
	marker := filepath.Join(dir, "count")
	job := k.signJob(t, baseJob("j1", "echo x >> "+marker))
	rep := &fakeReporter{failN: 1000} // network down for every report
	e := newExec(t, k, OpenStore(jp), rep)
	e.Attempts = 2
	start(t, e)
	e.Submit([]api.Job{job})
	waitIdle(t, e)
	if rec, _ := e.Store.Get("j1"); rec.State != StateSucceeded || rec.Reported {
		t.Fatalf("record %+v", rec)
	}
	// restart, network back
	rep2 := &fakeReporter{}
	e2 := newExec(t, k, OpenStore(jp), rep2)
	e2.Recover()
	start(t, e2)
	e2.Submit([]api.Job{job}) // redelivered
	waitIdle(t, e2)
	e2.Flush(context.Background())
	b, _ := os.ReadFile(marker)
	if n := strings.Count(string(b), "x"); n != 1 {
		t.Fatalf("job ran %d times", n)
	}
	got := rep2.states("j1")
	if len(got) == 0 || got[len(got)-1] != "succeeded" {
		t.Fatalf("not re-reported: %v", got)
	}
	if rec, _ := e2.Store.Get("j1"); !rec.Reported {
		t.Fatal("not acknowledged after resend")
	}
	// and a further redelivery does nothing at all
	calls := rep2.calls
	e2.Submit([]api.Job{job})
	waitIdle(t, e2)
	if rep2.calls != calls {
		t.Fatal("acknowledged job triggered more reports")
	}
}

func TestTransientReportRetried(t *testing.T) {
	k := newKeys(t)
	rep := &fakeReporter{failN: 3}
	e := newExec(t, k, OpenStore(""), rep)
	start(t, e)
	e.Submit([]api.Job{k.signJob(t, baseJob("j1", "true"))})
	waitIdle(t, e)
	s := rep.states("j1")
	if len(s) == 0 || s[len(s)-1] != "succeeded" {
		t.Fatalf("%v", s)
	}
}

func TestRebootReportsBeforeActing(t *testing.T) {
	k := newKeys(t)
	var order []string
	var mu sync.Mutex
	rep := &fakeReporter{hook: func(r api.JobReport) { mu.Lock(); order = append(order, "report:"+r.State); mu.Unlock() }}
	rb := &fakeReboot{onCall: func() { mu.Lock(); order = append(order, "reboot"); mu.Unlock() }}
	e := newExec(t, k, OpenStore(""), rep)
	e.Rebooter = rb
	start(t, e)
	f := baseJob("r1", "")
	f["type"], f["params"] = "reboot", map[string]any{"delay_s": 45}
	delete(f, "script")
	job := k.signJob(t, f)
	e.Submit([]api.Job{job})
	waitIdle(t, e)
	mu.Lock()
	defer mu.Unlock()
	if len(order) < 2 || order[len(order)-2] != "report:succeeded" || order[len(order)-1] != "reboot" {
		t.Fatalf("order %v", order)
	}
	if rb.delay != 45*time.Second {
		t.Fatalf("delay %v", rb.delay)
	}
	// never retried automatically: redelivery does not reboot again
	e.Submit([]api.Job{job})
	waitIdle(t, e)
	if rb.called != 1 {
		t.Fatalf("rebooted %d times", rb.called)
	}
}

func TestTimeoutKillsProcessTree(t *testing.T) {
	k, dir := newKeys(t), t.TempDir()
	pidf := filepath.Join(dir, "pid")
	rep := &fakeReporter{}
	e := newExec(t, k, OpenStore(""), rep)
	start(t, e)
	f := baseJob("j1", fmt.Sprintf("sleep 300 & echo $! > %s; wait", pidf))
	f["timeout_s"] = 1
	t0 := time.Now()
	e.Submit([]api.Job{k.signJob(t, f)})
	waitIdle(t, e)
	if time.Since(t0) > 15*time.Second {
		t.Fatal("timeout not enforced")
	}
	last := rep.reports[len(rep.reports)-1]
	if last.State != "timed_out" {
		t.Fatalf("state %s", last.State)
	}
	b, err := os.ReadFile(pidf)
	if err != nil {
		t.Fatal(err)
	}
	pid, _ := strconv.Atoi(strings.TrimSpace(string(b)))
	time.Sleep(300 * time.Millisecond)
	if err := syscall.Kill(pid, 0); err == nil {
		// might be a zombie awaiting reaping by init; check /proc state
		st, _ := os.ReadFile(fmt.Sprintf("/proc/%d/stat", pid))
		if !strings.Contains(string(st), ") Z") {
			t.Fatalf("grandchild %d survived the timeout", pid)
		}
	}
}

func TestOutputCapAndRedaction(t *testing.T) {
	k := newKeys(t)
	rep := &fakeReporter{}
	e := newExec(t, k, OpenStore(""), rep)
	e.Secrets = func() []string { return []string{"dtok_SECRETVALUE123"} }
	start(t, e)
	script := `echo "Authorization: Bearer abcdef1234567890xyz"; echo "password=hunter2hunter2"; echo dtok_SECRETVALUE123; ` +
		`echo ghp_abcdefghijklmnopqrstuvwxyz0123456789; i=0; while [ $i -lt 2000 ]; do echo aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa; i=$((i+1)); done`
	f := baseJob("j1", script)
	f["max_output_bytes"] = 1024
	e.Submit([]api.Job{k.signJob(t, f)})
	waitIdle(t, e)
	out := rep.reports[len(rep.reports)-1].Output
	for _, leak := range []string{"abcdef1234567890xyz", "hunter2hunter2", "dtok_SECRETVALUE123", "ghp_abcdef"} {
		if strings.Contains(out, leak) {
			t.Fatalf("secret %q leaked: %s", leak, out)
		}
	}
	if !strings.Contains(out, "[REDACTED]") || !strings.Contains(out, "[output truncated") {
		t.Fatalf("missing markers: %s", out)
	}
	if len(out) > 1024+200 {
		t.Fatalf("output not capped: %d", len(out))
	}
}

func overlap(t *testing.T, conc int) bool {
	k, dir := newKeys(t), t.TempDir()
	rep := &fakeReporter{}
	e := newExec(t, k, OpenStore(""), rep)
	e.Concurrency = conc
	start(t, e)
	log := filepath.Join(dir, "log")
	var jobs []api.Job
	for i := 0; i < 3; i++ {
		s := fmt.Sprintf("echo s%d >> %s; sleep 0.4; echo e%d >> %s", i, log, i, log)
		jobs = append(jobs, k.signJob(t, baseJob(fmt.Sprintf("j%d", i), s)))
	}
	e.Submit(jobs)
	waitIdle(t, e)
	b, _ := os.ReadFile(log)
	lines := strings.Fields(string(b))
	if len(lines) != 6 {
		t.Fatalf("log %v", lines)
	}
	open := 0
	for _, l := range lines {
		if l[0] == 's' {
			open++
			if open > 1 {
				return true
			}
		} else {
			open--
		}
	}
	return false
}

func TestSerialByDefaultConcurrentWhenConfigured(t *testing.T) {
	if overlap(t, 1) {
		t.Fatal("jobs overlapped with concurrency 1")
	}
	if !overlap(t, 3) {
		t.Fatal("expected overlap with concurrency 3")
	}
}

func TestRevokedStopsExecution(t *testing.T) {
	k := newKeys(t)
	rep := &fakeReporter{revoked: true}
	e := newExec(t, k, OpenStore(""), rep)
	revoked := make(chan struct{}, 4)
	e.OnRevoked = func(error) { revoked <- struct{}{}; e.Halt() }
	start(t, e)
	e.Submit([]api.Job{k.signJob(t, baseJob("j1", "true")), k.signJob(t, baseJob("j2", "true"))})
	select {
	case <-revoked:
	case <-time.After(5 * time.Second):
		t.Fatal("revocation not signalled")
	}
	e.Wait()
	if _, ok := e.Store.Get("j2"); ok {
		t.Fatal("job started after revocation")
	}
}

func TestDisabledNeverRuns(t *testing.T) {
	k := newKeys(t)
	e := newExec(t, k, OpenStore(""), &fakeReporter{})
	e.Disabled = true
	start(t, e)
	e.Submit([]api.Job{k.signJob(t, baseJob("j1", "true"))})
	if !e.Idle() {
		t.Fatal("queued while disabled")
	}
}

func TestParamsReachScriptWithoutInjection(t *testing.T) {
	k := newKeys(t)
	rep := &fakeReporter{}
	e := newExec(t, k, OpenStore(""), rep)
	start(t, e)
	f := baseJob("j1", `printf '%s' "$RIVETIT_JOB_PARAMS"`)
	f["params"] = map[string]any{"x": "'; touch /tmp/pwn; echo '"}
	e.Submit([]api.Job{k.signJob(t, f)})
	waitIdle(t, e)
	out := rep.reports[len(rep.reports)-1].Output
	if !strings.Contains(out, "touch /tmp/pwn") {
		t.Fatalf("params not delivered verbatim: %s", out)
	}
	if _, err := os.Stat("/tmp/pwn"); err == nil {
		t.Fatal("injection executed")
	}
}
