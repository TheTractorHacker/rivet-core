package jobs

import (
	"sort"
	"sync"
	"time"

	"rivetit-agent/internal/store"
)

// Job states reported to the server.
const (
	StateRunning   = "running"
	StateSucceeded = "succeeded"
	StateFailed    = "failed"
	StateTimedOut  = "timed_out"
	StateCancelled = "cancelled"
)

func isTerminal(s string) bool { return s != StateRunning && s != "" }

// Record is the durable per-job state. A job_id is executed AT MOST ONCE:
// the `running` record is fsynced before the process is launched.
type Record struct {
	JobID      string `json:"job_id"`
	Attempt    int    `json:"attempt"`
	Type       string `json:"type"`
	State      string `json:"state"`
	Reason     string `json:"reason,omitempty"`
	ExitCode   *int   `json:"exit_code,omitempty"`
	Output     string `json:"output,omitempty"` // kept only until the terminal report is acknowledged
	StartedAt  string `json:"started_at,omitempty"`
	FinishedAt string `json:"finished_at,omitempty"`
	Reported   bool   `json:"reported"`
	UpdatedAt  string `json:"updated_at"`
}

const (
	maxRecords      = 500
	recordTTL       = 30 * 24 * time.Hour
	maxStoredOutput = 256 << 10
)

// Store is the durable job table (jobs.json).
type Store struct {
	mu   sync.Mutex
	path string
	recs map[string]*Record
}

// OpenStore loads jobs.json; a corrupt file starts empty (fail-safe is
// enforced by expires_at in the signed job, see README).
func OpenStore(path string) *Store {
	s := &Store{path: path, recs: map[string]*Record{}}
	var list []*Record
	if ok, err := store.ReadJSON(path, &list); ok && err == nil {
		for _, r := range list {
			s.recs[r.JobID] = r
		}
	}
	return s
}

func (s *Store) flush() error {
	if s.path == "" {
		return nil
	}
	list := make([]*Record, 0, len(s.recs))
	for _, r := range s.recs {
		list = append(list, r)
	}
	sort.Slice(list, func(i, j int) bool { return list[i].UpdatedAt < list[j].UpdatedAt })
	// prune acknowledged, old records
	cutoff := time.Now().Add(-recordTTL).UTC().Format(time.RFC3339)
	kept := list[:0]
	for _, r := range list {
		if r.Reported && r.UpdatedAt < cutoff {
			delete(s.recs, r.JobID)
			continue
		}
		kept = append(kept, r)
	}
	for len(kept) > maxRecords {
		if kept[0].Reported {
			delete(s.recs, kept[0].JobID)
			kept = kept[1:]
			continue
		}
		break
	}
	return store.WriteJSON(s.path, kept)
}

// Get returns a copy of the record.
func (s *Store) Get(id string) (Record, bool) {
	s.mu.Lock()
	defer s.mu.Unlock()
	r, ok := s.recs[id]
	if !ok {
		return Record{}, false
	}
	return *r, true
}

// Put stores (and fsyncs) a record.
func (s *Store) Put(r Record) error {
	s.mu.Lock()
	defer s.mu.Unlock()
	if len(r.Output) > maxStoredOutput {
		r.Output = r.Output[:maxStoredOutput]
	}
	r.UpdatedAt = time.Now().UTC().Format(time.RFC3339)
	c := r
	s.recs[r.JobID] = &c
	return s.flush()
}

// Unreported returns terminal records whose report was never acknowledged.
func (s *Store) Unreported() []Record {
	s.mu.Lock()
	defer s.mu.Unlock()
	var out []Record
	for _, r := range s.recs {
		if isTerminal(r.State) && !r.Reported {
			out = append(out, *r)
		}
	}
	sort.Slice(out, func(i, j int) bool { return out[i].UpdatedAt < out[j].UpdatedAt })
	return out
}

// Running returns records left in the running state (crash recovery).
func (s *Store) Running() []Record {
	s.mu.Lock()
	defer s.mu.Unlock()
	var out []Record
	for _, r := range s.recs {
		if r.State == StateRunning {
			out = append(out, *r)
		}
	}
	return out
}

// Len is the record count.
func (s *Store) Len() int { s.mu.Lock(); defer s.mu.Unlock(); return len(s.recs) }
