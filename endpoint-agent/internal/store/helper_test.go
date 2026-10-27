package store

import (
	"os"
	"testing"
	"time"
)

func fileTimeAgo(t *testing.T, p string) time.Time {
	old := time.Now().Add(-5 * time.Minute)
	if err := os.Chtimes(p, old, old); err != nil {
		t.Fatal(err)
	}
	return old
}
