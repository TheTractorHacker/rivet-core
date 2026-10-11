//go:build !windows

package remote

import (
	"errors"
	"io/fs"
	"os"
	"syscall"
)

// openNoFollow opens read-only without following a symlink in the last element
// and without blocking on a FIFO (the handle is then checked to be a regular file).
func openNoFollow(path string) (*os.File, error) {
	fd, err := syscall.Open(path, syscall.O_RDONLY|syscall.O_NOFOLLOW|syscall.O_NONBLOCK|syscall.O_CLOEXEC, 0)
	if err != nil {
		return nil, &fs.PathError{Op: "open", Path: path, Err: err}
	}
	return os.NewFile(uintptr(fd), path), nil
}

// commitFile moves tmp onto dest. Without overwrite it links first so an
// existing file can never be replaced (atomic no-clobber).
func commitFile(tmp, dest string, overwrite bool) error {
	if overwrite {
		if li, err := os.Lstat(dest); err == nil && !li.Mode().IsRegular() {
			return errors.New("destination is not a regular file")
		}
		return os.Rename(tmp, dest)
	}
	if err := os.Link(tmp, dest); err != nil {
		if errors.Is(err, fs.ErrExist) {
			return errExists
		}
		// Filesystems without hard links: fall back to check + rename.
		if _, lerr := os.Lstat(dest); lerr == nil {
			return errExists
		}
		return os.Rename(tmp, dest)
	}
	os.Remove(tmp)
	return nil
}
