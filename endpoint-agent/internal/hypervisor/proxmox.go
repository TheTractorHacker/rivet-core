package hypervisor

import (
	"context"
	"errors"
	"fmt"
	"os"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"time"

	"rivetit-agent/internal/api"
)

// The fixed command set of the Proxmox VE collector. Every argument is a
// literal here except a node name and a VM id, which have passed reNode and
// reVMID first. Nothing is run through a shell. All of them are read-only.
const (
	pveshPath  = "/usr/bin/pvesh"
	pveVerPath = "/usr/bin/pveversion"
	pveDir     = "/etc/pve"
)

var (
	zpoolPaths = []string{"/usr/sbin/zpool", "/sbin/zpool", "/usr/bin/zpool"}
	cephPaths  = []string{"/usr/bin/ceph"}

	reNode = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9-]{0,62}$`)
	reVMID = regexp.MustCompile(`^[1-9][0-9]{0,8}$`)
	reStor = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9._-]{0,47}$`)
)

// Proxmox collects from a Proxmox VE host with pvesh, zpool and ceph.
type Proxmox struct {
	R        Runner
	Exists   func(path string) bool // file or directory present (default os.Stat)
	Hostname func() string
	Now      func() time.Time

	snapOff int // rotates the snapshot look-ups so every guest is reached over several collections
}

func (p *Proxmox) exists(path string) bool {
	if p.Exists != nil {
		return p.Exists(path)
	}
	_, err := os.Stat(path)
	return err == nil
}

func (p *Proxmox) now() time.Time {
	if p.Now != nil {
		return p.Now()
	}
	return time.Now()
}

func (p *Proxmox) Platform() string { return PlatformProxmox }

func (p *Proxmox) first(paths []string) string {
	for _, x := range paths {
		if p.exists(x) {
			return x
		}
	}
	return ""
}

// DetectProxmox reports whether this is a Proxmox VE host: /usr/bin/pvesh and
// /etc/pve exist, or pveversion answers "pve-manager/...".
func DetectProxmox(ctx context.Context, p *Proxmox) (Collector, error) {
	if p.exists(pveshPath) && p.exists(pveDir) {
		return p, nil
	}
	if p.exists(pveVerPath) {
		out, err := run(ctx, p.R, 10*time.Second, pveVerPath)
		if err == nil && strings.HasPrefix(strings.TrimSpace(string(out)), "pve-manager/") && p.exists(pveshPath) {
			return p, nil
		}
	}
	return nil, ErrNotHost
}

func (p *Proxmox) pvesh(ctx context.Context, timeout time.Duration, path string, extra ...string) ([]byte, error) {
	args := append([]string{"get", path}, extra...)
	args = append(args, "--output-format", "json")
	return run(ctx, p.R, timeout, pveshPath, args...)
}

// States is one cheap call: the state of every guest.
func (p *Proxmox) States(ctx context.Context, o Options) (map[string]string, error) {
	out, err := p.pvesh(ctx, cmdTimeout, "/cluster/resources", "--type", "vm")
	if err != nil {
		return nil, err
	}
	vms, _ := parseResources(out)
	m := make(map[string]string, len(vms))
	for _, v := range vms {
		m[v.ID] = v.State
	}
	return m, nil
}

// Full reads the guests, storage, cluster, backups, snapshots, ZFS and Ceph.
func (p *Proxmox) Full(ctx context.Context, o Options) (*api.HypervisorReport, error) {
	ctx, cancel := context.WithTimeout(ctx, 3*time.Minute)
	defer cancel()
	if o.MaxVMs <= 0 || o.MaxVMs > MaxVMs {
		o.MaxVMs = MaxVMs
	}
	rep := &api.HypervisorReport{Mode: "full", Platform: PlatformProxmox, CollectedAt: p.now().UTC().Format(time.RFC3339)}
	var problems []string
	fail := func(what string, err error) {
		problems = append(problems, what+": "+errText(err))
	}

	if out, err := p.pvesh(ctx, 10*time.Second, "/version"); err != nil {
		fail("version", err)
	} else {
		rep.Version = parseVersion(out)
	}
	var local string
	var onlineNodes []string
	if out, err := p.pvesh(ctx, 10*time.Second, "/cluster/status"); err != nil {
		fail("cluster status", err)
	} else {
		rep.Cluster, local, onlineNodes = parseClusterStatus(out)
	}
	if local == "" && p.Hostname != nil {
		if h := strings.ToLower(strings.SplitN(p.Hostname(), ".", 2)[0]); reNode.MatchString(h) {
			local = h
		}
	}

	out, err := p.pvesh(ctx, cmdTimeout, "/cluster/resources", "--type", "vm")
	if err != nil {
		rep.Error = cleanText("guests: "+errText(err), MaxErrorLen)
		return rep, errors.New(rep.Error)
	}
	vms, bad := parseResources(out)
	if bad > 0 {
		problems = append(problems, fmt.Sprintf("%d guest entries could not be read", bad))
	}
	sort.Slice(vms, func(i, j int) bool { return vms[i].sortKey() < vms[j].sortKey() })
	if len(vms) > o.MaxVMs {
		vms, rep.Truncated = vms[:o.MaxVMs], true
	}

	// storage of the local node (shared storage appears under every node; the local one is enough)
	var backupStores []string
	if local != "" {
		if out, err := p.pvesh(ctx, cmdTimeout, "/nodes/"+local+"/storage"); err != nil {
			fail("storage", err)
		} else {
			var st []storageInfo
			st = parseStorage(out)
			for _, s := range st {
				rep.Storage = append(rep.Storage, s.HypervisorStore)
				if s.backup && s.Health == "ok" && reStor.MatchString(s.Name) {
					backupStores = append(backupStores, s.Name)
				}
			}
		}
	} else {
		problems = append(problems, "storage: the local node name is unknown")
	}
	p.appendPools(ctx, rep, &problems)

	// backups: which guests a job covers, vzdump tasks (per guest), and the newest backup volume per guest
	covered := map[string]bool{}
	haveCover := false
	if out, err := p.pvesh(ctx, 10*time.Second, "/cluster/backup-info/not-backed-up"); err == nil {
		not := parseNotBackedUp(out)
		haveCover = true
		for _, v := range vms {
			if !not[v.vmid] {
				covered[v.vmid] = true
			}
		}
	}
	taskRes := map[string]backupTask{}
	nodes := onlineNodes
	if len(nodes) == 0 && local != "" {
		nodes = []string{local}
	}
	for i, n := range nodes {
		if i >= 8 || !reNode.MatchString(n) {
			break
		}
		out, err := p.pvesh(ctx, cmdTimeout, "/nodes/"+n+"/tasks", "--typefilter", "vzdump", "--limit", "20")
		if err != nil {
			fail("backup tasks of "+n, err)
			continue
		}
		for id, t := range parseBackupTasks(out) {
			if cur, ok := taskRes[id]; !ok || t.end > cur.end {
				taskRes[id] = t
			}
		}
	}
	newest := map[string]int64{}
	for i, s := range backupStores {
		if i >= 6 || local == "" {
			break
		}
		out, err := p.pvesh(ctx, cmdTimeout, "/nodes/"+local+"/storage/"+s+"/content", "--content", "backup")
		if err != nil {
			continue // a backup target that is offline is already visible as storage health
		}
		for id, ct := range parseBackupVolumes(out) {
			if ct > newest[id] {
				newest[id] = ct
			}
		}
	}

	// snapshots: bounded look-ups, rotated
	budget := o.SnapshotLookups
	if budget <= 0 || budget > MaxSnapshotCalls {
		budget = MaxSnapshotCalls
	}
	cand := make([]int, 0, len(vms))
	for i, v := range vms {
		if !v.Template && reNode.MatchString(v.Node) {
			cand = append(cand, i)
		}
	}
	snapOK, snapFail := 0, 0
	if len(cand) > 0 {
		start := p.snapOff % len(cand)
		done := 0
		for k := 0; k < len(cand) && done < budget; k++ {
			if ctx.Err() != nil {
				break
			}
			v := &vms[cand[(start+k)%len(cand)]]
			out, err := p.pvesh(ctx, 5*time.Second, "/nodes/"+v.Node+"/"+v.Type+"/"+v.vmid+"/snapshot")
			done++
			if err != nil {
				snapFail++
				continue
			}
			c, oldest := parseSnapshots(out)
			v.Snapshots = &api.HypervisorSnapshots{Count: c, OldestAt: oldest}
			snapOK++
		}
		p.snapOff = (start + done) % len(cand)
		if done < len(cand) {
			rep.Truncated = true
		}
	}
	if snapFail > 0 {
		problems = append(problems, fmt.Sprintf("snapshots: %d look-ups failed", snapFail))
	}

	for i := range vms {
		v := &vms[i]
		if haveCover {
			v.BackupJob = ptr(covered[v.vmid])
		}
		var at int64
		res := ""
		if ct := newest[v.vmid]; ct > 0 {
			at, res = ct, "ok"
		}
		if t, ok := taskRes[v.vmid]; ok && t.end >= at {
			at, res = t.end, t.result
		}
		if at > 0 {
			v.LastBackup = &api.HypervisorBackup{At: isoUTC(at), Result: res}
		}
		rep.VMs = append(rep.VMs, v.HypervisorVM)
	}
	if len(problems) > 0 {
		rep.Error = cleanText(strings.Join(problems, "; "), MaxErrorLen)
	}
	return rep, nil
}

func errText(err error) string {
	if err == nil {
		return ""
	}
	return cleanText(err.Error(), 120)
}

func (p *Proxmox) appendPools(ctx context.Context, rep *api.HypervisorReport, problems *[]string) {
	if zp := p.first(zpoolPaths); zp != "" {
		out, err := run(ctx, p.R, 15*time.Second, zp, "list", "-H", "-p", "-o", "name,size,allocated,free,health")
		if err != nil {
			*problems = append(*problems, "zpool: "+errText(err))
		} else {
			rep.Storage = append(rep.Storage, parseZpool(out)...)
		}
	}
	if cp := p.first(cephPaths); cp != "" {
		out, err := run(ctx, p.R, 15*time.Second, cp, "health", "--format", "json")
		if err != nil {
			*problems = append(*problems, "ceph: "+errText(err))
		} else if h, ok := parseCephHealth(out); ok {
			rep.Storage = append(rep.Storage, api.HypervisorStore{Name: "ceph", Type: "ceph", Health: h})
		}
	}
	if len(rep.Storage) > MaxStorage {
		rep.Storage, rep.Truncated = rep.Storage[:MaxStorage], true
	}
}

// ---- parsing (pure functions, unit-tested against real-looking pvesh output) ----

type pveVM struct {
	api.HypervisorVM
	vmid string
}

func (v pveVM) sortKey() string {
	n, _ := strconv.Atoi(v.vmid)
	return fmt.Sprintf("%012d%s", n, v.Type)
}

func parseVersion(b []byte) string {
	m, ok := decodeObject(b)
	if !ok {
		return ""
	}
	return cleanText(str(m["version"]), MaxVersionLen)
}

// parseClusterStatus returns the cluster (nil for a stand-alone node), the local
// node name and the online node names.
func parseClusterStatus(b []byte) (*api.HypervisorCluster, string, []string) {
	items, ok := decodeArray(b)
	if !ok {
		return nil, "", nil
	}
	var cl *api.HypervisorCluster
	var local string
	var online []string
	var nodes []api.HypervisorNode
	for _, it := range items {
		switch str(it["type"]) {
		case "cluster":
			cl = &api.HypervisorCluster{Name: cleanText(str(it["name"]), MaxNameLen)}
			if _, has := it["quorate"]; has {
				cl.Quorate = ptr(truthy(it["quorate"]))
			}
		case "node":
			n := cleanText(str(it["name"]), MaxNodeLen)
			if !reNode.MatchString(n) {
				continue
			}
			on := truthy(it["online"])
			if truthy(it["local"]) {
				local = n
			}
			if on {
				online = append(online, n)
			}
			if len(nodes) < MaxNodes {
				nodes = append(nodes, api.HypervisorNode{Name: n, Online: on})
			}
		}
	}
	if cl != nil {
		cl.Nodes = nodes
	}
	return cl, local, online
}

func stateOf(s string) string {
	switch strings.ToLower(strings.TrimSpace(s)) {
	case "running":
		return StateRunning
	case "stopped":
		return StateStopped
	case "paused":
		return StatePaused
	case "suspended":
		return StateSuspended
	case "prelaunch", "starting", "stopping":
		return StateTransitioning
	}
	return StateUnknown
}

// parseResources reads /cluster/resources?type=vm. bad counts entries that were skipped.
func parseResources(b []byte) (vms []pveVM, bad int) {
	items, ok := decodeArray(b)
	if !ok {
		return nil, 1
	}
	for _, it := range items {
		typ := str(it["type"])
		if typ != "qemu" && typ != "lxc" {
			continue
		}
		vmid := str(it["vmid"])
		node := str(it["node"])
		if !reVMID.MatchString(vmid) || !reNode.MatchString(node) {
			bad++
			continue
		}
		v := pveVM{vmid: vmid}
		v.ID = typ + "/" + vmid
		v.Type, v.Node = typ, node
		v.Name = cleanText(str(it["name"]), MaxNameLen)
		if v.Name == "" {
			v.Name = typ + " " + vmid
		}
		v.State = stateOf(str(it["status"]))
		v.Template = truthy(it["template"])
		if f, ok := num(it["maxcpu"]); ok && f >= 0 && f <= 4096 {
			v.CPUs = int(f)
		}
		running := v.State == StateRunning
		if f, ok := num(it["cpu"]); ok && running {
			if c, ok := pct(f, true); ok {
				v.CPUPct = &c
			}
		}
		if f, ok := num(it["mem"]); ok && running {
			if n, ok := clampBytes(f); ok {
				v.MemBytes = &n
			}
		}
		if f, ok := num(it["maxmem"]); ok {
			if n, ok := clampBytes(f); ok && n > 0 {
				v.MemMaxBytes = &n
			}
		}
		if f, ok := num(it["maxdisk"]); ok {
			if n, ok := clampBytes(f); ok && n > 0 {
				v.DiskBytes = &n
			}
		}
		if f, ok := num(it["uptime"]); ok && running {
			if n, ok := clampUptime(f); ok {
				v.UptimeS = &n
			}
		}
		v.Tags = splitTags(str(it["tags"]))
		vms = append(vms, v)
	}
	return vms, bad
}

func splitTags(s string) []string {
	var out []string
	for _, t := range strings.FieldsFunc(s, func(r rune) bool { return r == ';' || r == ',' || r == ' ' }) {
		if t = cleanText(t, MaxTagLen); t != "" {
			out = append(out, t)
			if len(out) == MaxTags {
				break
			}
		}
	}
	return out
}

type storageInfo struct {
	api.HypervisorStore
	backup bool
}

func parseStorage(b []byte) []storageInfo {
	items, ok := decodeArray(b)
	if !ok {
		return nil
	}
	var out []storageInfo
	for _, it := range items {
		name := cleanText(str(it["storage"]), 48)
		if name == "" {
			continue
		}
		if _, has := it["enabled"]; has && !truthy(it["enabled"]) {
			continue // switched off by the administrator
		}
		s := storageInfo{}
		s.Name, s.Type = name, cleanText(str(it["type"]), 24)
		s.Shared = truthy(it["shared"])
		s.Health = "ok"
		if _, has := it["active"]; has && !truthy(it["active"]) {
			s.Health = "error"
		}
		if f, ok := num(it["total"]); ok {
			s.TotalBytes, _ = clampBytes(f)
		}
		if f, ok := num(it["used"]); ok {
			s.UsedBytes, _ = clampBytes(f)
		}
		if s.UsedBytes > s.TotalBytes {
			s.UsedBytes = s.TotalBytes
		}
		for _, c := range strings.Split(str(it["content"]), ",") {
			if strings.TrimSpace(c) == "backup" {
				s.backup = true
			}
		}
		out = append(out, s)
		if len(out) >= MaxStorage {
			break
		}
	}
	return out
}

func parseNotBackedUp(b []byte) map[string]bool {
	items, _ := decodeArray(b)
	m := map[string]bool{}
	for _, it := range items {
		if id := str(it["vmid"]); reVMID.MatchString(id) {
			m[id] = true
		}
	}
	return m
}

type backupTask struct {
	end    int64
	result string
}

// parseBackupTasks reads /nodes/<n>/tasks?typefilter=vzdump. A job over several
// guests is ONE task without a guest id; it cannot be attributed and is skipped
// (the newest backup volume covers that case).
func parseBackupTasks(b []byte) map[string]backupTask {
	items, _ := decodeArray(b)
	m := map[string]backupTask{}
	for _, it := range items {
		if str(it["type"]) != "vzdump" {
			continue
		}
		id := str(it["id"])
		if !reVMID.MatchString(id) {
			continue
		}
		end, ok := num(it["endtime"])
		if !ok || end <= 0 {
			continue // still running
		}
		st := strings.TrimSpace(str(it["status"]))
		res := "failed"
		switch {
		case st == "OK":
			res = "ok"
		case strings.HasPrefix(st, "WARNINGS"):
			res = "warning"
		}
		if cur, ok := m[id]; !ok || int64(end) > cur.end {
			m[id] = backupTask{end: int64(end), result: res}
		}
	}
	return m
}

// parseBackupVolumes reads a storage's backup content: the newest ctime per guest.
func parseBackupVolumes(b []byte) map[string]int64 {
	items, _ := decodeArray(b)
	m := map[string]int64{}
	for n, it := range items {
		if n >= 20000 {
			break
		}
		id := str(it["vmid"])
		if !reVMID.MatchString(id) {
			continue
		}
		if f, ok := num(it["ctime"]); ok && f > 0 && int64(f) > m[id] {
			m[id] = int64(f)
		}
	}
	return m
}

func parseSnapshots(b []byte) (count int, oldest string) {
	items, _ := decodeArray(b)
	var min int64
	for _, it := range items {
		if str(it["name"]) == "current" {
			continue
		}
		count++
		if f, ok := num(it["snaptime"]); ok && f > 0 && (min == 0 || int64(f) < min) {
			min = int64(f)
		}
	}
	if count > 1000 {
		count = 1000
	}
	return count, isoUTC(min)
}

func poolHealth(s string) string {
	switch strings.ToUpper(strings.TrimSpace(s)) {
	case "ONLINE":
		return "ok"
	case "DEGRADED":
		return "warn"
	case "FAULTED", "UNAVAIL", "SUSPENDED", "REMOVED", "OFFLINE":
		return "error"
	}
	return "unknown"
}

// parseZpool reads `zpool list -H -p -o name,size,allocated,free,health`.
func parseZpool(b []byte) []api.HypervisorStore {
	var out []api.HypervisorStore
	for _, line := range strings.Split(string(b), "\n") {
		f := strings.Split(strings.TrimRight(line, "\r"), "\t")
		if len(f) < 5 {
			continue
		}
		name := cleanText(f[0], 48)
		if name == "" {
			continue
		}
		s := api.HypervisorStore{Name: name, Type: "zfs_pool", Health: poolHealth(f[4])}
		if v, err := strconv.ParseFloat(f[1], 64); err == nil {
			s.TotalBytes, _ = clampBytes(v)
		}
		if v, err := strconv.ParseFloat(f[2], 64); err == nil {
			s.UsedBytes, _ = clampBytes(v)
		}
		if s.UsedBytes > s.TotalBytes {
			s.UsedBytes = s.TotalBytes
		}
		out = append(out, s)
		if len(out) >= 50 {
			break
		}
	}
	return out
}

func parseCephHealth(b []byte) (string, bool) {
	m, ok := decodeObject(b)
	if !ok {
		return "", false
	}
	switch strings.ToUpper(str(m["status"])) {
	case "HEALTH_OK":
		return "ok", true
	case "HEALTH_WARN":
		return "warn", true
	case "HEALTH_ERR":
		return "error", true
	}
	return "unknown", true
}
