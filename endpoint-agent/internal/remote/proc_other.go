//go:build !linux && !windows

package remote

import (
	"context"
	"errors"
	"os"
)

var errUnsupportedOS = errors.New("remote tools are not supported on this OS")

type unsupportedBackend struct{}

func (unsupportedBackend) List(context.Context) ([]Service, error) { return nil, errUnsupportedOS }
func (unsupportedBackend) Status(context.Context, string) (string, string, error) {
	return "", "", errUnsupportedOS
}
func (unsupportedBackend) Apply(context.Context, string, string) error { return errUnsupportedOS }
func (unsupportedBackend) Snapshot(context.Context) ([]ProcSample, error) {
	return nil, errUnsupportedOS
}
func (unsupportedBackend) Lookup(context.Context, int) (ProcSample, bool, error) {
	return ProcSample{}, false, errUnsupportedOS
}
func (unsupportedBackend) Kill(int, bool) (string, error) { return "", errUnsupportedOS }
func (unsupportedBackend) Self() (int, int)               { return os.Getpid(), os.Getppid() }

// NewProcBackend is the production backend of this OS (none here).
func NewProcBackend() ProcBackend { return unsupportedBackend{} }

// NewServiceBackend is the production backend of this OS (none here).
func NewServiceBackend(Runner) ServiceBackend { return unsupportedBackend{} }
