package hypervisor

import (
	"context"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

const (
	sc  = `C:\Windows\System32\sc.exe`
	psx = `C:\Windows\System32\WindowsPowerShell\v1.0\powershell.exe`
)

// hvFake is a Hyper-V host with the fixtures wired in (paths are joined the way the code joins them, so the test also runs on Linux).
func hvFake(t testing.TB) (*fakeRunner, *HyperV) {
	f := newFake()
	h := &HyperV{R: f, SystemRoot: `C:\Windows`, Hostname: func() string { return "hv1.corp.local" }, Now: func() time.Time { return time.Date(2026, 10, 20, 12, 0, 0, 0, time.UTC) }}
	ps := func(script string) string { return h.psExe() + " -NoProfile -NonInteractive -Command " + script }
	f.on(h.scExe()+" query vmms", "SERVICE_NAME: vmms\n        STATE : 4  RUNNING\n")
	f.on(ps(psDetect), "yes\r\n")
	f.on(ps(psFull), fixture(t, "hyperv_full.json"))
	f.on(h.scExe()+" query ClusSvc", "SERVICE_NAME: ClusSvc\n")
	f.on(ps(psCluster), fixture(t, "hyperv_cluster.json"))
	f.on(ps(psStates), `[{"id":"0F1A2B3C-0000-4000-8000-000000000001","state":"Running"},{"id":"0f1a2b3c-0000-4000-8000-000000000002","state":"Off"}]`)
	_ = filepath.Join
	return f, h
}

func TestHyperVFull(t *testing.T) {
	f, h := hvFake(t)
	rep, err := h.Full(context.Background(), Options{})
	if err != nil {
		t.Fatal(err)
	}
	if rep.Platform != "hyperv" || rep.Version != "10.0.20348" || len(rep.VMs) != 3 {
		t.Fatalf("%+v", rep)
	}
	sql := vmByID(t, rep, "0f1a2b3c-0000-4000-8000-000000000001")
	if sql.Name != "SQL01" || sql.State != "running" || sql.Type != "hyperv" || sql.Node != "HV1" || sql.CPUs != 8 || *sql.CPUPct != 12 || *sql.MemBytes != 17179869184 || *sql.MemMaxBytes != 34359738368 ||
		*sql.UptimeS != 123456 || *sql.DiskBytes != 214748364800 || *sql.DiskUsed != 96636764160 || sql.Generation != 2 || sql.GuestAgent == nil || !*sql.GuestAgent || sql.Replication != "normal" {
		t.Fatalf("SQL01 %+v", sql)
	}
	if sql.Snapshots == nil || sql.Snapshots.Count != 2 || sql.Snapshots.OldestAt != "2025-09-27T19:06:40Z" {
		t.Fatalf("snapshots %+v", sql.Snapshots)
	}
	fs := vmByID(t, rep, "0f1a2b3c-0000-4000-8000-000000000002")
	if fs.State != "stopped" || fs.CPUPct != nil || fs.MemBytes != nil || fs.GuestAgent != nil || fs.Replication != "critical" || fs.Snapshots == nil || fs.Snapshots.Count != 0 {
		t.Fatalf("FS01 %+v", fs)
	}
	app := vmByID(t, rep, "0f1a2b3c-0000-4000-8000-000000000003")
	if app.State != "error" || app.CPUPct != nil || app.UptimeS != nil {
		t.Fatalf("APP01 (a critical state carries no live metrics) %+v", app)
	}
	if len(rep.Storage) != 1 || rep.Storage[0].Name != "D:" || rep.Storage[0].UsedBytes != 1500000000000 {
		t.Fatalf("volumes %+v", rep.Storage)
	}
	if rep.Cluster == nil || rep.Cluster.Name != "HVCL01" || rep.Cluster.FailedGroups != 1 || rep.Cluster.Quorate == nil || !*rep.Cluster.Quorate || rep.Cluster.Nodes[1].Online {
		t.Fatalf("cluster %+v", rep.Cluster)
	}
	if !strings.Contains(rep.Error, "replication: access denied") {
		t.Fatalf("a script-side problem is reported: %q", rep.Error)
	}
	for _, c := range f.calls {
		if !(strings.HasPrefix(c, h.scExe()+" query ") || strings.HasPrefix(c, h.psExe()+" -NoProfile -NonInteractive -Command ")) {
			t.Errorf("unexpected command %q", c)
		}
	}
}

// The scripts are fixed text: no double quotes (argv quoting), no secret-bearing cmdlets, only reads.
func TestHyperVScriptsAreReadOnlyAndQuoteSafe(t *testing.T) {
	for name, s := range map[string]string{"detect": psDetect, "states": psStates, "full": psFull, "cluster": psCluster} {
		if strings.ContainsAny(s, "\"\n`") {
			t.Errorf("%s: contains a double quote, line break or backtick", name)
		}
		for _, bad := range []string{"Set-", "Remove-", "Start-", "Stop-", "New-VM", "Export-", "Invoke-", "Get-VMHost", "Get-Credential", "Enable-", "Disable-", "Checkpoint-", "Restore-", "Move-", "Import-Clixml", "Get-Content", "Invoke-Command", "iex"} {
			if strings.Contains(s, bad) && !(bad == "Import-Clixml") {
				t.Errorf("%s: contains %s", name, bad)
			}
		}
	}
	if len(psFull) > 8000 {
		t.Errorf("psFull is %d characters; the Windows command line is limited to 32767", len(psFull))
	}
}

func TestHyperVSingleVMAndNulls(t *testing.T) {
	_, h := hvFake(t)
	h.R.(*fakeRunner).on(h.psExe()+" -NoProfile -NonInteractive -Command "+psFull, fixture(t, "hyperv_single.json"))
	rep, err := h.Full(context.Background(), Options{})
	if err != nil || len(rep.VMs) != 1 || rep.VMs[0].State != "suspended" || rep.VMs[0].Snapshots != nil {
		t.Fatalf("%v %+v", err, rep)
	}
}

func TestHyperVFailures(t *testing.T) {
	for name, out := range map[string]string{
		"module missing": `{"ok":false,"error":"the Hyper-V PowerShell module is not installed"}`,
		"garbage":        `Get-VM : You do not have the required permission`,
		"empty":          ``,
	} {
		_, h := hvFake(t)
		h.R.(*fakeRunner).on(h.psExe()+" -NoProfile -NonInteractive -Command "+psFull, out)
		rep, err := h.Full(context.Background(), Options{})
		if err == nil || rep.Error == "" || len(rep.VMs) != 0 {
			t.Errorf("%s: want an error report, got %v / %+v", name, err, rep)
		}
	}
	f, h := hvFake(t)
	f.fail(h.psExe()+" -NoProfile -NonInteractive -Command "+psFull, 1)
	if rep, err := h.Full(context.Background(), Options{}); err == nil || rep.Error == "" {
		t.Error("a failing powershell is an error report")
	}
	// a broken cluster query must not lose the VMs
	f, h = hvFake(t)
	f.fail(h.psExe()+" -NoProfile -NonInteractive -Command "+psCluster, 1)
	rep, err := h.Full(context.Background(), Options{})
	if err != nil || len(rep.VMs) != 3 || rep.Cluster != nil || !strings.Contains(rep.Error, "cluster") {
		t.Errorf("%v %+v", err, rep)
	}
}

func TestHyperVHostileNames(t *testing.T) {
	_, h := hvFake(t)
	evil := `{"ok":true,"vms":[{"id":"0f1a2b3c-0000-4000-8000-00000000000a","name":"a\u0000b\n$(calc)\u202e<img src=x onerror=alert(1)>","state":"Running","cpus":99999999,"memA":"abc","memMax":-1,"up":"1e999"}],"errors":["` + strings.Repeat("x", 5000) + `"]}`
	h.R.(*fakeRunner).on(h.psExe()+" -NoProfile -NonInteractive -Command "+psFull, evil)
	rep, err := h.Full(context.Background(), Options{})
	if err != nil || len(rep.VMs) != 1 {
		t.Fatalf("%v %+v", err, rep)
	}
	v := rep.VMs[0]
	if strings.ContainsAny(v.Name, "\x00\n\u202e") || v.CPUs != 0 || v.MemBytes != nil || v.MemMaxBytes != nil || v.UptimeS != nil || len(rep.Error) > MaxErrorLen {
		t.Fatalf("%+v / %d", v, len(rep.Error))
	}
}

func TestHyperVDetectAndStates(t *testing.T) {
	ctx := context.Background()
	f, h := hvFake(t)
	if c, err := DetectHyperV(ctx, h); err != nil || c.Platform() != "hyperv" {
		t.Fatalf("%v", err)
	}
	f.fail(h.scExe()+" query vmms", 1060)
	if _, err := DetectHyperV(ctx, h); err != ErrNotHost {
		t.Fatal("no vmms service: not a host")
	}
	f, h = hvFake(t)
	f.on(h.psExe()+" -NoProfile -NonInteractive -Command "+psDetect, "no\r\n")
	if _, err := DetectHyperV(ctx, h); err != ErrNotHost {
		t.Fatal("service without the module: not a host")
	}
	_, h = hvFake(t)
	m, err := h.States(ctx, Options{})
	if err != nil || m["0f1a2b3c-0000-4000-8000-000000000001"] != "running" || m["0f1a2b3c-0000-4000-8000-000000000002"] != "stopped" {
		t.Fatalf("%v %v", err, m)
	}
}

func TestHyperVHugeIsCapped(t *testing.T) {
	_, h := hvFake(t)
	var b strings.Builder
	b.WriteString(`{"ok":true,"vms":[`)
	for i := 0; i < 3000; i++ {
		if i > 0 {
			b.WriteByte(',')
		}
		b.WriteString(strings.Replace(`{"id":"0f1a2b3c-0000-4000-8000-0000000XXXXX","name":"vm","state":"Running","cpus":2,"cpu":5,"memA":1073741824,"memMax":2147483648,"up":7}`, "XXXXX", pad5(i), 1))
	}
	b.WriteString(`]}`)
	h.R.(*fakeRunner).on(h.psExe()+" -NoProfile -NonInteractive -Command "+psFull, b.String())
	rep, err := h.Full(context.Background(), Options{})
	if err != nil || len(rep.VMs) != MaxVMs || !rep.Truncated {
		t.Fatalf("%v %d %v", err, len(rep.VMs), rep.Truncated)
	}
}

func pad5(i int) string {
	s := "00000" + strings.TrimLeft(strings.Repeat("0", 0)+itoa(i), "")
	return s[len(s)-5:]
}
