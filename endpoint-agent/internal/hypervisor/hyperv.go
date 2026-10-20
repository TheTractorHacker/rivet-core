package hypervisor

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"time"

	"rivetit-agent/internal/api"
)

// HyperV collects from a Hyper-V host with fixed PowerShell (see hyperv_scripts.go).
type HyperV struct {
	R          Runner
	SystemRoot string // default %SystemRoot%
	Now        func() time.Time
	Hostname   func() string
}

func (h *HyperV) Platform() string { return PlatformHyperV }

func (h *HyperV) now() time.Time {
	if h.Now != nil {
		return h.Now()
	}
	return time.Now()
}

func (h *HyperV) root() string {
	if h.SystemRoot != "" {
		return h.SystemRoot
	}
	return os.Getenv("SystemRoot")
}

func (h *HyperV) psExe() string {
	if r := h.root(); r != "" {
		return filepath.Join(r, "System32", "WindowsPowerShell", "v1.0", "powershell.exe")
	}
	return "powershell.exe"
}

func (h *HyperV) scExe() string {
	if r := h.root(); r != "" {
		return filepath.Join(r, "System32", "sc.exe")
	}
	return "sc.exe"
}

func (h *HyperV) ps(ctx context.Context, timeout time.Duration, script string) ([]byte, error) {
	return run(ctx, h.R, timeout, h.psExe(), "-NoProfile", "-NonInteractive", "-Command", script)
}

// DetectHyperV reports whether the vmms service exists and the Hyper-V module is installed.
func DetectHyperV(ctx context.Context, h *HyperV) (Collector, error) {
	out, err := run(ctx, h.R, 10*time.Second, h.scExe(), "query", "vmms")
	if err != nil || !strings.Contains(strings.ToLower(string(out)), "vmms") {
		return nil, ErrNotHost // exit 1060: the service does not exist
	}
	out, err = h.ps(ctx, 30*time.Second, psDetect)
	if err != nil || !strings.Contains(string(out), "yes") {
		return nil, ErrNotHost
	}
	return h, nil
}

func (h *HyperV) States(ctx context.Context, o Options) (map[string]string, error) {
	out, err := h.ps(ctx, 30*time.Second, psStates)
	if err != nil {
		return nil, err
	}
	items, ok := decodeArray(out)
	if !ok {
		return nil, fmt.Errorf("unreadable Get-VM output")
	}
	m := map[string]string{}
	for _, it := range items {
		if id := vmGUID(str(it["id"])); id != "" {
			m[id] = hvState(str(it["state"]))
		}
	}
	return m, nil
}

func (h *HyperV) Full(ctx context.Context, o Options) (*api.HypervisorReport, error) {
	ctx, cancel := context.WithTimeout(ctx, 3*time.Minute)
	defer cancel()
	if o.MaxVMs <= 0 || o.MaxVMs > MaxVMs {
		o.MaxVMs = MaxVMs
	}
	rep := &api.HypervisorReport{Mode: "full", Platform: PlatformHyperV, CollectedAt: h.now().UTC().Format(time.RFC3339)}
	out, err := h.ps(ctx, 150*time.Second, psFull)
	if err != nil {
		rep.Error = cleanText("Get-VM: "+errText(err), MaxErrorLen)
		return rep, fmt.Errorf("%s", rep.Error)
	}
	ver, vms, vols, problems, perr := parseHyperV(out, h.hostName())
	if perr != nil {
		rep.Error = cleanText(perr.Error(), MaxErrorLen)
		return rep, perr
	}
	rep.Version = ver
	if len(vms) > o.MaxVMs {
		vms, rep.Truncated = vms[:o.MaxVMs], true
	}
	rep.VMs, rep.Storage = vms, vols
	if cout, cerr := run(ctx, h.R, 10*time.Second, h.scExe(), "query", "ClusSvc"); cerr == nil && strings.Contains(strings.ToLower(string(cout)), "clussvc") {
		if out, err := h.ps(ctx, 60*time.Second, psCluster); err != nil {
			problems = append(problems, "cluster: "+errText(err))
		} else if cl, msg := parseHyperVCluster(out); msg != "" {
			problems = append(problems, "cluster: "+msg)
		} else {
			rep.Cluster = cl
		}
	}
	if len(problems) > 0 {
		rep.Error = cleanText(strings.Join(problems, "; "), MaxErrorLen)
	}
	return rep, nil
}

func (h *HyperV) hostName() string {
	if h.Hostname != nil {
		return strings.ToUpper(strings.SplitN(h.Hostname(), ".", 2)[0])
	}
	n, _ := os.Hostname()
	return strings.ToUpper(strings.SplitN(n, ".", 2)[0])
}

// ---- parsing ----

func vmGUID(s string) string {
	s = strings.ToLower(strings.TrimSpace(s))
	if len(s) != 36 {
		return ""
	}
	for i, r := range s {
		switch {
		case i == 8 || i == 13 || i == 18 || i == 23:
			if r != '-' {
				return ""
			}
		case !(r >= '0' && r <= '9' || r >= 'a' && r <= 'f'):
			return ""
		}
	}
	return s
}

func hvState(s string) string {
	l := strings.ToLower(strings.TrimSpace(s))
	switch {
	case strings.Contains(l, "critical"):
		return StateError
	case l == "running":
		return StateRunning
	case l == "off":
		return StateStopped
	case l == "paused":
		return StatePaused
	case l == "saved":
		return StateSuspended
	case l == "starting" || l == "stopping" || l == "saving" || l == "pausing" || l == "resuming" || l == "reset" || l == "snapshotting" || l == "deleting" || l == "waiting":
		return StateTransitioning
	}
	return StateUnknown
}

func replHealth(s string) string {
	switch strings.ToLower(strings.TrimSpace(s)) {
	case "normal":
		return "normal"
	case "warning":
		return "warning"
	case "critical":
		return "critical"
	}
	return ""
}

// parseHyperV reads the object psFull prints.
func parseHyperV(b []byte, host string) (version string, vms []api.HypervisorVM, vols []api.HypervisorStore, problems []string, err error) {
	m, ok := decodeObject(b)
	if !ok {
		return "", nil, nil, nil, fmt.Errorf("the Hyper-V script printed unreadable output")
	}
	if !truthy(m["ok"]) {
		msg := cleanText(str(m["error"]), 200)
		if msg == "" {
			msg = "the Hyper-V script failed"
		}
		return "", nil, nil, nil, fmt.Errorf("%s", msg)
	}
	version = cleanText(str(m["version"]), MaxVersionLen)
	for _, e := range anyList(m["errors"]) {
		if s := cleanText(str(e), 120); s != "" && len(problems) < 3 {
			problems = append(problems, s)
		}
	}
	for _, it := range anyMaps(m["vms"]) {
		id := vmGUID(str(it["id"]))
		if id == "" {
			continue
		}
		v := api.HypervisorVM{ID: id, Type: "hyperv", Node: cleanText(host, MaxNodeLen)}
		v.Name = cleanText(str(it["name"]), MaxNameLen)
		if v.Name == "" {
			v.Name = id
		}
		v.State = hvState(str(it["state"]))
		if f, ok := num(it["gen"]); ok && f >= 1 && f <= 2 {
			v.Generation = int(f)
		}
		if f, ok := num(it["cpus"]); ok && f >= 0 && f <= 4096 {
			v.CPUs = int(f)
		}
		running := v.State == StateRunning
		if f, ok := num(it["cpu"]); ok && running {
			if c, ok := pct(f, false); ok {
				v.CPUPct = &c
			}
		}
		if f, ok := num(it["memA"]); ok && running {
			if n, ok := clampBytes(f); ok {
				v.MemBytes = &n
			}
		}
		if f, ok := num(it["memMax"]); ok {
			if n, ok := clampBytes(f); ok && n > 0 {
				v.MemMaxBytes = &n
			}
		}
		if f, ok := num(it["up"]); ok && running {
			if n, ok := clampUptime(f); ok {
				v.UptimeS = &n
			}
		}
		if f, ok := num(it["disk"]); ok {
			if n, ok := clampBytes(f); ok && n > 0 {
				v.DiskBytes = &n
				if u, ok := num(it["diskUsed"]); ok {
					if un, ok := clampBytes(u); ok {
						v.DiskUsed = &un
					}
				}
			}
		}
		if f, ok := num(it["snaps"]); ok && f >= 0 {
			c := int(min(f, 1000))
			v.Snapshots = &api.HypervisorSnapshots{Count: c}
			if o, ok := num(it["oldest"]); ok {
				v.Snapshots.OldestAt = isoUTC(int64(o))
			}
		}
		if running {
			if hb := strings.TrimSpace(str(it["hb"])); hb != "" {
				v.GuestAgent = ptr(strings.HasPrefix(strings.ToLower(hb), "ok"))
			}
		}
		v.Replication = replHealth(str(it["repl"]))
		vms = append(vms, v)
	}
	for _, it := range anyMaps(m["vols"]) {
		name := cleanText(str(it["name"]), 8)
		t, ok1 := num(it["total"])
		fr, ok2 := num(it["free"])
		if name == "" || !ok1 || !ok2 {
			continue
		}
		total, _ := clampBytes(t)
		free, _ := clampBytes(fr)
		free = min(free, total)
		vols = append(vols, api.HypervisorStore{Name: name, Type: "volume", TotalBytes: total, UsedBytes: total - free, Health: "ok"})
	}
	return version, vms, vols, problems, nil
}

func parseHyperVCluster(b []byte) (*api.HypervisorCluster, string) {
	m, ok := decodeObject(b)
	if !ok {
		return nil, "unreadable output"
	}
	if !truthy(m["ok"]) {
		return nil, cleanText(str(m["error"]), 120)
	}
	cl := &api.HypervisorCluster{Name: cleanText(str(m["name"]), MaxNameLen)}
	up, total := 0, 0
	for _, it := range anyMaps(m["nodes"]) {
		n := cleanText(str(it["name"]), MaxNodeLen)
		if n == "" {
			continue
		}
		total++
		on := strings.EqualFold(str(it["state"]), "up") || str(it["state"]) == "0"
		if on {
			up++
		}
		if len(cl.Nodes) < MaxNodes {
			cl.Nodes = append(cl.Nodes, api.HypervisorNode{Name: n, Online: on})
		}
	}
	if total > 0 {
		cl.Quorate = ptr(up*2 > total) // inferred: a majority of nodes is up
	}
	if f, ok := num(m["failed"]); ok && f > 0 {
		cl.FailedGroups = int(min(f, 10000))
	}
	return cl, ""
}

func anyList(v any) []any {
	switch x := v.(type) {
	case []any:
		return x
	case nil:
		return nil
	}
	return []any{v} // a single element unwrapped by ConvertTo-Json
}

func anyMaps(v any) []map[string]any {
	var out []map[string]any
	for _, e := range anyList(v) {
		if m, ok := e.(map[string]any); ok {
			out = append(out, m)
		}
	}
	return out
}
