//go:build windows

package main

import (
	"errors"
	"path/filepath"
	"strings"
	"sync"
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
	pinMu.Lock()
	pinned[strings.ToLower(filepath.Clean(path))] = fileID{uint64(info.VolumeSerialNumber), uint64(info.FileIndexHigh)<<32 | uint64(info.FileIndexLow)}
	pinMu.Unlock()
	return func() {
		pinMu.Lock()
		delete(pinned, strings.ToLower(filepath.Clean(path)))
		pinMu.Unlock()
		windows.CloseHandle(h)
	}, nil
}

var (
	pinMu  sync.Mutex
	pinned = map[string]fileID{}
)

// verifyPinnedWindows opens path the way later code will (following every link, parents included) and requires it to be the very file that was pinned
// (volume serial + file index). A junction in a parent folder re-pointed after the pin is caught here (review CRR-5). UNVERIFIED on Windows.
func verifyPinnedWindows(path string) error {
	pinMu.Lock()
	want, ok := pinned[strings.ToLower(filepath.Clean(path))]
	pinMu.Unlock()
	if !ok {
		return errors.New("the installer file is not pinned")
	}
	p, err := windows.UTF16PtrFromString(path)
	if err != nil {
		return err
	}
	h, err := windows.CreateFile(p, 0, windows.FILE_SHARE_READ|windows.FILE_SHARE_WRITE|windows.FILE_SHARE_DELETE, nil, windows.OPEN_EXISTING, windows.FILE_ATTRIBUTE_NORMAL, 0)
	if err != nil {
		return err
	}
	defer windows.CloseHandle(h)
	var info windows.ByHandleFileInformation
	if err := windows.GetFileInformationByHandle(h, &info); err != nil {
		return err
	}
	got := fileID{uint64(info.VolumeSerialNumber), uint64(info.FileIndexHigh)<<32 | uint64(info.FileIndexLow)}
	if !sameFile(want, got) {
		return errors.New("the path now leads to a different file")
	}
	return nil
}
