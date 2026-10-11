package remote

import (
	"context"
	"encoding/json"
	"strings"
)

// Result is what a remote job handler reports. Output is always compact JSON
// text no longer than the cap the handler was given. Cannot marks a failure
// before anything ran (bad params, disabled locally): the executor reports it
// without an exit code.
type Result struct {
	OK     bool
	Detail string
	Output string
	Cannot bool
}

// Handler runs one remote job type.
type Handler func(ctx context.Context, params json.RawMessage, maxOut int) Result

func marshal(v any) string {
	b, err := json.Marshal(v)
	if err != nil {
		return `{"v":1,"ok":false,"detail":"internal_error"}`
	}
	return string(b)
}

// failure is the output of a failed job: {"v":1,"ok":false,"detail":...} plus
// any identifying members.
func failure(detail string, extra map[string]any) Result {
	m := map[string]any{"v": 1, "ok": false, "detail": sanitizeDetail(detail)}
	for k, v := range extra {
		m[k] = v
	}
	return Result{OK: false, Detail: sanitizeDetail(detail), Output: marshal(m)}
}

// cannotLaunch is a failure that never started the operation.
func cannotLaunch(detail string) Result {
	r := failure(detail, nil)
	r.Cannot = true
	return r
}

// sanitizeDetail keeps a detail code short, printable and single line.
func sanitizeDetail(s string) string {
	s = strings.Map(func(r rune) rune {
		if r < 0x20 || r == 0x7f {
			return ' '
		}
		return r
	}, s)
	if len(s) > 240 {
		s = strings.ToValidUTF8(s[:240], "")
	}
	return s
}

// fitList renders a document holding a list so that it fits maxOut. render(n,
// truncated) must produce the JSON with the first n entries. When the whole list
// does not fit, entries are dropped from the END (binary search for the largest
// n that fits) and truncated is reported true; text is never cut. ok is false
// when not even an empty list fits.
func fitList(total int, alreadyTruncated bool, maxOut int, render func(n int, truncated bool) string) (string, bool) {
	if maxOut <= 0 {
		maxOut = 1 << 20
	}
	if s := render(total, alreadyTruncated); len(s) <= maxOut {
		return s, true
	}
	if s := render(0, true); len(s) > maxOut {
		return "", false
	}
	lo, hi := 0, total-1 // largest n in [0,total-1] that fits; render(0) fits
	for lo < hi {
		mid := (lo + hi + 1) / 2
		if len(render(mid, true)) <= maxOut {
			lo = mid
		} else {
			hi = mid - 1
		}
	}
	return render(lo, true), true
}
