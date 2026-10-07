package main

import (
	"bytes"
	"context"
	"encoding/json"
	"flag"
	"io"
	"math/rand/v2"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"
)

func TestTargetSafety(t *testing.T) {
	ok := []struct {
		url      string
		insecure bool
	}{
		{"https://rmm.example.com/api/v1/", false}, {"http://127.0.0.1:8700/api/v1/", true}, {"http://localhost/api/v1", true},
		{"http://[::1]:8080/x", true}, {"https://127.0.0.1:8443/", true}, {"http://127.9.9.9/", true},
	}
	for _, c := range ok {
		if err := checkTarget(c.url, c.insecure); err != nil {
			t.Errorf("%s insecure=%v: unexpected refusal: %v", c.url, c.insecure, err)
		}
	}
	bad := []struct {
		url      string
		insecure bool
	}{
		{"http://127.0.0.1:8700/", false},     // plain http needs -insecure
		{"http://rmm.example.com/", true},     // ... and a loopback host
		{"http://10.0.0.5/", true},            // private is not loopback
		{"https://rmm.example.com/", true},    // -insecure never reaches a real host
		{"http://127.0.0.1.evil.test/", true}, // a hostname that merely starts like one
		{"ftp://127.0.0.1/", true}, {"", true}, {"not a url", true},
	}
	for _, c := range bad {
		if err := checkTarget(c.url, c.insecure); err == nil {
			t.Errorf("%q insecure=%v: should be refused", c.url, c.insecure)
		}
	}
}

func TestFlagValidation(t *testing.T) {
	parse := func(args ...string) error {
		fs := flag.NewFlagSet("t", flag.ContinueOnError)
		fs.SetOutput(io.Discard)
		_, err := parseFlags(args, fs)
		return err
	}
	if err := parse("-url", "http://127.0.0.1:1/", "-token", "t", "-insecure"); err != nil {
		t.Fatal(err)
	}
	for name, args := range map[string][]string{
		"no url": {"-token", "t"}, "no token": {"-url", "https://x.test/"}, "zero devices": {"-url", "https://x.test/", "-token", "t", "-devices", "0"},
		"tiny interval": {"-url", "https://x.test/", "-token", "t", "-interval", "10ms"}, "non-loopback insecure": {"-url", "http://x.test/", "-token", "t", "-insecure"},
		"bad concurrency": {"-url", "https://x.test/", "-token", "t", "-concurrency", "0"},
	} {
		if parse(args...) == nil {
			t.Errorf("%s: should be refused", name)
		}
	}
}

func TestHistogramPercentiles(t *testing.T) {
	var h hist
	for i := 1; i <= 1000; i++ {
		h.add(time.Duration(i) * time.Millisecond)
	}
	s := h.snapshot()
	within := func(got, want time.Duration) {
		t.Helper()
		if got < want || got > time.Duration(float64(want)*1.08) {
			t.Errorf("got %v, want %v..+7%%", got, want)
		}
	}
	within(s.percentile(50), 500*time.Millisecond)
	within(s.percentile(95), 950*time.Millisecond)
	within(s.percentile(99), 990*time.Millisecond)
	if s.percentile(100) > time.Second {
		t.Errorf("p100 %v above the slowest request", s.percentile(100))
	}
	if s.n != 1000 || s.mean() < 490*time.Millisecond || s.mean() > 510*time.Millisecond {
		t.Errorf("n=%d mean=%v", s.n, s.mean())
	}
	prev := h.snapshot()
	h.add(2 * time.Second)
	d := h.snapshot().sub(prev)
	if d.n != 1 || d.percentile(50) < 2*time.Second {
		t.Errorf("interval view n=%d p50=%v", d.n, d.percentile(50))
	}
	var empty hist
	if empty.snapshot().percentile(99) != 0 || empty.snapshot().bars() != "" {
		t.Error("an empty histogram reports zeros")
	}
	if !strings.Contains(s.bars(), "#") {
		t.Error("bars render")
	}
}

func TestCheckinPayloadIsRealisticAndWithinTheProtocolCaps(t *testing.T) {
	r := rand.New(rand.NewPCG(1, 2))
	b, err := buildCheckin(r, 7, time.Now(), "0.0.0-sim", 4, false, "SIM-1", "SN1")
	if err != nil {
		t.Fatal(err)
	}
	if len(b) < 1200 || len(b) > 2600 {
		t.Errorf("a steady-state check-in is about 1.5 to 2 KB, got %d bytes", len(b))
	}
	var m map[string]any
	if err := json.Unmarshal(b, &m); err != nil {
		t.Fatal(err)
	}
	if m["inventory"] != nil || m["seq"].(float64) != 7 || len(m["buffered"].([]any)) != 4 || len(m["checks"].([]any)) != 3 {
		t.Errorf("shape: inventory=%v seq=%v buffered=%d checks=%d", m["inventory"], m["seq"], len(m["buffered"].([]any)), len(m["checks"].([]any)))
	}
	for _, k := range []string{"seq", "collected_at", "agent_version", "inventory", "metrics", "checks", "buffered"} {
		if _, ok := m[k]; !ok {
			t.Errorf("missing field %s", k)
		}
	}
	first, _ := buildCheckin(r, 1, time.Now(), "0.0.0-sim", 4, true, "SIM-1", "SN1")
	if len(first) <= len(b) || len(first) > 1<<20 {
		t.Errorf("the first check-in carries the inventory: %d vs %d bytes", len(first), len(b))
	}
	// buffered samples are older than the current one, oldest first, 60 s apart
	buf := m["buffered"].([]any)
	t0, _ := time.Parse("2006-01-02T15:04:05Z", m["collected_at"].(string))
	t1, _ := time.Parse("2006-01-02T15:04:05Z", buf[len(buf)-1].(map[string]any)["collected_at"].(string))
	if d := t0.Sub(t1); d < 55*time.Second || d > 65*time.Second {
		t.Errorf("newest buffered sample is %v older, want about 60 s", d)
	}
}

func TestEnrollBodyIsUniquePerDevice(t *testing.T) {
	r := rand.New(rand.NewPCG(3, 4))
	a, ia, _ := buildEnroll(r, "tok", 1, "00001")
	b, ib, _ := buildEnroll(r, "tok", 2, "00001")
	if bytes.Equal(a, b) || ia == ib || !strings.Contains(string(a), `"enrollment_token":"tok"`) {
		t.Error("each device gets its own identity")
	}
}

// fakeServer implements just enough of the device protocol to drive the simulator.
type fakeServer struct {
	mu        sync.Mutex
	enrolls   int
	seqs      map[string][]uint64
	mode      atomic.Value // "ok" | "shed" | "disabled"
	checkins  atomic.Int64
	disabledT []time.Time
}

func newFake() *fakeServer {
	f := &fakeServer{seqs: map[string][]uint64{}}
	f.mode.Store("ok")
	return f
}

func (f *fakeServer) handler() http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("/api/v1/agent_enroll", func(w http.ResponseWriter, r *http.Request) {
		f.mu.Lock()
		f.enrolls++
		n := f.enrolls
		f.mu.Unlock()
		w.WriteHeader(201)
		json.NewEncoder(w).Encode(map[string]any{"device_id": n, "device_token": strings.Repeat("a", 60) + string(rune('a'+n%26)) + "bcd"})
	})
	mux.HandleFunc("/api/v1/agent_checkin", func(w http.ResponseWriter, r *http.Request) {
		f.checkins.Add(1)
		switch f.mode.Load().(string) {
		case "disabled":
			f.mu.Lock()
			f.disabledT = append(f.disabledT, time.Now())
			f.mu.Unlock()
			w.Header().Set("Retry-After", "3600")
			w.WriteHeader(503)
			io.WriteString(w, `{"error":"The RMM service is disabled on this server.","code":"module_disabled"}`)
			return
		case "shed":
			w.Header().Set("Retry-After", "1")
			w.WriteHeader(503)
			io.WriteString(w, `{"error":"The service is busy. Try again later.","code":"unavailable"}`)
			return
		}
		var b struct {
			Seq uint64 `json:"seq"`
		}
		json.NewDecoder(r.Body).Decode(&b)
		tok := r.Header.Get("Authorization")
		f.mu.Lock()
		f.seqs[tok] = append(f.seqs[tok], b.Seq)
		f.mu.Unlock()
		io.WriteString(w, `{"ok":true,"next_check_in_s":300}`)
	})
	return mux
}

func quickConfig(url string) config {
	return config{URL: url + "/api/v1/", Token: "tok", Devices: 20, Interval: 600 * time.Millisecond, Duration: 3 * time.Second, Concurrency: 8, Insecure: true,
		Batches: 4, TokenUses: 5000, Report: time.Hour, Seed: 42, Timeout: 5 * time.Second, RetryScale: 0.01, DisabledFloor: 15 * time.Minute}
}

func TestRunEnrollsChecksInWithIncreasingSeqAndReports(t *testing.T) {
	f := newFake()
	srv := httptest.NewServer(f.handler())
	defer srv.Close()
	var out bytes.Buffer
	c := quickConfig(srv.URL)
	c.JSON = true
	s := newSim(c, &out)
	if err := s.run(context.Background()); err != nil {
		t.Fatal(err)
	}
	r := s.result(20)
	if f.enrolls != 20 || s.enrolled.Load() != 20 {
		t.Errorf("enrolled %d / %d", f.enrolls, s.enrolled.Load())
	}
	if r.Requests < 40 || r.Codes["200"] != r.Requests || r.ErrorPct != 0 {
		t.Errorf("requests=%d codes=%v err=%v", r.Requests, r.Codes, r.ErrorPct)
	}
	f.mu.Lock()
	defer f.mu.Unlock()
	for tok, seqs := range f.seqs {
		for i := 1; i < len(seqs); i++ {
			if seqs[i] != seqs[i-1]+1 {
				t.Fatalf("device %s: seq %v is not consecutive", tok[len(tok)-3:], seqs)
			}
		}
	}
	var line map[string]any
	if err := json.Unmarshal(bytes.TrimSpace(out.Bytes()[bytes.LastIndexByte(bytes.TrimSpace(out.Bytes()), '\n')+1:]), &line); err != nil || line["p95_ms"] == nil {
		t.Errorf("final JSON line: %v %q", err, out.String())
	}
}

func TestShedResponsesAreRetriedWithTheSameSeqAfterRetryAfter(t *testing.T) {
	f := newFake()
	f.mode.Store("shed")
	srv := httptest.NewServer(f.handler())
	defer srv.Close()
	c := quickConfig(srv.URL)
	c.Devices, c.Duration, c.Interval = 5, 4*time.Second, 300*time.Millisecond
	c.RetryScale = 0.02 // Retry-After 1 s -> 20 ms floor, backoff 100 ms * 2^attempt
	go func() { time.Sleep(600 * time.Millisecond); f.mode.Store("ok") }()
	s := newSim(c, io.Discard)
	if err := s.run(context.Background()); err != nil {
		t.Fatal(err)
	}
	r := s.result(5)
	if r.Codes["503:unavailable"] == 0 || r.Codes["200"] == 0 {
		t.Fatalf("expected both sheds and successes: %v", r.Codes)
	}
	f.mu.Lock()
	defer f.mu.Unlock()
	for _, seqs := range f.seqs {
		if len(seqs) > 0 && seqs[0] != 1 {
			t.Errorf("the first accepted seq must still be 1 (the shed body was kept and resent): %v", seqs)
		}
	}
}

func TestModuleDisabledBacksOffToTheFloor(t *testing.T) {
	f := newFake()
	f.mode.Store("disabled")
	srv := httptest.NewServer(f.handler())
	defer srv.Close()
	c := quickConfig(srv.URL)
	c.Devices, c.Duration, c.Interval = 6, 2500*time.Millisecond, 500*time.Millisecond
	c.RetryScale, c.DisabledFloor = 0.0001, time.Hour // 3600 s Retry-After * 0.0001 = 360 ms; the 15 min floor would be 360 ms too
	s := newSim(c, io.Discard)
	if err := s.run(context.Background()); err != nil {
		t.Fatal(err)
	}
	perDevice := float64(f.checkins.Load()) / 6
	if perDevice > 8 {
		t.Errorf("a disabled server must not be hammered: %.1f requests per device in 2.5 s", perDevice)
	}
	if s.result(6).Codes["503:module_disabled"] == 0 {
		t.Error("module_disabled answers were not seen")
	}
}

func TestInsecureNeverReachesANonLoopbackHostEvenIfConstructedDirectly(t *testing.T) {
	c := quickConfig("http://192.0.2.1")
	if err := c.validate(); err == nil {
		t.Fatal("a non-loopback http target must fail validation")
	}
}

func TestSeveralTokensCoverMoreThanOneTokensWorthOfDevices(t *testing.T) {
	c := config{URL: "https://x.test/", Token: "a, b ,c", Devices: 12001, Interval: time.Second, Concurrency: 1, Batches: 4, RetryScale: 1, TokenUses: 5000}
	if err := c.validate(); err != nil {
		t.Fatal(err)
	}
	for n, want := range map[int]string{1: "a", 5000: "a", 5001: "b", 10000: "b", 10001: "c", 12001: "c"} {
		if got := c.tokenFor(n); got != want {
			t.Errorf("device %d uses %q, want %q", n, got, want)
		}
	}
	c.Token = "a,b"
	if c.validate() == nil {
		t.Error("two tokens cannot enroll 12,001 devices at 5,000 each")
	}
}
