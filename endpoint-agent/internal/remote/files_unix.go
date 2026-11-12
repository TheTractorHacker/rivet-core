//go:build !windows

package remote

import (
	"errors"
	"fmt"
	"io/fs"
	"os"
	"path/filepath"
	"strconv"
	"syscall"

	"golang.org/x/sys/unix"
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

// putTemp is the temp file of a file_put together with an O_DIRECTORY|O_NOFOLLOW handle on its parent directory (CORE-21). The destination directory
// is writable by the user whose file is being replaced, so nothing between the first byte and the commit may resolve a path again: the file is created
// with openat(O_EXCL|O_NOFOLLOW), chmodded with fchmod on the open descriptor, and finally moved with linkat/renameat relative to the directory
// descriptor, after checking that the name still refers to the very inode that was written and hashed.
type putTemp struct {
	File  *os.File
	dirfd int
	name  string
}

func beginPut(dir, name string) (*putTemp, error) {
	// Canonicalise the ANCESTORS only (a symlinked /var/tmp, a /tmp -> /private/tmp style link or a bind mount is legitimate), then open the final
	// component with O_NOFOLLOW so that a symlinked destination directory itself is still refused.
	if parent := filepath.Dir(dir); parent != dir {
		rp, err := filepath.EvalSymlinks(parent)
		if err != nil {
			return nil, &fs.PathError{Op: "resolve", Path: parent, Err: err}
		}
		dir = filepath.Join(rp, filepath.Base(dir))
	}
	dfd, err := unix.Open(dir, unix.O_RDONLY|unix.O_DIRECTORY|unix.O_NOFOLLOW|unix.O_CLOEXEC, 0)
	if err != nil {
		return nil, &fs.PathError{Op: "open", Path: dir, Err: err}
	}
	// The policy resolved dir moments ago. If a parent component was swapped for a link since, the descriptor leads elsewhere: refuse (best effort,
	// needs /proc).
	if got, err := os.Readlink("/proc/self/fd/" + strconv.Itoa(dfd)); err == nil && got != dir {
		unix.Close(dfd)
		return nil, fmt.Errorf("destination directory changed: %s is now %s", dir, got)
	}
	open := func() (int, error) {
		return unix.Openat(dfd, name, unix.O_WRONLY|unix.O_CREAT|unix.O_EXCL|unix.O_NOFOLLOW|unix.O_CLOEXEC, 0o600)
	}
	fd, err := open()
	if errors.Is(err, unix.EEXIST) {
		// A stale file (or link) of a crashed earlier run of the same transfer: remove the name itself, never what it points to.
		var st unix.Stat_t
		if unix.Fstatat(dfd, name, &st, unix.AT_SYMLINK_NOFOLLOW) == nil && (st.Mode&unix.S_IFMT == unix.S_IFREG || st.Mode&unix.S_IFMT == unix.S_IFLNK) {
			if unix.Unlinkat(dfd, name, 0) == nil {
				fd, err = open()
			}
		}
	}
	if err != nil {
		unix.Close(dfd)
		return nil, &fs.PathError{Op: "open", Path: dir + "/" + name, Err: err}
	}
	return &putTemp{File: os.NewFile(uintptr(fd), name), dirfd: dfd, name: name}, nil
}

// Commit makes the temp file the destination: mode is applied to the open descriptor (when chmod), then the name is verified and moved. Without
// overwrite it links first so an existing file can never be replaced (atomic no-clobber).
func (t *putTemp) Commit(dest string, mode os.FileMode, chmod, overwrite bool) error {
	var fst unix.Stat_t
	if err := unix.Fstat(int(t.File.Fd()), &fst); err != nil {
		return err
	}
	if chmod {
		if err := unix.Fchmod(int(t.File.Fd()), uint32(mode)); err != nil {
			return fmt.Errorf("%w: %v", errChmod, err)
		}
	}
	if err := t.File.Close(); err != nil {
		return err
	}
	var nst unix.Stat_t
	if err := unix.Fstatat(t.dirfd, t.name, &nst, unix.AT_SYMLINK_NOFOLLOW); err != nil {
		return err
	}
	if nst.Mode&unix.S_IFMT != unix.S_IFREG || nst.Dev != fst.Dev || nst.Ino != fst.Ino {
		return errors.New("temp file was replaced while it was being written")
	}
	if overwrite {
		var dst unix.Stat_t
		if err := unix.Fstatat(t.dirfd, dest, &dst, unix.AT_SYMLINK_NOFOLLOW); err == nil && dst.Mode&unix.S_IFMT != unix.S_IFREG {
			return errors.New("destination is not a regular file")
		}
		return unix.Renameat(t.dirfd, t.name, t.dirfd, dest)
	}
	if err := unix.Linkat(t.dirfd, t.name, t.dirfd, dest, 0); err != nil {
		if errors.Is(err, unix.EEXIST) {
			return errExists
		}
		// Filesystems without hard links: fall back to check + rename.
		var dst unix.Stat_t
		if unix.Fstatat(t.dirfd, dest, &dst, unix.AT_SYMLINK_NOFOLLOW) == nil {
			return errExists
		}
		return unix.Renameat(t.dirfd, t.name, t.dirfd, dest)
	}
	_ = unix.Unlinkat(t.dirfd, t.name, 0)
	return nil
}

// Discard removes the temp name (relative to the directory descriptor) and releases the handles.
func (t *putTemp) Discard() {
	t.File.Close()
	_ = unix.Unlinkat(t.dirfd, t.name, 0)
	unix.Close(t.dirfd)
}

// Close releases the handles of a committed put.
func (t *putTemp) Close() {
	t.File.Close()
	unix.Close(t.dirfd)
}
