package logs

import (
	"rivetit-agent/internal/store"
)

// bookmarkFile is logs_state.json: the last acknowledged position per source.
// It holds journal cursors and event record ids only, nothing sensitive.
type bookmarkFile struct {
	V     int               `json:"v"`
	Marks map[string]string `json:"marks"`
}

func loadBookmarks(path string) map[string]string {
	m := map[string]string{}
	if path == "" {
		return m
	}
	var f bookmarkFile
	if ok, err := store.ReadJSON(path, &f); ok && err == nil {
		for k, v := range f.Marks {
			if v != "" {
				m[k] = v
			}
		}
	}
	return m
}

func saveBookmarks(path string, m map[string]string) error {
	if path == "" {
		return nil
	}
	return store.WriteJSON(path, bookmarkFile{V: 1, Marks: m})
}
