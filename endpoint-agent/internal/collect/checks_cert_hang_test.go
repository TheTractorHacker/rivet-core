package collect

import (
	"context"
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"rivetit-agent/internal/store"
)

// CORE-DS-3: a hung read inside a check must not hold the check loop. The hard per-check limit abandons it, and the same key then fails fast.
func TestRunnerAbandonsAHungCheck(t *testing.T) {
	c := newCol(newFake())
	block := make(chan struct{})
	defer close(block)
	x := DefaultSys()
	x.ReadFile = func(string) ([]byte, error) { <-block; return nil, nil }
	c.X = x
	c.CheckHardLimit = 200 * time.Millisecond
	r := NewCheckRunner(c)
	specs := []store.CheckSpec{{Key: "hang", Type: "cert", Params: json.RawMessage(`{"paths":["/etc/hostname"]}`)}}
	start := time.Now()
	out := r.Run(context.Background(), specs, time.Unix(1000, 0))
	if time.Since(start) > 3*time.Second {
		t.Fatalf("Run blocked for %v", time.Since(start))
	}
	if len(out) != 1 || out[0].Status != Unknown {
		t.Fatalf("got %+v", out)
	}
	start = time.Now()
	r.Run(context.Background(), specs, time.Unix(5000, 0))
	if time.Since(start) > time.Second {
		t.Fatalf("a check that is still hung was started again and blocked for %v", time.Since(start))
	}
}

// CORE-DS-3: a FIFO (or any non-regular file) named in a cert check is refused, not opened.
func TestCertCheckRefusesAFifo(t *testing.T) {
	fifo := filepath.Join(t.TempDir(), "pipe")
	if err := mkfifo(fifo); err != nil {
		t.Skip("mkfifo unavailable:", err)
	}
	done := make(chan struct{})
	go func() {
		defer close(done)
		_, err := readFileCapped(fifo)
		if err == nil {
			t.Error("a FIFO was read")
		}
	}()
	select {
	case <-done:
	case <-time.After(3 * time.Second):
		// unblock the stuck opener so the goroutine does not outlive the test
		if f, err := os.OpenFile(fifo, os.O_WRONLY, 0); err == nil {
			f.Close()
		}
		t.Fatal("readFileCapped blocked on a FIFO")
	}
}

// CORE-DS-3 (review CR1): procfs/sysfs/devtmpfs entries (/proc/kmsg is a "regular" file that blocks in the netpoller and consumes kernel log records)
// are refused by the cert check, by path and by file system.
func TestCertPathsRefuseProcfsSysfsAndDev(t *testing.T) {
	for _, p := range []string{"/proc/kmsg", "/proc/self/status", "/sys/kernel/notes", "/dev/kmsg", "/dev/zero", "/proc", "//proc/kmsg", "/./proc/kmsg", "/etc/../proc/kmsg", "/proc/./self/status", "/sys/./kernel/notes", "/dev/./kmsg", "/tmp/../dev/zero"} {
		if safeCertPath(p) {
			t.Errorf("%s accepted by safeCertPath", p)
		}
	}
	if !safeCertPath("/etc/ssl/certs/ca.pem") {
		t.Error("an ordinary path was refused")
	}
}

func TestReadFileCappedRefusesPseudoFilesystems(t *testing.T) {
	if _, err := os.Stat("/proc/self/status"); err != nil {
		t.Skip("no procfs")
	}
	if b, err := readFileCapped("/proc/self/status"); err == nil {
		t.Fatalf("procfs file was read (%d bytes)", len(b))
	}
}

// CORE-DS-3 (review CR1): the whole check pass has a total budget, so 50 hung checks cannot hold the first check-in for 50 x the per-check limit.
func TestRunnerStopsStartingChecksWhenTheBudgetIsSpent(t *testing.T) {
	c := newCol(newFake())
	block := make(chan struct{})
	defer close(block)
	x := DefaultSys()
	x.ReadFile = func(string) ([]byte, error) { <-block; return nil, nil }
	c.X = x
	c.CheckHardLimit = 10 * time.Second
	c.RunBudget = 400 * time.Millisecond
	r := NewCheckRunner(c)
	var specs []store.CheckSpec
	for i := 0; i < 20; i++ {
		specs = append(specs, store.CheckSpec{Key: "hang" + string(rune('a'+i)), Type: "cert", Params: json.RawMessage(`{"paths":["/etc/hostname"]}`)})
	}
	start := time.Now()
	r.Run(context.Background(), specs, time.Unix(1000, 0))
	if d := time.Since(start); d > 3*time.Second {
		t.Fatalf("Run took %v with a 400ms budget and 20 hung checks", d)
	}
}

// CORE-DS-3 (review CR1): fixing the parameters of a check that hung must not leave its key "still running" under the same key.
func TestFixedParamsOfAHungCheckRunAgain(t *testing.T) {
	c := newCol(newFake())
	block := make(chan struct{})
	defer close(block)
	x := DefaultSys()
	x.ReadFile = func(p string) ([]byte, error) {
		if p == "/etc/hang" {
			<-block
		}
		return nil, os.ErrNotExist
	}
	c.X = x
	c.CheckHardLimit = 100 * time.Millisecond
	r := NewCheckRunner(c)
	bad := []store.CheckSpec{{Key: "k", Type: "cert", Params: json.RawMessage(`{"paths":["/etc/hang"]}`)}}
	r.Run(context.Background(), bad, time.Unix(1000, 0))
	good := []store.CheckSpec{{Key: "k", Type: "cert", Params: json.RawMessage(`{"paths":["/etc/fixed"]}`)}}
	out := r.Run(context.Background(), good, time.Unix(5000, 0))
	if len(out) != 1 || strings.Contains(out[0].Detail, "still running") || strings.Contains(out[0].Detail, "did not finish") {
		t.Fatalf("the corrected check was not run: %+v", out)
	}
}
