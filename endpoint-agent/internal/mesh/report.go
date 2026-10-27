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
	// State is additive: "ready" (installed, node id read), "installed_no_node_id" (installed, id not read yet),
	// "not_found" (an install was attempted or is expected but no agent is on the machine), "error" (install failed).
	State string `json:"state,omitempty"`
}

// Report states.
const (
	StateReady    = "ready"
	StateNoNodeID = "installed_no_node_id"
	StateNotFound = "not_found"
	StateError    = "error"
	fastEvery     = 60 * time.Second // look for the id this often ...
	fastWindow    = 10 * time.Minute // ... for the first 10 minutes after the agent starts,
	slowEvery     = 5 * time.Minute  // then this often ...
	slowWindow    = 2 * time.Hour    // ... for the next 2 hours, then the daily cadence.
)

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

	mu      sync.Mutex
	at      time.Time
	started time.Time
	lastExe string
	cached  *Report
}

func (r *Reporter) now() time.Time {
	if r.Now != nil {
		return r.Now()
	}
	return time.Now()
}

// Retry returns how soon the node id should be looked for again while it has not been read yet (0 once it was found, or
// when this device shows no sign of a Mesh agent). The agent uses it to keep its check-in loop short enough for the
// 60 s / 5 min schedule.
func (r *Reporter) Retry() time.Duration {
	r.mu.Lock()
	defer r.mu.Unlock()
	if r.cached == nil || !r.cached.Installed || r.cached.NodeID != "" || r.started.IsZero() {
		return 0
	}
	return r.pendingEvery(r.now())
}

// pendingEvery is the lookup interval for a missing id at now (0 after the fast and slow windows).
func (r *Reporter) pendingEvery(now time.Time) time.Duration {
	el := now.Sub(r.started)
	switch {
	case el < fastWindow:
		return fastEvery
	case el < fastWindow+slowWindow:
		return slowEvery
	}
	return 0
}

// Invalidate makes the next Current look at the machine again (after a pushed install or uninstall changed it).
func (r *Reporter) Invalidate() {
	r.mu.Lock()
	r.at = time.Time{}
	r.started = time.Time{} // a fresh install restarts the 60 s / 5 min schedule
	r.mu.Unlock()
}

// Current returns the report, or nil when there is nothing to say. The agent's presence is a cheap stat on every call; the
// program is run only when the agent appeared or changed, when the id is still missing and the retry schedule says so (every 60 s
// for 10 minutes after start, every 5 minutes for the next 2 hours), or once a day.
func (r *Reporter) Current(ctx context.Context) *Report {
	r.mu.Lock()
	defer r.mu.Unlock()
	now := r.now()
	if r.started.IsZero() {
		r.started = now
	}
	every := r.RefreshEvery
	if every <= 0 {
		every = 24 * time.Hour
	}
	exe := r.Probe.Found()
	if !r.at.IsZero() && exe == r.lastExe {
		limit := every
		if r.cached == nil || r.cached.NodeID == "" {
			if p := r.pendingEvery(now); p > 0 && p < limit {
				limit = p
			}
		}
		if age := now.Sub(r.at); age >= 0 && age < limit {
			return r.cached
		}
	}
	r.at = now
	r.lastExe = exe
	r.cached = r.build(ctx, exe)
	return r.cached
}

func (r *Reporter) build(ctx context.Context, exe string) *Report {
	res := LoadResult(r.StateDir)
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
	switch {
	case rep.Installed && rep.NodeID != "":
		rep.State = StateReady
	case rep.Installed:
		rep.State = StateNoNodeID
	case rep.Error != "":
		rep.State = StateError
	default:
		rep.State = StateNotFound
	}
	return rep
}
