package agent

import (
	"encoding/json"
	"fmt"
	"math"
	"time"

	"rivetit-agent/internal/api"
	"rivetit-agent/internal/store"
)

// Clock correction (rc.23). The agent keeps an estimate of "server time minus this PC's
// clock" from the server_time of every enroll and check-in answer (the HTTP Date header
// when an answer has none). While the estimate is large the agent stamps what it sends
// with local time plus the estimate, so a PC whose clock or time zone is wrong still
// reports times the server accepts. The correction is bounded: it never exceeds a day
// and the server still validates its own window.
const (
	// clockApplyAbove switches the correction on; clockReleaseBelow switches it off
	// again (a gap, so an offset hovering near the threshold does not flap).
	clockApplyAbove   = 60 * time.Second
	clockReleaseBelow = 30 * time.Second
	// clockMaxOffset caps both the offset the agent believes and the shift it applies.
	clockMaxOffset = 24 * time.Hour
	// clockStep: a new measurement that differs from the estimate by more than this is a
	// real change (the PC clock was fixed, the zone changed), adopted at once; smaller
	// differences are latency and Date-header rounding and are smoothed.
	clockStep      = 30 * time.Second
	clockWarnEvery = time.Hour
)

// ClockOffset is the current estimate of server time minus the PC clock and whether
// the correction is being applied.
func (a *Agent) ClockOffset() (off time.Duration, have, applied bool) {
	a.mu.Lock()
	defer a.mu.Unlock()
	return a.clkOff, a.clkHave, a.skew != 0
}

// loadClock restores the persisted estimate at start-up, so a restarted agent (and the
// service started right after `enroll` in another process) stamps correctly from its
// very first check-in.
func (a *Agent) loadClock(st store.State) {
	off := time.Duration(st.ClockSkewS * float64(time.Second))
	if st.ClockSkewS == 0 || math.IsNaN(st.ClockSkewS) || off > clockMaxOffset || off < -clockMaxOffset {
		return
	}
	a.mu.Lock()
	a.clkOff, a.clkHave = off, true
	a.setAppliedLocked()
	a.mu.Unlock()
}

// setAppliedLocked derives the applied correction from the estimate, with hysteresis.
func (a *Agent) setAppliedLocked() {
	abs := a.clkOff
	if abs < 0 {
		abs = -abs
	}
	switch {
	case !a.clkHave:
		a.skew = 0
	case a.skew != 0 && abs < clockReleaseBelow:
		a.skew = 0
	case a.skew == 0 && abs > clockApplyAbove:
		a.skew = a.clkOff
	case a.skew != 0:
		a.skew = a.clkOff
	}
}

// observeServerTime folds one measurement in: server is the server clock, local the raw
// PC clock read when the answer arrived. Implausible values (zero, beyond a day from the
// PC clock) are ignored. reset adopts the value without smoothing (a time refusal).
func (a *Agent) observeServerTime(server, local time.Time, reset bool) {
	if server.IsZero() || local.IsZero() {
		return
	}
	m := server.Sub(local)
	if m > clockMaxOffset || m < -clockMaxOffset {
		a.log.Warn("ignoring an implausible server time", "server_time", server.UTC().Format(time.RFC3339), "pc_time", local.UTC().Format(time.RFC3339))
		return
	}
	a.mu.Lock()
	switch {
	case !a.clkHave || reset:
		a.clkOff = m
	case absDur(m-a.clkOff) > clockStep:
		a.clkOff = m
	default:
		a.clkOff += (m - a.clkOff) / 4
	}
	a.clkHave = true
	a.setAppliedLocked()
	off, applied := a.clkOff, a.skew != 0
	warn := applied && (a.clkWarned.IsZero() || local.Sub(a.clkWarned) >= clockWarnEvery)
	if warn {
		a.clkWarned = local
	}
	a.mu.Unlock()
	if warn {
		a.log.Warn(fmt.Sprintf("this PC's clock is %s the server's; times are being corrected. Fix the PC's time zone and time sync (tzutil /g, tzutil /s, w32tm /resync)", aheadBehind(off)),
			"offset_s", int64(math.Round(off.Seconds())))
	}
	secs := off.Seconds()
	if cur, _ := a.o.Store.LoadState(); math.Abs(cur.ClockSkewS-secs) >= 1 {
		_ = a.o.Store.Update(func(st *store.State) error { st.ClockSkewS = secs; return nil })
	}
}

func absDur(d time.Duration) time.Duration {
	if d < 0 {
		return -d
	}
	return d
}

func aheadBehind(off time.Duration) string {
	// off is server minus PC: a positive offset means the PC is BEHIND the server.
	m := int(math.Round(absDur(off).Minutes()))
	switch {
	case m < 1:
		return fmt.Sprintf("%d seconds %s", int(absDur(off).Seconds()), dirWord(off))
	case m < 120:
		return fmt.Sprintf("%d minutes %s", m, dirWord(off))
	}
	return fmt.Sprintf("%.1f hours %s", absDur(off).Hours(), dirWord(off))
}

func dirWord(off time.Duration) string {
	if off > 0 {
		return "behind"
	}
	return "ahead of"
}

// observeResponse is called with the raw PC time right after a check-in or enroll answer.
func (a *Agent) observeResponse(st api.ServerTime, local time.Time) {
	if st.OK {
		a.observeServerTime(st.T, local, false)
		return
	}
	if c := a.api(); c != nil {
		if d, ok := c.LastDate(); ok {
			a.observeServerTime(d.Add(500*time.Millisecond), local, false) // the Date header is truncated to the second
		}
	}
}

// clockOffsetS is the estimate for the check-in's clock_offset_s member (nil until measured).
func (a *Agent) clockOffsetS() *int64 {
	a.mu.Lock()
	defer a.mu.Unlock()
	if !a.clkHave {
		return nil
	}
	v := int64(math.Round(a.clkOff.Seconds()))
	return &v
}

// recoverTime handles the 422 a server answers to a timestamp outside its window. It
// re-measures the offset from the server clock the refusal carries, shifts every time in
// the in-flight body by the skew the server reported and reports true so the caller
// resends it once, right away. At most once per seq: a second refusal falls through to
// the normal drop-and-back-off path.
func (a *Agent) recoverTime(in *store.Inflight, err error) bool {
	ae, ok := err.(*api.APIError)
	if !ok || !ae.TimeRefusal() || a.timeRetrySeq == in.Seq {
		return false
	}
	a.timeRetrySeq = in.Seq
	local := a.o.Now()
	server := ae.ServerTime.T
	if !ae.ServerTime.OK {
		return false
	}
	var req map[string]json.RawMessage
	if json.Unmarshal(in.Body, &req) != nil {
		return false
	}
	var collected string
	if json.Unmarshal(req["collected_at"], &collected) != nil {
		return false
	}
	sent, perr := time.Parse(time.RFC3339, collected)
	if perr != nil {
		return false
	}
	skew := sent.Sub(server) // what the server computed: the stamp minus its own clock
	if ae.SkewS != nil {
		skew = time.Duration(*ae.SkewS) * time.Second
	}
	if absDur(skew) > clockMaxOffset {
		return false // a stamp more than a day off is not a clock problem we correct; the server's window decides
	}
	a.observeServerTime(server, local, true)
	body, ok := shiftCheckin(in.Body, -skew, a.clockOffsetS())
	if !ok {
		return false
	}
	in.Body = body
	if a.o.Store.SaveInflight(*in) != nil {
		return false
	}
	a.log.Warn("server refused the check-in time; corrected the stamps and retrying once", "skew_s", int64(skew.Seconds()), "server_time", server.UTC().Format(time.RFC3339))
	return true
}

// shiftCheckin moves collected_at and every buffered collected_at of an encoded check-in
// by d and sets clock_offset_s. Unknown members are preserved byte for byte.
func shiftCheckin(body []byte, d time.Duration, offset *int64) ([]byte, bool) {
	var req map[string]json.RawMessage
	if json.Unmarshal(body, &req) != nil {
		return nil, false
	}
	shift := func(raw json.RawMessage) (json.RawMessage, bool) {
		var s string
		if json.Unmarshal(raw, &s) != nil {
			return nil, false
		}
		t, err := time.Parse(time.RFC3339, s)
		if err != nil {
			return nil, false
		}
		out, _ := json.Marshal(t.Add(d).UTC().Format(time.RFC3339))
		return out, true
	}
	c, ok := shift(req["collected_at"])
	if !ok {
		return nil, false
	}
	req["collected_at"] = c
	if raw, has := req["buffered"]; has {
		var list []map[string]json.RawMessage
		if json.Unmarshal(raw, &list) == nil {
			for _, e := range list {
				if v, ok := shift(e["collected_at"]); ok {
					e["collected_at"] = v
				}
			}
			req["buffered"], _ = json.Marshal(list)
		}
	}
	if offset != nil {
		req["clock_offset_s"], _ = json.Marshal(*offset)
	}
	out, err := json.Marshal(req)
	return out, err == nil
}
