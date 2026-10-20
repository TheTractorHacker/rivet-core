package hypervisor

import (
	"encoding/json"
	"errors"
	"fmt"
	"regexp"
	"time"
)

// SignDomain is the first line of the signed message of a configuration: a job,
// a check or a change configuration can never verify as one, nor the reverse.
const SignDomain = "rivetit-hypervisor-config-v1"

// Bounds the agent applies to a configuration, however validly it is signed.
const (
	MinFullInterval    = 300 * time.Second
	MaxFullInterval    = 24 * time.Hour
	DefaultFullS       = 600
	MinSummaryInterval = 60 * time.Second
	MaxSummaryInterval = 600 * time.Second
	DefaultSummaryS    = 120
	maxConfigBytes     = 8 << 10
)

var reVersion = regexp.MustCompile(`^[0-9a-f]{16}$`)

// ErrNoConfig is returned for an absent or empty block (hypervisor collection off).
var ErrNoConfig = errors.New("no hypervisor configuration")

type wireConfig struct {
	V         int    `json:"v"`
	Kind      string `json:"kind"`
	Platform  string `json:"platform"`
	FullS     int    `json:"full_interval_s"`
	SummaryS  int    `json:"summary_interval_s"`
	MaxVMs    int    `json:"max_vms"`
	Snapshots int    `json:"snapshot_lookups"`
	Seq       int64  `json:"seq"`
	Version   string `json:"version"`
	Signature string `json:"signature"`
}

// Config is a verified, normalised configuration.
type Config struct {
	Seq             int64
	Version         string
	Platform        string
	FullInterval    time.Duration
	SummaryInterval time.Duration
	MaxVMs          int
	SnapshotLookups int
}

// VerifyFunc checks the detached signature of a configuration object.
type VerifyFunc func(domain string, raw []byte, sigB64 string) error

// Parse verifies and normalises config.hypervisor. Nothing in it is acted on
// before the signature verifies against the pinned key.
func Parse(raw json.RawMessage, goos string, verify VerifyFunc) (*Config, error) {
	if len(raw) == 0 || string(raw) == "null" {
		return nil, ErrNoConfig
	}
	if len(raw) > maxConfigBytes {
		return nil, errors.New("hypervisor configuration too large")
	}
	var w wireConfig
	if err := json.Unmarshal(raw, &w); err != nil {
		return nil, fmt.Errorf("hypervisor configuration unreadable: %w", err)
	}
	if verify == nil {
		return nil, errors.New("no verifier")
	}
	if err := verify(SignDomain, raw, w.Signature); err != nil {
		return nil, fmt.Errorf("hypervisor configuration signature: %w", err)
	}
	if w.V != 1 || w.Kind != "hypervisor_config" || !reVersion.MatchString(w.Version) {
		return nil, errors.New("hypervisor configuration is not for this agent")
	}
	if w.Platform != goos {
		return nil, errors.New("hypervisor configuration is for another platform")
	}
	if w.FullS <= 0 {
		w.FullS = DefaultFullS
	}
	if w.SummaryS <= 0 {
		w.SummaryS = DefaultSummaryS
	}
	c := &Config{Seq: w.Seq, Version: w.Version, Platform: w.Platform}
	c.FullInterval = min(max(time.Duration(w.FullS)*time.Second, MinFullInterval), MaxFullInterval)
	c.SummaryInterval = min(max(time.Duration(w.SummaryS)*time.Second, MinSummaryInterval), MaxSummaryInterval)
	c.SummaryInterval = min(c.SummaryInterval, c.FullInterval)
	c.MaxVMs = w.MaxVMs
	if c.MaxVMs <= 0 || c.MaxVMs > MaxVMs {
		c.MaxVMs = MaxVMs
	}
	c.SnapshotLookups = w.Snapshots
	if c.SnapshotLookups <= 0 || c.SnapshotLookups > MaxSnapshotCalls {
		c.SnapshotLookups = MaxSnapshotCalls
	}
	return c, nil
}
