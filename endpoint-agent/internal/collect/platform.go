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
