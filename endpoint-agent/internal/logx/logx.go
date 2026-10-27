// Package logx provides a size-rotated file writer for the service log.
package logx

import (
	"fmt"
	"io"
	"log/slog"
	"os"
	"sync"
)

// Rotating appends to path and rotates to path.1 when it exceeds Max bytes
// (one generation kept), so the log can never fill the disk.
type Rotating struct {
	mu   sync.Mutex
	path string
	max  int64
	f    *os.File
	size int64
}

// NewRotating opens the log (0600).
func NewRotating(path string, max int64) (*Rotating, error) {
	r := &Rotating{path: path, max: max}
	return r, r.open()
}

func (r *Rotating) open() error {
	f, err := os.OpenFile(r.path, os.O_CREATE|os.O_APPEND|os.O_WRONLY, 0o600)
	if err != nil {
		return err
	}
	fi, err := f.Stat()
	if err != nil {
		f.Close()
		return err
	}
	r.f, r.size = f, fi.Size()
	return nil
}

func (r *Rotating) Write(p []byte) (int, error) {
	r.mu.Lock()
	defer r.mu.Unlock()
	if r.size+int64(len(p)) > r.max {
		r.f.Close()
		_ = os.Remove(r.path + ".1")
		_ = os.Rename(r.path, r.path+".1")
		if err := r.open(); err != nil {
			return 0, err
		}
	}
	n, err := r.f.Write(p)
	r.size += int64(n)
	return n, err
}

// Close closes the file.
func (r *Rotating) Close() error { return r.f.Close() }

// New builds a text logger writing to w at the given level name.
func New(w io.Writer, level string) *slog.Logger {
	var l slog.Level
	if err := l.UnmarshalText([]byte(level)); err != nil {
		l = slog.LevelInfo
		fmt.Fprintf(os.Stderr, "unknown log level %q, using info\n", level)
	}
	return slog.New(slog.NewTextHandler(w, &slog.HandlerOptions{Level: l}))
}
