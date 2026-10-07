package jobs

import (
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"encoding/base64"
	"encoding/json"
	"errors"
	"sync"
	"testing"
	"time"

	"rivetit-agent/internal/api"
)

type keys struct {
	pub  ed25519.PublicKey
	priv ed25519.PrivateKey
}

func newKeys(t testing.TB) keys {
	pub, priv, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	return keys{pub, priv}
}

// signJob builds a signed job object the way the server contract describes.
func (k keys) signJob(t testing.TB, fields map[string]any) api.Job {
	raw, _ := json.Marshal(fields)
	canon, err := Canonical(raw)
	if err != nil {
		t.Fatal(err)
	}
	fields["signature"] = base64.StdEncoding.EncodeToString(ed25519.Sign(k.priv, canon))
	raw, _ = json.Marshal(fields)
	jobs, err := api.UnmarshalJobs([]byte(`{"jobs":[` + string(raw) + `]}`))
	if err != nil {
		t.Fatal(err)
	}
	return jobs[0]
}

func baseJob(id, script string) map[string]any {
	now := time.Now().UTC()
	return map[string]any{
		"job_id": id, "attempt": 1, "type": ScriptType(), "script": script,
		"params": map[string]any{}, "timeout_s": 20, "max_output_bytes": 4096,
		"issued_at":  now.Format(time.RFC3339),
		"expires_at": now.Add(time.Hour).Format(time.RFC3339),
	}
}

type fakeReporter struct {
	mu      sync.Mutex
	reports []api.JobReport
	failN   int // fail the first N calls with a transient error
	calls   int
	revoked bool
	hook    func(api.JobReport)
}

func (f *fakeReporter) ReportJob(_ context.Context, r api.JobReport) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.calls++
	if f.revoked {
		return &api.APIError{Status: 401, Code: "revoked"}
	}
	if f.failN > 0 {
		f.failN--
		return &api.APIError{Err: errors.New("net down")}
	}
	f.reports = append(f.reports, r)
	if f.hook != nil {
		f.hook(r)
	}
	return nil
}

func (f *fakeReporter) states(id string) []string {
	f.mu.Lock()
	defer f.mu.Unlock()
	var s []string
	for _, r := range f.reports {
		if r.JobID == id {
			s = append(s, r.State)
		}
	}
	return s
}

type fakeReboot struct {
	mu     sync.Mutex
	called int
	delay  time.Duration
	onCall func()
}

func (f *fakeReboot) Schedule(d time.Duration) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.called++
	f.delay = d
	if f.onCall != nil {
		f.onCall()
	}
	return nil
}

func newExec(t testing.TB, k keys, st *Store, rep Reporter) *Executor {
	return &Executor{
		Store:    st,
		Verifier: &Verifier{PublicKey: k.pub, DeviceID: "dev-1"},
		Reporter: rep,
		Rebooter: &fakeReboot{},
		Backoff:  api.Backoff{Base: time.Millisecond, Max: 5 * time.Millisecond},
		Attempts: 5,
	}
}

func waitIdle(t testing.TB, e *Executor) {
	t.Helper()
	deadline := time.Now().Add(30 * time.Second)
	for !e.Idle() {
		if time.Now().After(deadline) {
			t.Fatal("executor never went idle")
		}
		time.Sleep(10 * time.Millisecond)
	}
}
