//go:build windows

package remote

// Windows process backend. CROSS-COMPILED AND VETTED ONLY: this code has never
// been executed in this repository's tests.

import (
	"context"
	"errors"
	"time"
	"unsafe"

	"golang.org/x/sys/windows"
)

var procGetProcessMemoryInfo = windows.NewLazySystemDLL("kernel32.dll").NewProc("K32GetProcessMemoryInfo")

type processMemoryCounters struct {
	CB                         uint32
	PageFaultCount             uint32
	PeakWorkingSetSize         uintptr
	WorkingSetSize             uintptr
	QuotaPeakPagedPoolUsage    uintptr
	QuotaPagedPoolUsage        uintptr
	QuotaPeakNonPagedPoolUsage uintptr
	QuotaNonPagedPoolUsage     uintptr
	PagefileUsage              uintptr
	PeakPagefileUsage          uintptr
}

// WinProcs is the Windows ProcBackend (Toolhelp32 + GetProcessTimes + K32GetProcessMemoryInfo).
type WinProcs struct{}

func ftToUint64(ft windows.Filetime) uint64 {
	return uint64(ft.HighDateTime)<<32 | uint64(ft.LowDateTime)
}

func (WinProcs) forEach(fn func(e *windows.ProcessEntry32) bool) error {
	snap, err := windows.CreateToolhelp32Snapshot(windows.TH32CS_SNAPPROCESS, 0)
	if err != nil {
		return err
	}
	defer windows.CloseHandle(snap)
	var e windows.ProcessEntry32
	e.Size = uint32(unsafe.Sizeof(e))
	if err := windows.Process32First(snap, &e); err != nil {
		return err
	}
	for {
		if !fn(&e) {
			return nil
		}
		if err := windows.Process32Next(snap, &e); err != nil {
			if errors.Is(err, windows.ERROR_NO_MORE_FILES) {
				return nil
			}
			return err
		}
	}
}

func fromEntry(e *windows.ProcessEntry32) ProcSample {
	return ProcSample{PID: int(e.ProcessID), PPID: int(e.ParentProcessID), Threads: int(e.Threads), Name: windows.UTF16ToString(e.ExeFile[:])}
}

// fill adds CPU time, start time and working set when the process can be opened.
func fill(s *ProcSample) {
	h, err := windows.OpenProcess(windows.PROCESS_QUERY_LIMITED_INFORMATION, false, uint32(s.PID))
	if err != nil {
		return
	}
	defer windows.CloseHandle(h)
	var cr, ex, kr, us windows.Filetime
	if windows.GetProcessTimes(h, &cr, &ex, &kr, &us) == nil {
		s.CPU = time.Duration(ftToUint64(kr)+ftToUint64(us)) * 100 * time.Nanosecond
		s.Started = time.Unix(0, cr.Nanoseconds()).UTC()
	}
	var pmc processMemoryCounters
	pmc.CB = uint32(unsafe.Sizeof(pmc))
	if r, _, _ := procGetProcessMemoryInfo.Call(uintptr(h), uintptr(unsafe.Pointer(&pmc)), uintptr(pmc.CB)); r != 0 {
		s.Mem = uint64(pmc.WorkingSetSize)
	}
}

// Snapshot implements ProcBackend.
func (w WinProcs) Snapshot(ctx context.Context) ([]ProcSample, error) {
	var out []ProcSample
	err := w.forEach(func(e *windows.ProcessEntry32) bool {
		if ctx.Err() != nil {
			return false
		}
		s := fromEntry(e)
		fill(&s)
		out = append(out, s)
		return true
	})
	if err == nil && ctx.Err() != nil {
		err = ctx.Err()
	}
	return out, err
}

// ResolveUsers implements UserResolver (best effort: "" when the token is denied).
func (WinProcs) ResolveUsers(ctx context.Context, ps []ProcSample) {
	for i := range ps {
		if ctx.Err() != nil {
			return
		}
		ps[i].User = tokenUser(uint32(ps[i].PID))
	}
}

func tokenUser(pid uint32) string {
	h, err := windows.OpenProcess(windows.PROCESS_QUERY_LIMITED_INFORMATION, false, pid)
	if err != nil {
		return ""
	}
	defer windows.CloseHandle(h)
	var tok windows.Token
	if err := windows.OpenProcessToken(h, windows.TOKEN_QUERY, &tok); err != nil {
		return ""
	}
	defer tok.Close()
	u, err := tok.GetTokenUser()
	if err != nil {
		return ""
	}
	account, domain, _, err := u.User.Sid.LookupAccount("")
	if err != nil {
		return ""
	}
	if domain != "" {
		return domain + `\` + account
	}
	return account
}

// Lookup implements ProcBackend: the image name is re-read from a fresh snapshot.
func (w WinProcs) Lookup(ctx context.Context, pid int) (ProcSample, bool, error) {
	var found ProcSample
	ok := false
	err := w.forEach(func(e *windows.ProcessEntry32) bool {
		if int(e.ProcessID) == pid {
			found, ok = fromEntry(e), true
			return false
		}
		return true
	})
	return found, ok, err
}

// Kill implements ProcBackend: TerminateProcess with exit code 1 (force is moot).
func (WinProcs) Kill(pid int, _ bool) (string, error) {
	h, err := windows.OpenProcess(windows.PROCESS_TERMINATE|windows.PROCESS_QUERY_LIMITED_INFORMATION, false, uint32(pid))
	if err != nil {
		switch {
		case errors.Is(err, windows.ERROR_INVALID_PARAMETER):
			return "terminate", ErrProcNotFound
		case errors.Is(err, windows.ERROR_ACCESS_DENIED):
			return "terminate", ErrProcDenied
		}
		return "terminate", err
	}
	defer windows.CloseHandle(h)
	if err := windows.TerminateProcess(h, 1); err != nil {
		if errors.Is(err, windows.ERROR_ACCESS_DENIED) {
			return "terminate", ErrProcDenied
		}
		return "terminate", err
	}
	return "terminate", nil
}

// Self implements ProcBackend.
func (WinProcs) Self() (int, int) {
	pid := int(windows.GetCurrentProcessId())
	ppid := 0
	_ = WinProcs{}.forEach(func(e *windows.ProcessEntry32) bool {
		if int(e.ProcessID) == pid {
			ppid = int(e.ParentProcessID)
			return false
		}
		return true
	})
	return pid, ppid
}

// NewProcBackend is the production backend of this OS.
func NewProcBackend() ProcBackend { return WinProcs{} }
