package update

import (
	"errors"
	"fmt"
	"io/fs"
	"os"
	"path/filepath"
	"time"
)

// Paths are the files involved in an update, all beside the running binary.
// None is ever derived from server-supplied text.
type Paths struct {
	Current string // the running/installed binary
	Staged  string // downloaded, verified, not yet active
	Prev    string // last known good
	Failed  string // the binary that failed health check (kept briefly for diagnosis)
}

// PathsFor derives the layout from the installed binary path.
func PathsFor(exe string) Paths {
	return Paths{Current: exe, Staged: exe + ".new", Prev: exe + ".prev", Failed: exe + ".failed"}
}

func exists(p string) bool { _, err := os.Stat(p); return err == nil }

// rename retries briefly: on Windows antivirus/indexers can hold a handle for
// a moment right after a file is written.
func rename(from, to string) error {
	var err error
	for i := 0; i < 5; i++ {
		if err = os.Rename(from, to); err == nil {
			return nil
		}
		time.Sleep(time.Duration(i+1) * 100 * time.Millisecond)
	}
	return err
}

// Swap activates the staged binary: current -> prev (becoming the last good),
// staged -> current. If the second rename fails the first is undone. Windows
// permits renaming a running executable (but not deleting/overwriting it),
// which is why this is a rename dance rather than an overwrite.
func Swap(p Paths) error {
	if !exists(p.Staged) {
		return errors.New("no staged binary")
	}
	if !exists(p.Current) {
		return errors.New("current binary missing")
	}
	if err := os.Remove(p.Prev); err != nil && !errors.Is(err, fs.ErrNotExist) {
		return fmt.Errorf("remove old last-good: %w", err)
	}
	if err := rename(p.Current, p.Prev); err != nil {
		return fmt.Errorf("retire current: %w", err)
	}
	if err := rename(p.Staged, p.Current); err != nil {
		if uerr := rename(p.Prev, p.Current); uerr != nil {
			return fmt.Errorf("activate staged failed (%v) AND restoring previous failed: %w", err, uerr)
		}
		return fmt.Errorf("activate staged: %w", err)
	}
	return nil
}

// Rollback restores the last good binary: current -> failed, prev -> current.
func Rollback(p Paths) error {
	if !exists(p.Prev) {
		return errors.New("no last-good binary to roll back to")
	}
	_ = os.Remove(p.Failed)
	if err := rename(p.Current, p.Failed); err != nil {
		return fmt.Errorf("retire failed binary: %w", err)
	}
	if err := rename(p.Prev, p.Current); err != nil {
		_ = rename(p.Failed, p.Current)
		return fmt.Errorf("restore last good: %w", err)
	}
	return nil
}

// Cleanup removes leftovers that are safe to delete (never Current/Prev).
func Cleanup(p Paths) {
	_ = os.Remove(p.Staged)
	_ = os.Remove(p.Failed) // may fail on Windows while the old image is mapped; retried next start
}

func ensureParent(p string) error { return os.MkdirAll(filepath.Dir(p), 0o755) }
