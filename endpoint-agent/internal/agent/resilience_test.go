package agent

import (
	"bytes"
	"context"
	"log/slog"
	"net/http"
	"runtime"
	"strings"
	"sync"
	"testing"
	"time"

	"rivetit-agent/internal/api"
)

func disabledAnswer(retryAfter string) func(int, []byte) (int, any, http.Header) {
	return func(int, []byte) (int, any, http.Header) {
		h := http.Header{}
		if retryAfter != "" {
			h.Set("Retry-After", retryAfter)
		}
		return 503, map[string]any{"error": "The RMM module is disabled on this server", "code": "module_disabled"}, h
	}
}

func okAnswer(int, []byte) (int, any, http.Header) {
	return 200, map[string]any{"ok": true, "next_check_in_s": 60, "jobs_pending": 0, "status": "linked", "matched_asset_id": 42}, nil
}

func TestModuleDisabledBacksOffKeepsEverything(t *testing.T) {
	r := newRig(t)
	r.enroll()
	r.s.checkinFn = disabledAnswer("3600")
	ctx := context.Background()
	r.a.sample(ctx)
	out := r.a.checkIn(ctx, 0)
	if out.ok || !out.disabled {
		t.Fatalf("outcome %+v", out)
	}
	// Rand pinned at 0.5 => no jitter: exactly the server's hour
	if out.delay != time.Hour {
		t.Fatalf("delay %v want 1h", out.delay)
	}
	in, _ := r.st.LoadInflight()
	if in == nil {
		t.Fatal("the in-flight check-in must be kept: a disabled server is not a poison payload")
	}
	if len(r.a.ring.Snapshot()) == 0 {
		t.Fatal("the sample buffer must be kept")
	}
	if tok, _ := r.st.LoadToken(); tok == "" {
		t.Fatal("credential wiped")
	}
	if st, _ := r.st.LoadState(); st.Dormant {
		t.Fatal("a disabled module must not make the agent dormant")
	}
	if n := r.s.hit("GET /api/v1/agent_jobs") + r.s.hit("POST /api/v1/agent_jobs"); n != 0 {
		t.Fatalf("job endpoint touched %d times while the module is disabled", n)
	}
	// the very same body (same seq) is replayed once the server is back
	r.s.checkinFn = okAnswer
	r.a.disabledLogged = time.Time{}
	if out := r.a.checkIn(ctx, 0); !out.ok {
		t.Fatalf("%+v", out)
	}
	bs := r.s.checkinBodies()
	if len(bs) != 2 || !bytes.Equal(bs[0], bs[1]) {
		t.Fatal("the body was rebuilt instead of replayed")
	}
	if r.a.disabledN != 0 {
		t.Fatal("disabled counter not reset by a successful check-in")
	}
}

func TestModuleDisabledDelayBoundsAndGrowth(t *testing.T) {
	r := newRig(t)
	r.enroll()
	ctx := context.Background()
	// without Retry-After: 15 min, then 30, 1h ... (Rand pinned => exact)
	r.s.checkinFn = disabledAnswer("")
	var got []time.Duration
	for i := 0; i < 4; i++ {
		got = append(got, r.a.checkIn(ctx, i).delay)
	}
	want := []time.Duration{15 * time.Minute, 30 * time.Minute, time.Hour, 2 * time.Hour}
	for i := range want {
		if got[i] != want[i] {
			t.Fatalf("step %d: %v want %v (all %v)", i, got[i], want[i], got)
		}
	}
	// 25 h Retry-After is capped at 24 h
	r.a.disabledN = 0
	r.s.checkinFn = disabledAnswer("90000")
	if d := r.a.checkIn(ctx, 0).delay; d != 24*time.Hour {
		t.Fatalf("%v", d)
	}
	// +/-20 % at the extremes of the random source
	r.a.disabledN = 0
	r.s.checkinFn = disabledAnswer("3600")
	r.a.o.Rand = func() float64 { return 0 }
	if d := r.a.checkIn(ctx, 0).delay; d != 48*time.Minute {
		t.Fatalf("low end %v", d)
	}
	r.a.o.Rand = func() float64 { return 0.999999999 }
	if d := r.a.checkIn(ctx, 0).delay; d < 71*time.Minute || d > 72*time.Minute {
		t.Fatalf("high end %v", d)
	}
}

// syncBuf is a log sink usable from the agent's goroutines.
type syncBuf struct {
	mu sync.Mutex
	b  bytes.Buffer
}

func (s *syncBuf) Write(p []byte) (int, error) { s.mu.Lock(); defer s.mu.Unlock(); return s.b.Write(p) }
func (s *syncBuf) String() string              { s.mu.Lock(); defer s.mu.Unlock(); return s.b.String() }

func TestModuleDisabledLoggedOncePerHour(t *testing.T) {
	r := newRig(t)
	r.enroll()
	buf := &syncBuf{}
	r.a.log = slog.New(slog.NewTextHandler(buf, nil))
	now := time.Now()
	r.a.o.Now = func() time.Time { return now }
	r.s.checkinFn = disabledAnswer("3600")
	ctx := context.Background()
	count := func() int { return strings.Count(buf.String(), "keeping the credential") }
	for i := 0; i < 5; i++ {
		r.a.checkIn(ctx, i)
		now = now.Add(10 * time.Minute)
	}
	if count() != 1 {
		t.Fatalf("logged %d times within an hour:\n%s", count(), buf.String())
	}
	now = now.Add(time.Hour)
	r.a.checkIn(ctx, 0)
	if count() != 2 {
		t.Fatalf("not logged again after an hour: %d", count())
	}
}

// The loop must not spin: while a disabled answer is in force it makes exactly
// one request however many times it wakes up.
func TestModuleDisabledNoTightLoop(t *testing.T) {
	r := newRig(t)
	r.enroll()
	r.s.checkinFn = disabledAnswer("3600")
	ctx, cancel := context.WithCancel(context.Background())
	var waits int
	r.a.o.WaitFn = func(ctx context.Context, d time.Duration) bool {
		waits++
		if waits > 300 {
			cancel()
		}
		return ctx.Err() == nil
	}
	if err := r.a.Run(ctx); err != nil {
		t.Fatal(err)
	}
	if n := r.s.hit("POST /api/v1/agent_checkin"); n != 1 {
		t.Fatalf("%d check-ins in %d wake-ups while disabled (want exactly 1)", n, waits)
	}
}

// An old server answers 403 forbidden when disabled; the established handling
// (drop the unacceptable body, back off) is unchanged.
func TestOldServer403StillHandledAsBefore(t *testing.T) {
	r := newRig(t)
	r.enroll()
	r.s.checkinFn = func(int, []byte) (int, any, http.Header) {
		return 403, map[string]any{"error": "disabled", "code": "forbidden"}, nil
	}
	out := r.a.checkIn(context.Background(), 0)
	if out.ok || out.disabled {
		t.Fatalf("%+v", out)
	}
	if in, _ := r.st.LoadInflight(); in != nil {
		t.Fatal("legacy 403 handling changed: the in-flight body used to be dropped")
	}
}

func TestPendingEnrollModuleDisabledKeepsTokenAndBacksOff(t *testing.T) {
	r := newRig(t)
	if err := r.st.SaveEnrollToken("enr_offline_install"); err != nil {
		t.Fatal(err)
	}
	r.s.enrollFn = func(api.EnrollRequest) (int, any, http.Header) {
		return 503, map[string]any{"error": "disabled", "code": "module_disabled"}, http.Header{"Retry-After": {"3600"}}
	}
	attempt := 0
	d := r.a.tryPendingEnroll(context.Background(), &attempt)
	if d != time.Hour {
		t.Fatalf("delay %v", d)
	}
	if tok, _ := r.st.LoadEnrollToken(); tok == "" {
		t.Fatal("the one-shot enrollment token was discarded while the server was merely switched off")
	}
}

func TestCheckinIntervalJitter(t *testing.T) {
	r := newRig(t)
	r.enroll()
	r.s.checkinFn = okAnswer
	ctx := context.Background()
	for _, c := range []struct {
		rnd  float64
		want time.Duration
	}{{0, 54 * time.Second}, {0.5, 60 * time.Second}, {0.999999999, 66 * time.Second}} {
		r.a.o.Rand = func() float64 { return c.rnd }
		out := r.a.checkIn(ctx, 0)
		if d := out.delay - c.want; !out.ok || d < -time.Millisecond || d > time.Millisecond {
			t.Errorf("rand %v: delay %v want %v", c.rnd, out.delay, c.want)
		}
	}
	// real randomness: different delays, always inside +/-10 %
	r.a.o.Rand = nil
	seen := map[time.Duration]bool{}
	for i := 0; i < 30; i++ {
		d := r.a.checkIn(ctx, 0).delay
		if d < 54*time.Second || d > 66*time.Second {
			t.Fatalf("delay %v outside 60s +/-10%%", d)
		}
		seen[d] = true
	}
	if len(seen) < 10 {
		t.Fatalf("check-in delays are not jittered: %d distinct", len(seen))
	}
}

func TestJitterNeverBelowMinIntervalAndSkippedWhileDraining(t *testing.T) {
	r := newRig(t)
	r.enroll()
	r.a.o.MinInterval = 60 * time.Second
	r.a.o.Rand = func() float64 { return 0 }
	r.s.checkinFn = okAnswer
	if d := r.a.checkIn(context.Background(), 0).delay; d != 60*time.Second {
		t.Fatalf("jitter pushed the delay below the minimum interval: %v", d)
	}
	r.s.checkinFn = func(int, []byte) (int, any, http.Header) {
		return 200, map[string]any{"ok": true, "next_check_in_s": 300, "jobs_pending": 2, "status": "linked", "matched_asset_id": 42}, nil
	}
	if d := r.a.checkIn(context.Background(), 0).delay; d != 60*time.Second {
		t.Fatalf("queue draining must use the plain minimum interval: %v", d)
	}
}

func TestCheckinReportsPlatformAndCapabilities(t *testing.T) {
	r := newRig(t)
	r.enroll()
	r.a.checkIn(context.Background(), 0)
	m := decodeCheckin(t, r.s.checkinBodies()[0])
	if m["platform"] != runtime.GOOS || m["arch"] != runtime.GOARCH {
		t.Fatalf("platform/arch: %v %v", m["platform"], m["arch"])
	}
	caps, _ := m["capabilities"].([]any)
	has := map[string]bool{}
	for _, c := range caps {
		has[c.(string)] = true
	}
	for _, w := range []string{"job:collect", "job:reboot", "check:disk", "check:service", "check:pending_reboot", "check:script"} {
		if !has[w] {
			t.Errorf("capability %s missing: %v", w, caps)
		}
	}
	if runtime.GOOS == "windows" {
		if !has["job:powershell"] || has["job:shell"] {
			t.Errorf("windows capabilities: %v", caps)
		}
	} else if !has["job:shell"] || has["job:powershell"] {
		t.Errorf("unix capabilities: %v", caps)
	}
	// every other contract field is still there, untouched
	for _, k := range []string{"seq", "collected_at", "agent_version", "inventory", "metrics", "checks", "buffered"} {
		if _, ok := m[k]; !ok {
			t.Errorf("contract field %s missing", k)
		}
	}
}

func TestCapabilitiesSortedAndStable(t *testing.T) {
	a, b := Capabilities(), Capabilities()
	if strings.Join(a, ",") != strings.Join(b, ",") {
		t.Fatal("unstable")
	}
	for i := 1; i < len(a); i++ {
		if a[i-1] > a[i] {
			t.Fatalf("not sorted: %v", a)
		}
	}
}
