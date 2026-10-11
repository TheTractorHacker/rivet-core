package mesh

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"path/filepath"
	"sync"
	"time"

	"rivetit-agent/internal/store"
)

// ResultFile is the install outcome saved next to the agent's other state.
const ResultFile = "mesh_agent.json"

// SaveResult writes the install outcome atomically (0600).
func SaveResult(stateDir string, r Result) error {
	return store.WriteJSON(filepath.Join(stateDir, ResultFile), r)
}

// LoadResult reads the saved outcome; nil when there is none.
func LoadResult(stateDir string) *Result {
	var r Result
	ok, err := store.ReadJSON(filepath.Join(stateDir, ResultFile), &r)
	if err != nil || !ok {
		return nil
	}
	return &r
}

// Report is the `mesh_agent` block of a check-in (additive; see
// docs/rmm/PROTOCOL.md). Absent when this device has nothing to say about Mesh.
type Report struct {
	Installed bool   `json:"installed"`
	Version   string `json:"version,omitempty"`
	NodeID    string `json:"node_id,omitempty"`
	Error     string `json:"error,omitempty"`
}

// Hash identifies the content of a report (sent again when it changes, or daily).
func (r Report) Hash() string {
	b, _ := json.Marshal(r)
	s := sha256.Sum256(b)
	return hex.EncodeToString(s[:8])
}

// Reporter combines the saved install outcome with what is on the machine now.
// The node id is looked up at most once per RefreshEvery (running a program),
// and not at all on a machine without a Mesh agent.
type Reporter struct {
	StateDir     string
	Probe        Probe
	Now          func() time.Time
	RefreshEvery time.Duration // default 24 h; a missing id is retried hourly
	// Override returns an id an operator supplied by hand (config mesh_node_id or mesh_node_id.txt); it is used when the agent cannot be asked. May be nil.
	Override func() string

	mu     sync.Mutex
	at     time.Time
	cached *Report
}

// Current returns the report, or nil when there is nothing to say.
func (r *Reporter) Current(ctx context.Context) *Report {
	r.mu.Lock()
	defer r.mu.Unlock()
	now := time.Now()
	if r.Now != nil {
		now = r.Now()
	}
	every := r.RefreshEvery
	if every <= 0 {
		every = 24 * time.Hour
	}
	if !r.at.IsZero() {
		limit := every
		if (r.cached == nil || !r.cached.Installed || r.cached.NodeID == "") && time.Hour < limit {
			limit = time.Hour // nothing yet, or no id yet: look again within the hour
		}
		if age := now.Sub(r.at); age >= 0 && age < limit {
			return r.cached
		}
	}
	r.at = now
	r.cached = r.build(ctx)
	return r.cached
}

func (r *Reporter) build(ctx context.Context) *Report {
	res := LoadResult(r.StateDir)
	exe := r.Probe.Found()
	if res == nil && exe == "" {
		return nil
	}
	rep := &Report{Installed: exe != "" || (res != nil && res.Installed)}
	if exe != "" {
		if id, _ := r.Probe.NodeID(ctx); id != "" {
			rep.NodeID = id
		} else if r.Override != nil {
			if id, ok := NormalizeNodeID(r.Override()); ok {
				rep.NodeID = id
			}
		}
	}
	if !rep.Installed && res != nil {
		rep.Error = res.Error
	}
	return rep
}
