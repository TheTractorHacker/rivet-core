package collect

import (
	"context"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"

	"rivetit-agent/internal/store"
)

type fake struct {
	mu          sync.Mutex
	idle, total uint64
	memT, memA  uint64
	rx, tx      uint64
	disks       []DiskStat
	svc         map[string]ServiceInfo
	reboot      bool
	fail        map[string]bool
	hang        map[string]chan struct{}
	user        string
}

func newFake() *fake {
	return &fake{memT: 1000, memA: 250, svc: map[string]ServiceInfo{}, fail: map[string]bool{}, hang: map[string]chan struct{}{}}
}

func (f *fake) gate(name string) error {
	f.mu.Lock()
	h, fl := f.hang[name], f.fail[name]
	f.mu.Unlock()
	if h != nil {
		<-h // ignores ctx on purpose: a hung collector
	}
	if fl {
		return errors.New(name + " broke")
	}
	return nil
}
func (f *fake) Identity(context.Context) (Identity, error) { return Identity{}, f.gate("identity") }
func (f *fake) OSInfo(context.Context) (string, string, error) {
	return "windows", "Windows 11", f.gate("osinfo")
}
func (f *fake) CPUModel(context.Context) (string, error) { return "Fake CPU", f.gate("cpumodel") }
func (f *fake) CPUTimes(context.Context) (uint64, uint64, error) {
	if err := f.gate("cpu"); err != nil {
		return 0, 0, err
	}
	f.mu.Lock()
	defer f.mu.Unlock()
	return f.idle, f.total, nil
}
func (f *fake) Memory(context.Context) (uint64, uint64, error) {
	if err := f.gate("mem"); err != nil {
		return 0, 0, err
	}
	return f.memT, f.memA, nil
}
func (f *fake) Disks(context.Context) ([]DiskStat, error) { return f.disks, f.gate("disks") }
func (f *fake) NetBytes(context.Context) (uint64, uint64, error) {
	if err := f.gate("net"); err != nil {
		return 0, 0, err
	}
	f.mu.Lock()
	defer f.mu.Unlock()
	return f.rx, f.tx, nil
}
func (f *fake) UptimeS(context.Context) (uint64, error) { return 5, f.gate("uptime") }
func (f *fake) LoggedInUser(context.Context) (string, error) {
	return f.user, f.gate("user")
}
func (f *fake) PendingReboot(context.Context) (bool, []string, error) {
	return f.reboot, []string{"CBS RebootPending"}, f.gate("pendingreboot")
}
func (f *fake) ServiceState(_ context.Context, n string) (ServiceInfo, error) {
	if err := f.gate("svc"); err != nil {
		return ServiceInfo{}, err
	}
	s, ok := f.svc[n]
	if !ok {
		return ServiceInfo{}, ErrNotFound
	}
	return s, nil
}
func (f *fake) MeshAgentDir() string { return "" }

func newCol(f *fake) *Collector {
	c := New(f, func() store.Config { return store.Config{} })
	c.Timeout = 300 * time.Millisecond
	return c
}

func TestCPUDeltaAndFirstSampleNull(t *testing.T) {
	f := newFake()
	c := newCol(f)
	f.idle, f.total = 100, 200
	m := c.Metrics(context.Background())
	if m.CPUPct != nil || m.NetRxBps != nil {
		t.Fatal("first sample must leave rate metrics null, not zero")
	}
	f.idle, f.total = 150, 300 // 50 idle of 100 => 50% busy
	m = c.Metrics(context.Background())
	if m.CPUPct == nil || *m.CPUPct != 50 {
		t.Fatalf("cpu %v", m.CPUPct)
	}
	if m.MemPct == nil || *m.MemPct != 75 {
		t.Fatalf("mem %v", m.MemPct)
	}
	f.total = 300 // no time passed (delta 0) => null
	if m = c.Metrics(context.Background()); m.CPUPct != nil {
		t.Fatalf("zero delta must be null, got %v", *m.CPUPct)
	}
}

func TestFailedCollectorsAreNullNeverZero(t *testing.T) {
	f := newFake()
	for _, k := range []string{"cpu", "mem", "disks", "net"} {
		f.fail[k] = true
	}
	b, _ := json.Marshal(newCol(f).Metrics(context.Background()))
	if string(b) != `{"cpu_pct":null,"mem_pct":null,"disk":null,"net_rx_bps":null,"net_tx_bps":null}` {
		t.Fatalf("got %s", b)
	}
}

func TestNetRateAndCounterReset(t *testing.T) {
	f := newFake()
	c := newCol(f)
	now := time.Unix(1000, 0)
	c.Now = func() time.Time { return now }
	f.rx, f.tx = 1000, 2000
	c.Metrics(context.Background())
	now = now.Add(10 * time.Second)
	f.rx, f.tx = 2000, 4000
	m := c.Metrics(context.Background())
	if m.NetRxBps == nil || *m.NetRxBps != 800 || *m.NetTxBps != 1600 { // bits per second
		t.Fatalf("%v %v", m.NetRxBps, m.NetTxBps)
	}
	now = now.Add(10 * time.Second)
	f.rx = 5 // counter reset
	if m = c.Metrics(context.Background()); m.NetRxBps != nil {
		t.Fatal("counter reset must yield null")
	}
}

func TestHungCollectorCannotHangLoop(t *testing.T) {
	f := newFake()
	f.hang["mem"] = make(chan struct{})
	defer close(f.hang["mem"])
	c := newCol(f)
	t0 := time.Now()
	m := c.Metrics(context.Background())
	if time.Since(t0) > 2*time.Second {
		t.Fatal("hung collector blocked the sample")
	}
	if m.MemPct != nil {
		t.Fatal("hung memory collector must be null")
	}
	// next call fails fast (no goroutine pile-up)
	t0 = time.Now()
	c.Metrics(context.Background())
	if time.Since(t0) > 200*time.Millisecond {
		t.Fatal("second call should fail fast while the first is still stuck")
	}
}

func spec(key, typ, params string) store.CheckSpec {
	return store.CheckSpec{Key: key, Type: typ, Params: json.RawMessage(params)}
}

func TestServiceCheck(t *testing.T) {
	f := newFake()
	f.svc["Spooler"] = ServiceInfo{State: "running", Startup: "automatic"}
	f.svc["Dead"] = ServiceInfo{State: "stopped", Startup: "automatic"}
	f.svc["Man"] = ServiceInfo{State: "running", Startup: "manual"}
	c := newCol(f)
	ctx := context.Background()
	cases := []struct{ params, want string }{
		{`{"name":"Spooler"}`, OK}, {`{"name":"Dead"}`, Fail}, {`{"name":"Nope"}`, Fail},
		{`{"name":"Man","startup":"automatic"}`, Warn}, {`{"name":"Dead","expected":"stopped"}`, OK}, {`{}`, Unknown}, {`nonsense`, Unknown},
	}
	for _, tc := range cases {
		if got := c.RunCheck(ctx, spec("k", "service", tc.params)); got.Status != tc.want {
			t.Errorf("%s: got %s (%s) want %s", tc.params, got.Status, got.Detail, tc.want)
		}
	}
	f.fail["svc"] = true
	if got := c.RunCheck(ctx, spec("k", "service", `{"name":"Spooler"}`)); got.Status != Unknown {
		t.Errorf("collector error must be unknown, got %s", got.Status)
	}
}

func TestDiskCheck(t *testing.T) {
	f := newFake()
	const gb = 1 << 30
	f.disks = []DiskStat{{Mount: "C:", Total: 100 * gb, Free: 15 * gb}, {Mount: "D:", Total: 100 * gb, Free: 60 * gb}}
	c := newCol(f)
	ctx := context.Background()
	if r := c.RunCheck(ctx, spec("d", "disk", `{"mount":"C:","warn_free_pct":20,"fail_free_pct":10}`)); r.Status != Warn {
		t.Fatalf("%+v", r)
	}
	if r := c.RunCheck(ctx, spec("d", "disk", `{"mount":"c:","fail_free_gb":20}`)); r.Status != Fail {
		t.Fatalf("%+v", r)
	}
	if r := c.RunCheck(ctx, spec("d", "disk", `{"mount":"D:"}`)); r.Status != OK {
		t.Fatalf("%+v", r)
	}
	if r := c.RunCheck(ctx, spec("d", "disk", `{}`)); r.Status != Warn { // all mounts, worst wins (C: at 15%)
		t.Fatalf("%+v", r)
	}
	if r := c.RunCheck(ctx, spec("d", "disk", `{"mount":"Z:"}`)); r.Status != Unknown {
		t.Fatalf("%+v", r)
	}
}

func TestPendingRebootCheck(t *testing.T) {
	f := newFake()
	c := newCol(f)
	if r := c.RunCheck(context.Background(), spec("p", "pending_reboot", ``)); r.Status != OK {
		t.Fatal(r)
	}
	f.reboot = true
	if r := c.RunCheck(context.Background(), spec("p", "pending_reboot", ``)); r.Status != Warn || !strings.Contains(r.Detail, "CBS") {
		t.Fatal(r)
	}
}

func TestScriptCheckMapping(t *testing.T) {
	c := newCol(newFake())
	ctx := context.Background()
	// unsigned script checks are refused outright (never executed)
	marker := filepath.Join(t.TempDir(), "ran")
	up, _ := json.Marshal(map[string]any{"script": "touch " + marker})
	if r := c.RunCheck(ctx, spec("s", "script", string(up))); r.Status != Unknown || !strings.Contains(r.Detail, "refused") {
		t.Fatalf("unsigned script check not refused: %+v", r)
	}
	c.VerifyCheck = func(store.CheckSpec) error { return errors.New("bad signature") }
	if r := c.RunCheck(ctx, spec("s", "script", string(up))); r.Status != Unknown || !strings.Contains(r.Detail, "refused") {
		t.Fatalf("badly signed script check not refused: %+v", r)
	}
	if _, err := os.Stat(marker); err == nil {
		t.Fatal("refused script check executed")
	}
	// a non-script check with an invalid signature is refused too
	sp := spec("d", "pending_reboot", ``)
	sp.Signature = "AAAA"
	if r := c.RunCheck(ctx, sp); r.Status != Unknown {
		t.Fatalf("badly signed check accepted: %+v", r)
	}
	c.VerifyCheck = func(store.CheckSpec) error { return nil } // signature verified
	for script, want := range map[string]string{
		"echo fine; exit 0": OK, "echo hmm; exit 1": Warn, "echo bad; exit 2": Fail, "exit 255": Fail,
	} {
		p, _ := json.Marshal(map[string]any{"script": script})
		if r := c.RunCheck(ctx, spec("s", "script", string(p))); r.Status != want {
			t.Errorf("%q -> %s want %s", script, r.Status, want)
		}
	}
	p, _ := json.Marshal(map[string]any{"script": "sleep 30", "timeout_s": 1})
	t0 := time.Now()
	if r := c.RunCheck(ctx, spec("s", "script", string(p))); r.Status != Unknown || time.Since(t0) > 10*time.Second {
		t.Errorf("timeout must be unknown and prompt: %+v", r)
	}
	p, _ = json.Marshal(map[string]any{"script": `echo "password=supersecretvalue"`})
	if r := c.RunCheck(ctx, spec("s", "script", string(p))); strings.Contains(r.Detail, "supersecretvalue") {
		t.Errorf("secret in detail: %q", r.Detail)
	}
	// local kill switch
	c.Cfg = func() store.Config { return store.Config{DisableScriptChk: true} }
	p, _ = json.Marshal(map[string]any{"script": "exit 0"})
	if r := c.RunCheck(ctx, spec("s", "script", string(p))); r.Status != Unknown {
		t.Errorf("disabled script check ran: %+v", r)
	}
	if r := c.RunCheck(ctx, spec("x", "mystery", ``)); r.Status != Unknown {
		t.Errorf("unknown type: %+v", r)
	}
}

func TestCheckRunnerIntervalsAndRemoval(t *testing.T) {
	f := newFake()
	f.reboot = false
	c := newCol(f)
	r := NewCheckRunner(c)
	specs := []store.CheckSpec{{Key: "b", Type: "pending_reboot", IntervalS: 60}, {Key: "a", Type: "pending_reboot", IntervalS: 60}}
	t0 := time.Unix(10000, 0)
	out := r.Run(context.Background(), specs, t0)
	if len(out) != 2 || out[0].Key != "a" {
		t.Fatalf("%v", out)
	}
	f.reboot = true
	if out = r.Run(context.Background(), specs, t0.Add(10*time.Second)); out[0].Status != OK {
		t.Fatal("check re-ran before its interval")
	}
	if out = r.Run(context.Background(), specs, t0.Add(61*time.Second)); out[0].Status != Warn {
		t.Fatal("check did not re-run when due")
	}
	if out = r.Run(context.Background(), specs[:1], t0.Add(62*time.Second)); len(out) != 1 {
		t.Fatal("removed check still reported")
	}
}

func TestInventoryNullsAndHash(t *testing.T) {
	f := newFake()
	f.fail["identity"], f.fail["user"], f.fail["uptime"] = true, true, true
	c := newCol(f)
	inv := c.Inventory(context.Background())
	if inv.Serial != nil || inv.LoggedInUser != nil || inv.UptimeS != nil {
		t.Fatalf("failed fields must be null: %+v", inv)
	}
	f.fail = map[string]bool{}
	f.disks = []DiskStat{{Mount: "C:", Total: 100, Free: 50, FS: "NTFS"}}
	a := c.Inventory(context.Background())
	h1 := InventoryHash(a)
	f.disks = []DiskStat{{Mount: "C:", Total: 100, Free: 10, FS: "NTFS"}}
	b := c.Inventory(context.Background())
	if InventoryHash(b) != h1 {
		t.Fatal("free space churn must not change the hash")
	}
	f.disks = []DiskStat{{Mount: "C:", Total: 200, Free: 10, FS: "NTFS"}}
	if InventoryHash(c.Inventory(context.Background())) == h1 {
		t.Fatal("capacity change must change the hash")
	}
}

func TestMeshNodeIDOverrideAndFile(t *testing.T) {
	f := newFake()
	c := newCol(f)
	const id = "AbCdEfGhIjKlMnOpQrStUvWxYz0123456789@$AbCdEfGhIjKlMnOpQrSt"
	c.Cfg = func() store.Config { return store.Config{MeshNodeID: id} }
	if got := c.MeshNodeID(context.Background()); got != id {
		t.Fatalf("got %q", got)
	}
	c.Cfg = func() store.Config { return store.Config{MeshNodeID: "bad id; rm -rf /"} }
	if got := c.MeshNodeID(context.Background()); got != "" {
		t.Fatalf("invalid override accepted: %q", got)
	}
	dir := t.TempDir()
	os.WriteFile(filepath.Join(dir, "mesh_node_id.txt"), []byte(id+"\n"), 0o600)
	c.SetStateDir(dir)
	if got := c.MeshNodeID(context.Background()); got != id {
		t.Fatalf("file: %q", got)
	}
}
