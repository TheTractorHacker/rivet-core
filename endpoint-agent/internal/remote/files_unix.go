//go:build !windows

package remote

import (
	"errors"
	"fmt"
	"io/fs"
	"os"
	"path/filepath"
	"strings"

	"golang.org/x/sys/unix"
)

// openDirNoFollow opens the absolute, clean directory path one component at a time with openat(O_DIRECTORY|O_NOFOLLOW), starting at "/". No
// component may be a symbolic link: the path policy hands over an already symlink-resolved path, so a link met here was put there after the check
// (CORE-DS-6, CORE-DS-24) and is refused rather than followed. Bind mounts are not links and work.
func openDirNoFollow(dir string) (int, error) {
	if !filepath.IsAbs(dir) || filepath.Clean(dir) != dir {
		return -1, &fs.PathError{Op: "open", Path: dir, Err: unix.EINVAL}
	}
	const flags = unix.O_RDONLY | unix.O_DIRECTORY | unix.O_NOFOLLOW | unix.O_CLOEXEC
	cur, err := unix.Open("/", flags, 0)
	if err != nil {
		return -1, &fs.PathError{Op: "open", Path: "/", Err: err}
	}
	for _, comp := range strings.Split(strings.TrimPrefix(dir, "/"), "/") {
		if comp == "" {
			continue
		}
		next, err := unix.Openat(cur, comp, flags, 0)
		unix.Close(cur)
		if err != nil {
			return -1, &fs.PathError{Op: "open", Path: dir, Err: err}
		}
		cur = next
	}
	return cur, nil
}

// openNoFollow opens read-only without following a symlink in ANY element of the path (the directory is walked with openDirNoFollow, the last
// element is opened relative to it with O_NOFOLLOW) and without blocking on a FIFO (the handle is then checked to be a regular file).
func openNoFollow(path string) (*os.File, error) {
	dfd, err := openDirNoFollow(filepath.Dir(path))
	if err != nil {
		return nil, err
	}
	defer unix.Close(dfd)
	fd, err := unix.Openat(dfd, filepath.Base(path), unix.O_RDONLY|unix.O_NOFOLLOW|unix.O_NONBLOCK|unix.O_CLOEXEC, 0)
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
	// dir is the symlink-resolved directory the path policy approved. It is NOT resolved again: every component is opened with O_NOFOLLOW, so an
	// ancestor swapped for a link since the check makes this fail (CORE-DS-6) instead of being followed.
	dfd, err := openDirNoFollow(dir)
	if err != nil {
		return nil, err
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
	// CORE-DS-25: the agent runs as root, so the temp file is root-owned. Hand it to whoever owns the file being replaced, else the directory it goes
	// into, so the user the file was delivered to can read it. Done before the mode (chown clears setuid/setgid) and best effort: an agent that is
	// not root, or a filesystem that refuses, simply leaves the owner as it is.
	if uid, gid, ok := t.deliveryOwner(dest, overwrite); ok {
		_ = unix.Fchown(int(t.File.Fd()), int(uid), int(gid))
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

// deliveryOwner is the owner the delivered file should get (see Commit).
func (t *putTemp) deliveryOwner(dest string, overwrite bool) (uid, gid uint32, ok bool) {
	var dst, dir unix.Stat_t
	var dstp *unix.Stat_t
	if overwrite && unix.Fstatat(t.dirfd, dest, &dst, unix.AT_SYMLINK_NOFOLLOW) == nil {
		dstp = &dst
	}
	if unix.Fstat(t.dirfd, &dir) != nil {
		return 0, 0, false
	}
	uid, gid = ownerFor(&dir, dstp)
	return uid, gid, true
}

// ownerFor picks the owner of a delivered file: the replaced regular file's, else the directory's.
func ownerFor(dir, replaced *unix.Stat_t) (uid, gid uint32) {
	if replaced != nil && replaced.Mode&unix.S_IFMT == unix.S_IFREG {
		return replaced.Uid, replaced.Gid
	}
	return dir.Uid, dir.Gid
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
