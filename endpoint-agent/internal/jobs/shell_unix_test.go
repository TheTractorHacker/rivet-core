//go:build !windows

package jobs

import (
	"os"
	"path/filepath"
	"strings"
	"syscall"
	"testing"
	"time"

	"rivetit-agent/internal/api"
)

func TestBuildScriptCommandHygiene(t *testing.T) {
	secret := "S3CRET-" + strings.Repeat("x", 20)
	sc, err := BuildScriptCommand("echo "+secret+"\nexit 3\n", []byte(`{"a":1}`))
	if err != nil {
		t.Fatal(err)
	}
	defer sc.Cleanup()
	// nothing of the script in argv (visible to every user via /proc/<pid>/cmdline)
	if strings.Contains(strings.Join(append([]string{sc.Name}, sc.Args...), " "), "S3CRET") {
		t.Fatal("script text leaked into argv")
	}
	if len(sc.Args) != 1 {
		t.Fatalf("args %v", sc.Args)
	}
	fi, err := os.Stat(sc.Args[0])
	if err != nil || fi.Mode().Perm() != 0o700 {
		t.Fatalf("script file mode %v %v", fi, err)
	}
	di, _ := os.Stat(filepath.Dir(sc.Args[0]))
	if di.Mode().Perm() != 0o700 {
		t.Fatalf("script dir mode %v", di.Mode().Perm())
	}
	if strings.Join(sc.Env, "|") != `RIVETIT_JOB_PARAMS={"a":1}` {
		t.Fatalf("env %v", sc.Env)
	}
	sc.Cleanup()
	if _, err := os.Stat(sc.Args[0]); err == nil {
		t.Fatal("cleanup left the script behind")
	}
	if sc2, _ := BuildScriptCommand("true", nil); strings.Join(sc2.Env, "") != "RIVETIT_JOB_PARAMS=null" {
		t.Fatalf("empty params must read as JSON null: %v", sc2.Env)
	} else {
		sc2.Cleanup()
	}
}

func TestShellJobRunsAndLeavesNothingBehind(t *testing.T) {
	k := newKeys(t)
	rep := &fakeReporter{}
	e := newExec(t, k, OpenStore(""), rep)
	start(t, e)
	tmp := t.TempDir()
	t.Setenv("TMPDIR", tmp)
	// stdin stays the job's own (a script must not be fed through stdin)
	e.Submit([]api.Job{k.signJob(t, baseJob("sh1", "echo uid=$(id -u); read -t 1 x; echo stdin-ok; echo \"$0\" | grep -q job.sh && echo file-run"))})
	waitIdle(t, e)
	last := rep.reports[len(rep.reports)-1]
	if last.State != "succeeded" || !strings.Contains(last.Output, "stdin-ok") || !strings.Contains(last.Output, "file-run") {
		t.Fatalf("%+v", last)
	}
	if ents, _ := os.ReadDir(tmp); len(ents) != 0 {
		t.Fatalf("temp script not cleaned: %v", ents)
	}
}

func TestShellJobProcessGroupKilledOnTimeout(t *testing.T) {
	k := newKeys(t)
	rep := &fakeReporter{}
	e := newExec(t, k, OpenStore(""), rep)
	start(t, e)
	pidf := filepath.Join(t.TempDir(), "pid")
	f := baseJob("sh2", "sleep 300 & echo $! > "+pidf+"; wait")
	f["timeout_s"] = 1
	e.Submit([]api.Job{k.signJob(t, f)})
	waitIdle(t, e)
	last := rep.reports[len(rep.reports)-1]
	if last.State != "timed_out" {
		t.Fatalf("%+v", last)
	}
	b, err := os.ReadFile(pidf)
	if err != nil {
		t.Fatal(err)
	}
	pid := 0
	for _, c := range strings.TrimSpace(string(b)) {
		pid = pid*10 + int(c-'0')
	}
	deadline := time.Now().Add(5 * time.Second)
	for time.Now().Before(deadline) {
		if err := syscall.Kill(pid, 0); err != nil {
			return // background child is gone
		}
		time.Sleep(50 * time.Millisecond)
	}
	t.Fatalf("child %d of a timed-out shell job survived", pid)
}

func TestForeignScriptTypeReportsUnsupportedPlatform(t *testing.T) {
	k := newKeys(t)
	rep := &fakeReporter{}
	e := newExec(t, k, OpenStore(""), rep)
	start(t, e)
	marker := filepath.Join(t.TempDir(), "ran")
	f := baseJob("ps1", "touch "+marker)
	f["type"] = TypePowerShell // a PowerShell job handed to a Linux agent
	e.Submit([]api.Job{k.signJob(t, f)})
	waitIdle(t, e)
	last := rep.reports[len(rep.reports)-1]
	if last.State != StateFailed || !strings.HasPrefix(last.Output, "unsupported_platform:") || !strings.Contains(last.Output, "powershell") {
		t.Fatalf("%+v", last)
	}
	if last.ExitCode != nil {
		t.Fatalf("no process ran, so there is no exit code: %v", *last.ExitCode)
	}
	if _, err := os.Stat(marker); err == nil {
		t.Fatal("an unsupported job was executed")
	}
	rec, _ := e.Store.Get("ps1")
	if rec.Reason != ReasonUnsupportedPlatform || !rec.Reported {
		t.Fatalf("%+v", rec)
	}
	// and the agent keeps working afterwards
	e.Submit([]api.Job{k.signJob(t, baseJob("ok1", "echo still-alive"))})
	waitIdle(t, e)
	if got := rep.states("ok1"); len(got) != 2 || got[1] != "succeeded" {
		t.Fatalf("%v", got)
	}
}

func TestVerifyAcceptsShellTypeAndStillRejectsUnknown(t *testing.T) {
	k := newKeys(t)
	v := &Verifier{PublicKey: k.pub, DeviceID: "dev-1"}
	good := baseJob("v1", "true")
	good["type"] = TypeShell
	if err := v.Verify(k.signJob(t, good)); err != nil {
		t.Fatalf("shell job refused: %v", err)
	}
	empty := baseJob("v2", "")
	empty["type"] = TypeShell
	if err := v.Verify(k.signJob(t, empty)); err == nil {
		t.Fatal("empty shell script accepted")
	}
	odd := baseJob("v3", "true")
	odd["type"] = "python"
	if err := v.Verify(k.signJob(t, odd)); err == nil {
		t.Fatal("unknown type accepted")
	}
}
