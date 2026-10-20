package agent

import (
	"context"
	"crypto/ed25519"
	"encoding/base64"
	"encoding/json"
	"net/http"
	"slices"
	"strings"
	"testing"
	"time"

	"rivetit-agent/internal/api"
	"rivetit-agent/internal/hypervisor"
	"rivetit-agent/internal/jobs"
)

func TestHypervisorCapabilityAndLocalSwitch(t *testing.T) {
	if hypervisor.Supported() && !slices.Contains(Capabilities(), "hypervisor") {
		t.Errorf("hypervisor not announced: %v", Capabilities())
	}
	if !slices.IsSorted(Capabilities()) {
		t.Error("the list must stay sorted")
	}
	r := newRig(t)
	cfg, _ := r.st.LoadConfig()
	cfg.DisableHypervisor = true
	r.st.SaveConfig(cfg)
	got := r.newAgent().capabilities()
	if slices.Contains(got, "hypervisor") || !slices.Contains(got, "logs:collect") || !slices.Contains(got, "remote:files") {
		t.Errorf("disable_hypervisor withdraws hypervisor and nothing else: %v", got)
	}
}

type fakeHost struct{ vms []api.HypervisorVM }

func (f fakeHost) Platform() string { return "proxmox" }
func (f fakeHost) Full(ctx context.Context, o hypervisor.Options) (*api.HypervisorReport, error) {
	return &api.HypervisorReport{Mode: "full", Platform: "proxmox", CollectedAt: "2026-10-20T12:00:00Z", Version: "8.2.4", VMs: f.vms}, nil
}
func (f fakeHost) States(ctx context.Context, o hypervisor.Options) (map[string]string, error) {
	return map[string]string{}, nil
}

func signHypervisor(t *testing.T, key ed25519.PrivateKey, cfg map[string]any) map[string]any {
	t.Helper()
	raw, _ := json.Marshal(cfg)
	canon, err := jobs.Canonical(raw)
	if err != nil {
		t.Fatal(err)
	}
	cfg["signature"] = base64.StdEncoding.EncodeToString(ed25519.Sign(key, append([]byte(hypervisor.SignDomain+"\n"), canon...)))
	return cfg
}

func hypervisorCfg(version string, seq int64) map[string]any {
	return map[string]any{"v": 1, "kind": "hypervisor_config", "platform": hypervisor.GOOS(), "full_interval_s": 600, "summary_interval_s": 120, "max_vms": 1000, "seq": seq, "version": version}
}

func TestHypervisorBlockFollowsTheSignedOffer(t *testing.T) {
	if !hypervisor.Supported() {
		t.Skip()
	}
	r := newRig(t)
	r.clk = &fakeClock{t: time.Date(2026, 10, 20, 12, 0, 0, 0, time.UTC)}
	r.a = r.newAgent()
	r.enroll()
	pub := r.s.key.Public().(ed25519.PublicKey)
	host := fakeHost{vms: []api.HypervisorVM{{ID: "qemu/100", Name: "dc01", Type: "qemu", State: "running"}}}
	r.a.hv = hypervisor.New(hypervisor.ManagerOptions{
		Detect: func(ctx context.Context) (hypervisor.Collector, error) { return host, nil },
		GOOS:   hypervisor.GOOS(), Now: r.a.now,
		Verify: func(domain string, raw []byte, sig string) error {
			return (&jobs.Verifier{PublicKey: pub}).VerifyDomain(domain, raw, sig)
		},
	})
	var features []string
	var cfg any
	r.s.mu.Lock()
	r.s.checkinFn = func(int, []byte) (int, any, http.Header) {
		resp := map[string]any{"ok": true, "next_check_in_s": 60, "jobs_pending": 0, "update": nil, "server_time": time.Now().UTC().Format(time.RFC3339)}
		c := map[string]any{"checks": []any{}, "collect_interval_s": 60}
		if cfg != nil {
			c["hypervisor"] = cfg
		}
		resp["config"] = c
		if features != nil {
			resp["features"] = features
		}
		return 200, resp, nil
	}
	r.s.mu.Unlock()
	ctx := context.Background()
	carries := func() bool {
		bodies := r.s.checkinBodies()
		return strings.Contains(string(bodies[len(bodies)-1]), `"hypervisor":{`)
	}
	wait := func() {
		for i := 0; i < 200 && !r.a.hv.Active(); i++ {
			time.Sleep(5 * time.Millisecond)
		}
	}
	r.a.checkIn(ctx, 0)
	if carries() || r.a.hv.Active() {
		t.Fatal("an old server: no block, nothing running")
	}
	cfg = signHypervisor(t, r.s.key, hypervisorCfg("a1a1a1a1a1a1a1a1", 1))
	r.a.checkIn(ctx, 0)
	if r.a.hv.Active() {
		t.Fatal("a config without the feature must not switch collection on")
	}
	features = []string{"hypervisor"}
	r.a.checkIn(ctx, 0) // learns the offer: collection starts in the background
	wait()
	if !r.a.hv.Active() {
		t.Fatal("feature + signed configuration: active")
	}
	got := false
	for i := 0; i < 100 && !got; i++ {
		time.Sleep(10 * time.Millisecond)
		r.a.checkIn(ctx, 0)
		got = carries()
	}
	if !got {
		t.Fatal("the full report must ride a check-in")
	}
	last := r.s.checkinBodies()
	var req struct {
		Hypervisor api.HypervisorReport `json:"hypervisor"`
		Caps       []string             `json:"capabilities"`
	}
	json.Unmarshal(last[len(last)-1], &req)
	if req.Hypervisor.Mode != "full" || len(req.Hypervisor.VMs) != 1 || req.Hypervisor.CfgVersion != "a1a1a1a1a1a1a1a1" || !slices.Contains(req.Caps, "hypervisor") {
		t.Fatalf("%+v", req)
	}
	r.a.checkIn(ctx, 0)
	if carries() {
		t.Fatal("an acknowledged full report is not sent again")
	}
	// forged configuration: refused, the last good stays
	_, other, _ := ed25519.GenerateKey(nil)
	cfg = signHypervisor(t, other, hypervisorCfg("b2b2b2b2b2b2b2b2", 2))
	r.a.checkIn(ctx, 0)
	if !r.a.hv.Active() {
		t.Fatal("a forged configuration keeps the last good one in force")
	}
	// the offer withdrawn
	features = nil
	r.a.checkIn(ctx, 0)
	if r.a.hv.Active() {
		t.Fatal("offer withdrawn: stopped")
	}
}
