//go:build windows

// Everything in this file is UNVERIFIED on real Windows: it cross-compiles
// and vets, but was never executed (no Windows host was available).
package collect

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"time"
	"unsafe"

	"golang.org/x/sys/windows"
	"golang.org/x/sys/windows/registry"
	"golang.org/x/sys/windows/svc"
	"golang.org/x/sys/windows/svc/mgr"

	"rivetit-agent/internal/jobs"
)

type WinPlatform struct {
	mu     sync.Mutex
	ident  *Identity
	user   string
	userAt time.Time
}

func NewPlatform() Platform { return &WinPlatform{} }

var (
	kernel32        = windows.NewLazySystemDLL("kernel32.dll")
	procGetSysTimes = kernel32.NewProc("GetSystemTimes")
	procGlobalMemEx = kernel32.NewProc("GlobalMemoryStatusEx")
	procGetTick64   = kernel32.NewProc("GetTickCount64")
)

func regString(root registry.Key, path, name string) (string, error) {
	k, err := registry.OpenKey(root, path, registry.QUERY_VALUE|registry.WOW64_64KEY)
	if err != nil {
		return "", err
	}
	defer k.Close()
	v, _, err := k.GetStringValue(name)
	return v, err
}

func junk(s string) bool {
	l := strings.ToLower(strings.TrimSpace(s))
	return l == "" || l == "0" || strings.Contains(l, "to be filled") || l == "default string" ||
		l == "system serial number" || l == "none" || l == "not specified" || strings.HasPrefix(l, "1234567890")
}

func ptr(s string) *string {
	if junk(s) {
		return nil
	}
	return &s
}

// runPS runs a FIXED PowerShell snippet (no server-controlled text) with a bound.
func runPS(ctx context.Context, snippet string) (string, error) {
	ps := filepath.Join(os.Getenv("SystemRoot"), "System32", "WindowsPowerShell", "v1.0", "powershell.exe")
	r := jobs.RunBounded(ctx, jobs.ExecSpec{Name: ps,
		Args:    []string{"-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass", "-Command", snippet},
		Timeout: 25 * time.Second, MaxOutput: 8192})
	if !r.HaveExit || r.ExitCode != 0 {
		return "", errors.New("powershell CIM query failed")
	}
	return r.Output, nil
}

func (p *WinPlatform) Identity(ctx context.Context) (Identity, error) {
	p.mu.Lock()
	defer p.mu.Unlock()
	if p.ident != nil {
		return *p.ident, nil
	}
	var id Identity
	if g, err := regString(registry.LOCAL_MACHINE, `SOFTWARE\Microsoft\Cryptography`, "MachineGuid"); err == nil {
		id.MachineGUID = ptr(g)
	}
	out, err := runPS(ctx, `$ErrorActionPreference='Stop';$b=Get-CimInstance Win32_BIOS;$c=Get-CimInstance Win32_ComputerSystem;`+
		`[pscustomobject]@{serial=$b.SerialNumber;manufacturer=$c.Manufacturer;model=$c.Model}|ConvertTo-Json -Compress`)
	if err == nil {
		var v struct{ Serial, Manufacturer, Model string }
		if json.Unmarshal([]byte(strings.TrimSpace(out)), &v) == nil {
			id.Serial, id.Manufacturer, id.Model = ptr(v.Serial), ptr(v.Manufacturer), ptr(v.Model)
		}
	}
	if id.MachineGUID != nil || err == nil { // cache only a meaningful result
		p.ident = &id
	}
	return id, nil
}

func (p *WinPlatform) OSInfo(ctx context.Context) (string, string, error) {
	const k = `SOFTWARE\Microsoft\Windows NT\CurrentVersion`
	prod, err := regString(registry.LOCAL_MACHINE, k, "ProductName")
	if err != nil {
		return "", "", err
	}
	build, _ := regString(registry.LOCAL_MACHINE, k, "CurrentBuildNumber")
	disp, _ := regString(registry.LOCAL_MACHINE, k, "DisplayVersion")
	var n int
	fmt.Sscanf(build, "%d", &n)
	if n >= 22000 && strings.Contains(prod, "Windows 10") { // ProductName is not updated by Windows 11
		prod = strings.Replace(prod, "Windows 10", "Windows 11", 1)
	}
	ubr := ""
	if kk, err := registry.OpenKey(registry.LOCAL_MACHINE, k, registry.QUERY_VALUE); err == nil {
		if v, _, err := kk.GetIntegerValue("UBR"); err == nil {
			ubr = fmt.Sprintf(".%d", v)
		}
		kk.Close()
	}
	return "windows", strings.TrimSpace(fmt.Sprintf("%s %s (10.0.%s%s)", prod, disp, build, ubr)), nil
}

func (p *WinPlatform) CPUModel(ctx context.Context) (string, error) {
	return regString(registry.LOCAL_MACHINE, `HARDWARE\DESCRIPTION\System\CentralProcessor\0`, "ProcessorNameString")
}

func ft(f windows.Filetime) uint64 { return uint64(f.HighDateTime)<<32 | uint64(f.LowDateTime) }

func (p *WinPlatform) CPUTimes(ctx context.Context) (uint64, uint64, error) {
	var idle, kernel, user windows.Filetime
	r, _, e := procGetSysTimes.Call(uintptr(unsafe.Pointer(&idle)), uintptr(unsafe.Pointer(&kernel)), uintptr(unsafe.Pointer(&user)))
	if r == 0 {
		return 0, 0, e
	}
	// kernel time includes idle time
	return ft(idle), ft(kernel) + ft(user), nil
}

type memStatusEx struct {
	Length                                                                         uint32
	MemoryLoad                                                                     uint32
	TotalPhys, AvailPhys                                                           uint64
	TotalPageFile, AvailPageFile, TotalVirtual, AvailVirtual, AvailExtendedVirtual uint64
}

func (p *WinPlatform) Memory(ctx context.Context) (uint64, uint64, error) {
	m := memStatusEx{Length: uint32(unsafe.Sizeof(memStatusEx{}))}
	r, _, e := procGlobalMemEx.Call(uintptr(unsafe.Pointer(&m)))
	if r == 0 {
		return 0, 0, e
	}
	return m.TotalPhys, m.AvailPhys, nil
}

func (p *WinPlatform) Disks(ctx context.Context) ([]DiskStat, error) {
	mask, err := windows.GetLogicalDrives()
	if err != nil {
		return nil, err
	}
	var out []DiskStat
	for i := 0; i < 26; i++ {
		if mask&(1<<uint(i)) == 0 {
			continue
		}
		root := fmt.Sprintf("%c:\\", 'A'+i)
		rp, _ := windows.UTF16PtrFromString(root)
		if windows.GetDriveType(rp) != windows.DRIVE_FIXED {
			continue
		}
		var avail, total, free uint64
		if err := windows.GetDiskFreeSpaceEx(rp, &avail, &total, &free); err != nil {
			continue
		}
		fsn := ""
		var fsb [32]uint16
		if err := windows.GetVolumeInformation(rp, nil, 0, nil, nil, nil, &fsb[0], uint32(len(fsb))); err == nil {
			fsn = windows.UTF16ToString(fsb[:])
		}
		out = append(out, DiskStat{Mount: fmt.Sprintf("%c:", 'A'+i), Total: total, Free: avail, FS: fsn})
	}
	return out, nil
}

func (p *WinPlatform) NetBytes(ctx context.Context) (uint64, uint64, error) {
	ifs, err := net.Interfaces()
	if err != nil {
		return 0, 0, err
	}
	var rx, tx uint64
	n := 0
	for _, i := range ifs {
		if i.Flags&net.FlagLoopback != 0 || i.Flags&net.FlagUp == 0 {
			continue
		}
		var row windows.MibIfRow2
		row.InterfaceIndex = uint32(i.Index)
		if err := windows.GetIfEntry2Ex(0, &row); err != nil {
			continue
		}
		rx += row.InOctets
		tx += row.OutOctets
		n++
	}
	if n == 0 {
		return 0, 0, errors.New("no interface counters")
	}
	return rx, tx, nil
}

func (p *WinPlatform) UptimeS(ctx context.Context) (uint64, error) {
	r, _, e := procGetTick64.Call()
	if r == 0 {
		return 0, e
	}
	return uint64(r) / 1000, nil
}

func (p *WinPlatform) LoggedInUser(ctx context.Context) (string, error) {
	p.mu.Lock()
	if p.user != "" && time.Since(p.userAt) < 5*time.Minute {
		u := p.user
		p.mu.Unlock()
		return u, nil
	}
	p.mu.Unlock()
	out, err := runPS(ctx, `(Get-CimInstance Win32_ComputerSystem).UserName`)
	if err != nil {
		return "", err
	}
	u := strings.TrimSpace(out)
	p.mu.Lock()
	p.user, p.userAt = u, time.Now()
	p.mu.Unlock()
	return u, nil // "" when nobody is logged on (reported as null)
}

func hasKey(path string) bool {
	k, err := registry.OpenKey(registry.LOCAL_MACHINE, path, registry.QUERY_VALUE|registry.WOW64_64KEY)
	if err != nil {
		return false
	}
	k.Close()
	return true
}

func (p *WinPlatform) PendingReboot(ctx context.Context) (bool, []string, error) {
	var why []string
	if hasKey(`SOFTWARE\Microsoft\Windows\CurrentVersion\Component Based Servicing\RebootPending`) {
		why = append(why, "CBS RebootPending")
	}
	if hasKey(`SOFTWARE\Microsoft\Windows\CurrentVersion\WindowsUpdate\Auto Update\RebootRequired`) {
		why = append(why, "WindowsUpdate RebootRequired")
	}
	if k, err := registry.OpenKey(registry.LOCAL_MACHINE, `SYSTEM\CurrentControlSet\Control\Session Manager`, registry.QUERY_VALUE); err == nil {
		if v, _, err := k.GetStringsValue("PendingFileRenameOperations"); err == nil && len(v) > 0 {
			why = append(why, "PendingFileRenameOperations")
		}
		k.Close()
	}
	return len(why) > 0, why, nil
}

func (p *WinPlatform) ServiceState(ctx context.Context, name string) (ServiceInfo, error) {
	m, err := mgr.Connect()
	if err != nil {
		return ServiceInfo{}, err
	}
	defer m.Disconnect()
	s, err := m.OpenService(name)
	if err != nil {
		if errors.Is(err, windows.ERROR_SERVICE_DOES_NOT_EXIST) {
			return ServiceInfo{}, ErrNotFound
		}
		return ServiceInfo{}, err
	}
	defer s.Close()
	st, err := s.Query()
	if err != nil {
		return ServiceInfo{}, err
	}
	cfg, err := s.Config()
	if err != nil {
		return ServiceInfo{}, err
	}
	state := map[svc.State]string{svc.Running: "running", svc.Stopped: "stopped", svc.Paused: "paused",
		svc.StartPending: "start_pending", svc.StopPending: "stop_pending",
		svc.ContinuePending: "continue_pending", svc.PausePending: "pause_pending"}[st.State]
	startup := map[uint32]string{mgr.StartAutomatic: "automatic", mgr.StartManual: "manual", mgr.StartDisabled: "disabled"}[cfg.StartType]
	if startup == "" {
		startup = "unknown"
	}
	return ServiceInfo{State: state, Startup: startup}, nil
}

func (p *WinPlatform) MeshAgentDir() string {
	for _, root := range []string{os.Getenv("ProgramFiles"), os.Getenv("ProgramW6432")} {
		if root == "" {
			continue
		}
		d := filepath.Join(root, "Mesh Agent")
		if _, err := os.Stat(d); err == nil {
			return d
		}
	}
	return ""
}
