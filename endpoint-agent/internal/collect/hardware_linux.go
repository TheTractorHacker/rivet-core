//go:build linux

package collect

import (
	"context"
	"os"
	"path/filepath"
	"strings"
)

// Hardware reads the device-type facts from /sys, /proc and (when present) systemd-detect-virt. Each failed read just leaves its fact out.
func (p *LinuxPlatform) Hardware(ctx context.Context) (Hardware, error) {
	var h Hardware
	if s, err := p.read("/sys/class/dmi/id/chassis_type"); err == nil {
		h.ChassisType = parseChassisFile(s)
	}
	// Battery: a power supply of type Battery that is the machine's own (peripherals such as a wireless mouse have scope "Device").
	if ents, err := filepath.Glob(p.f("/sys/class/power_supply/*")); err == nil {
		bat := false
		for _, e := range ents {
			t, _ := os.ReadFile(filepath.Join(e, "type"))
			sc, _ := os.ReadFile(filepath.Join(e, "scope"))
			if strings.TrimSpace(string(t)) == "Battery" && strings.TrimSpace(string(sc)) != "Device" {
				bat = true
			}
		}
		h.HasBattery = &bat
	}
	h.Virtualization = p.detectVirt(ctx)
	if _, err := os.Stat(p.f("/etc/pve")); err == nil {
		h.RoleHints = append(h.RoleHints, "hypervisor_host")
	} else if _, err := os.Stat(p.f("/usr/bin/pveversion")); err == nil {
		h.RoleHints = append(h.RoleHints, "hypervisor_host")
	}
	if _, err := os.Stat(p.f("/var/lib/samba/private/sam.ldb")); err == nil {
		h.RoleHints = append(h.RoleHints, "domain_controller")
	}
	return h.Finish(), nil
}

func (p *LinuxPlatform) detectVirt(ctx context.Context) string {
	if env, err := p.read("/proc/1/environ"); err == nil {
		if v := VirtFromContainerEnv(env); v != "" {
			return v
		}
	}
	run := p.Run
	if run == nil {
		run = execRunner
	}
	if out, _ := run(ctx, "systemd-detect-virt"); len(out) > 0 { // exit 1 means "none" and still prints it
		if v := VirtFromSystemd(string(out)); v != "" {
			return v
		}
	}
	if v := VirtFromStrings(p.dmi("sys_vendor"), p.dmi("product_name")+" "+p.dmi("board_vendor")); v != "" {
		return v
	}
	if _, err := p.read("/sys/class/dmi/id/product_name"); err != nil {
		return "" // no DMI at all: cannot tell
	}
	return "none"
}
