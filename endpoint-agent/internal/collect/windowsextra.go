//go:build windows

// UNVERIFIED on real Windows: cross-compiles and vets, never executed (no Windows host). Struct layouts follow the Win32/NT headers.

package collect

import (
	"context"
	"errors"
	"fmt"
	"net"
	"unsafe"

	"golang.org/x/sys/windows"
)

var (
	ntdll                   = windows.NewLazySystemDLL("ntdll.dll")
	procNtQuerySystemInfo   = ntdll.NewProc("NtQuerySystemInformation")
	procGetSystemPowerState = kernel32.NewProc("GetSystemPowerStatus")
)

const (
	systemProcessorPerformanceInformation = 8
	ioctlDiskPerformance                  = 0x00070020
	maxPhysicalDrives                     = 32
)

// sysProcPerf is SYSTEM_PROCESSOR_PERFORMANCE_INFORMATION (48 bytes on x64 and arm64).
type sysProcPerf struct {
	IdleTime, KernelTime, UserTime, DpcTime, InterruptTime int64
	InterruptCount                                         uint32
	_                                                      uint32
}

// CoreTimes: one NtQuerySystemInformation call, no performance-counter machinery. KernelTime includes idle time.
func (p *WinPlatform) CoreTimes(ctx context.Context) ([]CoreTime, error) {
	buf := make([]sysProcPerf, 256)
	var ret uint32
	r, _, _ := procNtQuerySystemInfo.Call(systemProcessorPerformanceInformation, uintptr(unsafe.Pointer(&buf[0])), uintptr(uint32(len(buf))*uint32(unsafe.Sizeof(buf[0]))), uintptr(unsafe.Pointer(&ret)))
	if r != 0 {
		return nil, fmt.Errorf("NtQuerySystemInformation: status 0x%x", uint32(r))
	}
	n := int(ret) / int(unsafe.Sizeof(buf[0]))
	if n <= 0 {
		return nil, errors.New("no processors returned")
	}
	out := make([]CoreTime, 0, n)
	for _, c := range buf[:n] {
		if c.KernelTime < 0 || c.UserTime < 0 || c.IdleTime < 0 {
			return nil, errors.New("negative processor time")
		}
		out = append(out, CoreTime{Idle: uint64(c.IdleTime), Total: uint64(c.KernelTime) + uint64(c.UserTime)})
	}
	return out, nil
}

// diskPerformance is DISK_PERFORMANCE (88 bytes).
type diskPerformance struct {
	BytesRead, BytesWritten, ReadTime, WriteTime, IdleTime int64
	ReadCount, WriteCount, QueueDepth, SplitCount          uint32
	QueryTime                                              int64
	StorageDeviceNumber                                    uint32
	StorageManagerName                                     [8]uint16
	_                                                      uint32
}

// DiskIO sums IOCTL_DISK_PERFORMANCE over \\.\PhysicalDrive0..N (cheap, no PDH/WMI). It needs the rights the service already has.
func (p *WinPlatform) DiskIO(ctx context.Context) (DiskIO, error) {
	var io DiskIO
	found := false
	for i := 0; i < maxPhysicalDrives; i++ {
		name, _ := windows.UTF16PtrFromString(fmt.Sprintf(`\\.\PhysicalDrive%d`, i))
		h, err := windows.CreateFile(name, 0, windows.FILE_SHARE_READ|windows.FILE_SHARE_WRITE, nil, windows.OPEN_EXISTING, 0, 0)
		if err != nil {
			continue
		}
		var dp diskPerformance
		var n uint32
		err = windows.DeviceIoControl(h, ioctlDiskPerformance, nil, 0, (*byte)(unsafe.Pointer(&dp)), uint32(unsafe.Sizeof(dp)), &n, nil)
		windows.CloseHandle(h)
		if err != nil {
			continue
		}
		io.Reads += uint64(dp.ReadCount)
		io.Writes += uint64(dp.WriteCount)
		io.Queue += float64(dp.QueueDepth)
		found = true
	}
	if !found {
		return DiskIO{}, errors.New("no physical drive performance data")
	}
	return io, nil
}

// NetErrors sums InErrors+OutErrors over the up, non-loopback interfaces that NetBytes counts.
func (p *WinPlatform) NetErrors(ctx context.Context) (uint64, error) {
	ifs, err := net.Interfaces()
	if err != nil {
		return 0, err
	}
	var e uint64
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
		e += row.InErrors + row.OutErrors
		n++
	}
	if n == 0 {
		return 0, errors.New("no interface counters")
	}
	return e, nil
}

type systemPowerStatus struct {
	ACLineStatus, BatteryFlag, BatteryLifePercent, SystemStatusFlag byte
	BatteryLifeTime, BatteryFullLifeTime                            uint32
}

// Battery uses GetSystemPowerStatus; ErrUnsupported on a machine without a battery or with an unknown level.
func (p *WinPlatform) Battery(ctx context.Context) (Battery, error) {
	var s systemPowerStatus
	r, _, e := procGetSystemPowerState.Call(uintptr(unsafe.Pointer(&s)))
	if r == 0 {
		return Battery{}, e
	}
	if s.BatteryFlag&128 != 0 || s.BatteryFlag == 255 || s.BatteryLifePercent > 100 {
		return Battery{}, ErrUnsupported
	}
	return Battery{Pct: float64(s.BatteryLifePercent), Charging: s.BatteryFlag&8 != 0 || s.ACLineStatus == 1 && s.BatteryLifePercent == 100}, nil
}
