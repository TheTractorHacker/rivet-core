package hypervisor

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"

	"rivetit-agent/internal/remote"
)

type fakeResp struct {
	out  string
	exit int
	err  error
}

// fakeRunner answers by exact command line and records every call.
type fakeRunner struct {
	mu    sync.Mutex
	resp  map[string]fakeResp
	calls []string
}

func newFake() *fakeRunner { return &fakeRunner{resp: map[string]fakeResp{}} }

func (f *fakeRunner) on(cmd string, out string) { f.resp[cmd] = fakeResp{out: out} }
func (f *fakeRunner) fail(cmd string, exit int) { f.resp[cmd] = fakeResp{exit: exit} }

func (f *fakeRunner) Run(ctx context.Context, name string, args []string, maxOut int, timeout time.Duration) (remote.RunResult, error) {
	line := name + " " + strings.Join(args, " ")
	f.mu.Lock()
	f.calls = append(f.calls, line)
	r, ok := f.resp[line]
	f.mu.Unlock()
	if !ok {
		return remote.RunResult{ExitCode: 127}, nil
	}
	if r.err != nil {
		return remote.RunResult{}, r.err
	}
	return remote.RunResult{Stdout: []byte(r.out), ExitCode: r.exit}, nil
}

func (f *fakeRunner) called(prefix string) int {
	f.mu.Lock()
	defer f.mu.Unlock()
	n := 0
	for _, c := range f.calls {
		if strings.HasPrefix(c, prefix) {
			n++
		}
	}
	return n
}

func fixture(t testing.TB, name string) string {
	t.Helper()
	b, err := os.ReadFile(filepath.Join("testdata", name))
	if err != nil {
		t.Fatal(err)
	}
	return string(b)
}

const (
	pv = "/usr/bin/pvesh get "
	js = " --output-format json"
)

// pveFake is a Proxmox host "devpve" in a two-node cluster with the fixtures wired in.
func pveFake(t testing.TB) (*fakeRunner, *Proxmox) {
	f := newFake()
	f.on(pv+"/version"+js, fixture(t, "pve_version.json"))
	f.on(pv+"/cluster/status"+js, fixture(t, "pve_cluster_status.json"))
	f.on(pv+"/cluster/resources --type vm"+js, fixture(t, "pve_resources.json"))
	f.on(pv+"/nodes/devpve/storage"+js, fixture(t, "pve_storage.json"))
	f.on(pv+"/cluster/backup-info/not-backed-up"+js, fixture(t, "pve_not_backed_up.json"))
	f.on(pv+"/nodes/devpve/tasks --typefilter vzdump --limit 20"+js, fixture(t, "pve_tasks.json"))
	f.on(pv+"/nodes/devpve/storage/local/content --content backup"+js, fixture(t, "pve_backup_content.json"))
	f.on(pv+"/nodes/devpve/qemu/100/snapshot"+js, fixture(t, "pve_snap_100.json"))
	f.on(pv+"/nodes/devpve/qemu/101/snapshot"+js, fixture(t, "pve_snap_none.json"))
	f.on(pv+"/nodes/devpve/lxc/200/snapshot"+js, "[]")
	f.on(pv+"/nodes/pve2/qemu/300/snapshot"+js, "[]")
	f.on("/usr/sbin/zpool list -H -p -o name,size,allocated,free,health", fixture(t, "zpool_list.txt"))
	f.on("/usr/bin/ceph health --format json", fixture(t, "ceph_health.json"))
	have := map[string]bool{pveshPath: true, pveDir: true, "/usr/sbin/zpool": true, "/usr/bin/ceph": true}
	p := &Proxmox{R: f, Exists: func(x string) bool { return have[x] }, Hostname: func() string { return "devpve.lan" },
		Now: func() time.Time { return time.Date(2026, 10, 20, 12, 0, 0, 0, time.UTC) }}
	return f, p
}
