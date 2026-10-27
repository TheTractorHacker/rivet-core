package change

import (
	"crypto/rand"
	"encoding/hex"

	"rivetit-agent/internal/store"
)

type watchState struct {
	Spec string   `json:"spec"`
	At   string   `json:"at"`
	Snap Snapshot `json:"items"`
}

// stateFile is change_state.json (0600): the last snapshot of every watch, the
// records not yet acknowledged, and the key that keys the hashes of secret values.
// Snapshots hold only what the agent would also send: values and file lines are
// redacted and cut before they are stored.
type stateFile struct {
	V       int                    `json:"v"`
	Key     string                 `json:"key"`
	Watches map[string]*watchState `json:"watches"`
	Pending []Record               `json:"pending"`
	Dropped int                    `json:"dropped"`
	// WithdrawnAt is when the server stopped offering monitoring (RFC 3339); what is kept is deleted a day later.
	Seq         int64  `json:"seq,omitempty"` // the highest configuration seq accepted
	WithdrawnAt string `json:"withdrawn_at,omitempty"`
}

func loadState(path string) *stateFile {
	s := &stateFile{}
	if path != "" {
		if ok, err := store.ReadJSON(path, s); !ok || err != nil {
			s = &stateFile{}
		}
	}
	s.V = 1
	if s.Watches == nil {
		s.Watches = map[string]*watchState{}
	}
	if len(s.Key) != 64 {
		var b [32]byte
		if _, err := rand.Read(b[:]); err == nil {
			s.Key = hex.EncodeToString(b[:])
		}
	}
	return s
}

func (s *stateFile) save(path string) error {
	if path == "" {
		return nil
	}
	return store.WriteJSON(path, s)
}
