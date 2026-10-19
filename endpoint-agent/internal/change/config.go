package change

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"regexp"
	"strings"
	"time"
)

// SignDomain is the first line of the signed message of a configuration: a job
// or a check definition can never verify as one, nor the reverse.
const SignDomain = "rivetit-change-config-v1"

// Bounds the agent applies to a configuration, however validly it is signed.
const (
	MinInterval      = 60 * time.Second
	MaxInterval      = 24 * time.Hour
	MaxBatch         = 100
	MinRatePerMin    = 10
	MaxRatePerMin    = 2000
	MinValueBytes    = 64
	MaxValueBytes    = 512
	MaxWatches       = 60
	MaxPathsPerWatch = 20
	maxConfigBytes   = 64 << 10
	maxValueFilters  = 20
)

var (
	reWatchID = regexp.MustCompile(`^(?:win|lnx|custom)\.[a-z0-9_]{1,40}$`)
	reVersion = regexp.MustCompile(`^[0-9a-f]{16}$`)
	reGroup   = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9 _.-]{0,63}$`)
	reMatch   = regexp.MustCompile(`^[A-Za-z0-9._*?-]{1,60}$`)
)

// PathSpec is one thing to read inside a watch: a registry key (Key, Depth,
// Values) or a file or folder (Path, Depth, Match).
type PathSpec struct {
	Key    string   `json:"key,omitempty"`
	Path   string   `json:"path,omitempty"`
	Depth  int      `json:"depth,omitempty"`
	Values []string `json:"values,omitempty"`
	Match  string   `json:"match,omitempty"`
}

// Watch is one named watch: registry, file, group or task.
type Watch struct {
	ID    string     `json:"id"`
	Kind  string     `json:"kind"`
	Name  string     `json:"name,omitempty"`
	Paths []PathSpec `json:"paths,omitempty"`
}

// Hash identifies the watch's specification: when it changes the watch is
// re-baselined silently instead of reporting everything as added.
func (w Watch) Hash() string {
	b, _ := json.Marshal(w)
	s := sha256.Sum256(b)
	return hex.EncodeToString(s[:8])
}

type wireConfig struct {
	V             int     `json:"v"`
	Kind          string  `json:"kind"`
	Platform      string  `json:"platform"`
	IntervalS     int     `json:"interval_s"`
	BatchMax      int     `json:"batch_max"`
	RatePerMin    int     `json:"rate_per_min"`
	MaxValueBytes int     `json:"max_value_bytes"`
	Watches       []Watch `json:"watches"`
	Version       string  `json:"version"`
	Signature     string  `json:"signature"`
}

// Config is a verified, normalised change-monitoring configuration.
type Config struct {
	Version       string
	Platform      string
	Interval      time.Duration
	BatchMax      int
	RatePerMin    int
	MaxValueBytes int
	Watches       []Watch
	// Refused lists what the agent's own policy removed from the signed list.
	Refused []string
}

// VerifyFunc checks the detached signature of a configuration object.
type VerifyFunc func(domain string, raw []byte, sigB64 string) error

// ParseOptions say how a configuration is checked.
type ParseOptions struct {
	Verify     VerifyFunc
	Platform   string   // "windows" or "linux": the platform this agent runs on
	ExtraRoots []string // extra file roots a local administrator allowed
}

// ErrNoConfig is returned for an absent or empty block (change monitoring off).
var ErrNoConfig = errors.New("no change-monitoring configuration")

// Parse verifies and normalises config.change. Nothing in it is acted on before
// the signature verifies against the pinned key.
func Parse(raw json.RawMessage, o ParseOptions) (*Config, error) {
	if len(raw) == 0 || string(raw) == "null" {
		return nil, ErrNoConfig
	}
	if len(raw) > maxConfigBytes {
		return nil, errors.New("change configuration too large")
	}
	var w wireConfig
	if err := json.Unmarshal(raw, &w); err != nil {
		return nil, fmt.Errorf("change configuration unreadable: %w", err)
	}
	if o.Verify == nil {
		return nil, errors.New("no verifier")
	}
	if err := o.Verify(SignDomain, raw, w.Signature); err != nil {
		return nil, fmt.Errorf("change configuration signature: %w", err)
	}
	if w.V != 1 || w.Kind != "change_config" || w.Platform != o.Platform || !reVersion.MatchString(w.Version) {
		return nil, errors.New("change configuration is not for this agent")
	}
	c := &Config{Version: w.Version, Platform: w.Platform, BatchMax: w.BatchMax, RatePerMin: w.RatePerMin, MaxValueBytes: w.MaxValueBytes}
	c.Interval = min(max(time.Duration(w.IntervalS)*time.Second, MinInterval), MaxInterval)
	if c.BatchMax <= 0 || c.BatchMax > MaxBatch {
		c.BatchMax = MaxBatch
	}
	c.RatePerMin = min(max(c.RatePerMin, MinRatePerMin), MaxRatePerMin)
	c.MaxValueBytes = min(max(c.MaxValueBytes, MinValueBytes), MaxValueBytes)
	seen := map[string]bool{}
	for i, wt := range w.Watches {
		if i >= MaxWatches {
			c.Refused = append(c.Refused, "more than 60 watches: the rest are ignored")
			break
		}
		nw, why := normaliseWatch(wt, o)
		if nw == nil {
			c.Refused = append(c.Refused, why)
			continue
		}
		if seen[nw.ID] {
			c.Refused = append(c.Refused, nw.ID+": repeated")
			continue
		}
		seen[nw.ID] = true
		c.Watches = append(c.Watches, *nw)
		c.Refused = append(c.Refused, refusedPaths(wt, *nw)...)
	}
	return c, nil
}

func refusedPaths(in, out Watch) []string {
	if len(in.Paths) == len(out.Paths) {
		return nil
	}
	return []string{fmt.Sprintf("%s: %d path(s) refused by this agent's policy", in.ID, len(in.Paths)-len(out.Paths))}
}

func normaliseWatch(w Watch, o ParseOptions) (*Watch, string) {
	if !reWatchID.MatchString(w.ID) {
		return nil, "a watch with an invalid id"
	}
	win := o.Platform == "windows"
	out := Watch{ID: w.ID, Kind: w.Kind}
	switch w.Kind {
	case "registry":
		if !win {
			return nil, w.ID + ": registry watches exist on Windows only"
		}
	case "file":
	case "group":
		if !win || !reGroup.MatchString(w.Name) {
			return nil, w.ID + ": a local group watch needs Windows and a plain group name"
		}
		out.Name = w.Name
		return &out, ""
	case "task":
		if !win {
			return nil, w.ID + ": scheduled-task watches exist on Windows only"
		}
		return &out, ""
	default:
		return nil, w.ID + ": unknown watch kind"
	}
	if len(w.Paths) == 0 || len(w.Paths) > MaxPathsPerWatch {
		return nil, w.ID + ": needs 1 to 20 paths"
	}
	for _, p := range w.Paths {
		np, ok := normalisePath(w.Kind, p, o)
		if ok {
			out.Paths = append(out.Paths, np)
		}
	}
	if len(out.Paths) == 0 {
		return nil, w.ID + ": every path was refused by this agent's policy"
	}
	return &out, ""
}

func normalisePath(kind string, p PathSpec, o ParseOptions) (PathSpec, bool) {
	out := PathSpec{}
	if kind == "registry" {
		if !RegistryKeyAllowed(p.Key) {
			return out, false
		}
		out.Key = p.Key
		out.Depth = min(max(p.Depth, 0), 4)
		if len(p.Values) > maxValueFilters {
			return out, false
		}
		for _, v := range p.Values {
			if v == "" || len(v) > 100 || Clean(v) != v {
				return out, false
			}
			out.Values = append(out.Values, v)
		}
		return out, true
	}
	if !FileAllowed(p.Path, o.ExtraRoots) {
		return out, false
	}
	out.Path = p.Path
	out.Depth = min(max(p.Depth, 0), 3)
	if p.Match != "" {
		if !reMatch.MatchString(p.Match) {
			return out, false
		}
		out.Match = p.Match
	}
	return out, true
}

// matchName reports whether a base name matches a pattern with * and ?.
func matchName(pattern, name string) bool {
	if pattern == "" {
		return true
	}
	return globMatch(pattern, name)
}

// globMatch matches s against a pattern of literal bytes, ? (one rune) and * (any
// run), in time proportional to len(p)*len(s): no pattern can make it explode.
func globMatch(p, s string) bool {
	pr, sr := []rune(p), []rune(s)
	pi, si, star, mark := 0, 0, -1, 0
	for si < len(sr) {
		switch {
		case pi < len(pr) && (pr[pi] == '?' || (pr[pi] != '*' && pr[pi] == sr[si])):
			pi++
			si++
		case pi < len(pr) && pr[pi] == '*':
			star, mark = pi, si
			pi++
		case star >= 0:
			pi = star + 1
			mark++
			si = mark
		default:
			return false
		}
	}
	for pi < len(pr) && pr[pi] == '*' {
		pi++
	}
	return pi == len(pr)
}

func lowerEq(a, b string) bool { return strings.EqualFold(a, b) }
