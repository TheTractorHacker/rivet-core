//go:build windows

package main

import (
	"errors"
	"path/filepath"
	"strings"

	"golang.org/x/sys/windows"
)

// pinFileWindows opens path read-only with FILE_SHARE_READ only. While the handle is open nobody can write to, truncate, rename or delete the file, so
// a standard user who owns the launch folder cannot swap the installer between the moment it is parsed and the moment its bytes are copied into the
// program directory (CORE-DS-48). The handle's final path must equal the path asked for (no link or junction in between). UNVERIFIED on Windows.
func pinFileWindows(path string) (func(), error) {
	p, err := windows.UTF16PtrFromString(path)
	if err != nil {
		return nil, err
	}
	h, err := windows.CreateFile(p, windows.GENERIC_READ, windows.FILE_SHARE_READ, nil, windows.OPEN_EXISTING, windows.FILE_ATTRIBUTE_NORMAL, 0)
	if err != nil {
		return nil, err
	}
	buf := make([]uint16, 1024)
	n, err := windows.GetFinalPathNameByHandle(h, &buf[0], uint32(len(buf)), 0)
	if err != nil || int(n) > len(buf) {
		windows.CloseHandle(h)
		return nil, errors.New("cannot determine the final path of the installer")
	}
	final := strings.TrimPrefix(windows.UTF16ToString(buf[:n]), `\\?\`)
	if !strings.EqualFold(filepath.Clean(final), filepath.Clean(path)) {
		windows.CloseHandle(h)
		return nil, errors.New("the installer path is a link or changed: " + final)
	}
	return func() { windows.CloseHandle(h) }, nil
}
