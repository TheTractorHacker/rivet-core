package api

import (
	"context"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"
)

func TestParseRetryAfterCaps(t *testing.T) {
	now := time.Now()
	if d := ParseRetryAfter("86400", now); d != time.Hour {
		t.Fatalf("regular cap must stay 1h: %v", d)
	}
	if d := ParseRetryAfterCap("86400", now, MaxModuleRetryAfter); d != 24*time.Hour {
		t.Fatalf("%v", d)
	}
	if d := ParseRetryAfterCap("999999999", now, MaxModuleRetryAfter); d != 24*time.Hour {
		t.Fatalf("24h cap: %v", d)
	}
	if d := ParseRetryAfterCap("7200", now, MaxModuleRetryAfter); d != 2*time.Hour {
		t.Fatalf("%v", d)
	}
	for _, bad := range []string{"", "-5", "soon"} {
		if d := ParseRetryAfterCap(bad, now, MaxModuleRetryAfter); d != 0 {
			t.Errorf("%q -> %v", bad, d)
		}
	}
}

func TestJitterBounds(t *testing.T) {
	for _, c := range []struct {
		r    float64
		want time.Duration
	}{{0, 54 * time.Second}, {0.5, 60 * time.Second}, {0.999999999, 66 * time.Second}} {
		got := Jitter(60*time.Second, 0.1, func() float64 { return c.r })
		if d := got - c.want; d < -time.Millisecond || d > time.Millisecond {
			t.Errorf("r=%v got %v want %v", c.r, got, c.want)
		}
	}
	if Jitter(0, 0.1, nil) != 0 || Jitter(time.Minute, 0, nil) != time.Minute || Jitter(time.Minute, 1.5, nil) != time.Minute {
		t.Fatal("degenerate inputs must be returned unchanged")
	}
	// real randomness stays within +/-10 % and actually varies
	seen := map[time.Duration]bool{}
	for i := 0; i < 200; i++ {
		d := Jitter(time.Minute, 0.1, nil)
		if d < 54*time.Second || d > 66*time.Second {
			t.Fatalf("outside +/-10%%: %v", d)
		}
		seen[d] = true
	}
	if len(seen) < 50 {
		t.Fatalf("jitter is not random: %d distinct values", len(seen))
	}
}

func TestDisabledBackoff(t *testing.T) {
	lo, hi := func() float64 { return 0 }, func() float64 { return 0.999999999 }
	mid := func() float64 { return 0.5 }
	for _, c := range []struct {
		name          string
		n             int
		ra            time.Duration
		min, mid, max time.Duration
	}{
		{"server says 1h", 0, time.Hour, 48 * time.Minute, time.Hour, 72 * time.Minute},
		{"no header, first answer: 15 min floor", 0, 0, 12 * time.Minute, 15 * time.Minute, 18 * time.Minute},
		{"no header, grows", 2, 0, 48 * time.Minute, time.Hour, 72 * time.Minute},
		{"tiny Retry-After is lifted to the 15 min floor", 5, 10 * time.Second, 12 * time.Minute, 15 * time.Minute, 18 * time.Minute},
		{"24h cap even with jitter", 0, 24 * time.Hour, 19*time.Hour + 12*time.Minute, 24 * time.Hour, 24 * time.Hour},
		{"huge n never overflows or exceeds 24h", 500, 0, 19*time.Hour + 12*time.Minute, 24 * time.Hour, 24 * time.Hour},
	} {
		for label, r := range map[string]func() float64{"lo": lo, "mid": mid, "hi": hi} {
			got := DisabledBackoff(c.n, c.ra, r)
			want := map[string]time.Duration{"lo": c.min, "mid": c.mid, "hi": c.max}[label]
			if d := got - want; d < -time.Second || d > time.Second {
				t.Errorf("%s/%s: got %v want %v", c.name, label, got, want)
			}
			if got > 24*time.Hour {
				t.Errorf("%s: %v exceeds the 24h cap", c.name, got)
			}
		}
	}
}

func TestModuleDisabledClassification(t *testing.T) {
	for _, c := range []struct {
		e    APIError
		want bool
	}{
		{APIError{Status: 503, Code: "module_disabled"}, true},
		{APIError{Status: 503, Code: "feature_disabled"}, true},
		{APIError{Status: 503, Code: "maintenance"}, false},
		{APIError{Status: 503}, false},
		{APIError{Status: 403, Code: "module_disabled"}, false}, // an old server's 403 keeps its old handling
		{APIError{Status: 403, Code: "forbidden"}, false},
		{APIError{Status: 429, Code: "module_disabled"}, false},
	} {
		if got := c.e.ModuleDisabled(); got != c.want {
			t.Errorf("%+v: %v", c.e, got)
		}
	}
	if !(&APIError{Status: 503, Code: "module_disabled"}).Transient() {
		t.Fatal("a disabled module is still a transient failure for callers that do not special-case it (old retry paths)")
	}
}

func TestClientParsesModuleDisabledAnswer(t *testing.T) {
	s := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Retry-After", "7200")
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(503)
		w.Write([]byte(`{"error":"The RMM module is disabled","code":"module_disabled"}`))
	}))
	defer s.Close()
	c, err := New(Options{ServerURL: s.URL, CAFile: caFile(t, s)})
	if err != nil {
		t.Fatal(err)
	}
	_, err = c.Checkin(context.Background(), []byte(`{}`))
	ae, ok := err.(*APIError)
	if !ok || !ae.ModuleDisabled() {
		t.Fatalf("%v", err)
	}
	if ae.RetryAfter != time.Hour || ae.RetryAfterRaw != 2*time.Hour {
		t.Fatalf("RetryAfter %v raw %v: the 1h cap must not apply to the module_disabled base", ae.RetryAfter, ae.RetryAfterRaw)
	}
}
