package collect

// Pure parsers for the Linux /proc, /sys and systemd text formats. They carry
// no build tag and touch no files, so their table tests run on every host
// (including Windows CI); collector_linux.go does the actual reading.

import (
	"bufio"
	"encoding/binary"
	"errors"
	"fmt"
	"strconv"
	"strings"
)

// parseCPUStat returns idle(+iowait) and total jiffies from the aggregate
// "cpu" line of /proc/stat. Guest time is already part of user time.
func parseCPUStat(s string) (idle, total uint64, err error) {
	line, _, _ := strings.Cut(s, "\n")
	fs := strings.Fields(line)
	if len(fs) < 6 || fs[0] != "cpu" {
		return 0, 0, errors.New("unexpected /proc/stat")
	}
	for i, f := range fs[1:] {
		if i >= 8 { // user nice system idle iowait irq softirq steal
			break
		}
		v, perr := strconv.ParseUint(f, 10, 64)
		if perr != nil {
			return 0, 0, perr
		}
		total += v
		if i == 3 || i == 4 {
			idle += v
		}
	}
	return idle, total, nil
}

// parseMeminfo returns MemTotal and MemAvailable in bytes.
func parseMeminfo(s string) (total, avail uint64, err error) {
	var haveT, haveA bool
	for _, l := range strings.Split(s, "\n") {
		k, v, ok := strings.Cut(l, ":")
		if !ok {
			continue
		}
		f := strings.Fields(v)
		if len(f) == 0 {
			continue
		}
		n, perr := strconv.ParseUint(f[0], 10, 64)
		if perr != nil {
			continue
		}
		switch k {
		case "MemTotal":
			total, haveT = n*1024, true
		case "MemAvailable":
			avail, haveA = n*1024, true
		}
	}
	if !haveT || !haveA {
		return 0, 0, errors.New("MemTotal/MemAvailable missing")
	}
	return total, avail, nil
}

// virtualNetPrefixes are interface families that carry no physical traffic of
// the machine itself (loopback, container bridges, veth pairs).
var virtualNetPrefixes = []string{"lo", "docker", "veth", "br-", "virbr", "cni", "flannel", "cali", "kube"}

func isVirtualNet(name string) bool {
	for _, p := range virtualNetPrefixes {
		if name == p || (p != "lo" && strings.HasPrefix(name, p)) {
			return true
		}
	}
	return false
}

// parseNetDev sums received/transmitted bytes over every non-virtual interface.
func parseNetDev(s string) (rx, tx uint64, err error) {
	lines := strings.Split(s, "\n")
	if len(lines) < 3 {
		return 0, 0, errors.New("unexpected /proc/net/dev")
	}
	for _, l := range lines[2:] {
		name, rest, ok := strings.Cut(l, ":")
		if !ok || isVirtualNet(strings.TrimSpace(name)) {
			continue
		}
		f := strings.Fields(rest)
		if len(f) < 9 {
			continue
		}
		r, e1 := strconv.ParseUint(f[0], 10, 64)
		t, e2 := strconv.ParseUint(f[8], 10, 64)
		if e1 != nil || e2 != nil {
			continue
		}
		rx += r
		tx += t
	}
	return rx, tx, nil
}

func parseUptime(s string) (uint64, error) {
	f := strings.Fields(s)
	if len(f) == 0 {
		return 0, errors.New("empty /proc/uptime")
	}
	v, err := strconv.ParseFloat(f[0], 64)
	if err != nil || v < 0 {
		return 0, errors.New("bad /proc/uptime")
	}
	return uint64(v), nil
}

// parseOSRelease returns a display name (PRETTY_NAME, else NAME) and the VERSION_ID.
func parseOSRelease(s string) (name, ver string, err error) {
	var pretty, plain string
	for _, l := range strings.Split(s, "\n") {
		if v, ok := strings.CutPrefix(l, "PRETTY_NAME="); ok {
			pretty = unquote(v)
		}
		if v, ok := strings.CutPrefix(l, "NAME="); ok {
			plain = unquote(v)
		}
		if v, ok := strings.CutPrefix(l, "VERSION_ID="); ok {
			ver = unquote(v)
		}
	}
	name = pretty
	if name == "" {
		name = plain
	}
	if name == "" {
		return "", "", errors.New("no PRETTY_NAME or NAME in os-release")
	}
	return name, ver, nil
}

func unquote(v string) string {
	v = strings.TrimSpace(v)
	if len(v) >= 2 && (v[0] == '"' || v[0] == '\'') && v[len(v)-1] == v[0] {
		v = v[1 : len(v)-1]
	}
	return strings.NewReplacer(`\"`, `"`, `\\`, `\`, `\$`, `$`, "\\`", "`").Replace(v)
}

// parseCPUModel finds the CPU model in /proc/cpuinfo. x86 has "model name";
// arm64 usually has neither, so "Model" (Raspberry Pi), "Hardware" and
// "Processor" are tried in that order. "" means unknown.
func parseCPUModel(s string) string {
	found := map[string]string{}
	for _, l := range strings.Split(s, "\n") {
		k, v, ok := strings.Cut(l, ":")
		if !ok {
			continue
		}
		k, v = strings.TrimSpace(k), strings.TrimSpace(v)
		if v != "" && found[k] == "" {
			found[k] = v
		}
	}
	for _, k := range []string{"model name", "Model", "Hardware", "Processor", "cpu model", "cpu"} {
		if v := found[k]; v != "" {
			return v
		}
	}
	return ""
}

// MountEntry is one parsed /proc/mounts line.
type MountEntry struct{ Device, Mount, FS string }

// decodeMountField undoes the octal escapes (\040 for a space, ...) of /proc/mounts.
func decodeMountField(s string) string {
	if !strings.Contains(s, `\`) {
		return s
	}
	var b strings.Builder
	for i := 0; i < len(s); i++ {
		if s[i] == '\\' && i+3 < len(s) {
			if n, err := strconv.ParseUint(s[i+1:i+4], 8, 8); err == nil {
				b.WriteByte(byte(n))
				i += 3
				continue
			}
		}
		b.WriteByte(s[i])
	}
	return b.String()
}

var realFS = map[string]bool{"ext2": true, "ext3": true, "ext4": true, "xfs": true, "btrfs": true, "vfat": true, "ntfs": true, "ntfs3": true, "zfs": true, "f2fs": true, "exfat": true}

// parseMounts returns the mounts of real, block-backed file systems, one per
// device (a bind mount or a btrfs subvolume of the same device is skipped; the
// first mount wins, which is the shortest path on a normal system).
func parseMounts(s string) []MountEntry {
	var out []MountEntry
	seen := map[string]bool{}
	sc := bufio.NewScanner(strings.NewReader(s))
	for sc.Scan() {
		fs := strings.Fields(sc.Text())
		if len(fs) < 3 || !realFS[fs[2]] {
			continue
		}
		dev := decodeMountField(fs[0])
		if seen[dev] {
			continue
		}
		seen[dev] = true
		out = append(out, MountEntry{Device: dev, Mount: decodeMountField(fs[1]), FS: fs[2]})
	}
	return out
}

// junkDMI are placeholder values firmware vendors leave in DMI fields.
var junkDMI = map[string]bool{
	"": true, "to be filled by o.e.m.": true, "none": true, "default string": true, "system serial number": true,
	"not specified": true, "not available": true, "n/a": true, "unknown": true, "0": true, "123456789": true,
	"o.e.m.": true, "oem": true, "system product name": true, "system manufacturer": true, "all series": true,
	"chassis serial number": true, "base board serial number": true, "type1productconfigid": true, "not applicable": true,
}

// cleanDMI returns the trimmed value or "" when it is a known placeholder
// (all-same-character values such as 0000000 or FFFFFFFF included).
func cleanDMI(s string) string {
	s = strings.TrimSpace(s)
	if junkDMI[strings.ToLower(s)] {
		return ""
	}
	if len(s) >= 4 && strings.Trim(s, string(s[0])) == "" {
		return ""
	}
	return s
}

// firstDMI returns the first usable value of the candidates, in order.
func firstDMI(vals ...string) string {
	for _, v := range vals {
		if c := cleanDMI(v); c != "" {
			return c
		}
	}
	return ""
}

// ParseSystemctlShow turns `systemctl show --property=ActiveState,LoadState,UnitFileState`
// output into a ServiceInfo; ErrNotFound when the unit does not exist.
func parseSystemctlShow(out string) (ServiceInfo, error) {
	m := map[string]string{}
	for _, l := range strings.Split(out, "\n") {
		if k, v, ok := strings.Cut(l, "="); ok {
			m[k] = strings.TrimSpace(v)
		}
	}
	if m["LoadState"] == "" {
		return ServiceInfo{}, errors.New("systemctl returned no unit state")
	}
	if m["LoadState"] == "not-found" {
		return ServiceInfo{}, ErrNotFound
	}
	st := map[string]string{"active": "running", "inactive": "stopped", "failed": "stopped", "activating": "start_pending", "deactivating": "stop_pending", "reloading": "running"}[m["ActiveState"]]
	if st == "" {
		st = m["ActiveState"]
	}
	su := "manual"
	switch m["UnitFileState"] {
	case "enabled", "enabled-runtime", "static":
		if m["UnitFileState"] != "static" {
			su = "automatic"
		}
	case "disabled", "masked":
		su = "disabled"
	}
	return ServiceInfo{State: st, Startup: su}, nil
}

// validServiceName rejects anything that could be taken for an option or a path.
func validServiceName(n string) error {
	if n == "" || len(n) > 256 || strings.HasPrefix(n, "-") || strings.ContainsAny(n, " \t\r\n/;&|$`\\\"'<>") {
		return fmt.Errorf("invalid service name")
	}
	return nil
}

// parseUtmp returns the user names of the login sessions (USER_PROCESS records)
// in a glibc utmp file (struct utmp, 384 bytes per record on every Linux ABI
// the agent builds for). Duplicates are removed, order is preserved.
func parseUtmp(b []byte) []string {
	const recSize, typeUserProcess = 384, 7
	var out []string
	seen := map[string]bool{}
	for off := 0; off+recSize <= len(b); off += recSize {
		rec := b[off : off+recSize]
		if int16(binary.LittleEndian.Uint16(rec[0:2])) != typeUserProcess {
			continue
		}
		u := rec[44 : 44+32]
		if i := indexZero(u); i >= 0 {
			u = u[:i]
		}
		name := string(u)
		if name != "" && !seen[name] {
			seen[name] = true
			out = append(out, name)
		}
	}
	return out
}

func indexZero(b []byte) int {
	for i, c := range b {
		if c == 0 {
			return i
		}
	}
	return -1
}
