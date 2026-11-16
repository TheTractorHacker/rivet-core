//go:build windows

package main

import (
	"unsafe"

	"golang.org/x/sys/windows"
)

// pinFileWindows opens path read-only with FILE_SHARE_READ only. While the handle is open nobody can write to, truncate, rename or delete the file, so
// a standard user who owns the launch folder cannot swap the installer between the moment it is parsed and the moment its bytes are copied into the
// program directory (CORE-DS-48). The handle is checked for being a link itself (name-surrogate tag); no path strings are compared, so UNC, mapped-drive and short-name paths work (review CR3). UNVERIFIED on Windows.
func pinFileWindows(path string) (func(), error) {
	p, err := windows.UTF16PtrFromString(path)
	if err != nil {
		return nil, err
	}
	// FILE_FLAG_OPEN_REPARSE_POINT: if the launch path is itself a link, the handle is the link and is refused below; otherwise it is the file.
	h, err := windows.CreateFile(p, windows.GENERIC_READ, windows.FILE_SHARE_READ, nil, windows.OPEN_EXISTING,
		windows.FILE_ATTRIBUTE_NORMAL|windows.FILE_FLAG_OPEN_REPARSE_POINT, 0)
	if err != nil {
		return nil, err
	}
	var info windows.ByHandleFileInformation
	if err := windows.GetFileInformationByHandle(h, &info); err != nil {
		windows.CloseHandle(h)
		return nil, err
	}
	var ti struct{ FileAttributes, ReparseTag uint32 }
	tag := uint32(0)
	if info.FileAttributes&attrReparsePointFile != 0 {
		if windows.GetFileInformationByHandleEx(h, windows.FileAttributeTagInfo, (*byte)(unsafe.Pointer(&ti)), uint32(unsafe.Sizeof(ti))) == nil {
			tag = ti.ReparseTag
		}
	}
	if err := checkPinnedFile(info.FileAttributes, tag); err != nil {
		windows.CloseHandle(h)
		return nil, err
	}
	return func() { windows.CloseHandle(h) }, nil
}
