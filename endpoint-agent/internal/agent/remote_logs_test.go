package agent

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"net/http"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"sync"
	"testing"
	"time"

	"rivetit-agent/internal/jobs"
	"rivetit-agent/internal/logs"
	"rivetit-agent/internal/store"
)

func TestTheRemoteAndLogCapabilitiesAreAnnounced(t *testing.T) {
	caps := Capabilities()
	for _, want := range []string{"remote:services", "remote:processes", "remote:files", "job:svc_list", "job:svc_control", "job:proc_list", "job:proc_kill", "job:file_get", "job:file_put"} {
		if !slices.Contains(caps, want) {
			t.Errorf("missing %s in %v", want, caps)
		}
	}
	if logs.Supported() && !slices.Contains(caps, "logs:collect") {
		t.Error("logs:collect not announced")
	}
	if !slices.IsSorted(caps) {
		t.Error("the list must stay sorted")
	}
	// the check-in carries it
	r := newRig(t)
	r.enroll()
	r.a.checkIn(context.Background(), 0)
	bodies := r.s.checkinBodies()
	got := decodeCheckin(t, bodies[len(bodies)-1])["capabilities"].([]any)
	var hasRemote bool
	for _, c := range got {
		hasRemote = hasRemote || c == "remote:files"
	}
	if !hasRemote {
		t.Errorf("the check-in's capabilities: %v", got)
	}
}

func TestLocalSwitchesRemoveWhatTheAgentAnnounces(t *testing.T) {
	r := newRig(t)
	cfg, _ := r.st.LoadConfig()
	cfg.DisableRemote = true
	r.st.SaveConfig(cfg)
	a := r.newAgent()
	caps := a.capabilities()
	for _, c := range caps {
		if strings.HasPrefix(c, "remote:") || (strings.HasPrefix(c, "job:") && jobs.IsRemoteType(strings.TrimPrefix(c, "job:"))) {
			t.Errorf("disable_remote still announces %s", c)
		}
	}
	if !slices.Contains(caps, "job:collect") || !slices.Contains(caps, "software_inventory") && len(caps) < 3 {
		t.Errorf("everything else stays: %v", caps)
	}
	cfg.DisableRemote, cfg.DisableLogs = false, true
	r.st.SaveConfig(cfg)
	a = r.newAgent()
	if slices.Contains(a.capabilities(), "logs:collect") || !slices.Contains(a.capabilities(), "remote:files") {
		t.Errorf("disable_logs: %v", a.capabilities())
	}
}

// logsOffer answers check-ins with an optional feature and config.logs.
type logsOffer struct {
	mu       sync.Mutex
	features []string
	logs     any
}

func (o *logsOffer) serve(r *rig) {
	r.s.mu.Lock()
	r.s.checkinFn = func(int, []byte) (int, any, http.Header) {
		o.mu.Lock()
		defer o.mu.Unlock()
		resp := map[string]any{"ok": true, "next_check_in_s": 60, "jobs_pending": 0, "update": nil, "server_time": time.Now().UTC().Format(time.RFC3339)}
		cfg := map[string]any{"checks": []any{}, "collect_interval_s": 60}
		if o.logs != nil {
			cfg["logs"] = o.logs
		}
		resp["config"] = cfg
		if o.features != nil {
			resp["features"] = o.features
		}
		return 200, resp, nil
	}
	r.s.mu.Unlock()
}

func TestLogCollectionFollowsTheLatestOffer(t *testing.T) {
	if !logs.Supported() {
		t.Skip("no log collection on this OS")
	}
	r := newRig(t)
	r.enroll()
	o := &logsOffer{}
	o.serve(r)
	ctx := context.Background()
	conf := map[string]any{"version": "v1", "interval_s": 60, "batch_max": 200, "max_message_bytes": 1024, "rate_per_min": 600,
		"sources": []any{map[string]any{"kind": "journal", "units": []string{"ssh.service"}, "min_level": "warning"}}}
	check := func(name string, want bool) {
		t.Helper()
		r.a.checkIn(ctx, 0)
		if got := r.a.logs.Active(); got != want {
			t.Errorf("%s: collection active = %v; want %v", name, got, want)
		}
	}
	check("an old server that never heard of logs", false)
	o.logs = conf
	check("a config without the feature", false)
	o.features = []string{"policies"}
	check("other features only", false)
	o.features = []string{"logs:collect", "policies"}
	check("the feature and the config", true)
	o.logs = "not an object"
	check("a malformed config block", false)
	o.logs = conf
	check("back on", true)
	o.features = nil
	check("the offer withdrawn (switch off or load being shed)", false)
	o.features = []string{"logs:collect"}
	check("offered again", true)
	cfg, _ := r.st.LoadConfig()
	cfg.DisableLogs = true
	r.st.SaveConfig(cfg)
	r.a.buildClient()
	check("the local switch wins", false)
}

func TestRemoteToolJobsRunThroughTheWholeAgent(t *testing.T) {
	if _, err := os.Stat("/proc/self/stat"); err != nil {
		t.Skip("needs /proc")
	}
	r := newRig(t)
	r.enroll()
	now := time.Now().UTC()
	job := func(id, typ string, params map[string]any) json.RawMessage {
		return r.s.signJob(map[string]any{"job_id": id, "attempt": 1, "type": typ, "script": "", "params": params, "timeout_s": 30, "max_output_bytes": 65536,
			"issued_at": now.Format(time.RFC3339), "expires_at": now.Add(time.Hour).Format(time.RFC3339)})
	}
	dir, _ := filepath.EvalSymlinks(t.TempDir())
	data := []byte(strings.Repeat("rivet", 2000))
	os.WriteFile(filepath.Join(dir, "report.txt"), data, 0o644)
	sum := sha256.Sum256(data)
	var tmu sync.Mutex
	var got []byte
	var lastHdr http.Header
	r.s.transferFn = func(rq *http.Request, body []byte) (int, any) {
		tmu.Lock()
		defer tmu.Unlock()
		lastHdr = rq.Header
		got = append(got, body...)
		return 200, map[string]any{"ok": true, "received": len(got), "complete": rq.Header.Get("X-Transfer-Last") == "1"}
	}
	token := strings.Repeat("ab", 32)
	r.s.jobsOut = []json.RawMessage{
		job("p1", "proc_list", map[string]any{"limit": 20, "sort": "mem"}),
		job("s1", "svc_control", map[string]any{"name": "rivetit-agent", "action": "stop"}),
		job("k1", "proc_kill", map[string]any{"pid": os.Getpid(), "name": "agent.test"}),
		job("f1", "file_get", map[string]any{"transfer_id": "0b1c2d3e-0000-4000-8000-000000000001", "token": token, "path": filepath.Join(dir, "report.txt"), "max_bytes": 1 << 20, "allow": dir, "chunk": 4096}),
		job("f2", "file_get", map[string]any{"transfer_id": "0b1c2d3e-0000-4000-8000-000000000002", "token": token, "path": "/etc/shadow", "max_bytes": 1 << 20, "allow": "/etc", "chunk": 4096}),
	}
	r.s.checkinFn = func(int, []byte) (int, any, http.Header) {
		return 200, map[string]any{"ok": true, "next_check_in_s": 60, "jobs_pending": 5}, nil
	}
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	r.a.newExecutor(ctx)
	r.a.checkIn(ctx, 0)
	deadline := time.Now().Add(30 * time.Second)
	final := func(id string) (reportOutput string, state string, ok bool) {
		r.s.mu.Lock()
		defer r.s.mu.Unlock()
		for _, rep := range r.s.reports {
			if rep.JobID == id && rep.State != "running" {
				return rep.Output, rep.State, true
			}
		}
		return "", "", false
	}
	for _, id := range []string{"p1", "s1", "k1", "f1", "f2"} {
		for {
			if _, _, ok := final(id); ok {
				break
			}
			if time.Now().After(deadline) {
				t.Fatalf("job %s never reported", id)
			}
			time.Sleep(20 * time.Millisecond)
		}
	}
	out, st, _ := final("p1")
	var pl struct {
		Count     int `json:"count"`
		Processes []struct {
			PID  int    `json:"pid"`
			Name string `json:"name"`
		} `json:"processes"`
	}
	if st != "succeeded" || json.Unmarshal([]byte(out), &pl) != nil || pl.Count == 0 || len(pl.Processes) != pl.Count || strings.Contains(out, "cmdline") {
		t.Errorf("proc_list: %s %.200s", st, out)
	}
	if out, st, _ := final("s1"); st != "failed" || !strings.Contains(out, "refused_self") {
		t.Errorf("the agent's own service must be refused: %s %s", st, out)
	}
	if out, st, _ := final("k1"); st != "failed" || !strings.Contains(out, "refused_self") {
		t.Errorf("the agent must refuse to kill itself: %s %s", st, out)
	}
	out, st, _ = final("f1")
	if st != "succeeded" || !strings.Contains(out, hex.EncodeToString(sum[:])) {
		t.Errorf("file_get: %s %s", st, out)
	}
	tmu.Lock()
	if string(got) != string(data) || lastHdr.Get("X-Transfer-Sha256") != hex.EncodeToString(sum[:]) || lastHdr.Get("X-Transfer-Token") != token || !strings.HasPrefix(lastHdr.Get("Authorization"), "Bearer ") {
		t.Errorf("transfer: %d bytes, headers %v", len(got), lastHdr)
	}
	tmu.Unlock()
	if out, st, _ := final("f2"); st != "failed" || !strings.Contains(out, "path_denied") || strings.Contains(out, "root:") {
		t.Errorf("/etc/shadow: %s %s", st, out)
	}
	_ = store.StatusLinked
}

func TestPatchCapabilitiesAndTheirLocalSwitch(t *testing.T) {
	caps := Capabilities()
	for _, want := range []string{"patch:scan", "patch:install", "job:patch_scan", "job:patch_install"} {
		if !slices.Contains(caps, want) {
			t.Errorf("missing %s in %v", want, caps)
		}
	}
	r := newRig(t)
	cfg, _ := r.st.LoadConfig()
	cfg.DisablePatching = true
	r.st.SaveConfig(cfg)
	a := r.newAgent()
	got := a.capabilities()
	for _, c := range got {
		if strings.HasPrefix(c, "patch:") || c == "job:patch_scan" || c == "job:patch_install" {
			t.Errorf("disable_patching still announces %s", c)
		}
	}
	if !slices.Contains(got, "remote:files") || !slices.Contains(got, "job:svc_list") {
		t.Errorf("the remote tools stay: %v", got)
	}
	cfg.DisablePatching, cfg.DisableRemote = false, true
	r.st.SaveConfig(cfg)
	a = r.newAgent()
	if !slices.Contains(a.capabilities(), "patch:scan") || slices.Contains(a.capabilities(), "remote:files") {
		t.Errorf("disable_remote leaves patching alone: %v", a.capabilities())
	}
}

// disable_software withdraws the software capabilities (and their job: entries) and touches nothing else (RMM Phase 5).
func TestDisableSoftwareWithdrawsOnlySoftware(t *testing.T) {
	if !jobs.SupportsType(jobs.TypeSoftwareDetect) {
		t.Skip("software jobs not supported on this platform")
	}
	r := newRig(t)
	a := r.newAgent()
	for _, c := range []string{"software:detect", "software:install", "job:software_detect", "job:software_install", "patch:scan"} {
		if !slices.Contains(a.capabilities(), c) {
			t.Errorf("a default agent announces %s: %v", c, a.capabilities())
		}
	}
	cfg, _ := r.st.LoadConfig()
	cfg.DisableSoftware = true
	r.st.SaveConfig(cfg)
	a = r.newAgent()
	for _, c := range a.capabilities() {
		if strings.HasPrefix(c, "software:") || c == "job:software_detect" || c == "job:software_install" {
			t.Errorf("disable_software still announces %s", c)
		}
	}
	if !slices.Contains(a.capabilities(), "patch:scan") || !slices.Contains(a.capabilities(), "remote:files") {
		t.Errorf("disable_software leaves patching and the remote tools alone: %v", a.capabilities())
	}
}
