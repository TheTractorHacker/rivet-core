package agent

import (
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"encoding/base64"
	"encoding/json"
	"encoding/pem"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"sync"
	"testing"
	"time"

	"rivetit-agent/internal/api"
	"rivetit-agent/internal/collect"
	"rivetit-agent/internal/jobs"
	"rivetit-agent/internal/store"
)

type fakePlatform struct {
	mu   sync.Mutex
	fail map[string]bool
	cpu  uint64

	// software inventory (collect.SoftwareLister)
	sw      []collect.SoftwareItem
	swErr   error
	swCalls int
}

func (f *fakePlatform) Software(context.Context) (collect.SoftwareList, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.swCalls++
	if f.swErr != nil {
		return collect.SoftwareList{}, f.swErr
	}
	return collect.SoftwareList{Items: append([]collect.SoftwareItem(nil), f.sw...)}, nil
}

func (f *fakePlatform) setSoftware(items ...collect.SoftwareItem) {
	f.mu.Lock()
	f.sw = items
	f.mu.Unlock()
}

func (f *fakePlatform) softwareCalls() int { f.mu.Lock(); defer f.mu.Unlock(); return f.swCalls }

func (f *fakePlatform) bad(k string) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	if f.fail[k] {
		return fmt.Errorf("%s unavailable", k)
	}
	return nil
}
func (f *fakePlatform) Identity(context.Context) (collect.Identity, error) {
	g, s := "guid-1", "SER123"
	return collect.Identity{MachineGUID: &g, Serial: &s}, nil
}
func (f *fakePlatform) OSInfo(context.Context) (string, string, error) {
	return "windows", "Windows 11 Pro", nil
}
func (f *fakePlatform) CPUModel(context.Context) (string, error) { return "CPU", nil }
func (f *fakePlatform) CPUTimes(context.Context) (uint64, uint64, error) {
	if err := f.bad("cpu"); err != nil {
		return 0, 0, err
	}
	f.mu.Lock()
	defer f.mu.Unlock()
	f.cpu += 100
	return f.cpu / 2, f.cpu, nil
}
func (f *fakePlatform) Memory(context.Context) (uint64, uint64, error) {
	if err := f.bad("mem"); err != nil {
		return 0, 0, err
	}
	return 1000, 400, nil
}
func (f *fakePlatform) Disks(context.Context) ([]collect.DiskStat, error) {
	if err := f.bad("disks"); err != nil {
		return nil, err
	}
	return []collect.DiskStat{{Mount: "C:", Total: 1000, Free: 500, FS: "NTFS"}}, nil
}
func (f *fakePlatform) NetBytes(context.Context) (uint64, uint64, error) {
	return 0, 0, fmt.Errorf("no counters")
}
func (f *fakePlatform) UptimeS(context.Context) (uint64, error)      { return 100, nil }
func (f *fakePlatform) LoggedInUser(context.Context) (string, error) { return "", nil }
func (f *fakePlatform) PendingReboot(context.Context) (bool, []string, error) {
	return false, nil, nil
}
func (f *fakePlatform) ServiceState(context.Context, string) (collect.ServiceInfo, error) {
	return collect.ServiceInfo{State: "running", Startup: "automatic"}, nil
}
func (f *fakePlatform) MeshAgentDir() string { return "" }

type recReboot struct{ n int }

func (r *recReboot) Schedule(time.Duration) error { r.n++; return nil }

// srv is a contract-shaped fake RivetIT server.
type srv struct {
	t   *testing.T
	ts  *httptest.Server
	key ed25519.PrivateKey
	pub ed25519.PublicKey

	mu         sync.Mutex
	enrollFn   func(req api.EnrollRequest) (int, any, http.Header)
	checkinFn  func(n int, body []byte) (int, any, http.Header)
	jobsOut    []json.RawMessage
	reports    []api.JobReport
	checkins   [][]byte
	reqCount   int
	enrollReqs []api.EnrollRequest
	tokens     []string // bearer tokens seen on checkin
	token      string
	reportFn   func(r api.JobReport) (int, any)
	hits       map[string]int
}

func newSrv(t *testing.T) *srv {
	pub, priv, _ := ed25519.GenerateKey(rand.Reader)
	s := &srv{t: t, key: priv, pub: pub, token: "devtok_0123456789abcdef", hits: map[string]int{}}
	s.ts = httptest.NewTLSServer(http.HandlerFunc(s.handle))
	t.Cleanup(s.ts.Close)
	return s
}

func (s *srv) writeJSON(w http.ResponseWriter, code int, v any, h http.Header) {
	for k, vs := range h {
		for _, x := range vs {
			w.Header().Add(k, x)
		}
	}
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(code)
	json.NewEncoder(w).Encode(v)
}

func (s *srv) enrollOK(status string) func(api.EnrollRequest) (int, any, http.Header) {
	return func(req api.EnrollRequest) (int, any, http.Header) {
		var asset *int64
		if status == "linked" {
			v := int64(42)
			asset = &v
		}
		return 201, map[string]any{"device_id": 7, "signing_key_id": "key-1", "device_token": s.token, "check_in_interval_s": 60,
			"server_time": time.Now().UTC().Format(time.RFC3339), "status": status, "matched_asset_id": asset,
			"signing_public_key": base64.StdEncoding.EncodeToString(s.pub),
			"config":             map[string]any{"checks": []any{map[string]any{"key": "disk_c", "type": "disk", "params": map[string]any{"mount": "C:"}, "interval_s": 60}}}}, nil
	}
}

func (s *srv) handle(w http.ResponseWriter, r *http.Request) {
	body, _ := io.ReadAll(r.Body)
	s.mu.Lock()
	s.reqCount++
	s.hits[r.Method+" "+r.URL.Path]++
	s.mu.Unlock()
	switch {
	case r.URL.Path == "/api/v1/agent_enroll":
		var req api.EnrollRequest
		json.Unmarshal(body, &req)
		s.mu.Lock()
		s.enrollReqs = append(s.enrollReqs, req)
		fn := s.enrollFn
		s.mu.Unlock()
		code, v, h := fn(req)
		s.writeJSON(w, code, v, h)
	case r.URL.Path == "/api/v1/agent_checkin":
		s.mu.Lock()
		s.checkins = append(s.checkins, body)
		s.tokens = append(s.tokens, r.Header.Get("Authorization"))
		n := len(s.checkins)
		fn := s.checkinFn
		s.mu.Unlock()
		if fn == nil {
			s.writeJSON(w, 200, map[string]any{"ok": true, "next_check_in_s": 60, "jobs_pending": 0, "server_time": time.Now().UTC().Format(time.RFC3339), "update": nil}, nil)
			return
		}
		code, v, h := fn(n, body)
		if code == -1 { // drop the connection after recording (lost response)
			hj, _ := w.(http.Hijacker)
			c, _, _ := hj.Hijack()
			c.Close()
			return
		}
		s.writeJSON(w, code, v, h)
	case r.URL.Path == "/api/v1/agent_jobs" && r.Method == "GET":
		s.mu.Lock()
		out := append([]json.RawMessage{}, s.jobsOut...)
		s.mu.Unlock()
		s.writeJSON(w, 200, map[string]any{"jobs": out}, nil)
	case r.URL.Path == "/api/v1/agent_jobs" && r.Method == "POST":
		var rep api.JobReport
		json.Unmarshal(body, &rep)
		s.mu.Lock()
		s.reports = append(s.reports, rep)
		fn := s.reportFn
		s.mu.Unlock()
		if fn != nil {
			code, v := fn(rep)
			s.writeJSON(w, code, v, nil)
			return
		}
		s.writeJSON(w, 200, map[string]any{"ok": true}, nil)
	default:
		http.NotFound(w, r)
	}
}

func (s *srv) signJob(fields map[string]any) json.RawMessage {
	raw, _ := json.Marshal(fields)
	canon, err := jobs.Canonical(raw)
	if err != nil {
		s.t.Fatal(err)
	}
	fields["signature"] = base64.StdEncoding.EncodeToString(ed25519.Sign(s.key, canon))
	raw, _ = json.Marshal(fields)
	return raw
}

func (s *srv) count() int { s.mu.Lock(); defer s.mu.Unlock(); return s.reqCount }

func (s *srv) checkinBodies() [][]byte {
	s.mu.Lock()
	defer s.mu.Unlock()
	return append([][]byte(nil), s.checkins...)
}

type rig struct {
	t   *testing.T
	s   *srv
	dir string
	st  *store.Store
	a   *Agent
	fp  *fakePlatform
	rb  *recReboot
	clk *fakeClock
}

// fakeClock is the injected agent clock (Options.Now).
type fakeClock struct {
	mu sync.Mutex
	t  time.Time
}

func (c *fakeClock) Now() time.Time { c.mu.Lock(); defer c.mu.Unlock(); return c.t }
func (c *fakeClock) Advance(d time.Duration) {
	c.mu.Lock()
	c.t = c.t.Add(d)
	c.mu.Unlock()
}

func newRig(t *testing.T) *rig {
	s := newSrv(t)
	s.enrollFn = s.enrollOK("linked")
	dir := t.TempDir()
	r := &rig{t: t, s: s, dir: dir, fp: &fakePlatform{fail: map[string]bool{}}, rb: &recReboot{}}
	ca := filepath.Join(dir, "ca.pem")
	os.WriteFile(ca, pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: s.ts.Certificate().Raw}), 0o600)
	var err error
	r.st, err = store.Open(filepath.Join(dir, "state"))
	if err != nil {
		t.Fatal(err)
	}
	if err := r.st.SaveConfig(store.Config{ServerURL: s.ts.URL, CAFile: ca}); err != nil {
		t.Fatal(err)
	}
	r.a = r.newAgent()
	return r
}

// newAgent simulates a process (re)start on the same state dir.
func (r *rig) newAgent() *Agent {
	st, err := store.Open(r.st.Dir)
	if err != nil {
		r.t.Fatal(err)
	}
	t := r.t
	var now func() time.Time
	if r.clk != nil {
		now = r.clk.Now
	}
	a, err := New(Options{Store: st, Version: "1.0.0", Platform: r.fp, Rebooter: r.rb, Now: now,
		Log: slog.New(slog.NewTextHandler(io.Discard, nil)), MinInterval: 20 * time.Millisecond, Rand: func() float64 { return 0.5 }})
	if err != nil {
		t.Fatal(err)
	}
	if err := a.buildClient(); err != nil {
		t.Fatal(err)
	}
	return a
}

func (r *rig) enroll() {
	if _, err := r.a.Enroll(context.Background(), "enr_token"); err != nil {
		r.t.Fatalf("enroll: %v", err)
	}
}

func decodeCheckin(t *testing.T, b []byte) map[string]any {
	var m map[string]any
	if err := json.Unmarshal(b, &m); err != nil {
		t.Fatal(err)
	}
	return m
}

func (s *srv) hit(k string) int { s.mu.Lock(); defer s.mu.Unlock(); return s.hits[k] }
