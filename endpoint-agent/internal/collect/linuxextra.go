//go:build linux

package collect

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
)

// parseCoreStat returns the per-core idle(+iowait) and total jiffies from the "cpuN" lines of /proc/stat.
func parseCoreStat(s string) ([]CoreTime, error) {
	var out []CoreTime
	for _, line := range strings.Split(s, "\n") {
		fs := strings.Fields(line)
		if len(fs) < 6 || !strings.HasPrefix(fs[0], "cpu") || fs[0] == "cpu" {
			continue
		}
		var ct CoreTime
		for i, f := range fs[1:] {
			if i >= 8 {
				break
			}
			v, err := strconv.ParseUint(f, 10, 64)
			if err != nil {
				return nil, err
			}
			ct.Total += v
			if i == 3 || i == 4 {
				ct.Idle += v
			}
		}
		out = append(out, ct)
	}
	if len(out) == 0 {
		return nil, errors.New("no per-core lines in /proc/stat")
	}
	return out, nil
}

// blockDiskRE matches whole block devices (not partitions, loop, ram or device-mapper nodes, which would double count).
var blockDiskRE = regexp.MustCompile(`^(sd[a-z]+|hd[a-z]+|vd[a-z]+|xvd[a-z]+|nvme[0-9]+n[0-9]+|mmcblk[0-9]+)$`)

// parseDiskstats sums completed reads/writes and in-flight requests over the whole physical disks.
func parseDiskstats(s string) (DiskIO, error) {
	var io DiskIO
	seen := false
	for _, line := range strings.Split(s, "\n") {
		f := strings.Fields(line)
		if len(f) < 12 || !blockDiskRE.MatchString(f[2]) {
			continue
		}
		r, e1 := strconv.ParseUint(f[3], 10, 64)
		w, e2 := strconv.ParseUint(f[7], 10, 64)
		q, e3 := strconv.ParseUint(f[11], 10, 64)
		if e1 != nil || e2 != nil || e3 != nil {
			continue
		}
		io.Reads += r
		io.Writes += w
		io.Queue += float64(q)
		seen = true
	}
	if !seen {
		return DiskIO{}, errors.New("no physical disks in /proc/diskstats")
	}
	return io, nil
}

// parseNetErrors sums receive and transmit errors over every non-virtual interface.
func parseNetErrors(s string) (uint64, error) {
	lines := strings.Split(s, "\n")
	if len(lines) < 3 {
		return 0, errors.New("unexpected /proc/net/dev")
	}
	var n uint64
	for _, l := range lines[2:] {
		name, rest, ok := strings.Cut(l, ":")
		if !ok || isVirtualNet(strings.TrimSpace(name)) {
			continue
		}
		f := strings.Fields(rest)
		if len(f) < 11 {
			continue
		}
		a, e1 := strconv.ParseUint(f[2], 10, 64)
		b, e2 := strconv.ParseUint(f[10], 10, 64)
		if e1 == nil {
			n += a
		}
		if e2 == nil {
			n += b
		}
	}
	return n, nil
}

func (p *LinuxPlatform) CoreTimes(ctx context.Context) ([]CoreTime, error) {
	s, err := p.read("/proc/stat")
	if err != nil {
		return nil, err
	}
	return parseCoreStat(s)
}

func (p *LinuxPlatform) DiskIO(ctx context.Context) (DiskIO, error) {
	s, err := p.read("/proc/diskstats")
	if err != nil {
		return DiskIO{}, err
	}
	return parseDiskstats(s)
}

func (p *LinuxPlatform) NetErrors(ctx context.Context) (uint64, error) {
	s, err := p.read("/proc/net/dev")
	if err != nil {
		return 0, err
	}
	return parseNetErrors(s)
}

// Battery reads the first /sys/class/power_supply/BAT* (ErrUnsupported on machines without one).
func (p *LinuxPlatform) Battery(ctx context.Context) (Battery, error) {
	dirs, _ := filepath.Glob(p.f("/sys/class/power_supply/BAT*"))
	for _, d := range dirs {
		b, err := os.ReadFile(filepath.Join(d, "capacity"))
		if err != nil {
			continue
		}
		v, err := strconv.ParseFloat(strings.TrimSpace(string(b)), 64)
		if err != nil || v < 0 || v > 100 {
			continue
		}
		st, _ := os.ReadFile(filepath.Join(d, "status"))
		status := strings.ToLower(strings.TrimSpace(string(st)))
		return Battery{Pct: v, Charging: status == "charging" || status == "full"}, nil
	}
	return Battery{}, ErrUnsupported
}

// DesktopSession: a graphical default target, an enabled display manager, or a live X/Wayland socket means the machine has a desktop. A server with the default
// multi-user.target and no display manager is headless, so the server leads with the terminal.
func (p *LinuxPlatform) DesktopSession(ctx context.Context) (bool, error) {
	target, _ := os.Readlink(p.f("/etc/systemd/system/default.target"))
	if target == "" {
		target, _ = os.Readlink(p.f("/lib/systemd/system/default.target"))
	}
	_, dmErr := os.Lstat(p.f("/etc/systemd/system/display-manager.service"))
	var socks []string
	if es, err := os.ReadDir(p.f("/tmp/.X11-unix")); err == nil {
		for _, e := range es {
			socks = append(socks, e.Name())
		}
	}
	if us, err := filepath.Glob(p.f("/run/user/*/wayland-*")); err == nil {
		for _, u := range us {
			socks = append(socks, filepath.Base(u))
		}
	}
	return linuxHasDesktop(target, dmErr == nil, socks), nil
}
