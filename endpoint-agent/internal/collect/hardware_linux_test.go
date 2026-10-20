//go:build linux

package collect

import (
	"context"
	"os"
	"path/filepath"
	"testing"
)

func hwRoot(t *testing.T, files map[string]string) *LinuxPlatform {
	root := t.TempDir()
	for p, s := range files {
		full := filepath.Join(root, p)
		os.MkdirAll(filepath.Dir(full), 0o755)
		if err := os.WriteFile(full, []byte(s), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	return &LinuxPlatform{Root: root, Run: func(context.Context, string, ...string) ([]byte, error) { return nil, os.ErrNotExist }}
}

func TestLinuxHardware(t *testing.T) {
	ctx := context.Background()
	lap := hwRoot(t, map[string]string{"sys/class/dmi/id/chassis_type": "10\n", "sys/class/dmi/id/sys_vendor": "LENOVO\n", "sys/class/dmi/id/product_name": "ThinkPad T14\n",
		"sys/class/power_supply/BAT0/type": "Battery\n", "sys/class/power_supply/AC/type": "Mains\n"})
	h, _ := lap.Hardware(ctx)
	if h.ChassisType != "laptop" || h.HasBattery == nil || !*h.HasBattery || h.Virtualization != "none" || len(h.RoleHints) != 0 {
		t.Fatalf("laptop %+v", h)
	}
	mouse := hwRoot(t, map[string]string{"sys/class/dmi/id/chassis_type": "3\n", "sys/class/dmi/id/product_name": "OptiPlex\n", "sys/class/power_supply/hidpp_battery_0/type": "Battery\n", "sys/class/power_supply/hidpp_battery_0/scope": "Device\n"})
	h, _ = mouse.Hardware(ctx)
	if h.HasBattery == nil || *h.HasBattery || h.ChassisType != "desktop" {
		t.Fatalf("a peripheral battery is not the machine's %+v", h)
	}
	pve := hwRoot(t, map[string]string{"sys/class/dmi/id/chassis_type": "23\n", "sys/class/dmi/id/product_name": "PowerEdge R650\n", "etc/pve/.version": "1"})
	h, _ = pve.Hardware(ctx)
	if h.ChassisType != "rack_server" || len(h.RoleHints) != 1 || h.RoleHints[0] != "hypervisor_host" || *h.IsVirtual {
		t.Fatalf("proxmox host %+v", h)
	}
	guest := hwRoot(t, map[string]string{"sys/class/dmi/id/chassis_type": "1\n", "sys/class/dmi/id/sys_vendor": "QEMU\n", "sys/class/dmi/id/product_name": "Standard PC (Q35 + ICH9, 2009)\n"})
	h, _ = guest.Hardware(ctx)
	if h.Virtualization != "qemu" || h.IsVirtual == nil || !*h.IsVirtual {
		t.Fatalf("kvm guest %+v", h)
	}
	guest.Run = func(context.Context, string, ...string) ([]byte, error) { return []byte("kvm\n"), nil }
	if h, _ = guest.Hardware(ctx); h.Virtualization != "kvm" {
		t.Fatalf("systemd wins %+v", h)
	}
	lxc := hwRoot(t, map[string]string{"proc/1/environ": "PATH=/bin\x00container=lxc\x00"})
	if h, _ = lxc.Hardware(ctx); h.Virtualization != "lxc" || !*h.IsVirtual {
		t.Fatalf("lxc %+v", h)
	}
	bare := hwRoot(t, nil)
	if h, _ = bare.Hardware(ctx); h.Virtualization != "" || h.ChassisType != "" {
		t.Fatalf("nothing readable must send nothing %+v", h)
	}
	garbage := hwRoot(t, map[string]string{"sys/class/dmi/id/chassis_type": "../../etc\n", "sys/class/dmi/id/product_name": "x\n"})
	if h, _ = garbage.Hardware(ctx); h.ChassisType != "" {
		t.Fatalf("garbage %+v", h)
	}
}
