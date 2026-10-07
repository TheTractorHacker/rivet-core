package api

import (
	"math/rand/v2"
	"net/http"
	"strconv"
	"strings"
	"time"
)

// Backoff implements exponential backoff with full jitter:
// delay = rand[0, min(Max, Base*2^attempt)].
type Backoff struct {
	Base, Max time.Duration
	Rand      func() float64 // [0,1); default math/rand/v2
}

// DefaultBackoff: 5s base, 15 minute cap.
func DefaultBackoff() Backoff { return Backoff{Base: 5 * time.Second, Max: 15 * time.Minute} }

// Ceiling is the un-jittered upper bound for an attempt (0-based).
func (b Backoff) Ceiling(attempt int) time.Duration {
	if attempt < 0 {
		attempt = 0
	}
	c := b.Base
	for i := 0; i < attempt && c < b.Max; i++ {
		c *= 2
	}
	if c > b.Max || c <= 0 {
		c = b.Max
	}
	return c
}

// Delay returns the jittered delay for an attempt.
func (b Backoff) Delay(attempt int) time.Duration {
	r := b.Rand
	if r == nil {
		r = rand.Float64
	}
	return time.Duration(r() * float64(b.Ceiling(attempt)))
}

// DelayWithRetryAfter honours a server Retry-After as a floor.
func (b Backoff) DelayWithRetryAfter(attempt int, retryAfter time.Duration) time.Duration {
	d := b.Delay(attempt)
	if retryAfter > d {
		return retryAfter
	}
	return d
}

// Caps for a parsed Retry-After.
const (
	MaxRetryAfter       = time.Hour
	MaxModuleRetryAfter = 24 * time.Hour
)

// ParseRetryAfter accepts delta-seconds or an HTTP date; result is capped at 1h.
func ParseRetryAfter(h string, now time.Time) time.Duration {
	return ParseRetryAfterCap(h, now, MaxRetryAfter)
}

// ParseRetryAfterCap is ParseRetryAfter with an explicit cap.
func ParseRetryAfterCap(h string, now time.Time, limit time.Duration) time.Duration {
	h = strings.TrimSpace(h)
	if h == "" {
		return 0
	}
	var d time.Duration
	if n, err := strconv.Atoi(h); err == nil {
		if n < 0 {
			return 0
		}
		d = time.Duration(n) * time.Second
	} else if t, err := http.ParseTime(h); err == nil {
		d = t.Sub(now)
	}
	if d < 0 {
		d = 0
	}
	if d > limit {
		d = limit
	}
	return d
}

// Jitter spreads d by +/- frac (0.1 = +/-10 %) using r in [0,1) (nil = math/rand/v2).
// frac outside (0,1) or d <= 0 returns d unchanged.
func Jitter(d time.Duration, frac float64, r func() float64) time.Duration {
	if d <= 0 || frac <= 0 || frac >= 1 {
		return d
	}
	if r == nil {
		r = rand.Float64
	}
	return time.Duration(float64(d) * (1 + frac*(2*r()-1)))
}

// DisabledBackoff is the delay before the next attempt while the server answers
// 503 module_disabled. Its Retry-After (up to 24 h) is the base; without one the
// base grows 15 min, 30 min, 1 h... with each consecutive answer (n counts from 0).
// The base never drops below 15 min and the final delay (after +/-20 % jitter)
// never exceeds 24 h, so a switched-off server sees a handful of requests a day
// per device and the fleet does not wake in step.
func DisabledBackoff(n int, retryAfter time.Duration, r func() float64) time.Duration {
	const floor = 15 * time.Minute
	base := retryAfter
	if base <= 0 {
		base = floor
		for i := 0; i < n && base < MaxModuleRetryAfter; i++ {
			base *= 2
		}
	}
	if base < floor {
		base = floor
	}
	if base > MaxModuleRetryAfter {
		base = MaxModuleRetryAfter
	}
	d := Jitter(base, 0.2, r)
	if d > MaxModuleRetryAfter {
		d = MaxModuleRetryAfter
	}
	return d
}
