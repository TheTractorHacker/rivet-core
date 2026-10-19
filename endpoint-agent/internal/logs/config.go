// Package logs implements RMM Phase 8 log collection: it reads Linux journald or
// Windows event log records according to the server's config.logs, batches them
// and ships them to POST agent_logs. Collection only runs while the latest
// check-in response offered the "logs:collect" feature AND carried config.logs;
// bookmarks (journal cursor, event record id) are persisted only after the
// server acknowledged the batch that contained the events.
//
// Reading a source is behind the Reader interface and process execution behind
// remote.Runner, so nothing here needs systemd, root or journalctl in tests. The
// Windows reader is cross-compiled and vetted only, never executed in this
// repository's test runs.
package logs

import (
	"crypto/sha1"
	"encoding/hex"
	"encoding/json"
	"strings"
	"time"
	"unicode/utf8"

	"rivetit-agent/internal/api"
)

// Config is config.logs with every limit clamped to a sane range.
type Config struct {
	Version         string
	Interval        time.Duration
	BatchMax        int
	MaxMessageBytes int
	RatePerMin      int
	Sources         []api.LogSource
}

// Bounds of the clamped settings.
const (
	MinInterval        = 30 * time.Second
	MaxInterval        = time.Hour
	DefaultInterval    = 60 * time.Second
	MaxBatch           = 200
	MaxSources         = 24
	DefaultMaxMessage  = 1024
	MinMaxMessage      = 64
	MaxMaxMessage      = 8192
	DefaultRatePerMin  = 600
	MaxRatePerMin      = 6000
	MaxBodyBytes       = 200000 // well under the server's 262144 byte request limit
	backlogCap         = 5000
	readBatch          = 500
	defaultPauseOnOff  = 15 * time.Minute
	maxFailBeforeReset = 3
)

// Normalize clamps a server config. A nil input gives nil.
func Normalize(c *api.LogsConfig) *Config {
	if c == nil {
		return nil
	}
	out := &Config{Version: c.Version, BatchMax: c.BatchMax, MaxMessageBytes: c.MaxMessageBytes, RatePerMin: c.RatePerMin}
	out.Interval = time.Duration(c.IntervalS) * time.Second
	if c.IntervalS <= 0 {
		out.Interval = DefaultInterval
	}
	out.Interval = min(max(out.Interval, MinInterval), MaxInterval)
	if out.BatchMax <= 0 || out.BatchMax > MaxBatch {
		out.BatchMax = MaxBatch
	}
	if out.MaxMessageBytes <= 0 {
		out.MaxMessageBytes = DefaultMaxMessage
	}
	out.MaxMessageBytes = min(max(out.MaxMessageBytes, MinMaxMessage), MaxMaxMessage)
	if out.RatePerMin <= 0 {
		out.RatePerMin = DefaultRatePerMin
	}
	out.RatePerMin = min(out.RatePerMin, MaxRatePerMin)
	for i, s := range c.Sources {
		if i >= MaxSources {
			break
		}
		out.Sources = append(out.Sources, s)
	}
	return out
}

// SourceKey is the stable bookmark identity of a source (kind plus a hash of its
// filter), so two sources on the same channel keep separate positions.
func SourceKey(s api.LogSource) string {
	b, _ := json.Marshal(s)
	sum := sha1.Sum(b)
	return s.Kind + ":" + hex.EncodeToString(sum[:6])
}

// LevelName maps a journald priority (0..7) to the wire level.
func LevelName(priority int) string {
	switch {
	case priority <= 2:
		return "critical"
	case priority == 3:
		return "error"
	case priority == 4:
		return "warning"
	case priority <= 6:
		return "info"
	}
	return "debug"
}

// CleanMessage removes control characters (newlines and tabs become spaces) and
// invalid UTF-8; it does not truncate.
func CleanMessage(s string) string {
	s = strings.ToValidUTF8(s, "�")
	s = strings.Map(func(r rune) rune {
		switch {
		case r == '\n' || r == '\r' || r == '\t':
			return ' '
		case r < 0x20 || r == 0x7f || (r >= 0x80 && r < 0xa0):
			return -1
		}
		return r
	}, s)
	return strings.TrimSpace(s)
}

// TruncateUTF8 cuts s to at most max bytes without splitting a rune.
func TruncateUTF8(s string, max int) string {
	if max <= 0 || len(s) <= max {
		return s
	}
	cut := max
	for cut > 0 && !utf8.RuneStart(s[cut]) {
		cut--
	}
	return s[:cut]
}

// MatchesContains reports whether msg contains ANY of the substrings
// (case-insensitive); an empty list matches everything.
func MatchesContains(msg string, contains []string) bool {
	if len(contains) == 0 {
		return true
	}
	l := strings.ToLower(msg)
	for _, c := range contains {
		if c != "" && strings.Contains(l, strings.ToLower(c)) {
			return true
		}
	}
	return false
}

// Item is one collected event with the reader position it came from.
type Item struct {
	Event api.LogEvent
	Pos   string
	// Fields are the named EventData values of a Windows event (not sent).
	Fields map[string]string
}

// ReadRequest asks a Reader for the events after From ("" = first run).
type ReadRequest struct {
	Src   api.LogSource
	From  string
	Limit int
}

// ReadResult is the outcome of one read: the events that passed the source's
// filters, and the position of the last record examined (events that were
// filtered out still advance it).
type ReadResult struct {
	Items   []Item
	LastPos string
}
