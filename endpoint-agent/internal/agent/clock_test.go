package agent

import (
	"context"
	"encoding/json"
	"net/http"
	"testing"
	"time"

	"rivetit-agent/internal/store"
)

// strictServer answers like the Core server: a collected_at more than window ahead of
// its clock (the real clock) is a 422 invalid with server_time and skew_s (withSkew), and
// every 200 carries server_time (serverTime returns what to put there).
func (r *rig) strictServer(window time.Duration, withSkew bool, serverTime func() any) {
	r.s.checkinFn = func(n int, body []byte) (int, any, http.Header) {
		var req struct {
			CollectedAt string `json:"collected_at"`
		}
		_ = json.Unmarshal(body, &req)
		at, _ := time.Parse(time.RFC3339, req.CollectedAt)
		now := time.Now()
		if at.Sub(now) > window {
			out := map[string]any{"error": "collected_at is in the future", "code": "invalid"}
			if withSkew {
				out["server_time"] = now.UTC().Format(time.RFC3339)
				out["skew_s"] = int64(at.Sub(now).Seconds())
			}
			return 422, out, nil
		}
		st := any(now.UTC().Format(time.RFC3339))
		if serverTime != nil {
			st = serverTime()
		}
		return 200, map[string]any{"ok": true, "next_check_in_s": 60, "jobs_pending": 0, "server_time": st, "update": nil}, nil
	}
}

// skewedRig is a rig whose PC clock is d away from real time (positive = ahead).
func skewedRig(t *testing.T, d time.Duration) *rig {
	r := newRig(t)
	r.clk = &fakeClock{t: time.Now().Add(d)}
	r.a = r.newAgent()
	r.enroll()
	return r
}

func lastCollected(t *testing.T, r *rig) (time.Time, map[string]any) {
	bodies := r.s.checkinBodies()
	m := decodeCheckin(t, bodies[len(bodies)-1])
	at, err := time.Parse(time.RFC3339, m["collected_at"].(string))
	if err != nil {
		t.Fatal(err)
	}
	return at, m
}

func within(a, b time.Time, d time.Duration) bool { return absDur(a.Sub(b)) <= d }

func TestPCAheadFiveMinutesAgainstAnOldServerRecoversOnTheDateOrServerTime(t *testing.T) {
	// The ZEISS case: a 300 s window (an older server) and a PC 5 minutes plus latency ahead.
	r := skewedRig(t, 6*time.Minute)
	r.strictServer(300*time.Second, true, nil)
	// Enrolment already told the agent the server time, so force the "no estimate" start the real PC had.
	r.a.mu.Lock()
	r.a.clkHave, r.a.clkOff, r.a.skew = false, 0, 0
	r.a.mu.Unlock()
	out := r.a.checkIn(context.Background(), 0)
	if !out.ok {
		t.Fatalf("check-in did not succeed after the corrected retry: %+v", out)
	}
	if n := r.s.hit("POST /api/v1/agent_checkin"); n != 2 {
		t.Fatalf("want exactly one refusal and one retry, got %d requests", n)
	}
	at, m := lastCollected(t, r)
	if !within(at, time.Now(), 5*time.Second) {
		t.Fatalf("retry was not server-aligned: %v vs %v", at, time.Now())
	}
	if v, _ := m["clock_offset_s"].(float64); v > -300 || v < -420 {
		t.Fatalf("clock_offset_s should be about -360, got %v", m["clock_offset_s"])
	}
}

func TestRetryHappensOnceThenNormalBackoff(t *testing.T) {
	r := skewedRig(t, 3*time.Hour)
	r.s.checkinFn = func(int, []byte) (int, any, http.Header) {
		return 422, map[string]any{"error": "collected_at is in the future", "code": "invalid", "server_time": time.Now().UTC().Format(time.RFC3339), "skew_s": 10800}, nil
	}
	r.a.mu.Lock()
	r.a.clkHave, r.a.clkOff, r.a.skew = false, 0, 0
	r.a.mu.Unlock()
	out := r.a.checkIn(context.Background(), 0)
	if out.ok {
		t.Fatal("must not succeed")
	}
	if n := r.s.hit("POST /api/v1/agent_checkin"); n != 2 {
		t.Fatalf("a refusal is retried exactly once, got %d requests", n)
	}
	if out.delay <= 0 {
		t.Fatalf("after the one retry the normal back-off applies: %+v", out)
	}
}

func TestPCBehindTwentyMinutesIsCorrectedFromTheNextStamp(t *testing.T) {
	r := skewedRig(t, -20*time.Minute)
	r.strictServer(900*time.Second, true, nil)
	if out := r.a.checkIn(context.Background(), 0); !out.ok {
		t.Fatal("an old stamp is accepted")
	}
	off, have, applied := r.a.ClockOffset()
	if !have || !applied || off < 19*time.Minute || off > 21*time.Minute {
		t.Fatalf("offset %v have=%v applied=%v", off, have, applied)
	}
	if !within(r.a.now(), time.Now(), 5*time.Second) {
		t.Fatalf("stamps are not server-aligned: %v vs %v", r.a.now(), time.Now())
	}
	r.a.sample(context.Background())
	if out := r.a.checkIn(context.Background(), 0); !out.ok {
		t.Fatal("second check-in")
	}
	at, m := lastCollected(t, r)
	if !within(at, time.Now(), 5*time.Second) {
		t.Fatalf("collected_at %v", at)
	}
	if v, _ := m["clock_offset_s"].(float64); v < 1150 || v > 1250 {
		t.Fatalf("clock_offset_s %v", m["clock_offset_s"])
	}
}

func TestZoneConfusionOffsetsAreCorrected(t *testing.T) {
	for _, d := range []time.Duration{time.Hour, -time.Hour, 5 * time.Hour, -8 * time.Hour, 13*time.Hour + 30*time.Minute} {
		r := skewedRig(t, d)
		r.strictServer(900*time.Second, true, nil)
		r.a.mu.Lock()
		r.a.clkHave, r.a.clkOff, r.a.skew = false, 0, 0
		r.a.mu.Unlock()
		if out := r.a.checkIn(context.Background(), 0); !out.ok {
			t.Fatalf("%v: %+v", d, out)
		}
		if !within(r.a.now(), time.Now(), 5*time.Second) {
			t.Fatalf("%v: not aligned", d)
		}
	}
}

func TestSmallOffsetsAreNotApplied(t *testing.T) {
	r := skewedRig(t, 45*time.Second)
	r.strictServer(900*time.Second, true, nil)
	r.a.checkIn(context.Background(), 0)
	if _, have, applied := r.a.ClockOffset(); !have || applied {
		t.Fatalf("45 s must be measured but not applied (have=%v applied=%v)", have, applied)
	}
	if d := r.a.now().Sub(r.clk.Now()); d != 0 {
		t.Fatalf("stamp moved by %v", d)
	}
	r2 := skewedRig(t, 90*time.Second)
	r2.strictServer(900*time.Second, true, nil)
	r2.a.checkIn(context.Background(), 0)
	if _, _, applied := r2.a.ClockOffset(); !applied {
		t.Fatal("90 s must be applied")
	}
}

func TestGarbageServerTimeIsIgnored(t *testing.T) {
	cases := map[string]any{
		"text":      "banana",
		"far past":  "1999-01-01T00:00:00Z",
		"far fut":   "2999-01-01T00:00:00Z",
		"two days":  time.Now().Add(48 * time.Hour).UTC().Format(time.RFC3339),
		"number":    12,
		"null":      nil,
		"23h59 ok?": time.Now().Add(25 * time.Hour).UTC().Format(time.RFC3339),
	}
	for name, v := range cases {
		r := skewedRig(t, 0)
		r.a.mu.Lock()
		r.a.clkHave, r.a.clkOff, r.a.skew = false, 0, 0
		r.a.mu.Unlock()
		r.a.o.Store.Update(func(st *store.State) error { st.ClockSkewS = 0; return nil })
		v := v
		r.strictServer(900*time.Second, true, func() any { return v })
		r.a.checkIn(context.Background(), 0)
		off, _, applied := r.a.ClockOffset()
		if applied || absDur(off) > 5*time.Minute {
			t.Fatalf("%s: garbage server time moved the clock: off=%v applied=%v", name, off, applied)
		}
		if !within(r.a.now(), r.clk.Now(), time.Second) {
			t.Fatalf("%s: stamps moved", name)
		}
	}
}

func TestOffsetNeverExceedsADay(t *testing.T) {
	r := skewedRig(t, 0)
	r.a.observeServerTime(r.clk.Now().Add(23*time.Hour), r.clk.Now(), true)
	if off, _, _ := r.a.ClockOffset(); off != 23*time.Hour {
		t.Fatalf("23 h is within the cap, got %v", off)
	}
	r.a.observeServerTime(r.clk.Now().Add(24*time.Hour+time.Minute), r.clk.Now(), true)
	if off, _, _ := r.a.ClockOffset(); off != 23*time.Hour {
		t.Fatalf("beyond a day must be ignored, got %v", off)
	}
}

func TestOffsetIsSmoothedAndStepsOnARealChange(t *testing.T) {
	r := skewedRig(t, 0)
	now := r.clk.Now()
	r.a.observeServerTime(now.Add(10*time.Minute), now, false)
	r.a.observeServerTime(now.Add(10*time.Minute+8*time.Second), now, false) // jitter: smoothed
	if off, _, _ := r.a.ClockOffset(); off < 10*time.Minute+1500*time.Millisecond || off > 10*time.Minute+3*time.Second {
		t.Fatalf("expected a smoothed step, got %v", off)
	}
	r.a.observeServerTime(now.Add(2*time.Second), now, false) // the PC clock was fixed
	if off, _, applied := r.a.ClockOffset(); off != 2*time.Second || applied {
		t.Fatalf("a real change is adopted at once and releases the correction: %v %v", off, applied)
	}
}

func TestOffsetSurvivesARestart(t *testing.T) {
	r := skewedRig(t, 2*time.Hour)
	r.strictServer(900*time.Second, true, nil)
	r.a.checkIn(context.Background(), 0)
	a2 := r.newAgent()
	off, have, applied := a2.ClockOffset()
	if !have || !applied || off > -time.Hour-50*time.Minute || off < -2*time.Hour-10*time.Minute {
		t.Fatalf("restarted agent lost the offset: %v %v %v", off, have, applied)
	}
	if !within(a2.now(), time.Now(), 5*time.Second) {
		t.Fatal("restarted agent stamps with the PC clock")
	}
}

func TestShiftCheckinMovesEveryTimeAndKeepsTheRest(t *testing.T) {
	body := []byte(`{"seq":7,"collected_at":"2026-10-20T10:00:00Z","agent_version":"1.0.0","unknown_member":{"a":1},"buffered":[{"collected_at":"2026-10-20T09:59:00Z","metrics":null},{"collected_at":"garbage"}]}`)
	off := int64(-300)
	out, ok := shiftCheckin(body, -5*time.Minute, &off)
	if !ok {
		t.Fatal("not shifted")
	}
	var m map[string]json.RawMessage
	json.Unmarshal(out, &m)
	if string(m["collected_at"]) != `"2026-10-20T09:55:00Z"` || string(m["clock_offset_s"]) != "-300" || string(m["unknown_member"]) != `{"a":1}` || string(m["seq"]) != "7" {
		t.Fatalf("%s", out)
	}
	var buf []map[string]string
	json.Unmarshal(m["buffered"], &buf)
	if buf[0]["collected_at"] != "2026-10-20T09:54:00Z" || buf[1]["collected_at"] != "garbage" {
		t.Fatalf("%v", buf)
	}
	if _, ok := shiftCheckin([]byte(`{"collected_at":"nope"}`), time.Minute, nil); ok {
		t.Fatal("a body without a valid collected_at is not shifted")
	}
}
