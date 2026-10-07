//go:build windows

// UNVERIFIED on real Windows: compiled and vetted only.
package main

import (
	"fmt"
	"os"
	"syscall"
	"unsafe"

	"golang.org/x/sys/windows"
)

func isElevatedWindows() bool {
	return windows.GetCurrentProcessToken().IsElevated()
}

// shellExecuteInfo mirrors SHELLEXECUTEINFOW (64-bit layouts: amd64, arm64).
type shellExecuteInfo struct {
	cbSize         uint32
	fMask          uint32
	hwnd           windows.HWND
	lpVerb         *uint16
	lpFile         *uint16
	lpParameters   *uint16
	lpDirectory    *uint16
	nShow          int32
	hInstApp       windows.Handle
	lpIDList       uintptr
	lpClass        *uint16
	hkeyClass      windows.Handle
	dwHotKey       uint32
	hIconOrMonitor windows.Handle
	hProcess       windows.Handle
}

const (
	seeMaskNoCloseProcess = 0x00000040
	errorCancelled        = syscall.Errno(1223) // the user declined the UAC prompt
)

var procShellExecuteEx = windows.NewLazySystemDLL("shell32.dll").NewProc("ShellExecuteExW")

// relaunchElevated runs this exe again with the "runas" verb and the given
// arguments (already an allowlisted list), waits, and returns its exit code.
func relaunchElevated(args []string) (int, error) {
	self, err := os.Executable()
	if err != nil {
		return 0, err
	}
	verb, _ := windows.UTF16PtrFromString("runas")
	file, err := windows.UTF16PtrFromString(self)
	if err != nil {
		return 0, err
	}
	params, err := windows.UTF16PtrFromString(joinArgs(args))
	if err != nil {
		return 0, err
	}
	sei := shellExecuteInfo{fMask: seeMaskNoCloseProcess, lpVerb: verb, lpFile: file, lpParameters: params, nShow: windows.SW_SHOWNORMAL}
	sei.cbSize = uint32(unsafe.Sizeof(sei))
	r, _, e := procShellExecuteEx.Call(uintptr(unsafe.Pointer(&sei)))
	if r == 0 {
		if e == errorCancelled {
			return 0, fmt.Errorf("the administrator prompt was cancelled")
		}
		return 0, e
	}
	if sei.hProcess == 0 {
		return 0, fmt.Errorf("no process handle returned")
	}
	defer windows.CloseHandle(sei.hProcess)
	if ev, err := windows.WaitForSingleObject(sei.hProcess, windows.INFINITE); err != nil || ev != windows.WAIT_OBJECT_0 {
		return 0, fmt.Errorf("waiting for the elevated process failed")
	}
	var code uint32
	if err := windows.GetExitCodeProcess(sei.hProcess, &code); err != nil {
		return 0, err
	}
	return int(code), nil
}

// showMessage displays a modal MessageBox (interactive runs only).
func showMessage(title, text string, isError bool) {
	t, _ := windows.UTF16PtrFromString(title)
	m, _ := windows.UTF16PtrFromString(text)
	flags := uint32(windows.MB_OK | windows.MB_ICONINFORMATION)
	if isError {
		flags = windows.MB_OK | windows.MB_ICONERROR
	}
	_, _ = windows.MessageBox(0, m, t, flags|windows.MB_SETFOREGROUND)
}
