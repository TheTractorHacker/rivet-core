//go:build windows

package main

import "golang.org/x/sys/windows"

// loadLibrarySearchSystem32 is LOAD_LIBRARY_SEARCH_SYSTEM32.
const loadLibrarySearchSystem32 = 0x00000800

// init restricts the DLL search for every later LoadLibrary in this process to %SystemRoot%\System32, so a DLL planted next to the executable or in
// the working directory is never picked up by an implicit load (CORE-DS-18, CORE-47). Windows 7 without KB2533623 lacks the API; then nothing changes.
func init() {
	p := windows.NewLazySystemDLL("kernel32.dll").NewProc("SetDefaultDllDirectories")
	if p.Find() == nil {
		_, _, _ = p.Call(loadLibrarySearchSystem32)
	}
}
