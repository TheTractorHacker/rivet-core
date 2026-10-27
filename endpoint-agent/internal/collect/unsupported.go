//go:build !windows && !linux

package collect

import "context"

type unsupported struct{}

func (unsupported) Identity(context.Context) (Identity, error)       { return Identity{}, ErrUnsupported }
func (unsupported) OSInfo(context.Context) (string, string, error)   { return "", "", ErrUnsupported }
func (unsupported) CPUModel(context.Context) (string, error)         { return "", ErrUnsupported }
func (unsupported) CPUTimes(context.Context) (uint64, uint64, error) { return 0, 0, ErrUnsupported }
func (unsupported) Memory(context.Context) (uint64, uint64, error)   { return 0, 0, ErrUnsupported }
func (unsupported) Disks(context.Context) ([]DiskStat, error)        { return nil, ErrUnsupported }
func (unsupported) NetBytes(context.Context) (uint64, uint64, error) { return 0, 0, ErrUnsupported }
func (unsupported) UptimeS(context.Context) (uint64, error)          { return 0, ErrUnsupported }
func (unsupported) LoggedInUser(context.Context) (string, error)     { return "", ErrUnsupported }
func (unsupported) PendingReboot(context.Context) (bool, []string, error) {
	return false, nil, ErrUnsupported
}
func (unsupported) ServiceState(context.Context, string) (ServiceInfo, error) {
	return ServiceInfo{}, ErrUnsupported
}
func (unsupported) MeshAgentDir() string { return "" }
