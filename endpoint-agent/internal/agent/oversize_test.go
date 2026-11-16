package agent

import (
	"context"
	"encoding/json"
	"net/http"
	"os"
	"strings"
	"testing"
	"time"

	"rivetit-agent/internal/api"
	"rivetit-agent/internal/buffer"
	"rivetit-agent/internal/jobs"
	"rivetit-agent/internal/store"
	"rivetit-agent/internal/update"
)

func fatChecks(n int) []api.CheckResult {
	out := make([]api.CheckResult, n)
	for i := range out {
		out[i] = api.CheckResult{Key: "k" + strings.Repeat("x", 90) + string(rune('a'+i%26)), Status: "ok", Detail: strings.Repeat("d", 300)}
	}
	return out
}

// CORE-DS-2: a full ring of fat samples must never produce a body over the request cap.
func TestBuildInflightStaysUnderTheRequestCap(t *testing.T) {
	r := newRig(t)
	r.enroll()
	// a ring whose own byte cap is larger than the request cap (the ring cap is configurable; software and inventory ride in the same body)
	r.a.ring = buffer.Open(r.st.BufferPath(), 100, 8<<20)
	for i := 0; i < 100; i++ {
		if _, err := r.a.ring.Append(r.a.stamp(), nil, fatChecks(50)); err != nil {
			t.Fatal(err)
		}
	}
	in, err := r.a.buildInflight(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	if len(in.Body) >= api.MaxRequestBytes {
		t.Fatalf("body %d bytes is over the %d cap", len(in.Body), api.MaxRequestBytes)
	}
	if len(in.Body) < 1000 {
		t.Fatalf("body suspiciously small: %d", len(in.Body))
	}
}

// CORE-DS-2: an oversized inflight.json written by an older agent is dropped and rebuilt, not resent forever.
func TestOversizedPersistedInflightIsDroppedAndRebuilt(t *testing.T) {
	r := newRig(t)
	r.enroll()
	r.a.sample(context.Background())
	if err := r.st.SaveInflight(store.Inflight{Seq: 999, Body: []byte(`{"x":"` + strings.Repeat("a", api.MaxRequestBytes+10) + `"}`)}); err != nil {
		t.Fatal(err)
	}
	before := len(r.s.checkinBodies())
	r.a.checkIn(context.Background(), 0)
	if got := len(r.s.checkinBodies()); got != before+1 {
		t.Fatalf("no check-in reached the server (%d -> %d): the oversized body is wedging the agent", before, got)
	}
}

// CORE-DS-51: hosted installer downloads trust the configured ca_file (the server may use a private CA).
func TestDownloadClientUsesTheConfiguredCA(t *testing.T) {
	r := newRig(t)
	resp, err := r.a.downloadClient().Get(r.s.ts.URL + "/nothing")
	if err != nil {
		t.Fatalf("download client does not trust the configured CA: %v", err)
	}
	resp.Body.Close()
}

// CORE-DS-49: a self-update restart would kill a running patch/software install, so an offered update waits until the executor is idle.
func TestUpdateWaitsForRunningJobs(t *testing.T) {
	r := newRig(t)
	r.enroll()
	exe := t.TempDir() + "/rivetit-agent"
	os.WriteFile(exe, []byte("x"), 0o700)
	r.a.upd = &update.Manager{Paths: update.PathsFor(exe), Version: "1.0.0", StatePath: r.st.UpdatePath()}
	now := time.Now().UTC()
	job := r.s.signJob(map[string]any{"job_id": "long-1", "device_id": 7, "attempt": 1, "type": jobs.ScriptType(), "script": "sleep 2",
		"params": map[string]any{}, "timeout_s": 20, "max_output_bytes": 4096,
		"issued_at": now.Format(time.RFC3339), "expires_at": now.Add(time.Hour).Format(time.RFC3339)})
	r.s.jobsOut = []json.RawMessage{job}
	r.s.checkinFn = func(int, []byte) (int, any, http.Header) {
		return 200, map[string]any{"ok": true, "next_check_in_s": 60, "jobs_pending": 1,
			"update": map[string]any{"version": "9.9.9", "url": r.s.ts.URL + "/bin", "sha256": strings.Repeat("a", 64), "signature": "AAAA", "min_version": "1.0.0"}}, nil
	}
	ctx, cancel := context.WithCancel(context.Background())
	r.a.newExecutor(ctx)
	defer func() { // the running job writes into the state dir: stop it before the temp dir is removed
		cancel()
		r.a.executor().Halt()
		r.a.executor().Wait()
	}()
	r.a.checkIn(ctx, 0) // delivers the job and the update offer
	deadline := time.Now().Add(5 * time.Second)
	for r.a.executor().Idle() && time.Now().Before(deadline) {
		time.Sleep(10 * time.Millisecond)
	}
	if r.a.executor().Idle() {
		t.Fatal("the job never started")
	}
	r.a.checkIn(ctx, 0) // the job is still running: the update must not even be attempted
	if st, _ := r.st.LoadState(); st.UpdateFailure != nil {
		t.Fatalf("an update was attempted while a job was running: %+v", st.UpdateFailure)
	}
}

// stopExecutorAfterTest halts the executor and waits for its workers when the test ends: a worker that is still writing jobs.json while the temp state
// directory is removed makes the test flaky on a slow machine ("directory not empty").
func stopExecutorAfterTest(t *testing.T, r *rig, cancel context.CancelFunc) {
	t.Helper()
	t.Cleanup(func() {
		cancel()
		if e := r.a.executor(); e != nil {
			e.Halt()
			e.Wait()
		}
	})
}

// CORE-DS-15 (review CR9): temporary update failures are retried, but not for ever: after updateTransientLimit the version counts as failed.
func TestTransientUpdateFailuresAreCapped(t *testing.T) {
	r := newRig(t)
	for i := 1; i < updateTransientLimit; i++ {
		if r.a.transientUpdateExhausted("9.9.9") {
			t.Fatalf("gave up after %d attempts", i)
		}
	}
	if !r.a.transientUpdateExhausted("9.9.9") {
		t.Fatal("never gives up")
	}
	if r.a.transientUpdateExhausted("10.0.0") {
		t.Fatal("the count leaked to another version")
	}
}
