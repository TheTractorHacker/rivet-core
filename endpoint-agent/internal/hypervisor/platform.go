package hypervisor

import (
	"context"
	"errors"
	"time"

	"rivetit-agent/internal/api"
	"rivetit-agent/internal/remote"
)

// Platform names on the wire.
const (
	PlatformProxmox = "proxmox"
	PlatformHyperV  = "hyperv"
)

// ErrNotHost means this machine is not a Proxmox VE or Hyper-V host: nothing is
// reported and no error is raised.
var ErrNotHost = errors.New("not a supported hypervisor host")

// Options bound one collection.
type Options struct {
	MaxVMs          int
	SnapshotLookups int
}

// Collector reads one hypervisor host.
type Collector interface {
	Platform() string
	// Full reads everything. It returns a report even when part of the read
	// failed (Report.Error then says what), and an error only when nothing
	// useful could be read at all.
	Full(ctx context.Context, o Options) (*api.HypervisorReport, error)
	// States is the cheap probe: the state of every VM, keyed by VM id.
	States(ctx context.Context, o Options) (map[string]string, error)
}

// Detector finds out whether this machine hosts a supported hypervisor.
// ErrNotHost (or any other error) means nothing is collected.
type Detector func(ctx context.Context) (Collector, error)

// State names on the wire (a closed set; the server maps anything else to "unknown").
const (
	StateRunning       = "running"
	StateStopped       = "stopped"
	StatePaused        = "paused"
	StateSuspended     = "suspended"
	StateTransitioning = "transitioning"
	StateError         = "error"
	StateUnknown       = "unknown"
)

// Runner is the command seam (remote.Runner: argv only, bounded output and time).
type Runner = remote.Runner

const (
	toolOutMax = 16 << 20
	cmdTimeout = 20 * time.Second
)

// run executes one fixed command; a non-zero exit is an error carrying the exit code only (never the output).
func run(ctx context.Context, r Runner, timeout time.Duration, name string, args ...string) ([]byte, error) {
	res, err := r.Run(ctx, name, args, toolOutMax, timeout)
	if err != nil {
		return nil, err
	}
	if res.ExitCode != 0 {
		return nil, &exitError{name: name, code: res.ExitCode}
	}
	if res.Truncated {
		return res.Stdout, errTruncated
	}
	return res.Stdout, nil
}

var errTruncated = errors.New("command output exceeded the size cap")

type exitError struct {
	name string
	code int
}

func (e *exitError) Error() string { return e.name + " exited with status " + itoa(e.code) }
