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
	OS        string          `json:"os,omitempty"`        // optional target: "windows" or "linux"; empty or "any" = every OS (rc.24, signed with the rest)
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

// PolicyInfo is the effective policy the server resolved for this device
// (config.policy, sent only to an agent that announced the "policies"
// capability). The checks and intervals it produced are already in the rest of
// the config; this block names the version and carries the feature toggles the
// agent also enforces locally.
type PolicyInfo struct {
	Version  string          `json:"version"`
	Applied  bool            `json:"applied"`
	Features map[string]bool `json:"features,omitempty"`
}

type ServerConfig struct {
	Checks           []CheckSpec `json:"checks"`
	CollectIntervalS int         `json:"collect_interval_s,omitempty"`
	Policy           *PolicyInfo `json:"policy,omitempty"`
	// Logs is the log-collection config (Phase 8). It is kept raw so that a
	// malformed block can never fail the whole check-in decode; ParseLogsConfig
	// reads it leniently and the agent only acts on it when the response also
	// offered the "logs:collect" feature.
	Logs json.RawMessage `json:"logs,omitempty"`
	// Change is the signed change-monitoring configuration. It is kept raw for
	// the same reason, and because its signature covers the exact object: the
	// agent verifies it before it reads a single registry value or file.
	Change json.RawMessage `json:"change,omitempty"`
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
	// Additive (rc.25): absent from older agents; a server that does not know them ignores them.
	FreeBytes  *uint64 `json:"free_bytes,omitempty"`
	TotalBytes *uint64 `json:"total_bytes,omitempty"`
}

// Metrics: a metric that could not be collected is null, NEVER 0.
type Metrics struct {
	CPUPct   *float64     `json:"cpu_pct"`
	MemPct   *float64     `json:"mem_pct"`
	Disk     []DiskMetric `json:"disk"`
	NetRxBps *float64     `json:"net_rx_bps"`
	NetTxBps *float64     `json:"net_tx_bps"`

	// Additive since rc.25 (protocol 1 stays frozen: every field is optional and omitted when it could not be read).
	MemUsedBytes      *uint64   `json:"mem_used_bytes,omitempty"`
	MemAvailableBytes *uint64   `json:"mem_available_bytes,omitempty"`
	MemTotalBytes     *uint64   `json:"mem_total_bytes,omitempty"`
	UptimeS           *uint64   `json:"uptime_s,omitempty"`
	PendingReboot     *int      `json:"pending_reboot,omitempty"` // 0 or 1
	CPUCorePct        []float64 `json:"cpu_core_pct,omitempty"`   // per logical core, at most MaxCores
	DiskReadIOPS      *float64  `json:"disk_read_iops,omitempty"`
	DiskWriteIOPS     *float64  `json:"disk_write_iops,omitempty"`
	DiskQueue         *float64  `json:"disk_queue,omitempty"`
	NetErrors         *uint64   `json:"net_errors,omitempty"` // errors since the previous sample
	BatteryPct        *float64  `json:"battery_pct,omitempty"`
	BatteryCharging   *int      `json:"battery_charging,omitempty"` // 0 or 1
}

// MaxCores caps cpu_core_pct.
const MaxCores = 256

type CheckResult struct {
	Key    string `json:"key"`
	Status string `json:"status"` // ok|warn|fail|unknown
	Detail string `json:"detail"`
	// Value is the number a check measured (latency in ms, percent used, days left, a count), for the server's threshold tiers. Absent
	// for the original check types and whenever nothing could be measured; a server that does not know it ignores it.
	Value *float64 `json:"value,omitempty"`
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
	// Software is the installed-software report (docs/rmm/PROTOCOL.md 3.2.1). It is
	// only ever present after a response offered the "software_inventory" feature.
	Software *SoftwareReport `json:"software,omitempty"`
	// MeshAgent is the optional MeshCentral agent state (docs/rmm/PROTOCOL.md 3.7). Sent
	// on the first check-in after it changes and then daily; absent on a device
	// without a Mesh agent and from every agent older than 1.0.0-rc.12.
	MeshAgent *MeshAgentReport `json:"mesh_agent,omitempty"`
	// ClockOffsetS is the agent's own estimate of server time minus its local clock, in
	// whole seconds (rc.23). Informational for the technician; the server never trusts
	// it for anything else. Absent until the agent has measured one.
	ClockOffsetS *int64 `json:"clock_offset_s,omitempty"`
}

// CapMeshNodeID is announced by an agent that can read and report the local
// MeshCentral node id.
const CapMeshNodeID = "mesh:nodeid"

// MeshAgentReport is the "mesh_agent" block of a check-in.
type MeshAgentReport struct {
	Installed bool   `json:"installed"`
	Version   string `json:"version,omitempty"`
	NodeID    string `json:"node_id,omitempty"`
	Error     string `json:"error,omitempty"`
	State     string `json:"state,omitempty"` // ready | installed_no_node_id | not_found | error (additive)
}

// SoftwareItem is one installed product. Its identity is (Source, Name).
type SoftwareItem struct {
	Name      string `json:"name"`
	Version   string `json:"version"`
	Publisher string `json:"publisher"`
	Source    string `json:"source"` // registry|registry32|appx|dpkg|rpm|snap|flatpak
	Installed string `json:"installed,omitempty"`
}

// SoftwareRef names a removed product.
type SoftwareRef struct {
	Source string `json:"source"`
	Name   string `json:"name"`
}

// SoftwareReport is the "software" block of a check-in. Mode "full" carries the
// whole list; "delta" the added/changed items plus the removed refs, applying to
// the list whose hash is BaseHash. Hash and Count describe the list AFTER the report.
type SoftwareReport struct {
	Mode      string         `json:"mode"`
	Hash      string         `json:"hash"`
	BaseHash  string         `json:"base_hash,omitempty"`
	Count     int            `json:"count"`
	Truncated bool           `json:"truncated"`
	Items     []SoftwareItem `json:"items"`
	Removed   []SoftwareRef  `json:"removed,omitempty"`
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
	// Features the server offers this device (e.g. "software_inventory"); Resync
	// asks for a full re-send of a data set (e.g. "software"). Both optional.
	Features []string `json:"features,omitempty"`
	Resync   []string `json:"resync,omitempty"`
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
