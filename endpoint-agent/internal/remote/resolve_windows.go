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
// pinned directory. The temp file is created with an explicit SYSTEM/Administrators-only DACL and no sharing, and is renamed onto the
// destination through its own handle (FileRenameInfo), so the written and hashed file is the delivered file. UNVERIFIED on a real Windows machine, see
// docs/rmm/AGENT_DEEP_SCAN_WINDOWS_TEST.md.
type putTemp struct {
	File *os.File
	path string
	dir  string
	pin  windows.Handle
	h    windows.Handle // the temp file's own handle (same as File, kept for the rename)
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

// tempSDDL is the DACL of the temp file: SYSTEM and Administrators only (protected), so a standard user who owns the destination folder cannot open
// or swap the bytes between the hash and the rename (the CORE-7 byte-swap variant, CORE-DS-13).
const tempSDDL = "D:P(A;;FA;;;SY)(A;;FA;;;BA)"

func beginPut(dir, name string) (*putTemp, error) {
	pin, err := pinDirectory(dir)
	if err != nil {
		return nil, err
	}
	path := filepath.Join(dir, name)
	open := func() (windows.Handle, error) {
		sd, err := windows.SecurityDescriptorFromString(tempSDDL)
		if err != nil {
			return 0, err
		}
		sa := &windows.SecurityAttributes{Length: uint32(unsafe.Sizeof(windows.SecurityAttributes{})), SecurityDescriptor: sd}
		p, err := windows.UTF16PtrFromString(path)
		if err != nil {
			return 0, err
		}
		// share mode 0: nobody else can open the temp file while it is written, hashed and renamed; DELETE lets us rename it by this very handle,
		// WRITE_DAC lets us hand the final file the folder's inherited permissions afterwards.
		return windows.CreateFile(p, windows.GENERIC_WRITE|windows.DELETE|windows.WRITE_DAC|windows.READ_CONTROL, 0, sa, windows.CREATE_NEW,
			windows.FILE_ATTRIBUTE_NORMAL|windows.FILE_FLAG_OPEN_REPARSE_POINT, 0)
	}
	h, err := open()
	if errors.Is(err, windows.ERROR_FILE_EXISTS) || errors.Is(err, windows.ERROR_ALREADY_EXISTS) {
		if li, lerr := os.Lstat(path); lerr == nil && (li.Mode().IsRegular() || li.Mode()&fs.ModeSymlink != 0) {
			if os.Remove(path) == nil {
				h, err = open()
			}
		}
	}
	if err != nil {
		windows.CloseHandle(pin)
		return nil, &fs.PathError{Op: "open", Path: path, Err: err}
	}
	return &putTemp{File: os.NewFile(uintptr(h), path), path: path, dir: dir, pin: pin, h: h}, nil
}

// Commit renames the open temp file onto dest THROUGH ITS HANDLE (FileRenameInfo), so the very file that was written and hashed becomes the
// destination; no path of the temp file is resolved again. Without overwrite an existing destination makes the rename fail (no clobber). The final
// file then gets the folder's inherited permissions (the temp file had SYSTEM/Administrators only).
func (t *putTemp) Commit(dest string, _ os.FileMode, _, overwrite bool) error {
	target := filepath.Join(t.dir, dest)
	if li, err := os.Lstat(target); err == nil {
		if !overwrite {
			return errExists
		}
		if !li.Mode().IsRegular() {
			return errors.New("destination is not a regular file")
		}
	}
	if err := t.File.Sync(); err != nil {
		return err
	}
	buf := renameInfoBuffer(target, overwrite, int(unsafe.Sizeof(uintptr(0))))
	if err := windows.SetFileInformationByHandle(t.h, windows.FileRenameInfo, &buf[0], uint32(len(buf))); err != nil {
		if errors.Is(err, windows.ERROR_ALREADY_EXISTS) || errors.Is(err, windows.ERROR_FILE_EXISTS) {
			return errExists
		}
		return err
	}
	// inherit the folder's permissions: an empty DACL, unprotected, makes Windows apply the parent's inheritable ACEs
	if acl, err := windows.ACLFromEntries(nil, nil); err == nil {
		_ = windows.SetSecurityInfo(t.h, windows.SE_FILE_OBJECT, windows.DACL_SECURITY_INFORMATION|windows.UNPROTECTED_DACL_SECURITY_INFORMATION, nil, nil, acl, nil)
	}
	return t.File.Close()
}

func (t *putTemp) release() {
	if t.pin != 0 {
		windows.CloseHandle(t.pin)
		t.pin = 0
	}
}

func (t *putTemp) Discard() { t.File.Close(); os.Remove(t.path); t.release() }
func (t *putTemp) Close()   { t.File.Close(); t.release() }
