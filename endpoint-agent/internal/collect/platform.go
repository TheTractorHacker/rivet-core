// Package collect gathers inventory, metrics and check results. All OS access
// goes through the small Platform interface so everything above it is pure Go
// and unit-tested on Linux; only collector_windows.go is Windows-specific.
package collect

import (
	"context"
	"errors"
)

// ErrNotFound is returned by ServiceState when the service does not exist.
var ErrNotFound = errors.New("not found")

// ErrUnsupported marks a datum this platform cannot provide.
var ErrUnsupported = errors.New("unsupported on this platform")

type Identity struct {
	MachineGUID  *string
	Serial       *string
	Manufacturer *string
	Model        *string
}

type DiskStat struct {
	Mount       string
	Total, Free uint64
	FS          string
	// Additive (header view-model): the file-system source (ZFS dataset, device node or volume GUID) and the volume label; empty when unknown.
	Source, Label string
}

type ServiceInfo struct {
	State   string // running|stopped|paused|start_pending|stop_pending|...
	Startup string // automatic|manual|disabled|unknown
}

// Platform is the OS layer. Every method must honour ctx where the
// underlying call allows it; the Collector additionally enforces a hard
// timeout around each call, so a stuck implementation cannot hang the loop.
type Platform interface {
	Identity(ctx context.Context) (Identity, error)
	OSInfo(ctx context.Context) (name, version string, err error)
	CPUModel(ctx context.Context) (string, error)
	CPUTimes(ctx context.Context) (idle, total uint64, err error)
	Memory(ctx context.Context) (total, available uint64, err error)
	Disks(ctx context.Context) ([]DiskStat, error)
	NetBytes(ctx context.Context) (rx, tx uint64, err error)
	UptimeS(ctx context.Context) (uint64, error)
	LoggedInUser(ctx context.Context) (string, error)
	PendingReboot(ctx context.Context) (bool, []string, error)
	ServiceState(ctx context.Context, name string) (ServiceInfo, error)
	// MeshAgentDir is where an existing MeshCentral agent is expected ("" if none).
	MeshAgentDir() string
}

// CoreTime is one logical core's cumulative idle and total time (any consistent unit).
type CoreTime struct{ Idle, Total uint64 }

// DiskIO is the cumulative read/write operation count over all physical disks and the instantaneous queue depth (negative = unknown).
type DiskIO struct {
	Reads, Writes uint64
	Queue         float64
}

// Battery is the charge state of the first battery; Charging is meaningful only when the call succeeds.
type Battery struct {
	Pct      float64
	Charging bool
}

// ExtraPlatform is OPTIONAL: a Platform that also implements it feeds the extended per-check-in metrics (rc.25). Any method may
// return an error (or ErrUnsupported); that field is then simply left out.
type ExtraPlatform interface {
	CoreTimes(ctx context.Context) ([]CoreTime, error)
	DiskIO(ctx context.Context) (DiskIO, error)
	NetErrors(ctx context.Context) (uint64, error)
	Battery(ctx context.Context) (Battery, error) // ErrUnsupported when the machine has none
}
