// Package api holds the wire types for the RivetIT agent contract and an
// HTTP client with TLS validation, size caps, backoff and Retry-After.
package api

import (
	"encoding/json"
	"fmt"
	"strconv"
	"strings"
	"time"
)

// Device is the enrollment identity block.
type Device struct {
	InstallID    string   `json:"install_id"`
	MachineGUID  *string  `json:"machine_guid"`
	Hostname     string   `json:"hostname"`
	OS           string   `json:"os"`
	OSVersion    string   `json:"os_version"`
	Arch         string   `json:"arch"`
	Serial       *string  `json:"serial"`
	Manufacturer *string  `json:"manufacturer"`
	Model        *string  `json:"model"`
	MACAddresses []string `json:"mac_addresses"`
	AgentVersion string   `json:"agent_version"`
}

type EnrollRequest struct {
	EnrollmentToken string `json:"enrollment_token"`
	Device          Device `json:"device"`
}

// CheckSpec is one server-defined check.
type CheckSpec struct {
	Key       string          `json:"key"`
	Type      string          `json:"type"`
	Params    json.RawMessage `json:"params,omitempty"`
	IntervalS int             `json:"interval_s,omitempty"`
	Signature string          `json:"signature,omitempty"` // detached ed25519 over the canonical object (server fixture: check_definition)
	Raw       json.RawMessage `json:"-"`                   // the object exactly as delivered, for signature verification
}

// UnmarshalJSON keeps the raw object next to the decoded fields.
func (c *CheckSpec) UnmarshalJSON(b []byte) error {
	type plain CheckSpec
	var p plain
	if err := json.Unmarshal(b, &p); err != nil {
		return err
	}
	*c = CheckSpec(p)
	c.Raw = append(json.RawMessage(nil), b...)
	return nil
}

type ServerConfig struct {
	Checks           []CheckSpec `json:"checks"`
	CollectIntervalS int         `json:"collect_interval_s,omitempty"`
}

type EnrollResponse struct {
	DeviceID         ID           `json:"device_id"`
	DeviceToken      string       `json:"device_token"`
	CheckInIntervalS int          `json:"check_in_interval_s"`
	ServerTime       ServerTime   `json:"server_time"`
	Status           string       `json:"status"`
	MatchedAssetID   *int64       `json:"matched_asset_id"`
	SigningPublicKey string       `json:"signing_public_key"`
	SigningKeyID     string       `json:"signing_key_id"`
	Config           ServerConfig `json:"config"`
}

type CPUInfo struct {
	Model string `json:"model"`
	Cores int    `json:"cores"`
}

type DiskInfo struct {
	Mount      string `json:"mount"`
	TotalBytes uint64 `json:"total_bytes"`
	FreeBytes  uint64 `json:"free_bytes"`
	FS         string `json:"fs"`
}

type NetInfo struct {
	Name string   `json:"name"`
	MAC  string   `json:"mac"`
	IPs  []string `json:"ips"`
}

// Inventory fields that could not be determined are null (pointers), never
// empty-string or zero guesses.
type Inventory struct {
	Hostname         string     `json:"hostname"`
	OS               string     `json:"os"`
	OSVersion        string     `json:"os_version"`
	Manufacturer     *string    `json:"manufacturer"`
	Model            *string    `json:"model"`
	Serial           *string    `json:"serial"`
	CPU              *CPUInfo   `json:"cpu"`
	MemoryTotalBytes *uint64    `json:"memory_total_bytes"`
	Disks            []DiskInfo `json:"disks"`
	Network          []NetInfo  `json:"network"`
	UptimeS          *uint64    `json:"uptime_s"`
	LoggedInUser     *string    `json:"logged_in_user"`
	PendingReboot    *bool      `json:"pending_reboot"`
	MeshNodeID       *string    `json:"mesh_node_id"`
}

type DiskMetric struct {
	Mount   string  `json:"mount"`
	UsedPct float64 `json:"used_pct"`
}

// Metrics: a metric that could not be collected is null, NEVER 0.
type Metrics struct {
	CPUPct   *float64     `json:"cpu_pct"`
	MemPct   *float64     `json:"mem_pct"`
	Disk     []DiskMetric `json:"disk"`
	NetRxBps *float64     `json:"net_rx_bps"`
	NetTxBps *float64     `json:"net_tx_bps"`
}

type CheckResult struct {
	Key    string `json:"key"`
	Status string `json:"status"` // ok|warn|fail|unknown
	Detail string `json:"detail"`
}

type Buffered struct {
	CollectedAt string        `json:"collected_at"`
	Metrics     *Metrics      `json:"metrics"`
	Checks      []CheckResult `json:"checks"`
}

type CheckinRequest struct {
	Seq          uint64        `json:"seq"`
	CollectedAt  string        `json:"collected_at"`
	AgentVersion string        `json:"agent_version"`
	Inventory    *Inventory    `json:"inventory"`
	Metrics      *Metrics      `json:"metrics"`
	Checks       []CheckResult `json:"checks"`
	Buffered     []Buffered    `json:"buffered"`
	UpdateResult *UpdateResult `json:"update_result,omitempty"`
	// Additive platform block (agent >= 0.1.0-beta.2). Servers that do not know
	// the fields ignore them; a server that does uses them to pick the job type
	// (powershell vs shell) and the update binary. Absent from older agents.
	Platform     string   `json:"platform,omitempty"`     // runtime.GOOS: windows | linux
	Arch         string   `json:"arch,omitempty"`         // runtime.GOARCH: amd64 | arm64
	Capabilities []string `json:"capabilities,omitempty"` // sorted: "job:shell", "check:disk", ...
}

// UpdateResult reports the last self-update attempt (state ok|failed|rolled_back).
type UpdateResult struct {
	Version string `json:"version"`
	State   string `json:"state"`
	Detail  string `json:"detail,omitempty"`
}

type UpdateManifest struct {
	Version    string `json:"version"`
	URL        string `json:"url"`
	SHA256     string `json:"sha256"`
	Signature  string `json:"signature"`
	MinVersion string `json:"min_version"`
}

type CheckinResponse struct {
	OK            bool            `json:"ok"`
	NextCheckInS  int             `json:"next_check_in_s"`
	JobsPending   *int            `json:"jobs_pending"`
	Config        *ServerConfig   `json:"config"`
	Update        *UpdateManifest `json:"update"`
	ServerTime    ServerTime      `json:"server_time"`
	SigningKeyID  string          `json:"signing_key_id"`
	Status        string          `json:"status"`
	MatchedAssetI *int64          `json:"matched_asset_id"` // optional extension
}

// Job as delivered by GET agent_jobs. Raw keeps the exact object so the
// signature is verified over what the server sent, not a re-marshal.
type Job struct {
	JobID          string          `json:"job_id"`
	Attempt        int             `json:"attempt"`
	Type           string          `json:"type"`
	Script         string          `json:"script"`
	Params         json.RawMessage `json:"params"`
	TimeoutS       int             `json:"timeout_s"`
	MaxOutputBytes int             `json:"max_output_bytes"`
	IssuedAt       ServerTime      `json:"issued_at"`
	ExpiresAt      ServerTime      `json:"expires_at"`
	DeviceID       ID              `json:"device_id,omitempty"`
	Signature      string          `json:"signature"`
	Raw            json.RawMessage `json:"-"`
}

type JobsResponse struct {
	Jobs []Job `json:"jobs"`
}

// UnmarshalJobs decodes a jobs response, retaining each raw job object.
func UnmarshalJobs(b []byte) ([]Job, error) {
	var env struct {
		Jobs []json.RawMessage `json:"jobs"`
	}
	if err := json.Unmarshal(b, &env); err != nil {
		return nil, err
	}
	out := make([]Job, 0, len(env.Jobs))
	for _, raw := range env.Jobs {
		var j Job
		if err := json.Unmarshal(raw, &j); err != nil {
			return nil, fmt.Errorf("job: %w", err)
		}
		j.Raw = append(json.RawMessage(nil), raw...)
		out = append(out, j)
	}
	return out, nil
}

type JobReport struct {
	JobID      string  `json:"job_id"`
	Attempt    int     `json:"attempt"`
	State      string  `json:"state"`
	ExitCode   *int    `json:"exit_code"`
	Output     string  `json:"output"`
	StartedAt  *string `json:"started_at"`
	FinishedAt *string `json:"finished_at"`
}

// ServerTime accepts RFC 3339, "YYYY-MM-DD HH:MM:SS" (UTC) or unix seconds.
type ServerTime struct {
	T  time.Time
	OK bool
}

func (s *ServerTime) UnmarshalJSON(b []byte) error {
	str := strings.TrimSpace(string(b))
	if str == "null" || str == "" {
		*s = ServerTime{}
		return nil
	}
	if strings.HasPrefix(str, `"`) {
		var v string
		if err := json.Unmarshal(b, &v); err != nil {
			return err
		}
		str = v
	}
	if t, ok := ParseTime(str); ok {
		*s = ServerTime{T: t, OK: true}
		return nil
	}
	*s = ServerTime{}
	return nil // unparseable: treated as absent (callers decide)
}

func (s ServerTime) MarshalJSON() ([]byte, error) {
	if !s.OK {
		return []byte("null"), nil
	}
	return json.Marshal(s.T.UTC().Format(time.RFC3339))
}

// ParseTime parses the accepted server time encodings.
func ParseTime(v string) (time.Time, bool) {
	v = strings.TrimSpace(v)
	if v == "" {
		return time.Time{}, false
	}
	if n, err := strconv.ParseFloat(v, 64); err == nil {
		if n < 1e8 || n > 4e10 { // sanity: not a plausible unix-seconds value
			return time.Time{}, false
		}
		return time.Unix(int64(n), 0).UTC(), true
	}
	for _, layout := range []string{time.RFC3339Nano, time.RFC3339, "2006-01-02 15:04:05", "2006-01-02T15:04:05"} {
		if t, err := time.ParseInLocation(layout, v, time.UTC); err == nil {
			return t.UTC(), true
		}
	}
	return time.Time{}, false
}

// ID is a server identifier that may arrive as a JSON integer (the real
// contract) or as a numeric/other string. It always compares as its text.
type ID string

func (i *ID) UnmarshalJSON(b []byte) error {
	s := strings.TrimSpace(string(b))
	switch {
	case s == "null" || s == "":
		*i = ""
	case strings.HasPrefix(s, `"`):
		var v string
		if err := json.Unmarshal(b, &v); err != nil {
			return err
		}
		*i = ID(v)
	default:
		var n json.Number
		if err := json.Unmarshal(b, &n); err != nil {
			return fmt.Errorf("id must be a number or string: %w", err)
		}
		*i = ID(n.String())
	}
	return nil
}

// MarshalJSON emits integers as numbers and everything else as strings.
func (i ID) MarshalJSON() ([]byte, error) {
	if i != "" {
		if _, err := strconv.ParseUint(string(i), 10, 64); err == nil {
			return []byte(i), nil
		}
	}
	return json.Marshal(string(i))
}

func (i ID) String() string { return string(i) }
