//go:build windows

package remote

import (
	"errors"
	"io/fs"
	"os"
	"path/filepath"
	"strings"
	"syscall"

	"golang.org/x/sys/windows"
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
	if info.FileAttributes&windows.FILE_ATTRIBUTE_REPARSE_POINT != 0 {
		windows.CloseHandle(h)
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
