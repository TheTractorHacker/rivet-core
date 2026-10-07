// Package buffer is a bounded on-disk ring of unacknowledged samples.
// Bounds: at most MaxEntries samples and MaxBytes of encoded JSON; when
// either is exceeded the OLDEST samples are dropped.
package buffer

import (
	"encoding/json"
	"os"
	"sync"

	"rivetit-agent/internal/api"
	"rivetit-agent/internal/store"
)

// Entry is one sample. ID is monotonic and never reused.
type Entry struct {
	ID          uint64            `json:"id"`
	CollectedAt string            `json:"collected_at"`
	Metrics     *api.Metrics      `json:"metrics"`
	Checks      []api.CheckResult `json:"checks"`
	size        int
}

type file struct {
	NextID  uint64  `json:"next_id"`
	Entries []Entry `json:"entries"`
}

type Ring struct {
	mu         sync.Mutex
	path       string
	MaxEntries int
	MaxBytes   int
	nextID     uint64
	entries    []Entry
	Dropped    uint64 // count of samples evicted by the bounds (this process)
}

// Open loads the ring (a corrupt file is discarded, never fatal).
func Open(path string, maxEntries, maxBytes int) *Ring {
	r := &Ring{path: path, MaxEntries: maxEntries, MaxBytes: maxBytes, nextID: 1}
	var f file
	if ok, err := store.ReadJSON(path, &f); ok && err == nil {
		r.nextID = f.NextID
		if r.nextID == 0 {
			r.nextID = 1
		}
		r.entries = f.Entries
		for i := range r.entries {
			r.entries[i].size = encodedSize(r.entries[i])
			if r.entries[i].ID >= r.nextID {
				r.nextID = r.entries[i].ID + 1
			}
		}
		r.trim()
	}
	return r
}

func encodedSize(e Entry) int {
	b, _ := json.Marshal(e)
	return len(b)
}

func (r *Ring) trim() {
	total := 0
	for _, e := range r.entries {
		total += e.size
	}
	for len(r.entries) > 0 && (len(r.entries) > r.MaxEntries || total > r.MaxBytes) {
		// Always keep the newest sample even if it alone exceeds MaxBytes.
		if len(r.entries) == 1 {
			break
		}
		total -= r.entries[0].size
		r.entries = r.entries[1:]
		r.Dropped++
	}
}

func (r *Ring) save() error {
	if r.path == "" {
		return nil
	}
	return store.WriteJSON(r.path, file{NextID: r.nextID, Entries: r.entries})
}

// Append adds a sample and returns its id.
func (r *Ring) Append(collectedAt string, m *api.Metrics, checks []api.CheckResult) (uint64, error) {
	r.mu.Lock()
	defer r.mu.Unlock()
	e := Entry{ID: r.nextID, CollectedAt: collectedAt, Metrics: m, Checks: checks}
	e.size = encodedSize(e)
	r.nextID++
	r.entries = append(r.entries, e)
	r.trim()
	return e.ID, r.save()
}

// Snapshot returns a copy of the entries, oldest first.
func (r *Ring) Snapshot() []Entry {
	r.mu.Lock()
	defer r.mu.Unlock()
	return append([]Entry(nil), r.entries...)
}

// Len is the number of retained samples.
func (r *Ring) Len() int {
	r.mu.Lock()
	defer r.mu.Unlock()
	return len(r.entries)
}

// RemoveThrough deletes every entry with ID <= id (acknowledged).
func (r *Ring) RemoveThrough(id uint64) error {
	r.mu.Lock()
	defer r.mu.Unlock()
	i := 0
	for i < len(r.entries) && r.entries[i].ID <= id {
		i++
	}
	r.entries = append([]Entry(nil), r.entries[i:]...)
	if len(r.entries) == 0 && r.path != "" {
		_ = os.Remove(r.path)
		return nil
	}
	return r.save()
}
