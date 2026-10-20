package hypervisor

import (
	"context"
	"encoding/json"
	"errors"
	"sync"
	"testing"
	"time"

	"rivetit-agent/internal/api"
)

// fakePlatform is a hypervisor host whose guests the test controls.
type fakePlatform struct {
	mu     sync.Mutex
	states map[string]string
	fulls  int
	probes int
	err    error
	probeE error
}

func (f *fakePlatform) Platform() string { return "proxmox" }

func (f *fakePlatform) Full(ctx context.Context, o Options) (*api.HypervisorReport, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.fulls++
	if f.err != nil {
		return &api.HypervisorReport{Mode: "full", Platform: "proxmox", CollectedAt: "2026-10-20T12:00:00Z", Error: f.err.Error()}, f.err
	}
	rep := &api.HypervisorReport{Mode: "full", Platform: "proxmox", CollectedAt: "2026-10-20T12:00:00Z"}
	for id, st := range f.states {
		rep.VMs = append(rep.VMs, api.HypervisorVM{ID: id, Name: id, Type: "qemu", State: st})
	}
	return rep, nil
}

func (f *fakePlatform) States(ctx context.Context, o Options) (map[string]string, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.probes++
	if f.probeE != nil {
		return nil, f.probeE
	}
	m := map[string]string{}
	for k, v := range f.states {
		m[k] = v
	}
	return m, nil
}

func (f *fakePlatform) set(id, st string) { f.mu.Lock(); f.states[id] = st; f.mu.Unlock() }

type clock struct {
	mu sync.Mutex
	t  time.Time
}

func (c *clock) now() time.Time { c.mu.Lock(); defer c.mu.Unlock(); return c.t }
func (c *clock) add(d time.Duration) {
	c.mu.Lock()
	c.t = c.t.Add(d)
	c.mu.Unlock()
}

func verifyOK(domain string, raw []byte, sig string) error {
	if domain != SignDomain {
		return errors.New("wrong domain")
	}
	if sig != "good" {
		return errors.New("bad signature")
	}
	return nil
}

func cfgJSON(seq int64, version string, extra map[string]any) json.RawMessage {
	m := map[string]any{"v": 1, "kind": "hypervisor_config", "platform": "linux", "full_interval_s": 600, "summary_interval_s": 120, "max_vms": 1000, "seq": seq, "version": version, "signature": "good"}
	for k, v := range extra {
		m[k] = v
	}
	b, _ := json.Marshal(m)
	return b
}

func newMgr(fp *fakePlatform, c *clock, detect Detector) *Manager {
	if detect == nil {
		detect = func(ctx context.Context) (Collector, error) { return fp, nil }
	}
	return New(ManagerOptions{Detect: detect, Verify: verifyOK, GOOS: "linux", Now: c.now})
}

// waitReport polls Build until a report shows (the collection runs in the background).
func waitReport(t *testing.T, m *Manager) *api.HypervisorReport {
	t.Helper()
	for i := 0; i < 400; i++ {
		if r := m.Build(); r != nil {
			return r
		}
		time.Sleep(5 * time.Millisecond)
	}
	return nil
}

func settle(m *Manager) {
	for i := 0; i < 400; i++ {
		m.mu.Lock()
		r := m.running
		m.mu.Unlock()
		if !r {
			return
		}
		time.Sleep(5 * time.Millisecond)
	}
}

func TestManagerOffUntilConfigured(t *testing.T) {
	fp := &fakePlatform{states: map[string]string{"qemu/100": "running"}}
	c := &clock{t: time.Date(2026, 10, 20, 12, 0, 0, 0, time.UTC)}
	m := newMgr(fp, c, nil)
	if m.Build() != nil || m.Active() {
		t.Fatal("nothing happens without a configuration")
	}
	time.Sleep(20 * time.Millisecond)
	if fp.fulls != 0 {
		t.Fatal("the collector must not run while the switch is off")
	}
	if err := m.Configure(cfgJSON(1, "aaaaaaaaaaaaaaaa", nil)); err != nil || !m.Active() {
		t.Fatal(err)
	}
	r := waitReport(t, m)
	if r == nil || r.Mode != "full" || len(r.VMs) != 1 || r.CfgVersion != "aaaaaaaaaaaaaaaa" {
		t.Fatalf("%+v", r)
	}
	if again := m.Build(); again != r {
		t.Fatal("an unacknowledged report is offered again, byte for byte the same")
	}
	m.Ack()
	if m.Build() != nil {
		t.Fatal("nothing due right after the ack")
	}
	// switching off wipes everything and stops collecting
	m.Configure(nil)
	if m.Active() || m.Build() != nil {
		t.Fatal("off")
	}
}

func TestManagerSignatureAndStaleConfigRefused(t *testing.T) {
	fp := &fakePlatform{states: map[string]string{}}
	c := &clock{t: time.Now()}
	m := newMgr(fp, c, nil)
	bad := cfgJSON(1, "bbbbbbbbbbbbbbbb", map[string]any{"signature": "forged"})
	if err := m.Configure(bad); err == nil || m.Active() {
		t.Fatal("an unsigned configuration must not switch collection on")
	}
	for name, extra := range map[string]map[string]any{
		"platform": {"platform": "windows"}, "kind": {"kind": "change_config"}, "v": {"v": 2}, "version": {"version": "zz"},
	} {
		if err := m.Configure(cfgJSON(1, "bbbbbbbbbbbbbbbb", extra)); err == nil || m.Active() {
			t.Fatalf("%s accepted", name)
		}
	}
	if err := m.Configure(json.RawMessage(`{"v":1`)); err == nil {
		t.Fatal("garbage accepted")
	}
	if err := m.Configure(cfgJSON(10, "cccccccccccccccc", nil)); err != nil {
		t.Fatal(err)
	}
	if err := m.Configure(cfgJSON(9, "dddddddddddddddd", nil)); err == nil {
		t.Fatal("a replayed older configuration must be refused")
	}
	if m.cfg.Version != "cccccccccccccccc" {
		t.Fatal("the last good configuration stays")
	}
}

func TestConfigClamps(t *testing.T) {
	cfg, err := Parse(cfgJSON(1, "eeeeeeeeeeeeeeee", map[string]any{"full_interval_s": 1, "summary_interval_s": 99999, "max_vms": 99999, "snapshot_lookups": 99999}), "linux", verifyOK)
	if err != nil {
		t.Fatal(err)
	}
	if cfg.FullInterval != MinFullInterval || cfg.SummaryInterval != MinFullInterval || cfg.MaxVMs != MaxVMs || cfg.SnapshotLookups != MaxSnapshotCalls {
		t.Fatalf("%+v", cfg)
	}
	cfg, _ = Parse(cfgJSON(1, "eeeeeeeeeeeeeeee", map[string]any{"full_interval_s": 999999999, "summary_interval_s": 0}), "linux", verifyOK)
	if cfg.FullInterval != MaxFullInterval || cfg.SummaryInterval != DefaultSummaryS*time.Second {
		t.Fatalf("%+v", cfg)
	}
	if _, err := Parse(json.RawMessage(`null`), "linux", verifyOK); !errors.Is(err, ErrNoConfig) {
		t.Fatal(err)
	}
	if _, err := Parse(append(cfgJSON(1, "eeeeeeeeeeeeeeee", map[string]any{"pad": string(make([]byte, 9000))}), ' '), "linux", verifyOK); err == nil {
		t.Fatal("oversized configuration accepted")
	}
}

func TestManagerSummaryBetweenFullReports(t *testing.T) {
	fp := &fakePlatform{states: map[string]string{"qemu/100": "running", "qemu/101": "stopped"}}
	c := &clock{t: time.Date(2026, 10, 20, 12, 0, 0, 0, time.UTC)}
	m := newMgr(fp, c, nil)
	m.Configure(cfgJSON(1, "aaaaaaaaaaaaaaaa", nil))
	waitReport(t, m)
	m.Ack()

	c.add(30 * time.Second)
	if m.Build() != nil || fp.probes != 0 {
		t.Fatal("too early for a probe")
	}
	c.add(100 * time.Second) // 130 s: past the summary interval, before the full one
	m.Build()
	settle(m)
	if r := m.Build(); r != nil || fp.probes != 1 || fp.fulls != 1 {
		t.Fatalf("no change, nothing to send: %+v probes=%d fulls=%d", r, fp.probes, fp.fulls)
	}
	fp.set("qemu/101", "running")
	c.add(130 * time.Second)
	r := waitReport(t, m)
	if r == nil || r.Mode != "summary" || len(r.Changes) != 1 || r.Changes[0] != (api.HypervisorChange{ID: "qemu/101", State: "running"}) || len(r.VMs) != 0 || r.VMCount != 2 {
		t.Fatalf("%+v", r)
	}
	m.Ack()
	c.add(130 * time.Second)
	m.Build()
	settle(m)
	if m.Build() != nil {
		t.Fatal("the change was acknowledged; it is not reported twice")
	}
	if fp.fulls != 1 {
		t.Fatalf("a summary must not trigger a full collection (fulls=%d)", fp.fulls)
	}
	c.add(10 * time.Minute)
	if r := waitReport(t, m); r == nil || r.Mode != "full" {
		t.Fatalf("a full report is due at least every full interval: %+v", r)
	}
}

func TestManagerUnackedSummaryIsNotLost(t *testing.T) {
	fp := &fakePlatform{states: map[string]string{"qemu/100": "running"}}
	c := &clock{t: time.Date(2026, 10, 20, 12, 0, 0, 0, time.UTC)}
	m := newMgr(fp, c, nil)
	m.Configure(cfgJSON(1, "aaaaaaaaaaaaaaaa", nil))
	waitReport(t, m)
	m.Ack()
	fp.set("qemu/100", "stopped")
	c.add(130 * time.Second)
	r := waitReport(t, m)
	if r == nil || r.Mode != "summary" {
		t.Fatalf("%+v", r)
	}
	m.Abandon() // the request was lost without an answer
	c.add(130 * time.Second)
	if r2 := waitReport(t, m); r2 == nil || len(r2.Changes) != 1 {
		t.Fatalf("the change must be found again: %+v", r2)
	}
}

func TestManagerErrorsAreReportedNotRaised(t *testing.T) {
	fp := &fakePlatform{states: map[string]string{}, err: errors.New("guests: pvesh exited with status 2")}
	c := &clock{t: time.Date(2026, 10, 20, 12, 0, 0, 0, time.UTC)}
	m := newMgr(fp, c, nil)
	m.Configure(cfgJSON(1, "aaaaaaaaaaaaaaaa", nil))
	r := waitReport(t, m)
	if r == nil || r.Error == "" || len(r.VMs) != 0 || r.Mode != "full" {
		t.Fatalf("%+v", r)
	}
	m.Ack()
	if m.baseline != nil {
		t.Fatal("an error report must not become the baseline")
	}
	c.add(4 * time.Minute)
	if m.Build() != nil {
		t.Fatal("retry no sooner than 5 minutes after a failure")
	}
	c.add(2 * time.Minute)
	fp.mu.Lock()
	fp.err = nil
	fp.states["qemu/1"] = "running"
	fp.mu.Unlock()
	if r := waitReport(t, m); r == nil || len(r.VMs) != 1 {
		t.Fatalf("recovered: %+v", r)
	}
}

func TestManagerNotAHostIsSilent(t *testing.T) {
	fp := &fakePlatform{}
	c := &clock{t: time.Date(2026, 10, 20, 12, 0, 0, 0, time.UTC)}
	calls := 0
	m := newMgr(fp, c, func(ctx context.Context) (Collector, error) { calls++; return nil, ErrNotHost })
	m.Configure(cfgJSON(1, "aaaaaaaaaaaaaaaa", nil))
	for i := 0; i < 5; i++ {
		m.Build()
		settle(m)
	}
	if calls != 1 {
		t.Fatalf("detection retried %d times inside the recheck window", calls)
	}
	if m.Build() != nil {
		t.Fatal("a machine that is not a hypervisor sends nothing at all")
	}
	c.add(11 * time.Minute)
	m.Build()
	settle(m)
	if calls != 2 {
		t.Fatalf("detection is re-run after the window (%d)", calls)
	}
}

func TestManagerPanicInCollectorIsContained(t *testing.T) {
	c := &clock{t: time.Date(2026, 10, 20, 12, 0, 0, 0, time.UTC)}
	m := newMgr(nil, c, func(ctx context.Context) (Collector, error) { panic("boom") })
	m.Configure(cfgJSON(1, "aaaaaaaaaaaaaaaa", nil))
	m.Build()
	settle(m)
	if m.Build() != nil {
		t.Fatal("no report from a panic")
	}
}

func TestFitCutsToBudget(t *testing.T) {
	rep := &api.HypervisorReport{Mode: "full", Platform: "proxmox"}
	for i := 0; i < 1500; i++ {
		rep.VMs = append(rep.VMs, api.HypervisorVM{ID: "qemu/" + itoa(i+100), Name: string(make([]byte, 0)) + "a-very-long-virtual-machine-name-that-takes-room-" + itoa(i), Type: "qemu", State: "running", Tags: []string{"aaaaaaaaaaaaaaaaaaaa", "bbbbbbbbbbbbbbbbbbbbbbb", "cccccccccccccccccccccccccc"}})
	}
	fit(rep)
	b, _ := json.Marshal(rep)
	if len(rep.VMs) > MaxVMs || len(b) > MaxReportBytes || !rep.Truncated {
		t.Fatalf("%d vms, %d bytes, truncated=%v", len(rep.VMs), len(b), rep.Truncated)
	}
}
