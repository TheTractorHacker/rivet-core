// Package hypervisor collects Proxmox VE and Hyper-V visibility on the host
// (docs/rmm/HYPERVISORS.md). It runs only FIXED commands as argv lists (never a
// shell, never server-chosen text), reads no secrets (no VM configuration, no
// cloud-init data, no guest file systems) and bounds everything it reports.
package hypervisor

import (
	"encoding/json"
	"math"
	"strconv"
	"strings"
	"time"
	"unicode/utf8"
)

// Caps on what one report may carry, whatever the host holds.
const (
	MaxVMs           = 1000
	MaxStorage       = 200
	MaxNodes         = 64
	MaxTags          = 8
	MaxTagLen        = 40
	MaxNameLen       = 100
	MaxNodeLen       = 64
	MaxErrorLen      = 300
	MaxVersionLen    = 60
	MaxChanges       = 1000
	MaxReportBytes   = 300 << 10 // the block inside the 1 MiB check-in body
	maxBytesValue    = int64(1) << 53
	maxUptimeS       = int64(10 * 366 * 86400)
	MaxSnapshotCalls = 300 // snapshot look-ups per full collection
)

// cleanText replaces runs of control characters with one space, drops invalid
// UTF-8, trims and cuts to max runes (the same rule as the server's cleanText).
func cleanText(s string, max int) string {
	s = strings.ToValidUTF8(s, "")
	var b strings.Builder
	b.Grow(min(len(s), max*4))
	inCtl := false
	n := 0
	for _, r := range s {
		if r < 0x20 || r == 0x7f || (r >= 0x80 && r < 0xa0) || r == 0x2028 || r == 0x2029 || r == 0xfeff || (r >= 0x202a && r <= 0x202e) || (r >= 0x2066 && r <= 0x2069) {
			if !inCtl {
				b.WriteByte(' ')
				inCtl = true
			}
			continue
		}
		inCtl = false
		if n >= max {
			break
		}
		b.WriteRune(r)
		n++
	}
	return strings.TrimSpace(b.String())
}

func clampBytes(v float64) (int64, bool) {
	if math.IsNaN(v) || math.IsInf(v, 0) || v < 0 {
		return 0, false
	}
	if v > float64(maxBytesValue) {
		return maxBytesValue, true
	}
	return int64(v), true
}

func clampUptime(v float64) (int64, bool) {
	n, ok := clampBytes(v)
	if !ok {
		return 0, false
	}
	return min(n, maxUptimeS), true
}

// pctOf is 0..100 with one decimal; a fraction (Proxmox reports cpu as 0..1) is multiplied first.
func pct(v float64, fraction bool) (float64, bool) {
	if math.IsNaN(v) || math.IsInf(v, 0) || v < 0 {
		return 0, false
	}
	if fraction {
		v *= 100
	}
	if v > 100 {
		v = 100
	}
	return math.Round(v*10) / 10, true
}

func ptr[T any](v T) *T { return &v }

// num reads a JSON number that may also arrive as a numeric string (pvesh and
// PowerShell both do that now and then).
func num(v any) (float64, bool) {
	switch x := v.(type) {
	case float64:
		return x, true
	case json.Number:
		f, err := x.Float64()
		return f, err == nil
	case string:
		f, err := strconv.ParseFloat(strings.TrimSpace(x), 64)
		return f, err == nil
	case bool:
		if x {
			return 1, true
		}
		return 0, true
	}
	return 0, false
}

func str(v any) string {
	switch x := v.(type) {
	case string:
		return x
	case float64:
		return strconv.FormatFloat(x, 'f', -1, 64)
	case json.Number:
		return x.String()
	}
	return ""
}

func truthy(v any) bool {
	switch x := v.(type) {
	case bool:
		return x
	case string:
		return x == "1" || strings.EqualFold(x, "true")
	}
	f, ok := num(v)
	return ok && f != 0
}

func isoUTC(sec int64) string {
	if sec <= 0 || sec > 4102444800 { // 2100-01-01
		return ""
	}
	return time.Unix(sec, 0).UTC().Format(time.RFC3339)
}

// validUTF8Len keeps the linter honest about utf8 use in tests.
var _ = utf8.RuneError

func itoa(n int) string { return strconv.Itoa(n) }
