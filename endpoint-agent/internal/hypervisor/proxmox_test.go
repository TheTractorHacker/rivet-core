package hypervisor

import (
	"context"
	"encoding/json"
	"fmt"
	"strings"
	"testing"

	"rivetit-agent/internal/api"
)

func vmByID(t *testing.T, rep *api.HypervisorReport, id string) api.HypervisorVM {
	t.Helper()
	for _, v := range rep.VMs {
		if v.ID == id {
			return v
		}
	}
	t.Fatalf("vm %s not in report: %+v", id, rep.VMs)
	return api.HypervisorVM{}
}

func TestProxmoxFull(t *testing.T) {
	f, p := pveFake(t)
	rep, err := p.Full(context.Background(), Options{})
	if err != nil {
		t.Fatal(err)
	}
	if rep.Platform != "proxmox" || rep.Mode != "full" || rep.Version != "8.2.4" || rep.Error != "" {
		t.Fatalf("header: %+v", rep)
	}
	if rep.Cluster == nil || rep.Cluster.Name != "homelab" || rep.Cluster.Quorate == nil || !*rep.Cluster.Quorate || len(rep.Cluster.Nodes) != 2 || rep.Cluster.Nodes[1].Online {
		t.Fatalf("cluster: %+v", rep.Cluster)
	}
	if len(rep.VMs) != 5 {
		t.Fatalf("vms: %d", len(rep.VMs))
	}
	dc := vmByID(t, rep, "qemu/100")
	if dc.Name != "dc01" || dc.State != "running" || dc.CPUs != 4 || *dc.CPUPct != 2.1 || *dc.MemBytes != 4294967296 || *dc.DiskBytes != 107374182400 || *dc.UptimeS != 864000 || len(dc.Tags) != 2 {
		t.Fatalf("dc01: %+v", dc)
	}
	if dc.Snapshots == nil || dc.Snapshots.Count != 2 || dc.Snapshots.OldestAt != "2025-09-27T19:06:40Z" {
		t.Fatalf("snapshots (current must not count): %+v", dc.Snapshots)
	}
	if dc.LastBackup == nil || dc.LastBackup.Result != "ok" || dc.LastBackup.At != isoUTC(1760900000) || dc.BackupJob == nil || !*dc.BackupJob {
		t.Fatalf("backup: %+v", dc.LastBackup)
	}
	web := vmByID(t, rep, "qemu/101")
	if web.State != "stopped" || web.CPUPct != nil || web.MemBytes != nil || web.UptimeS != nil || web.LastBackup == nil || web.LastBackup.Result != "failed" || *web.BackupJob {
		t.Fatalf("a stopped guest has no live metrics, a failed vzdump task shows: %+v", web)
	}
	if web.Snapshots == nil || web.Snapshots.Count != 0 {
		t.Fatalf("zero snapshots must be reported as 0: %+v", web.Snapshots)
	}
	tmpl := vmByID(t, rep, "qemu/9000")
	if !tmpl.Template {
		t.Fatal("template flag")
	}
	if tmpl.Snapshots != nil {
		t.Fatal("a template is not asked for snapshots")
	}
	// the running container's backup volume (vmid as a string) and its still-running task (no end time) are handled
	ct := vmByID(t, rep, "lxc/200")
	if ct.LastBackup == nil || ct.LastBackup.Result != "ok" || ct.Type != "lxc" || *ct.DiskBytes != 4294967296 {
		t.Fatalf("lxc: %+v", ct)
	}
	if vmByID(t, rep, "qemu/300").Node != "pve2" {
		t.Fatal("cluster-wide guests keep their node")
	}
	// storage: dir, zfspool, an inactive NFS (error), the disabled one is skipped, then ZFS pools and Ceph
	names := map[string]api.HypervisorStore{}
	for _, s := range rep.Storage {
		names[s.Name] = s
	}
	if _, ok := names["old"]; ok {
		t.Fatal("a disabled storage is not reported")
	}
	if names["nas-backup"].Health != "error" || names["local"].Health != "ok" || names["local-zfs"].UsedBytes != 451096576000 {
		t.Fatalf("storage: %+v", names)
	}
	if names["rpool"].Type != "zfs_pool" || names["rpool"].Health != "ok" || names["tank"].Health != "warn" || names["broken"].Health != "error" || names["ceph"].Health != "warn" {
		t.Fatalf("pools: %+v", names)
	}
	// every command that ran is on the fixed list and none can reach a VM configuration
	for _, c := range f.calls {
		ok := strings.HasPrefix(c, "/usr/bin/pvesh get /") && strings.HasSuffix(c, js) || strings.HasPrefix(c, "/usr/sbin/zpool list -H -p") || c == "/usr/bin/ceph health --format json"
		if !ok || strings.Contains(c, "/config") || strings.Contains(c, "agent") || strings.Contains(c, "cloudinit") || strings.ContainsAny(c, "|;&$`") {
			t.Errorf("unexpected command %q", c)
		}
	}
}

func TestProxmoxPartialFailureStillReports(t *testing.T) {
	f, p := pveFake(t)
	f.fail(pv+"/nodes/devpve/storage"+js, 2)
	f.fail(pv+"/nodes/devpve/tasks --typefilter vzdump --limit 20"+js, 1)
	delete(f.resp, pv+"/nodes/devpve/qemu/100/snapshot"+js) // 127 = failed look-up
	f.fail("/usr/bin/ceph health --format json", 1)
	rep, err := p.Full(context.Background(), Options{})
	if err != nil {
		t.Fatal(err)
	}
	if len(rep.VMs) != 5 || rep.Error == "" || len(rep.Error) > MaxErrorLen {
		t.Fatalf("partial: vms=%d error=%q", len(rep.VMs), rep.Error)
	}
	if vmByID(t, rep, "qemu/100").Snapshots != nil {
		t.Fatal("a failed snapshot look-up must leave the value unknown, not zero")
	}
}

func TestProxmoxGuestListFailureIsAnErrorReport(t *testing.T) {
	for name, out := range map[string]string{"malformed": `{"data": [`, "empty-output": ``, "html": `<html>502</html>`} {
		f, p := pveFake(t)
		f.on(pv+"/cluster/resources --type vm"+js, out)
		rep, err := p.Full(context.Background(), Options{})
		if name == "empty-output" {
			if err != nil || len(rep.VMs) != 0 {
				t.Fatalf("%s: empty output is an empty list (%v)", name, err)
			}
			continue
		}
		if err == nil || rep == nil || rep.Error == "" || len(rep.VMs) != 0 {
			t.Fatalf("%s: want an error report, got %v / %+v", name, err, rep)
		}
	}
	f, p := pveFake(t)
	f.fail(pv+"/cluster/resources --type vm"+js, 255)
	if rep, err := p.Full(context.Background(), Options{}); err == nil || rep.Error == "" {
		t.Fatal("a failing pvesh is an error report")
	}
}

func TestProxmoxEmpty(t *testing.T) {
	f, p := pveFake(t)
	f.on(pv+"/cluster/resources --type vm"+js, `[]`)
	f.on(pv+"/cluster/status"+js, fixture(t, "pve_cluster_status_standalone.json"))
	rep, err := p.Full(context.Background(), Options{})
	if err != nil || len(rep.VMs) != 0 || rep.Cluster != nil || rep.Error != "" {
		t.Fatalf("%v %+v", err, rep)
	}
}

func TestProxmoxHostileStrings(t *testing.T) {
	f, p := pveFake(t)
	evil := []any{
		map[string]any{"type": "qemu", "vmid": 400, "name": "x\u0000\n<script>alert(1)</script>‮\"; rm -rf /", "node": "devpve", "status": "running", "tags": strings.Repeat("t;", 50) + "<b>", "maxcpu": 1e12, "cpu": "NaN", "mem": -5, "maxmem": 1e300, "uptime": 1e30},
		map[string]any{"type": "qemu", "vmid": 401, "name": strings.Repeat("A", 5000), "node": "devpve", "status": "weird"},
		map[string]any{"type": "qemu", "vmid": "../../etc", "name": "path", "node": "devpve"},
		map[string]any{"type": "qemu", "vmid": 402, "name": "bad node", "node": "dev pve; id"},
		map[string]any{"type": "qemu", "vmid": 403, "name": "bad node 2", "node": "$(id)"},
		map[string]any{"type": "qemu", "vmid": 0, "name": "zero", "node": "devpve"},
		map[string]any{"type": "qemu", "vmid": 1e12, "name": "huge id", "node": "devpve"},
		"a string", nil, 5,
	}
	b, _ := json.Marshal(evil)
	f.on(pv+"/cluster/resources --type vm"+js, string(b))
	rep, err := p.Full(context.Background(), Options{})
	if err != nil {
		t.Fatal(err)
	}
	if len(rep.VMs) != 2 {
		t.Fatalf("only the two well-formed ids survive: %+v", rep.VMs)
	}
	v := vmByID(t, rep, "qemu/400")
	if strings.ContainsAny(v.Name, "\x00\n‮") || len(v.Tags) != MaxTags || v.CPUs != 0 || v.CPUPct != nil || v.MemBytes != nil || *v.MemMaxBytes != maxBytesValue || *v.UptimeS != maxUptimeS {
		t.Fatalf("not sanitised: %+v", v)
	}
	if long := vmByID(t, rep, "qemu/401"); len([]rune(long.Name)) != MaxNameLen || long.State != "unknown" {
		t.Fatalf("long name / unknown state: %d %s", len([]rune(long.Name)), long.State)
	}
	for _, c := range f.calls {
		if strings.ContainsAny(c, ";$`|\n") || strings.Contains(c, "..") || strings.Contains(c, "bad node") {
			t.Errorf("hostile text reached a command line: %q", c)
		}
	}
}

func TestProxmoxHugeIsCapped(t *testing.T) {
	f, p := pveFake(t)
	var list []map[string]any
	for i := 1; i <= 5000; i++ {
		list = append(list, map[string]any{"type": "qemu", "vmid": i + 99, "name": fmt.Sprintf("vm-%04d", i), "node": "devpve", "status": "running", "cpu": 0.1, "maxcpu": 2, "mem": 1 << 30, "maxmem": 2 << 30, "maxdisk": 1 << 36, "uptime": 99})
	}
	b, _ := json.Marshal(list)
	f.on(pv+"/cluster/resources --type vm"+js, string(b))
	rep, err := p.Full(context.Background(), Options{})
	if err != nil {
		t.Fatal(err)
	}
	if len(rep.VMs) != MaxVMs || !rep.Truncated {
		t.Fatalf("vms=%d truncated=%v", len(rep.VMs), rep.Truncated)
	}
	if n := f.called(pv + "/nodes/devpve/qemu/"); n > MaxSnapshotCalls {
		t.Fatalf("%d snapshot look-ups, cap %d", n, MaxSnapshotCalls)
	}
	fit(rep)
	if bb, _ := json.Marshal(rep); len(bb) > MaxReportBytes {
		t.Fatalf("report %d bytes over the %d cap", len(bb), MaxReportBytes)
	}
	// the look-ups rotate: a second collection reaches guests the first one skipped
	first := f.called(pv + "/nodes/devpve/qemu/")
	p.Full(context.Background(), Options{})
	if f.called(pv+"/nodes/devpve/qemu/") <= first {
		t.Fatal("no second round of look-ups")
	}
}

func TestProxmoxDetect(t *testing.T) {
	ctx := context.Background()
	mk := func(have ...string) *Proxmox {
		m := map[string]bool{}
		for _, h := range have {
			m[h] = true
		}
		return &Proxmox{R: newFake(), Exists: func(x string) bool { return m[x] }}
	}
	if _, err := DetectProxmox(ctx, mk(pveshPath, pveDir)); err != nil {
		t.Fatal(err)
	}
	if _, err := DetectProxmox(ctx, mk(pveshPath)); err != ErrNotHost {
		t.Fatal("pvesh without /etc/pve is not a host")
	}
	if _, err := DetectProxmox(ctx, mk()); err != ErrNotHost {
		t.Fatal("a plain Debian is not a host")
	}
	p := mk(pveshPath, pveVerPath)
	p.R.(*fakeRunner).on(pveVerPath+" ", "pve-manager/8.2.4/faa83925c9641325 (running kernel: 6.8.4-2-pve)\n")
	if _, err := DetectProxmox(ctx, p); err != nil {
		t.Fatalf("pveversion fallback: %v", err)
	}
}

func TestProxmoxStates(t *testing.T) {
	_, p := pveFake(t)
	m, err := p.States(context.Background(), Options{})
	if err != nil || m["qemu/100"] != "running" || m["qemu/101"] != "stopped" || len(m) != 5 {
		t.Fatalf("%v %v", err, m)
	}
}

func TestParsersTolerateGarbage(t *testing.T) {
	for _, in := range []string{``, `null`, `{}`, `[]`, `[1,2,"x",null]`, `{"a":`, "\xef\xbb\xbf[]", `[{"type":"cluster"}]`, `[{"type":"node"}]`} {
		parseResources([]byte(in))
		parseClusterStatus([]byte(in))
		parseStorage([]byte(in))
		parseNotBackedUp([]byte(in))
		parseBackupTasks([]byte(in))
		parseBackupVolumes([]byte(in))
		parseSnapshots([]byte(in))
		parseCephHealth([]byte(in))
		parseZpool([]byte(in))
		parseHyperV([]byte(in), "H")
		parseHyperVCluster([]byte(in))
	}
	if got := parseZpool([]byte("a\tb\n\t\t\t\t\nok\t10\t20\t0\tONLINE\n")); len(got) != 1 || got[0].UsedBytes != 10 {
		t.Fatalf("zpool used must not exceed total: %+v", got)
	}
}
