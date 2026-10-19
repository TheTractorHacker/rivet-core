//go:build !windows

package change

import (
	"path/filepath"
	"syscall"
	"testing"
	"time"
)

// A FIFO where a regular file was cannot hang a poll: it is not a regular file, and a swap after the lstat is opened O_NONBLOCK and refused.
func TestAFifoInAWatchedFolderNeverHangsThePoll(t *testing.T) {
	r, root := fileRig(t)
	write(t, filepath.Join(root, "a.conf"), "x\n", 0o644)
	if err := syscall.Mkfifo(filepath.Join(root, "pipe.conf"), 0o644); err != nil {
		t.Skip(err)
	}
	r.configure("f1f1f1f1f1f1f1f1", fileWatch("lnx.fifo", map[string]any{"path": root, "depth": 1}))
	done := make(chan struct{})
	go func() { r.tick(); close(done) }()
	select {
	case <-done:
	case <-time.After(10 * time.Second):
		t.Fatal("the poll hung on a FIFO")
	}
	if n := len(r.m.state.Watches["lnx.fifo"].Snap); n != 1 {
		t.Errorf("only the regular file is read: %d", n)
	}
	f, err := openNoFollow(filepath.Join(root, "pipe.conf"))
	if err != nil {
		t.Fatal(err) // O_NONBLOCK open of a FIFO for reading returns at once
	}
	f.Close()
}
