package collect

import (
	"encoding/json"
	"strings"
	"testing"

	"rivetit-agent/internal/api"
)

func TestChassisFromSMBIOS(t *testing.T) {
	for code, want := range map[int]string{3: "desktop", 4: "desktop", 6: "tower", 7: "tower", 8: "laptop", 9: "laptop", 10: "laptop", 14: "laptop", 31: "laptop", 13: "all_in_one",
		17: "server", 23: "rack_server", 5: "rack_server", 28: "blade", 30: "tablet", 35: "mini_pc", 1: "other", 2: "", 0: "", 999: "", -4: ""} {
		if got := ChassisFromSMBIOS(code); got != want {
			t.Errorf("chassis %d = %q, want %q", code, got, want)
		}
	}
}

func TestVirtFromStrings(t *testing.T) {
	cases := []struct{ man, model, want string }{
		{"VMware, Inc.", "VMware7,1", "vmware"}, {"VMware, Inc.", "VMware Virtual Platform", "vmware"},
		{"Microsoft Corporation", "Virtual Machine", "hyperv"}, {"Microsoft Corporation", "Surface Laptop 5", ""},
		{"QEMU", "Standard PC (Q35 + ICH9, 2009)", "qemu"}, {"", "Standard PC (i440FX + PIIX, 1996)", "kvm"}, {"Red Hat", "KVM", "kvm"},
		{"innotek GmbH", "VirtualBox", "virtualbox"}, {"Xen", "HVM domU", "xen"}, {"Parallels Software International Inc.", "Parallels Virtual Platform", "parallels"},
		{"Dell Inc.", "Latitude 7440", ""}, {"Dell Inc.", "PowerEdge R650", ""}, {"Xenon Computers", "X1", ""}, {"", "", ""},
		{"vmware\x00; DROP TABLE", "x", "vmware"},
	}
	for _, c := range cases {
		if got := VirtFromStrings(c.man, c.model); got != c.want {
			t.Errorf("%q/%q = %q, want %q", c.man, c.model, got, c.want)
		}
	}
}

func TestVirtFromSystemdAndEnviron(t *testing.T) {
	for in, want := range map[string]string{"none\n": "none", "kvm": "kvm", "microsoft": "hyperv", "oracle": "virtualbox", "lxc": "lxc", "docker": "container", "wsl": "container", "bochs": "other", "": "", "weird": "other"} {
		if got := VirtFromSystemd(in); got != want {
			t.Errorf("systemd %q = %q, want %q", in, got, want)
		}
	}
	if VirtFromContainerEnv("PATH=/bin\x00container=lxc\x00HOME=/") != "lxc" || VirtFromContainerEnv("container=podman\x00") != "container" || VirtFromContainerEnv("PATH=/bin\x00") != "" || VirtFromContainerEnv("container=\x00") != "" {
		t.Error("container environ")
	}
}

func TestFinishDropsHostileAndUnknown(t *testing.T) {
	h := Hardware{ChassisType: "spaceship", Virtualization: "<script>", RoleHints: []string{"hypervisor_host", "hypervisor_host", "root", "domain_controller", strings.Repeat("x", 9999)}}.Finish()
	if h.ChassisType != "" || h.Virtualization != "" || h.IsVirtual != nil || len(h.RoleHints) != 2 {
		t.Fatalf("%+v", h)
	}
	f := false
	if g := (Hardware{IsVirtual: &f, Virtualization: "kvm"}).Finish(); g.Virtualization != "none" {
		t.Fatalf("not virtual must read none: %+v", g)
	}
}

func TestParseWinHardware(t *testing.T) {
	h, ok := ParseWinHardware(`{"chassis":[10],"battery":1,"services":[],"hvguest":false}`, "Dell Inc.", "Latitude 7440")
	if !ok || h.ChassisType != "laptop" || h.HasBattery == nil || !*h.HasBattery || h.Virtualization != "none" || h.IsVirtual == nil || *h.IsVirtual {
		t.Fatalf("laptop %+v", h)
	}
	h, _ = ParseWinHardware(`{"chassis":[3],"battery":0,"services":["NTDS"],"hvguest":false}`, "Dell Inc.", "OptiPlex")
	if h.ChassisType != "desktop" || *h.HasBattery || len(h.RoleHints) != 1 || h.RoleHints[0] != "domain_controller" {
		t.Fatalf("dc %+v", h)
	}
	h, _ = ParseWinHardware(`{"chassis":[3],"battery":0,"services":["vmms"],"hvguest":false}`, "HPE", "ProLiant DL380")
	if h.RoleHints[0] != "hypervisor_host" || *h.IsVirtual {
		t.Fatalf("hv host %+v", h)
	}
	h, _ = ParseWinHardware(`{"chassis":[1],"battery":0,"services":[],"hvguest":true}`, "Microsoft Corporation", "Virtual Machine")
	if h.Virtualization != "hyperv" || !*h.IsVirtual {
		t.Fatalf("guest %+v", h)
	}
	for _, bad := range []string{"", "nope", "[1]", `{"chassis":"x"}`} {
		if _, ok := ParseWinHardware(bad, "", ""); ok && bad != "[1]" {
			t.Errorf("accepted %q", bad)
		}
	}
}

func TestOmittedWhenUnknown(t *testing.T) {
	b, _ := json.Marshal(api.Device{InstallID: "x"})
	for _, k := range []string{"chassis_type", "has_battery", "is_virtual", "virtualization", "role_hints"} {
		if strings.Contains(string(b), k) {
			t.Errorf("%s sent without data: %s", k, b)
		}
	}
}
