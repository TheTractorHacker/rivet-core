//go:build windows

package remote

import (
	"errors"
	"io/fs"
	"os"
	"path/filepath"
	"strings"
	"syscall"
	"unsafe"

	"golang.org/x/sys/windows"

	"rivetit-agent/internal/winacl"
)

// hostResolve returns the fully resolved form of an EXISTING path: symbolic links AND junctions (mount points) followed, the true case and long names.
// filepath.EvalSymlinks is not enough on Windows: since Go 1.23 it deliberately does not evaluate junctions, but the operating system does follow
// them when the file is opened, so a junction inside an allowed folder that points at C:\Windows would have passed the path policy (found on a real
// Windows 11 machine, docs/rmm/WINDOWS_VERIFICATION.md). GetFinalPathNameByHandle asks the file system itself where the path really ends up.
func hostResolve(path string) (string, error) {
	h, err := openForQuery(path)
	if err != nil {
		return "", &fs.PathError{Op: "resolve", Path: path, Err: err}
	}
	defer windows.CloseHandle(h)
	fp, err := finalPathOf(h)
	if err != nil {
		return "", &fs.PathError{Op: "resolve", Path: path, Err: err}
	}
	return fp, nil
}

// openForQuery opens a file or directory for attribute queries only (no data access), following links.
func openForQuery(path string) (windows.Handle, error) {
	p, err := windows.UTF16PtrFromString(path)
	if err != nil {
		return 0, err
	}
	return windows.CreateFile(p, 0, windows.FILE_SHARE_READ|windows.FILE_SHARE_WRITE|windows.FILE_SHARE_DELETE, nil,
		windows.OPEN_EXISTING, windows.FILE_FLAG_BACKUP_SEMANTICS, 0)
}

// finalPathOf is the normalized DOS path of an open handle without the \\?\ prefix (\\?\UNC\server\share becomes \\server\share).
func finalPathOf(h windows.Handle) (string, error) {
	buf := make([]uint16, 512)
	for {
		n, err := windows.GetFinalPathNameByHandle(h, &buf[0], uint32(len(buf)), 0)
		if err != nil {
			return "", err
		}
		if int(n) > len(buf) {
			buf = make([]uint16, n+1)
			continue
		}
		return stripDevicePrefix(windows.UTF16ToString(buf[:n])), nil
	}
}

func stripDevicePrefix(s string) string {
	switch {
	case strings.HasPrefix(s, `\\?\UNC\`):
		return `\\` + s[len(`\\?\UNC\`):]
	case strings.HasPrefix(s, `\\?\`):
		return s[len(`\\?\`):]
	}
	return s
}

// reparseTagOf is the reparse tag of an open handle (0 when there is none or it cannot be read).
func reparseTagOf(h windows.Handle) uint32 {
	var ti struct{ FileAttributes, ReparseTag uint32 }
	if err := windows.GetFileInformationByHandleEx(h, windows.FileAttributeTagInfo, (*byte)(unsafe.Pointer(&ti)), uint32(unsafe.Sizeof(ti))); err != nil {
		return 0
	}
	return ti.ReparseTag
}

// openNoFollow opens read-only. A final element that is a reparse point (symbolic link, junction, ...) is refused, and the handle's own final
// path must equal the path the policy resolved, so a link swapped in after the check (or in a parent folder) cannot redirect the read.
func openNoFollow(path string) (*os.File, error) {
	p, err := windows.UTF16PtrFromString(path)
	if err != nil {
		return nil, &fs.PathError{Op: "open", Path: path, Err: err}
	}
	h, err := windows.CreateFile(p, windows.GENERIC_READ, windows.FILE_SHARE_READ|windows.FILE_SHARE_WRITE|windows.FILE_SHARE_DELETE, nil,
		windows.OPEN_EXISTING, windows.FILE_ATTRIBUTE_NORMAL|windows.FILE_FLAG_OPEN_REPARSE_POINT, 0)
	if err != nil {
		return nil, &fs.PathError{Op: "open", Path: path, Err: err}
	}
	var info windows.ByHandleFileInformation
	if err := windows.GetFileInformationByHandle(h, &info); err != nil {
		windows.CloseHandle(h)
		return nil, &fs.PathError{Op: "open", Path: path, Err: err}
	}
	if info.FileAttributes&windows.FILE_ATTRIBUTE_REPARSE_POINT != 0 && winacl.IsNameSurrogate(reparseTagOf(h)) {
		windows.CloseHandle(h) // a symlink or junction; a Cloud Files placeholder is an ordinary file for this purpose (review CR2)
		return nil, &fs.PathError{Op: "open", Path: path, Err: errors.New("path is a link")}
	}
	if fp, err := finalPathOf(h); err != nil || !strings.EqualFold(filepath.Clean(fp), filepath.Clean(path)) {
		windows.CloseHandle(h)
		return nil, &fs.PathError{Op: "open", Path: path, Err: errors.New("path changed after the policy check")}
	}
	return os.NewFile(uintptr(syscall.Handle(h)), path), nil
}

// commitFile moves tmp onto dest; os.Rename replaces an existing file on
// Windows, so without overwrite the destination is checked just before.
func commitFile(tmp, dest string, overwrite bool) error {
	if li, err := os.Lstat(dest); err == nil {
		if !overwrite {
			return errExists
		}
		if !li.Mode().IsRegular() {
			return errors.New("destination is not a regular file")
		}
	}
	return os.Rename(tmp, dest)
}

// putTemp on Windows: the destination directory is PINNED for the whole transfer (CORE-DS-13). It is opened once, by handle, with
// FILE_FLAG_OPEN_REPARSE_POINT (a link is not followed) and WITHOUT FILE_SHARE_DELETE, and the handle stays open until the put is committed or
// discarded. NTFS refuses to rename or replace a directory (or any ancestor of it) that has an open handle without delete sharing, so after the
// check below nobody can swap the directory, or a parent of it, for a junction while the temp file is created, written and moved. The handle's
// final path must equal the path the policy approved. The temp file and the final rename are still addressed by path, but only inside that
// pinned directory. Not done (remaining part of CORE-7): creating the temp file with an explicit SYSTEM/Administrators-only security descriptor and
// renaming through the open handle; the temp file still inherits the folder's ACL. UNVERIFIED on a real Windows machine, see
// docs/rmm/AGENT_DEEP_SCAN_WINDOWS_TEST.md.
type putTemp struct {
	File *os.File
	path string
	dir  string
	pin  windows.Handle
}

// pinDirectory opens dir as described above and checks it against the approved path.
func pinDirectory(dir string) (windows.Handle, error) {
	p, err := windows.UTF16PtrFromString(dir)
	if err != nil {
		return 0, &fs.PathError{Op: "open", Path: dir, Err: err}
	}
	h, err := windows.CreateFile(p, windows.FILE_LIST_DIRECTORY|windows.FILE_READ_ATTRIBUTES|windows.SYNCHRONIZE,
		windows.FILE_SHARE_READ|windows.FILE_SHARE_WRITE, nil, windows.OPEN_EXISTING,
		windows.FILE_FLAG_BACKUP_SEMANTICS|windows.FILE_FLAG_OPEN_REPARSE_POINT, 0)
	if err != nil {
		return 0, &fs.PathError{Op: "open", Path: dir, Err: err}
	}
	var info windows.ByHandleFileInformation
	if err := windows.GetFileInformationByHandle(h, &info); err != nil {
		windows.CloseHandle(h)
		return 0, &fs.PathError{Op: "open", Path: dir, Err: err}
	}
	fp, err := finalPathOf(h)
	if err != nil {
		windows.CloseHandle(h)
		return 0, &fs.PathError{Op: "open", Path: dir, Err: err}
	}
	if err := checkPinnedDir(info.FileAttributes, reparseTagOf(h), fp, dir); err != nil {
		windows.CloseHandle(h)
		return 0, &fs.PathError{Op: "open", Path: dir, Err: err}
	}
	return h, nil
}

func beginPut(dir, name string) (*putTemp, error) {
	pin, err := pinDirectory(dir)
	if err != nil {
		return nil, err
	}
	path := filepath.Join(dir, name)
	open := func() (*os.File, error) { return os.OpenFile(path, os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0o600) }
	f, err := open()
	if errors.Is(err, fs.ErrExist) {
		if li, lerr := os.Lstat(path); lerr == nil && (li.Mode().IsRegular() || li.Mode()&fs.ModeSymlink != 0) {
			if os.Remove(path) == nil {
				f, err = open()
			}
		}
	}
	if err != nil {
		windows.CloseHandle(pin)
		return nil, err
	}
	return &putTemp{File: f, path: path, dir: dir, pin: pin}, nil
}

func (t *putTemp) Commit(dest string, _ os.FileMode, _, overwrite bool) error {
	if err := t.File.Close(); err != nil {
		return err
	}
	return commitFile(t.path, filepath.Join(t.dir, dest), overwrite)
}

func (t *putTemp) release() {
	if t.pin != 0 {
		windows.CloseHandle(t.pin)
		t.pin = 0
	}
}

func (t *putTemp) Discard() { t.File.Close(); os.Remove(t.path); t.release() }
func (t *putTemp) Close()   { t.File.Close(); t.release() }
