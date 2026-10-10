//go:build linux

package collect

import (
	"bytes"
	"context"
	"errors"
	"os"
	"os/exec"
	"strings"
	"syscall"
	"time"
)

// LinuxPlatform reads /proc, /sys and statfs and asks systemd about services.
// It is the Platform of the supported Linux agent.
type LinuxPlatform struct {
	// Root prefixes every file path. It is "" in production; tests point it
	// at a fake /proc, /sys and /etc tree.
	Root string
	// Systemctl and NeedsRestarting name the helper programs ("" = default,
	// looked up in PATH); tests point them at shims.
	Systemctl, NeedsRestarting string
	// Run executes the package tools of the software inventory (nil = the real
	// thing); tests inject fixture output.
	Run CmdRunner
}

func NewPlatform() Platform { return &LinuxPlatform{} }

func (p *LinuxPlatform) f(path string) string { return p.Root + path }

func (p *LinuxPlatform) read(path string) (string, error) {
	b, err := os.ReadFile(p.f(path))
	return string(b), err
}

func (p *LinuxPlatform) readTrim(path string) (string, error) {
	s, err := p.read(path)
	return strings.TrimSpace(s), err
}

// dmi reads one /sys/class/dmi/id field ("" when unreadable or a placeholder;
// product_serial is root-only, which the service is).
func (p *LinuxPlatform) dmi(name string) string {
	s, _ := p.read("/sys/class/dmi/id/" + name)
	return s
}

func optStr(s string) *string {
	if s = cleanDMI(s); s == "" {
		return nil
	}
	return &s
}

func (p *LinuxPlatform) Identity(ctx context.Context) (Identity, error) {
	var id Identity
	mid, err := p.readTrim("/etc/machine-id")
	if err != nil || mid == "" {
		mid, _ = p.readTrim("/var/lib/dbus/machine-id")
	}
	id.MachineGUID = optStr(mid)
	dt := func(n string) string { // device-tree boards (Raspberry Pi and friends) have no DMI
		s, _ := p.read("/sys/firmware/devicetree/base/" + n)
		return strings.TrimRight(s, "\x00\n ")
	}
	id.Serial = optStr(firstDMI(p.dmi("product_serial"), p.dmi("board_serial"), p.dmi("chassis_serial"), dt("serial-number")))
	id.Manufacturer = optStr(firstDMI(p.dmi("sys_vendor"), p.dmi("board_vendor"), p.dmi("chassis_vendor")))
	id.Model = optStr(firstDMI(p.dmi("product_name"), p.dmi("board_name"), dt("model")))
	return id, nil
}

func (p *LinuxPlatform) OSInfo(ctx context.Context) (string, string, error) {
	s, err := p.read("/etc/os-release")
	if err != nil {
		if s, err = p.read("/usr/lib/os-release"); err != nil {
			return "", "", err
		}
	}
	name, ver, err := parseOSRelease(s)
	if err != nil {
		return "", "", err
	}
	if ver != "" && !strings.Contains(name, ver) {
		name = name + " (" + ver + ")"
	}
	return "linux", name, nil
}

func (p *LinuxPlatform) CPUModel(ctx context.Context) (string, error) {
	s, err := p.read("/proc/cpuinfo")
	if err != nil {
		return "", err
	}
	if m := parseCPUModel(s); m != "" {
		return m, nil
	}
	if m, _ := p.read("/sys/firmware/devicetree/base/model"); strings.TrimRight(m, "\x00\n ") != "" {
		return strings.TrimRight(m, "\x00\n "), nil
	}
	return "", errors.New("cpu model not found")
}

func (p *LinuxPlatform) CPUTimes(ctx context.Context) (uint64, uint64, error) {
	s, err := p.read("/proc/stat")
	if err != nil {
		return 0, 0, err
	}
	return parseCPUStat(s)
}

func (p *LinuxPlatform) Memory(ctx context.Context) (uint64, uint64, error) {
	s, err := p.read("/proc/meminfo")
	if err != nil {
		return 0, 0, err
	}
	return parseMeminfo(s)
}

func (p *LinuxPlatform) Disks(ctx context.Context) ([]DiskStat, error) {
	s, err := p.read("/proc/mounts")
	if err != nil {
		return nil, err
	}
	var out []DiskStat
	for _, m := range parseMounts(s) {
		var st syscall.Statfs_t
		if err := syscall.Statfs(p.f(m.Mount), &st); err != nil {
			continue
		}
		bs := uint64(st.Bsize)
		out = append(out, DiskStat{Mount: m.Mount, Total: st.Blocks * bs, Free: st.Bavail * bs, FS: m.FS})
	}
	return out, nil
}

func (p *LinuxPlatform) NetBytes(ctx context.Context) (uint64, uint64, error) {
	s, err := p.read("/proc/net/dev")
	if err != nil {
		return 0, 0, err
	}
	return parseNetDev(s)
}

func (p *LinuxPlatform) UptimeS(ctx context.Context) (uint64, error) {
	s, err := p.readTrim("/proc/uptime")
	if err != nil {
		return 0, err
	}
	return parseUptime(s)
}

func (p *LinuxPlatform) LoggedInUser(ctx context.Context) (string, error) {
	for _, path := range []string{"/var/run/utmp", "/run/utmp"} {
		if b, err := os.ReadFile(p.f(path)); err == nil {
			return strings.Join(parseUtmp(b), ","), nil
		}
	}
	return "", ErrUnsupported
}

// PendingReboot reports Debian/Ubuntu's reboot-required flag and, where the
// dnf/yum "needs-restarting" tool exists, its verdict (exit status 1 = reboot).
func (p *LinuxPlatform) PendingReboot(ctx context.Context) (bool, []string, error) {
	var why []string
	for _, f := range []string{"/var/run/reboot-required", "/run/reboot-required"} {
		if _, err := os.Stat(p.f(f)); err == nil {
			why = append(why, "reboot-required")
			if pk, err := p.readTrim(f + ".pkgs"); err == nil && pk != "" {
				why = append(why, "packages: "+strings.Join(strings.Fields(pk), " "))
			}
			break
		}
	}
	if len(why) == 0 {
		nr := p.NeedsRestarting
		if nr == "" {
			nr, _ = exec.LookPath("needs-restarting")
		}
		if nr != "" {
			cctx, cancel := context.WithTimeout(ctx, 30*time.Second)
			defer cancel()
			err := exec.CommandContext(cctx, nr, "-r").Run()
			var ee *exec.ExitError
			if errors.As(err, &ee) && ee.ExitCode() == 1 {
				why = append(why, "needs-restarting -r")
			}
		}
	}
	return len(why) > 0, why, nil
}

func (p *LinuxPlatform) ServiceState(ctx context.Context, name string) (ServiceInfo, error) {
	if err := validServiceName(name); err != nil {
		return ServiceInfo{}, err
	}
	sc := p.Systemctl
	if sc == "" {
		sc = "systemctl"
	}
	var out, stderr bytes.Buffer
	cmd := exec.CommandContext(ctx, sc, "show", "--property=ActiveState,LoadState,UnitFileState", "--", name)
	cmd.Stdout, cmd.Stderr = &out, &stderr
	if err := cmd.Run(); err != nil && out.Len() == 0 {
		return ServiceInfo{}, errors.New("systemctl unavailable: " + strings.TrimSpace(stderr.String()))
	}
	return parseSystemctlShow(out.String())
}

func (p *LinuxPlatform) MeshAgentDir() string {
	for _, d := range []string{"/usr/local/mesh_services/meshagent", "/opt/meshagent"} {
		if _, err := os.Stat(p.f(d)); err == nil {
			return d
		}
	}
	return ""
}
