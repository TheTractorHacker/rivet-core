package store

import (
	"errors"
	"fmt"
	"io/fs"
	"os"
	"path/filepath"
	"time"
)

// lockDir takes a simple advisory lock (O_EXCL lock file). A lock older than
// staleAfter is assumed to belong to a crashed process and is broken.
func lockDir(dir string) (func(), error) {
	const (
		staleAfter = 30 * time.Second
		wait       = 10 * time.Second
	)
	p := filepath.Join(dir, "state.lock")
	deadline := time.Now().Add(wait)
	for {
		f, err := os.OpenFile(p, os.O_CREATE|os.O_EXCL|os.O_WRONLY, 0o600)
		if err == nil {
			fmt.Fprintf(f, "%d\n", os.Getpid())
			f.Close()
			return func() { _ = os.Remove(p) }, nil
		}
		if !errors.Is(err, fs.ErrExist) {
			return nil, err
		}
		if fi, serr := os.Stat(p); serr == nil && time.Since(fi.ModTime()) > staleAfter {
			_ = os.Remove(p)
			continue
		}
		if time.Now().After(deadline) {
			return nil, errors.New("timed out waiting for state lock")
		}
		time.Sleep(20 * time.Millisecond)
	}
}
