// Package store persists the agent's configuration and state with atomic
// writes and a cross-process lock (the CLI and the service both write).
package store

import (
	"crypto/rand"
	"encoding/json"
	"errors"
	"fmt"
	"io/fs"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"time"
)

// Config is operator-supplied configuration (config.json).
type Config struct {
	ServerURL         string   `json:"server_url"`
	CAFile            string   `json:"ca_file,omitempty"`         // extra CA PEM (internal CAs), added to the system pool
	PinSPKISHA256     string   `json:"pin_spki_sha256,omitempty"` // optional hex SHA-256 of the server leaf SubjectPublicKeyInfo
	Department        string   `json:"department,omitempty"`      // informational; the enrollment token is the authority
	MeshNodeID        string   `json:"mesh_node_id,omitempty"`    // explicit override for the MeshCentral node id
	MeshMSHPath       string   `json:"mesh_msh_path,omitempty"`   // override path of meshagent.msh
	MaxConcurrentJobs int      `json:"max_concurrent_jobs,omitempty"`
	DisableJobs       bool     `json:"disable_jobs,omitempty"`          // local kill switch: never execute server jobs
	DisableScriptChk  bool     `json:"disable_script_checks,omitempty"` // local kill switch for script checks
	UpdateHosts       []string `json:"update_hosts,omitempty"`          // extra hosts allowed to serve update binaries (default: server host)
	BufferMaxSamples  int      `json:"buffer_max_samples,omitempty"`
	BufferMaxBytes    int      `json:"buffer_max_bytes,omitempty"`
	// SoftwareStoreApps adds the Microsoft Store (appx) packages to the Windows
	// software inventory. Off by default; ignored on Linux.
	SoftwareStoreApps bool `json:"software_store_apps,omitempty"`
}

// Defaults applied on load.
const (
	DefaultMaxConcurrentJobs = 1
	DefaultBufferSamples     = 100
	DefaultBufferBytes       = 1 << 20
)

// WithDefaults fills zero values.
func (c Config) WithDefaults() Config {
	if c.MaxConcurrentJobs <= 0 {
		c.MaxConcurrentJobs = DefaultMaxConcurrentJobs
	}
	if c.BufferMaxSamples <= 0 {
		c.BufferMaxSamples = DefaultBufferSamples
	}
	if c.BufferMaxBytes <= 0 {
		c.BufferMaxBytes = DefaultBufferBytes
	}
	return c
}

// Enrollment status strings (server vocabulary plus local ones).
const (
	StatusUnenrolled = "unenrolled"
	StatusLinked     = "linked"
	StatusPending    = "pending_approval"
	StatusAmbiguous  = "ambiguous"
	StatusDormant    = "dormant_revoked"
)

// CheckSpec mirrors one server-configured check.
type CheckSpec struct {
	Key       string          `json:"key"`
	Type      string          `json:"type"`
	Params    json.RawMessage `json:"params,omitempty"`
	IntervalS int             `json:"interval_s,omitempty"`
	Signature string          `json:"signature,omitempty"`
	Raw       json.RawMessage `json:"raw,omitempty"` // as delivered; used to verify Signature at run time
}

// UpdateFailure records the last failed self-update so it can be reported.
type UpdateFailure struct {
	Version string    `json:"version"`
	Reason  string    `json:"reason"`
	At      time.Time `json:"at"`
}

// State is machine-written state (state.json).
type State struct {
	InstallID        string         `json:"install_id"`
	DeviceID         string         `json:"device_id,omitempty"`
	Status           string         `json:"status"`
	MatchedAssetID   *int64         `json:"matched_asset_id,omitempty"`
	Seq              uint64         `json:"seq"`
	SigningPublicKey string         `json:"signing_public_key,omitempty"`
	SigningKeyID     string         `json:"signing_key_id,omitempty"`
	UpdateResult     *UpdateResultS `json:"update_result,omitempty"` // reported on the next check-in, then cleared
	CheckInIntervalS int            `json:"check_in_interval_s,omitempty"`
	CollectIntervalS int            `json:"collect_interval_s,omitempty"`
	Checks           []CheckSpec    `json:"checks,omitempty"`
	LastCheckIn      time.Time      `json:"last_check_in,omitempty"`
	LastError        string         `json:"last_error,omitempty"`
	InventoryHash    string         `json:"inventory_hash,omitempty"`
	InventorySentAt  time.Time      `json:"inventory_sent_at,omitempty"`
	ClockSkewS       float64        `json:"clock_skew_s,omitempty"`
	Dormant          bool           `json:"dormant,omitempty"`
	DormantReason    string         `json:"dormant_reason,omitempty"`
	UpdateFailure    *UpdateFailure `json:"update_failure,omitempty"`
	EnrolledAt       time.Time      `json:"enrolled_at,omitempty"`
	// ServerFeatures is the "features" list of the LAST successful check-in
	// response (absence = none), kept across restarts.
	ServerFeatures []string `json:"server_features,omitempty"`
	// Software snapshot bookkeeping: the hash of the list the server acknowledged
	// (the list itself is software.json), when the last full report was acked and
	// when the last software report of any kind was sent.
	SoftwareHash   string    `json:"software_hash,omitempty"`
	SoftwareFullAt time.Time `json:"software_full_at,omitempty"`
	SoftwareSentAt time.Time `json:"software_sent_at,omitempty"`
}

// UpdateResultS is a pending update_result report.
type UpdateResultS struct {
	Version string `json:"version"`
	State   string `json:"state"`
	Detail  string `json:"detail,omitempty"`
}

// Inflight is the exact check-in request that has been allocated a seq but
// not yet acknowledged; a retry re-sends it byte for byte (same seq).
type Inflight struct {
	Seq           uint64 `json:"seq"`
	Body          []byte `json:"body"`       // base64 in the file so the bytes replay exactly
	ThroughID     uint64 `json:"through_id"` // ring entries <= this are acked with it
	InventoryHash string `json:"inventory_hash,omitempty"`
	WithInventory bool   `json:"with_inventory,omitempty"`
	WithUpdate    bool   `json:"with_update_result,omitempty"`
	// WithSoftware marks a body that carries a software block; SoftwareHash is
	// that block's hash and SoftwareFull whether it is a full report.
	WithSoftware bool   `json:"with_software,omitempty"`
	SoftwareHash string `json:"software_hash,omitempty"`
	SoftwareFull bool   `json:"software_full,omitempty"`
}

// Store is a directory of agent files.
type Store struct {
	Dir string
	mu  sync.Mutex
}

// Open creates (if needed) and secures the state directory.
func Open(dir string) (*Store, error) {
	if dir == "" {
		return nil, errors.New("empty state dir")
	}
	if err := ensureDir(dir); err != nil {
		return nil, err
	}
	return &Store{Dir: dir}, nil
}

func (s *Store) path(n string) string { return filepath.Join(s.Dir, n) }

// File locations.
func (s *Store) ConfigPath() string      { return s.path("config.json") }
func (s *Store) StatePath() string       { return s.path("state.json") }
func (s *Store) TokenPath() string       { return s.path("device.token") }
func (s *Store) EnrollTokenPath() string { return s.path("enroll.token") }
func (s *Store) JobsPath() string        { return s.path("jobs.json") }
func (s *Store) BufferPath() string      { return s.path("buffer.json") }
func (s *Store) InflightPath() string    { return s.path("inflight.json") }
func (s *Store) UpdatePath() string      { return s.path("update.json") }
func (s *Store) SoftwarePath() string    { return s.path("software.json") }
func (s *Store) LogPath() string         { return s.path("agent.log") }

func readJSON(path string, v any) (bool, error) {
	b, err := os.ReadFile(path)
	if errors.Is(err, fs.ErrNotExist) {
		return false, nil
	}
	if err != nil {
		return false, err
	}
	if err := json.Unmarshal(b, v); err != nil {
		return true, fmt.Errorf("%s: %w", filepath.Base(path), err)
	}
	return true, nil
}

// WriteJSON writes v atomically with 0600 permissions.
func WriteJSON(path string, v any) error {
	b, err := json.MarshalIndent(v, "", "  ")
	if err != nil {
		return err
	}
	return WriteFileAtomic(path, b, 0o600)
}

// ReadJSON is the exported counterpart (false when the file is missing).
func ReadJSON(path string, v any) (bool, error) { return readJSON(path, v) }

// WriteFileAtomic writes data to a temp file in the same directory, syncs and
// renames it over path, so a crash leaves either the old or the new file.
func WriteFileAtomic(path string, data []byte, perm os.FileMode) error {
	dir := filepath.Dir(path)
	f, err := os.CreateTemp(dir, ".tmp-*")
	if err != nil {
		return err
	}
	tmp := f.Name()
	cleanup := func() { _ = os.Remove(tmp) }
	if _, err := f.Write(data); err != nil {
		f.Close()
		cleanup()
		return err
	}
	if err := f.Sync(); err != nil {
		f.Close()
		cleanup()
		return err
	}
	if err := f.Close(); err != nil {
		cleanup()
		return err
	}
	if err := os.Chmod(tmp, perm); err != nil {
		cleanup()
		return err
	}
	if err := os.Rename(tmp, path); err != nil {
		cleanup()
		return err
	}
	syncDir(dir)
	return nil
}

// LoadConfig reads config.json (a missing file yields defaults).
func (s *Store) LoadConfig() (Config, error) {
	var c Config
	if _, err := readJSON(s.ConfigPath(), &c); err != nil {
		return Config{}, err
	}
	return c.WithDefaults(), nil
}

// SaveConfig persists config.json.
func (s *Store) SaveConfig(c Config) error { return WriteJSON(s.ConfigPath(), c) }

// LoadState reads state.json without locking (readers see a complete file
// because writes are atomic).
func (s *Store) LoadState() (State, error) {
	var st State
	if _, err := readJSON(s.StatePath(), &st); err != nil {
		return State{}, err
	}
	if st.Status == "" {
		st.Status = StatusUnenrolled
	}
	return st, nil
}

// Update performs a locked read-modify-write of state.json. The install_id
// is generated here exactly once and never changes afterwards.
func (s *Store) Update(fn func(*State) error) error {
	s.mu.Lock()
	defer s.mu.Unlock()
	unlock, err := lockDir(s.Dir)
	if err != nil {
		return err
	}
	defer unlock()
	st, err := s.LoadState()
	if err != nil {
		return err
	}
	before := st.InstallID
	if st.InstallID == "" {
		st.InstallID = NewUUID()
	}
	if err := fn(&st); err != nil {
		return err
	}
	if before != "" {
		st.InstallID = before // identity is immutable
	}
	return WriteJSON(s.StatePath(), st)
}

// InstallID returns the persistent install id, creating it on first use.
func (s *Store) InstallID() (string, error) {
	var id string
	err := s.Update(func(st *State) error { id = st.InstallID; return nil })
	return id, err
}

// NewUUID returns a random RFC 4122 version 4 UUID.
func NewUUID() string {
	var b [16]byte
	if _, err := rand.Read(b[:]); err != nil {
		panic(err)
	}
	b[6] = (b[6] & 0x0f) | 0x40
	b[8] = (b[8] & 0x3f) | 0x80
	return fmt.Sprintf("%x-%x-%x-%x-%x", b[0:4], b[4:6], b[6:8], b[8:10], b[10:])
}

// --- credential files ---

const (
	schemePlain = "plain"
	schemeDPAPI = "dpapi"
)

func (s *Store) writeSecret(path, secret string) error {
	scheme, blob, err := protect([]byte(secret))
	if err != nil {
		return err
	}
	return WriteFileAtomic(path, []byte("v1:"+scheme+":"+blob+"\n"), 0o600)
}

func (s *Store) readSecret(path string) (string, error) {
	b, err := os.ReadFile(path)
	if errors.Is(err, fs.ErrNotExist) {
		return "", nil
	}
	if err != nil {
		return "", err
	}
	tightenPerms(path)
	parts := strings.SplitN(strings.TrimSpace(string(b)), ":", 3)
	if len(parts) != 3 || parts[0] != "v1" {
		return "", errors.New("unrecognised credential file format")
	}
	pt, err := unprotect(parts[1], parts[2])
	if err != nil {
		return "", err
	}
	return string(pt), nil
}

// SaveToken stores the device token protected at rest (DPAPI on Windows,
// a 0600 file on Linux).
func (s *Store) SaveToken(tok string) error { return s.writeSecret(s.TokenPath(), tok) }

// LoadToken returns the device token or "" when absent.
func (s *Store) LoadToken() (string, error) { return s.readSecret(s.TokenPath()) }

// WipeToken removes the device token and best-effort overwrites it first.
func (s *Store) WipeToken() error {
	p := s.TokenPath()
	if fi, err := os.Stat(p); err == nil {
		_ = os.WriteFile(p, make([]byte, fi.Size()), 0o600)
	}
	if err := os.Remove(p); err != nil && !errors.Is(err, fs.ErrNotExist) {
		return err
	}
	return nil
}

// SaveEnrollToken keeps an enrollment token until the first successful
// enrollment (used when install runs before the network is reachable).
func (s *Store) SaveEnrollToken(tok string) error { return s.writeSecret(s.EnrollTokenPath(), tok) }

// LoadEnrollToken returns the pending enrollment token or "".
func (s *Store) LoadEnrollToken() (string, error) { return s.readSecret(s.EnrollTokenPath()) }

// DropEnrollToken deletes the pending enrollment token.
func (s *Store) DropEnrollToken() {
	_ = os.Remove(s.EnrollTokenPath())
}

// CheckPerms reports credential files that are readable by other users
// (Unix only; on Windows the directory ACL is the control).
func (s *Store) CheckPerms() []string {
	return looseFiles(s.Dir, []string{"device.token", "enroll.token", "state.json"})
}

// --- inflight check-in ---

// LoadInflight returns the unacknowledged check-in, or nil.
func (s *Store) LoadInflight() (*Inflight, error) {
	var in Inflight
	ok, err := readJSON(s.InflightPath(), &in)
	if err != nil {
		// A corrupt inflight file must not wedge the agent.
		_ = os.Remove(s.InflightPath())
		return nil, nil
	}
	if !ok {
		return nil, nil
	}
	return &in, nil
}

// SaveInflight persists the request about to be sent.
func (s *Store) SaveInflight(in Inflight) error { return WriteJSON(s.InflightPath(), in) }

// ClearInflight removes the acknowledged request.
func (s *Store) ClearInflight() { _ = os.Remove(s.InflightPath()) }
