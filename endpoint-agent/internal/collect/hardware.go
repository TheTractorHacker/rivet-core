package collect

import (
	"context"
	"encoding/json"
	"errors"
	"strconv"
	"strings"
	"time"

	"rivetit-agent/internal/api"
)

// Device-type facts (rc.29). Everything here is pure (no files, no processes) so the tables are tested on every host;
// hardware_linux.go and hardware_windows.go do the reading. A fact that cannot be read is left out, never guessed.

// Closed vocabularies the server validates against; a value outside them is dropped before it is sent.
var (
	chassisVocab  = map[string]bool{"laptop": true, "desktop": true, "tower": true, "all_in_one": true, "mini_pc": true, "server": true, "rack_server": true, "blade": true, "tablet": true, "other": true}
	virtVocab     = map[string]bool{"vmware": true, "hyperv": true, "kvm": true, "qemu": true, "xen": true, "virtualbox": true, "parallels": true, "lxc": true, "container": true, "other": true, "none": true}
	roleHintVocab = map[string]bool{"hypervisor_host": true, "domain_controller": true}
)

// Hardware is what a platform can say about the box it runs on.
type Hardware struct {
	ChassisType    string // chassisVocab or ""
	HasBattery     *bool
	IsVirtual      *bool
	Virtualization string // virtVocab or ""
	RoleHints      []string
}

// HardwarePlatform is OPTIONAL on a Platform: without it no device-type fact is sent (an old server or a bare Platform is unaffected).
type HardwarePlatform interface {
	Hardware(ctx context.Context) (Hardware, error)
}

// ChassisFromSMBIOS maps an SMBIOS "System Enclosure" type code (DMTF DSP0134 table 17) to the small vocabulary; "" for unknown (2) or out of range.
func ChassisFromSMBIOS(code int) string {
	switch code {
	case 3, 4, 15, 16, 24:
		return "desktop"
	case 6, 7:
		return "tower"
	case 5, 23:
		return "rack_server"
	case 17, 25:
		return "server"
	case 8, 9, 10, 14, 31, 32:
		return "laptop"
	case 11, 30:
		return "tablet"
	case 13:
		return "all_in_one"
	case 28, 29:
		return "blade"
	case 35, 36:
		return "mini_pc"
	case 1, 12, 18, 19, 20, 21, 22, 26, 27, 33, 34:
		return "other"
	}
	return ""
}

// VirtFromStrings recognises a hypervisor guest from the firmware vendor and product strings ("" when they look like real hardware).
func VirtFromStrings(manufacturer, model string) string {
	m := strings.ToLower(manufacturer + " | " + model)
	has := func(s ...string) bool {
		for _, x := range s {
			if strings.Contains(m, x) {
				return true
			}
		}
		return false
	}
	switch {
	case has("vmware"):
		return "vmware"
	case has("virtualbox", "innotek"):
		return "virtualbox"
	case has("parallels"):
		return "parallels"
	case has("xen") && !has("xenon"):
		return "xen"
	case has("microsoft") && has("virtual machine"):
		return "hyperv"
	case has("qemu"):
		return "qemu"
	case has("kvm", "rhev", "bochs", "standard pc (q35", "standard pc (i440fx", "openstack", "amazon ec2", "google compute engine", "digitalocean", "hetzner vserver"):
		return "kvm"
	}
	return ""
}

// VirtFromSystemd maps `systemd-detect-virt` output.
func VirtFromSystemd(out string) string {
	switch strings.ToLower(strings.TrimSpace(out)) {
	case "none":
		return "none"
	case "kvm":
		return "kvm"
	case "qemu":
		return "qemu"
	case "vmware":
		return "vmware"
	case "microsoft":
		return "hyperv"
	case "oracle":
		return "virtualbox"
	case "xen":
		return "xen"
	case "parallels":
		return "parallels"
	case "lxc", "lxc-libvirt":
		return "lxc"
	case "docker", "podman", "openvz", "wsl", "systemd-nspawn", "rkt", "container-other":
		return "container"
	case "":
		return ""
	}
	return "other"
}

// VirtFromContainerEnv recognises PID 1's environment (/proc/1/environ, NUL separated) of a container.
func VirtFromContainerEnv(environ string) string {
	for _, kv := range strings.Split(environ, "\x00") {
		if v, ok := strings.CutPrefix(kv, "container="); ok {
			if v == "lxc" || v == "lxc-libvirt" {
				return "lxc"
			}
			if v != "" {
				return "container"
			}
		}
	}
	return ""
}

// Finish validates and normalises: unknown vocabulary is dropped, is_virtual follows the virtualization, role hints are de-duplicated.
func (h Hardware) Finish() Hardware {
	if !chassisVocab[h.ChassisType] {
		h.ChassisType = ""
	}
	if !virtVocab[h.Virtualization] {
		h.Virtualization = ""
	}
	if h.Virtualization != "" && h.IsVirtual == nil {
		v := h.Virtualization != "none"
		h.IsVirtual = &v
	}
	if h.Virtualization == "none" || (h.IsVirtual != nil && !*h.IsVirtual) {
		h.Virtualization = "none"
	}
	seen := map[string]bool{}
	var roles []string
	for _, r := range h.RoleHints {
		if roleHintVocab[r] && !seen[r] && len(roles) < 4 {
			seen[r] = true
			roles = append(roles, r)
		}
	}
	h.RoleHints = roles
	return h
}

// ParseWinHardware parses the JSON of the fixed Windows snippet: {"chassis":[3],"battery":0,"services":["NTDS","vmms"],"hvguest":false}
// together with the manufacturer and model already read.
func ParseWinHardware(out, manufacturer, model string) (Hardware, bool) {
	var v struct {
		Chassis  []int    `json:"chassis"`
		Battery  *int     `json:"battery"`
		Services []string `json:"services"`
		HVGuest  bool     `json:"hvguest"`
	}
	if json.Unmarshal([]byte(strings.TrimSpace(out)), &v) != nil {
		return Hardware{}, false
	}
	var h Hardware
	if len(v.Chassis) > 0 {
		h.ChassisType = ChassisFromSMBIOS(v.Chassis[0])
	}
	if v.Battery != nil {
		b := *v.Battery > 0
		h.HasBattery = &b
	}
	h.Virtualization = VirtFromStrings(manufacturer, model)
	if h.Virtualization == "" && v.HVGuest {
		h.Virtualization = "hyperv"
	}
	if h.Virtualization == "" {
		h.Virtualization = "none"
	}
	for _, s := range v.Services {
		switch strings.ToLower(s) {
		case "vmms":
			h.RoleHints = append(h.RoleHints, "hypervisor_host")
		case "ntds":
			h.RoleHints = append(h.RoleHints, "domain_controller")
		}
	}
	return h.Finish(), true
}

// parseChassisFile reads the content of /sys/class/dmi/id/chassis_type.
func parseChassisFile(s string) string {
	n, err := strconv.Atoi(strings.TrimSpace(s))
	if err != nil {
		return ""
	}
	return ChassisFromSMBIOS(n)
}

// hardware reads the facts to the enrollment block / inventory through one bounded, failure-tolerant call.
func (c *Collector) hardware(ctx context.Context) (Hardware, bool) {
	hp, ok := c.P.(HardwarePlatform)
	if !ok {
		return Hardware{}, false
	}
	h, err := call(c, ctx, "hardware", 30*time.Second, hp.Hardware)
	if err != nil {
		return Hardware{}, false
	}
	return h.Finish(), true
}

func (h Hardware) applyDevice(d *api.Device) {
	d.ChassisType, d.HasBattery, d.IsVirtual, d.Virtualization, d.RoleHints = h.ChassisType, h.HasBattery, h.IsVirtual, h.Virtualization, h.RoleHints
}

func (h Hardware) applyInventory(i *api.Inventory) {
	i.ChassisType, i.HasBattery, i.IsVirtual, i.Virtualization, i.RoleHints = h.ChassisType, h.HasBattery, h.IsVirtual, h.Virtualization, h.RoleHints
}

var errBadHardware = errors.New("hardware: unparseable output")
