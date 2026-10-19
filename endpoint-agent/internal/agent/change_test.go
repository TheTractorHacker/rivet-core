package agent

import (
	"context"
	"crypto/ed25519"
	"encoding/base64"
	"encoding/json"
	"net/http"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"
	"time"

	"rivetit-agent/internal/change"
	"rivetit-agent/internal/jobs"
)

func TestChangeMonitoringCapabilitiesAndTheirLocalSwitch(t *testing.T) {
	caps := Capabilities()
	if change.Supported() && !slices.Contains(caps, "change:monitor") {
		t.Errorf("change:monitor not announced: %v", caps)
	}
	if !slices.Contains(caps, "logs:presets") {
		t.Errorf("logs:presets not announced: %v", caps)
	}
	if !slices.IsSorted(caps) {
		t.Error("the list must stay sorted")
	}
	r := newRig(t)
	cfg, _ := r.st.LoadConfig()
	cfg.DisableChange = true
	r.st.SaveConfig(cfg)
	a := r.newAgent()
	got := a.capabilities()
	if slices.Contains(got, "change:monitor") || !slices.Contains(got, "logs:collect") || !slices.Contains(got, "remote:files") {
		t.Errorf("disable_change withdraws change monitoring and nothing else: %v", got)
	}
	cfg.DisableChange, cfg.DisableLogs = false, true
	r.st.SaveConfig(cfg)
	a = r.newAgent()
	if slices.Contains(a.capabilities(), "logs:presets") || slices.Contains(a.capabilities(), "logs:collect") || !slices.Contains(a.capabilities(), "change:monitor") {
		t.Errorf("disable_logs withdraws the log capabilities, presets included: %v", a.capabilities())
	}
}

// signChange signs a configuration the way the server does: the domain line, a line feed, the canonical object without its signature.
func signChange(t *testing.T, key ed25519.PrivateKey, cfg map[string]any) map[string]any {
	t.Helper()
	raw, _ := json.Marshal(cfg)
	canon, err := jobs.Canonical(raw)
	if err != nil {
		t.Fatal(err)
	}
	cfg["signature"] = base64.StdEncoding.EncodeToString(ed25519.Sign(key, append([]byte(change.SignDomain+"\n"), canon...)))
	return cfg
}

func changeCfg(version string, watches ...map[string]any) map[string]any {
	ws := make([]any, len(watches))
	for i, w := range watches {
		ws[i] = w
	}
	return map[string]any{"v": 1, "kind": "change_config", "platform": change.Platform(), "interval_s": 60, "batch_max": 100, "rate_per_min": 600, "max_value_bytes": 256, "watches": ws, "version": version}
}

type changeOffer struct {
	features []string
	cfg      any
}

func (o *changeOffer) serve(r *rig) {
	r.s.mu.Lock()
	r.s.checkinFn = func(int, []byte) (int, any, http.Header) {
		resp := map[string]any{"ok": true, "next_check_in_s": 60, "jobs_pending": 0, "update": nil, "server_time": time.Now().UTC().Format(time.RFC3339)}
		cfg := map[string]any{"checks": []any{}, "collect_interval_s": 60}
		if o.cfg != nil {
			cfg["change"] = o.cfg
		}
		resp["config"] = cfg
		if o.features != nil {
			resp["features"] = o.features
		}
		return 200, resp, nil
	}
	r.s.mu.Unlock()
}

func TestChangeMonitoringFollowsTheLatestSignedOfferAndShipsWhatChanged(t *testing.T) {
	if change.Platform() != "linux" {
		t.Skip("the end-to-end run watches files on Linux")
	}
	r := newRig(t)
	r.clk = &fakeClock{t: time.Date(2026, 10, 18, 12, 0, 0, 0, time.UTC)}
	root, _ := filepath.EvalSymlinks(t.TempDir())
	etc := filepath.Join(root, "etc")
	os.MkdirAll(etc, 0o755)
	f := filepath.Join(etc, "app.conf")
	os.WriteFile(f, []byte("mode = safe\npassword = hunter2\n"), 0o644)
	cfg, _ := r.st.LoadConfig()
	cfg.ChangeExtraRoots = []string{etc}
	r.st.SaveConfig(cfg)
	r.a = r.newAgent()
	r.enroll()
	var shipped []string
	r.s.changeFn = func(body []byte) (int, any) {
		shipped = append(shipped, string(body))
		return 200, map[string]any{"ok": true, "accepted": 1}
	}
	signed := signChange(t, r.s.key, changeCfg("a1a1a1a1a1a1a1a1", map[string]any{"id": "custom.app", "kind": "file", "paths": []any{map[string]any{"path": f}}}))
	o := &changeOffer{}
	o.serve(r)
	ctx := context.Background()
	check := func(name string, want bool) {
		t.Helper()
		r.a.checkIn(ctx, 0)
		if got := r.a.chg.Active(); got != want {
			t.Errorf("%s: monitoring active = %v; want %v", name, got, want)
		}
	}
	check("an old server", false)
	o.cfg = signed
	check("a config without the feature", false)
	o.features = []string{"change:monitor"}
	check("the feature and a signed config", true)
	r.a.chg.Tick(ctx) // baseline: only the status heartbeat goes out
	if len(shipped) != 1 || strings.Contains(shipped[0], `"change":"`) {
		t.Fatalf("baseline: %v", shipped)
	}
	os.WriteFile(f, []byte("mode = open\npassword = hunter3\n"), 0o644)
	r.clk.Advance(61 * time.Second)
	r.a.chg.Tick(ctx)
	if len(shipped) != 2 {
		t.Fatalf("shipped %d", len(shipped))
	}
	var body struct {
		Changes []struct {
			Kind, Watch, Change, Target, Old, New, Summary string
		} `json:"changes"`
		ConfigVersion string `json:"config_version"`
	}
	if err := json.Unmarshal([]byte(shipped[1]), &body); err != nil || len(body.Changes) != 1 {
		t.Fatalf("%v %s", err, shipped[1])
	}
	c := body.Changes[0]
	if c.Kind != "file" || c.Watch != "custom.app" || c.Change != "modified" || c.Target != f || c.Summary != "+1 -1 lines" || body.ConfigVersion != "a1a1a1a1a1a1a1a1" {
		t.Errorf("%+v", c)
	}
	if strings.Contains(shipped[1], "hunter") {
		t.Fatalf("a secret reached the wire: %s", shipped[1])
	}
	r.s.mu.Lock()
	auth := r.s.changeAuth[len(r.s.changeAuth)-1]
	r.s.mu.Unlock()
	if !strings.HasPrefix(auth, "Bearer ") {
		t.Errorf("the device credential is sent: %q", auth)
	}
	// a configuration signed by another key is refused, and the last good one stays
	_, otherKey, _ := ed25519.GenerateKey(nil)
	o.cfg = signChange(t, otherKey, changeCfg("b2b2b2b2b2b2b2b2", map[string]any{"id": "custom.app", "kind": "file", "paths": []any{map[string]any{"path": f}}}))
	check("a forged configuration", true)
	// the offer withdrawn
	o.features = nil
	check("the offer withdrawn (switch off or load being shed)", false)
	o.features = []string{"change:monitor"}
	o.cfg = signed
	check("offered again", true)
	// the local switch wins
	cfg, _ = r.st.LoadConfig()
	cfg.DisableChange = true
	r.st.SaveConfig(cfg)
	r.a.buildClient()
	r.a.mu.Lock()
	r.a.cfg = cfg
	r.a.mu.Unlock()
	check("the local switch wins", false)
}
