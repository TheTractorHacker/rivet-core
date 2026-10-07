package api

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"encoding/pem"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func caFile(t *testing.T, s *httptest.Server) string {
	p := filepath.Join(t.TempDir(), "ca.pem")
	os.WriteFile(p, pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: s.Certificate().Raw}), 0o600)
	return p
}

func TestTLSRequiresTrustedCert(t *testing.T) {
	s := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { w.Write([]byte(`{}`)) }))
	defer s.Close()
	c, err := New(Options{ServerURL: s.URL}) // httptest cert is not in the system pool
	if err != nil {
		t.Fatal(err)
	}
	err = c.Do(context.Background(), "GET", "agent_jobs", nil, nil)
	if err == nil || !strings.Contains(err.Error(), "certificate") {
		t.Fatalf("untrusted certificate accepted: %v", err)
	}
	c, _ = New(Options{ServerURL: s.URL, CAFile: caFile(t, s)})
	if err := c.Do(context.Background(), "GET", "agent_jobs", nil, nil); err != nil {
		t.Fatalf("extra CA not honoured: %v", err)
	}
}

func TestPlainHTTPRefused(t *testing.T) {
	if _, err := New(Options{ServerURL: "http://example.com"}); err == nil {
		t.Fatal("http accepted")
	}
	if _, err := New(Options{ServerURL: "ftp://example.com"}); err == nil {
		t.Fatal("ftp accepted")
	}
	if _, err := New(Options{ServerURL: "not a url"}); err == nil {
		t.Fatal("garbage accepted")
	}
}

func TestSPKIPin(t *testing.T) {
	s := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { w.Write([]byte(`{}`)) }))
	defer s.Close()
	sum := sha256.Sum256(s.Certificate().RawSubjectPublicKeyInfo)
	good := hex.EncodeToString(sum[:])
	c, _ := New(Options{ServerURL: s.URL, CAFile: caFile(t, s), PinSPKISHA256: good})
	if err := c.Do(context.Background(), "GET", "x", nil, nil); err != nil {
		t.Fatalf("correct pin rejected: %v", err)
	}
	c, _ = New(Options{ServerURL: s.URL, CAFile: caFile(t, s), PinSPKISHA256: strings.Repeat("0", 64)})
	if err := c.Do(context.Background(), "GET", "x", nil, nil); err == nil {
		t.Fatal("wrong pin accepted")
	}
	if _, err := New(Options{ServerURL: s.URL, PinSPKISHA256: "xyz"}); err == nil {
		t.Fatal("malformed pin accepted")
	}
}

func TestBadCAFile(t *testing.T) {
	p := filepath.Join(t.TempDir(), "x.pem")
	os.WriteFile(p, []byte("not pem"), 0o600)
	if _, err := New(Options{ServerURL: "https://a.example", CAFile: p}); err == nil {
		t.Fatal("garbage CA accepted")
	}
}

func TestRedirectNotFollowedAndTokenNotForwarded(t *testing.T) {
	var leaked bool
	other := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Authorization") != "" {
			leaked = true
		}
	}))
	defer other.Close()
	s := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		http.Redirect(w, r, other.URL, http.StatusFound)
	}))
	defer s.Close()
	c, _ := New(Options{ServerURL: s.URL, CAFile: caFile(t, s), Token: func() string { return "SECRET" }})
	err := c.Do(context.Background(), "GET", "agent_jobs", nil, nil)
	if err == nil || leaked {
		t.Fatalf("redirect followed (err=%v leaked=%v)", err, leaked)
	}
}

func TestErrorDecodingAndRetryAfter(t *testing.T) {
	s := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Retry-After", "30")
		w.WriteHeader(429)
		w.Write([]byte(`{"error":"slow down","code":"rate_limited"}`))
	}))
	defer s.Close()
	c, _ := New(Options{ServerURL: s.URL, CAFile: caFile(t, s)})
	err := c.Do(context.Background(), "GET", "x", nil, nil)
	ae, ok := err.(*APIError)
	if !ok || ae.Status != 429 || ae.Code != "rate_limited" || ae.RetryAfter != 30*time.Second || !ae.Transient() {
		t.Fatalf("%#v", err)
	}
}

func TestSizeCaps(t *testing.T) {
	big := strings.Repeat("a", MaxResponseBytes+10)
	s := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { w.Write([]byte(big)) }))
	defer s.Close()
	c, _ := New(Options{ServerURL: s.URL, CAFile: caFile(t, s)})
	if err := c.Do(context.Background(), "GET", "x", nil, nil); err == nil {
		t.Fatal("oversized response accepted")
	}
	if err := c.DoRaw(context.Background(), "POST", "x", make([]byte, MaxRequestBytes+1), nil); err == nil {
		t.Fatal("oversized request sent")
	}
}

func TestBearerHeaderAndPathPrefix(t *testing.T) {
	var gotAuth, gotPath string
	s := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotAuth, gotPath = r.Header.Get("Authorization"), r.URL.Path
		w.Write([]byte(`{"jobs":[]}`))
	}))
	defer s.Close()
	c, _ := New(Options{ServerURL: s.URL + "/itflow/", CAFile: caFile(t, s), Token: func() string { return "tok" }})
	if _, err := c.Jobs(context.Background()); err != nil {
		t.Fatal(err)
	}
	if gotAuth != "Bearer tok" || gotPath != "/itflow/api/v1/agent_jobs" {
		t.Fatalf("%q %q", gotAuth, gotPath)
	}
}

func TestBackoffBounds(t *testing.T) {
	b := Backoff{Base: time.Second, Max: 30 * time.Second}
	wantCeil := []time.Duration{1, 2, 4, 8, 16, 30, 30, 30}
	for i, w := range wantCeil {
		if got := b.Ceiling(i); got != w*time.Second {
			t.Errorf("ceiling(%d)=%v want %v", i, got, w*time.Second)
		}
	}
	if b.Ceiling(10000) != 30*time.Second {
		t.Error("overflow not capped")
	}
	for i := 0; i < 5000; i++ {
		attempt := i % 12
		d := b.Delay(attempt)
		if d < 0 || d > b.Ceiling(attempt) {
			t.Fatalf("delay %v outside [0,%v]", d, b.Ceiling(attempt))
		}
	}
	// full jitter spans the range (not constant)
	seen := map[time.Duration]bool{}
	for i := 0; i < 200; i++ {
		seen[b.Delay(4)] = true
	}
	if len(seen) < 50 {
		t.Error("jitter is not random")
	}
	b.Rand = func() float64 { return 0.999999 }
	if d := b.Delay(3); d > 8*time.Second || d < 7*time.Second {
		t.Errorf("deterministic max: %v", d)
	}
	b.Rand = func() float64 { return 0 }
	if b.Delay(9) != 0 {
		t.Error("min jitter")
	}
	if got := b.DelayWithRetryAfter(0, time.Minute); got != time.Minute {
		t.Errorf("Retry-After floor not honoured: %v", got)
	}
}

func TestParseRetryAfter(t *testing.T) {
	now := time.Date(2026, 1, 1, 0, 0, 0, 0, time.UTC)
	cases := map[string]time.Duration{
		"": 0, "5": 5 * time.Second, "-3": 0, "junk": 0, "999999": time.Hour,
		now.Add(90 * time.Second).Format(http.TimeFormat): 90 * time.Second,
		now.Add(-time.Hour).Format(http.TimeFormat):       0,
	}
	for in, want := range cases {
		if got := ParseRetryAfter(in, now); got != want {
			t.Errorf("%q -> %v want %v", in, got, want)
		}
	}
}

func TestServerTimeFormats(t *testing.T) {
	var r struct {
		A, B, C, D ServerTime
	}
	in := `{"A":"2026-10-06T12:00:00Z","B":"2026-10-06 12:00:00","C":1791288000,"D":"garbage"}`
	if err := json.Unmarshal([]byte(in), &r); err != nil {
		t.Fatal(err)
	}
	if !r.A.OK || !r.B.OK || !r.C.OK || r.D.OK {
		t.Fatalf("%+v", r)
	}
	if !r.A.T.Equal(r.B.T) {
		t.Fatal("formats disagree")
	}
}

func FuzzDecoders(f *testing.F) {
	f.Add([]byte(`{"ok":true,"next_check_in_s":60,"jobs_pending":1,"config":{"checks":[{"key":"a","type":"disk","params":{"x":1},"interval_s":5}],"collect_interval_s":30},"update":{"version":"1","url":"u","sha256":"s","signature":"g","min_version":"0"},"server_time":"2026-01-01T00:00:00Z"}`))
	f.Add([]byte(`{"jobs":[{"job_id":"j","attempt":1,"type":"powershell","script":"x","params":{},"timeout_s":5,"max_output_bytes":1,"issued_at":1,"expires_at":"x","signature":"s"}]}`))
	f.Add([]byte(`{"device_id":"d","device_token":"t","server_time":12345,"config":null}`))
	f.Add([]byte(`null`))
	f.Fuzz(func(t *testing.T, in []byte) {
		var cr CheckinResponse
		_ = json.Unmarshal(in, &cr)
		var er EnrollResponse
		_ = json.Unmarshal(in, &er)
		jobs, err := UnmarshalJobs(in)
		if err == nil {
			for _, j := range jobs {
				if len(j.Raw) == 0 {
					t.Fatal("job without raw bytes")
				}
			}
		}
		// round trip of what we send must always encode
		if _, err := json.Marshal(CheckinRequest{Buffered: []Buffered{}}); err != nil {
			t.Fatal(err)
		}
	})
}

func TestIDAcceptsNumberOrString(t *testing.T) {
	var r EnrollResponse
	for in, want := range map[string]string{`{"device_id":42}`: "42", `{"device_id":"42"}`: "42", `{"device_id":"abc"}`: "abc", `{"device_id":null}`: ""} {
		r = EnrollResponse{}
		if err := json.Unmarshal([]byte(in), &r); err != nil || r.DeviceID.String() != want {
			t.Errorf("%s -> %q (%v)", in, r.DeviceID, err)
		}
	}
	if err := json.Unmarshal([]byte(`{"device_id":{"x":1}}`), &r); err == nil {
		t.Error("object accepted as id")
	}
	jobs, err := UnmarshalJobs([]byte(`{"jobs":[{"job_id":"j","device_id":42,"signature":"s"},{"job_id":"k","device_id":"42"}]}`))
	if err != nil || jobs[0].DeviceID != "42" || jobs[1].DeviceID != "42" {
		t.Fatalf("%v %+v", err, jobs)
	}
	b, _ := json.Marshal(ID("42"))
	if string(b) != "42" {
		t.Error(string(b))
	}
}
