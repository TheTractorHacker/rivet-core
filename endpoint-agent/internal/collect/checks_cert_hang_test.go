package collect

import (
	"context"
	"encoding/json"
	"os"
	"path/filepath"
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
