package agent

import (
	"context"
	"encoding/json"
	"errors"
	"net/http"
	"os"
	"strings"
	"testing"
	"time"

	"rivetit-agent/internal/api"
	"rivetit-agent/internal/jobs"
	"rivetit-agent/internal/store"
)

func TestEnrollNewLinked(t *testing.T) {
	r := newRig(t)
	r.enroll()
	st, _ := r.st.LoadState()
	if st.DeviceID != "7" || st.Status != "linked" || st.MatchedAssetID == nil || *st.MatchedAssetID != 42 {
		t.Fatalf("%+v", st)
	}
	if tok, _ := r.st.LoadToken(); tok != r.s.token {
		t.Fatal("token not stored")
	}
	req := r.s.enrollReqs[0]
	if req.EnrollmentToken != "enr_token" || req.Device.InstallID != st.InstallID || req.Device.OS == "" ||
		req.Device.Arch == "" || req.Device.AgentVersion != "1.0.0" || req.Device.MachineGUID == nil || *req.Device.MachineGUID != "guid-1" {
		t.Fatalf("%+v", req.Device)
	}
	b, _ := json.Marshal(req.Device)
	for _, k := range []string{`"serial"`, `"manufacturer"`, `"model"`, `"mac_addresses"`} {
		if !strings.Contains(string(b), k) {
			t.Errorf("device JSON lacks %s: %s", k, b)
		}
	}
	if len(st.Checks) != 1 || st.Checks[0].Key != "disk_c" {
		t.Fatalf("config checks not stored: %+v", st.Checks)
	}
	if st.SigningPublicKey == "" {
		t.Fatal("signing key not pinned")
	}
	if fi, _ := os.Stat(r.st.TokenPath()); fi.Mode().Perm() != 0o600 {
		t.Fatalf("token perms %v", fi.Mode())
	}
}

func TestEnrollPendingAndAmbiguousStatusText(t *testing.T) {
	for _, status := range []string{"pending_approval", "ambiguous"} {
		r := newRig(t)
		r.s.enrollFn = r.s.enrollOK(status)
		r.enroll()
		st, _ := r.st.LoadState()
		cfg, _ := r.st.LoadConfig()
		txt := Describe(st, cfg, true)
		want := map[string]string{"pending_approval": "WAITING FOR ADMIN APPROVAL", "ambiguous": "AMBIGUOUS"}[status]
		if st.Status != status || !strings.Contains(txt, want) {
			t.Fatalf("%s: %s", status, txt)
		}
		// low-rate check-in even though the server asked for 60s
		out := r.a.checkIn(context.Background(), 0)
		if !out.ok || out.delay < lowRateInterval {
			t.Fatalf("%s: delay %v", status, out.delay)
		}
	}
}

func TestEnrollErrors(t *testing.T) {
	cases := []struct {
		name   string
		code   int
		body   map[string]any
		header http.Header
		check  func(*api.APIError) bool
	}{
		{"invalid", 401, map[string]any{"error": "bad token", "code": "invalid_token"}, nil, func(e *api.APIError) bool { return e.AuthRejected() && !e.Transient() }},
		{"expired", 401, map[string]any{"error": "expired", "code": "expired"}, nil, func(e *api.APIError) bool { return e.Code == "expired" }},
		{"revoked", 401, map[string]any{"error": "revoked", "code": "revoked"}, nil, func(e *api.APIError) bool { return e.Revoked() }},
		{"rate limited", 429, map[string]any{"error": "slow down", "code": "rate_limited"}, http.Header{"Retry-After": {"7"}}, func(e *api.APIError) bool { return e.Transient() && e.RetryAfter == 7*time.Second }},
	}
	for _, c := range cases {
		r := newRig(t)
		r.s.enrollFn = func(api.EnrollRequest) (int, any, http.Header) { return c.code, c.body, c.header }
		_, err := r.a.Enroll(context.Background(), "x")
		var ae *api.APIError
		if !errors.As(err, &ae) || !c.check(ae) {
			t.Errorf("%s: %v", c.name, err)
		}
		if tok, _ := r.st.LoadToken(); tok != "" {
			t.Errorf("%s: token stored after failed enrollment", c.name)
		}
		if st, _ := r.st.LoadState(); st.DeviceID != "" || st.Status != store.StatusUnenrolled {
			t.Errorf("%s: state changed %+v", c.name, st)
		}
	}
}

func TestInstallIDStableAndReenrollRotates(t *testing.T) {
	r := newRig(t)
	r.enroll()
	st1, _ := r.st.LoadState()
	r.s.token = "devtok_ROTATED_fedcba9876"
	a2 := r.newAgent() // restart
	if _, err := a2.Enroll(context.Background(), "enr2"); err != nil {
		t.Fatal(err)
	}
	st2, _ := r.st.LoadState()
	if st1.InstallID == "" || st1.InstallID != st2.InstallID {
		t.Fatalf("install_id changed: %q -> %q", st1.InstallID, st2.InstallID)
	}
	if r.s.enrollReqs[0].Device.InstallID != r.s.enrollReqs[1].Device.InstallID {
		t.Fatal("re-enrollment sent a different install_id")
	}
	if tok, _ := r.st.LoadToken(); tok != "devtok_ROTATED_fedcba9876" {
		t.Fatal("credential not rotated")
	}
	if st2.Seq != st1.Seq {
		t.Fatal("seq must survive re-enrollment")
	}
}

func TestCheckinContentAndNullNeverZero(t *testing.T) {
	r := newRig(t)
	r.enroll()
	r.fp.fail["mem"], r.fp.fail["disks"] = true, true
	ctx := context.Background()
	r.a.sample(ctx)
	r.a.sample(ctx) // second sample => CPU delta exists
	if out := r.a.checkIn(ctx, 0); !out.ok {
		t.Fatal("check-in failed")
	}
	m := decodeCheckin(t, r.s.checkinBodies()[0])
	if m["seq"].(float64) != 1 || m["agent_version"] != "1.0.0" || m["collected_at"] == "" {
		t.Fatalf("%v", m)
	}
	met := m["metrics"].(map[string]any)
	if met["mem_pct"] != nil || met["disk"] != nil || met["net_rx_bps"] != nil || met["net_tx_bps"] != nil {
		t.Fatalf("failed metrics must be null, never 0: %v", met)
	}
	if met["cpu_pct"] == nil {
		t.Fatalf("cpu should have a value: %v", met)
	}
	if m["inventory"] == nil {
		t.Fatal("first check-in must carry inventory")
	}
	inv := m["inventory"].(map[string]any)
	if inv["cpu"] == nil || inv["serial"] != "SER123" || inv["pending_reboot"] != false {
		t.Fatalf("%v", inv)
	}
	if m["buffered"] == nil {
		t.Fatal("buffered must be an array, not null")
	}
	hasUpd := false
	for _, c := range m["checks"].([]any) {
		if c.(map[string]any)["key"] == "agent_update" {
			hasUpd = true
		}
	}
	if !hasUpd {
		t.Fatal("agent_update check missing")
	}
	if r.s.tokens[0] != "Bearer "+r.s.token {
		t.Fatalf("auth header %q", r.s.tokens[0])
	}
}

func TestInventoryOnlyOnChangeThenDaily(t *testing.T) {
	r := newRig(t)
	r.enroll()
	ctx := context.Background()
	r.a.checkIn(ctx, 0)
	r.a.checkIn(ctx, 0)
	bs := r.s.checkinBodies()
	if decodeCheckin(t, bs[0])["inventory"] == nil || decodeCheckin(t, bs[1])["inventory"] != nil {
		t.Fatal("inventory should be sent once, then only on change/daily")
	}
	// force it stale (daily) and past the local re-collect interval
	r.a.invAt = time.Now().Add(-time.Hour)
	_ = r.st.Update(func(s *store.State) error { s.InventorySentAt = time.Now().Add(-25 * time.Hour); return nil })
	r.a.checkIn(ctx, 0)
	if decodeCheckin(t, r.s.checkinBodies()[2])["inventory"] == nil {
		t.Fatal("daily inventory not sent")
	}
}

// The response to check-in #1 is lost; after a process restart the agent must
// resend the SAME seq with the SAME body, and the next one must be seq+1.
func TestSeqIdempotencyAcrossRestart(t *testing.T) {
	r := newRig(t)
	r.enroll()
	r.s.checkinFn = func(n int, _ []byte) (int, any, http.Header) {
		if n == 1 {
			return -1, nil, nil // recorded by the server, response lost
		}
		return 200, map[string]any{"ok": true, "next_check_in_s": 60, "jobs_pending": 0}, nil
	}
	ctx := context.Background()
	if out := r.a.checkIn(ctx, 0); out.ok {
		t.Fatal("expected failure")
	}
	a2 := r.newAgent() // crash + restart
	if out := a2.checkIn(ctx, 0); !out.ok {
		t.Fatal("retry failed")
	}
	a2.checkIn(ctx, 0)
	bs := r.s.checkinBodies()
	if len(bs) != 3 {
		t.Fatalf("%d check-ins", len(bs))
	}
	if string(bs[0]) != string(bs[1]) {
		t.Fatalf("retry body differs:\n%s\n%s", bs[0], bs[1])
	}
	if decodeCheckin(t, bs[0])["seq"].(float64) != 1 || decodeCheckin(t, bs[2])["seq"].(float64) != 2 {
		t.Fatal("seq not monotonic after ack")
	}
	if st, _ := r.st.LoadState(); st.Seq != 2 {
		t.Fatalf("persisted seq %d", st.Seq)
	}
}

func TestBufferedReplayOrderAndBounds(t *testing.T) {
	r := newRig(t)
	r.enroll()
	ctx := context.Background()
	r.s.checkinFn = func(n int, _ []byte) (int, any, http.Header) {
		if n <= 1 {
			return 503, map[string]any{"error": "down", "code": "unavailable"}, nil
		}
		return 200, map[string]any{"ok": true, "next_check_in_s": 60, "jobs_pending": 0}, nil
	}
	// outage: many samples accumulate (more than the 100 cap)
	r.a.sample(ctx)
	if out := r.a.checkIn(ctx, 0); out.ok {
		t.Fatal("should fail")
	}
	for i := 0; i < 130; i++ {
		r.a.sample(ctx)
	}
	if n := r.a.ring.Len(); n > 100 {
		t.Fatalf("ring unbounded: %d", n)
	}
	// retry of the in-flight request goes first (same seq, same body)...
	if out := r.a.checkIn(ctx, 1); !out.ok {
		t.Fatal("retry failed")
	}
	// ...then the backlog replays, oldest first, as `buffered`
	if out := r.a.checkIn(ctx, 0); !out.ok {
		t.Fatal("replay failed")
	}
	bs := r.s.checkinBodies()
	last := decodeCheckin(t, bs[len(bs)-1])
	buf := last["buffered"].([]any)
	if len(buf) == 0 || len(buf) > 100 {
		t.Fatalf("buffered %d", len(buf))
	}
	prev := ""
	for _, e := range buf {
		ts := e.(map[string]any)["collected_at"].(string)
		if ts < prev {
			t.Fatalf("buffered not oldest-first: %s after %s", ts, prev)
		}
		prev = ts
	}
	if r.a.ring.Len() != 0 {
		t.Fatal("ring not drained after ack")
	}
}

func TestRevokedGoesDormantAndStopsTalking(t *testing.T) {
	r := newRig(t)
	r.enroll()
	r.s.checkinFn = func(int, []byte) (int, any, http.Header) {
		return 401, map[string]any{"error": "revoked", "code": "revoked"}, nil
	}
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	done := make(chan error, 1)
	go func() { done <- r.a.Run(ctx) }()
	deadline := time.Now().Add(10 * time.Second)
	for {
		if st, _ := r.st.LoadState(); st.Dormant {
			break
		}
		if time.Now().After(deadline) {
			t.Fatal("never went dormant")
		}
		time.Sleep(20 * time.Millisecond)
	}
	if tok, _ := r.st.LoadToken(); tok != "" {
		t.Fatal("device token not wiped")
	}
	if _, err := os.Stat(r.st.TokenPath()); err == nil {
		t.Fatal("token file still exists")
	}
	n := r.s.count()
	time.Sleep(600 * time.Millisecond) // a spinning agent would hammer the server here
	if r.s.count() != n {
		t.Fatalf("dormant agent kept talking to the server (%d -> %d requests)", n, r.s.count())
	}
	st, _ := r.st.LoadState()
	cfg, _ := r.st.LoadConfig()
	if txt := Describe(st, cfg, false); !strings.Contains(txt, "DORMANT") {
		t.Fatalf("status text: %s", txt)
	}
	// jobs offered by a revoked-then-confused server are not fetched or run
	cancel()
	if err := <-done; err != nil {
		t.Fatal(err)
	}
	if r.s.hit("GET /api/v1/agent_jobs") != 0 {
		t.Fatal("jobs polled after revocation")
	}
}

func TestInvalidTokenIsNotWipedAndBacksOff(t *testing.T) {
	r := newRig(t)
	r.enroll()
	r.s.checkinFn = func(int, []byte) (int, any, http.Header) {
		return 401, map[string]any{"error": "bad", "code": "invalid_token"}, nil
	}
	out := r.a.checkIn(context.Background(), 0)
	if out.ok || out.delay < 0 {
		t.Fatal("expected failure with backoff")
	}
	if tok, _ := r.st.LoadToken(); tok == "" {
		t.Fatal("invalid_token must not wipe the credential")
	}
	if st, _ := r.st.LoadState(); st.Dormant {
		t.Fatal("invalid_token must not go dormant")
	}
}

func TestRateLimitedHonoursRetryAfter(t *testing.T) {
	r := newRig(t)
	r.enroll()
	r.s.checkinFn = func(int, []byte) (int, any, http.Header) {
		return 429, map[string]any{"error": "slow", "code": "rate_limited"}, http.Header{"Retry-After": {"120"}}
	}
	out := r.a.checkIn(context.Background(), 0)
	if out.ok || out.delay < 120*time.Second {
		t.Fatalf("delay %v", out.delay)
	}
}

func TestPoisonPayloadDropped(t *testing.T) {
	r := newRig(t)
	r.enroll()
	r.s.checkinFn = func(n int, _ []byte) (int, any, http.Header) {
		if n == 1 {
			return 400, map[string]any{"error": "bad payload", "code": "validation"}, nil
		}
		return 200, map[string]any{"ok": true, "next_check_in_s": 60, "jobs_pending": 0}, nil
	}
	ctx := context.Background()
	r.a.checkIn(ctx, 0)
	if in, _ := r.st.LoadInflight(); in != nil {
		t.Fatal("poison in-flight request retained")
	}
	if out := r.a.checkIn(ctx, 0); !out.ok {
		t.Fatal("agent wedged after a rejected payload")
	}
}

func TestJobsEndToEnd(t *testing.T) {
	r := newRig(t)
	r.enroll()
	now := time.Now().UTC()
	job := r.s.signJob(map[string]any{"job_id": "job-1", "attempt": 1, "type": jobs.ScriptType(), "script": "echo from-job; exit 0",
		"params": map[string]any{}, "timeout_s": 20, "max_output_bytes": 4096,
		"issued_at": now.Format(time.RFC3339), "expires_at": now.Add(time.Hour).Format(time.RFC3339)})
	forged := append([]byte(nil), job...)
	forged = []byte(strings.Replace(string(forged), "from-job", "pwned", 1))
	r.s.jobsOut = []json.RawMessage{job, forged}
	r.s.checkinFn = func(int, []byte) (int, any, http.Header) {
		return 200, map[string]any{"ok": true, "next_check_in_s": 60, "jobs_pending": 2}, nil
	}
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	r.a.newExecutor(ctx)
	r.a.checkIn(ctx, 0)
	deadline := time.Now().Add(20 * time.Second)
	for {
		r.s.mu.Lock()
		n := len(r.s.reports)
		r.s.mu.Unlock()
		if n >= 2 {
			break
		}
		if time.Now().After(deadline) {
			t.Fatal("job never reported")
		}
		time.Sleep(20 * time.Millisecond)
	}
	r.s.mu.Lock()
	defer r.s.mu.Unlock()
	last := r.s.reports[len(r.s.reports)-1]
	if last.JobID != "job-1" || last.State != "succeeded" || !strings.Contains(last.Output, "from-job") {
		t.Fatalf("%+v", last)
	}
	for _, rep := range r.s.reports {
		if strings.Contains(rep.Output, "pwned") {
			t.Fatal("forged job executed")
		}
	}
}

func TestBadUpdateIsReportedAsAgentUpdateCheck(t *testing.T) {
	r := newRig(t)
	exe := t.TempDir() + "/rivetit-agent"
	os.WriteFile(exe, []byte("x"), 0o700)
	r.a.o.Exe = exe
	a, err := New(Options{Store: r.a.o.Store, Version: "1.0.0", Exe: exe, Platform: r.fp, Rebooter: r.rb, Log: r.a.log, MinInterval: 20 * time.Millisecond})
	if err != nil {
		t.Fatal(err)
	}
	a.buildClient()
	r.enroll()
	r.s.checkinFn = func(int, []byte) (int, any, http.Header) {
		return 200, map[string]any{"ok": true, "next_check_in_s": 60, "jobs_pending": 0,
			"update": map[string]any{"version": "9.9.9", "url": r.s.ts.URL + "/bin", "sha256": strings.Repeat("a", 64),
				"signature": "AAAA", "min_version": "1.0.0"}}, nil
	}
	ctx := context.Background()
	a.checkIn(ctx, 0)
	st, _ := r.st.LoadState()
	if st.UpdateFailure == nil || st.UpdateFailure.Version != "9.9.9" {
		t.Fatalf("failure not recorded: %+v", st.UpdateFailure)
	}
	if a.restartWanted() {
		t.Fatal("restart requested for a rejected update")
	}
	a.sample(ctx)
	a.checkIn(ctx, 0)
	bs := r.s.checkinBodies()
	body := decodeCheckin(t, bs[len(bs)-1])
	found := false
	for _, c := range body["checks"].([]any) {
		cm := c.(map[string]any)
		if cm["key"] == "agent_update" && cm["status"] == "fail" && strings.Contains(cm["detail"].(string), "9.9.9") {
			found = true
		}
	}
	if !found {
		t.Fatalf("agent_update fail check missing: %v", body["checks"])
	}
}

func TestClockSkewCorrectsTimestampsAndJobExpiry(t *testing.T) {
	r := newRig(t)
	r.enroll()
	r.s.checkinFn = func(int, []byte) (int, any, http.Header) {
		return 200, map[string]any{"ok": true, "next_check_in_s": 60, "jobs_pending": 0,
			"server_time": time.Now().Add(2 * time.Hour).UTC().Format(time.RFC3339)}, nil
	}
	r.a.checkIn(context.Background(), 0)
	if d := time.Until(r.a.now()); d < time.Hour+50*time.Minute || d > 2*time.Hour+10*time.Minute {
		t.Fatalf("skew not applied: %v", d)
	}
	st, _ := r.st.LoadState()
	if st.ClockSkewS < 3600 {
		t.Fatalf("skew not persisted: %v", st.ClockSkewS)
	}
}

func TestNotEnrolledCheckinDoesNothing(t *testing.T) {
	r := newRig(t)
	if out := r.a.checkIn(context.Background(), 0); out.ok || r.s.count() != 0 {
		t.Fatal("must not call the server without a credential")
	}
}

func TestPendingEnrollTokenUsedByRunLoop(t *testing.T) {
	r := newRig(t)
	if err := r.st.SaveEnrollToken("enr_offline_install"); err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan error, 1)
	go func() { done <- r.a.Run(ctx) }()
	deadline := time.Now().Add(15 * time.Second)
	for r.s.hit("POST /api/v1/agent_checkin") == 0 {
		if time.Now().After(deadline) {
			t.Fatal("agent never enrolled+checked in")
		}
		time.Sleep(20 * time.Millisecond)
	}
	cancel()
	<-done
	if tok, _ := r.st.LoadEnrollToken(); tok != "" {
		t.Fatal("one-shot enrollment token retained")
	}
	if r.s.enrollReqs[0].EnrollmentToken != "enr_offline_install" {
		t.Fatal("wrong token used")
	}
}

func TestEnrollmentTokenNotLeakedInStateFiles(t *testing.T) {
	r := newRig(t)
	r.enroll()
	entries, _ := os.ReadDir(r.st.Dir)
	for _, e := range entries {
		b, _ := os.ReadFile(r.st.Dir + "/" + e.Name())
		if strings.Contains(string(b), "enr_token") {
			t.Fatalf("enrollment token persisted in %s", e.Name())
		}
	}
}

func TestScriptChecksRequireServerSignature(t *testing.T) {
	r := newRig(t)
	r.enroll()
	signed := r.s.signJob(map[string]any{"key": "chk_signed", "type": "script", "params": map[string]any{"script": "echo ok-signed; exit 0"}, "interval_s": 30})
	unsigned, _ := json.Marshal(map[string]any{"key": "chk_unsigned", "type": "script", "params": map[string]any{"script": "echo SHOULD-NOT-RUN; exit 0"}, "interval_s": 30})
	forged := []byte(strings.Replace(string(signed), "ok-signed", "evil", 1))
	forged = []byte(strings.Replace(string(forged), "chk_signed", "chk_forged", 1))
	r.s.checkinFn = func(int, []byte) (int, any, http.Header) {
		return 200, map[string]any{"ok": true, "next_check_in_s": 60, "jobs_pending": 0,
			"config": map[string]any{"checks": []json.RawMessage{signed, unsigned, forged}}}, nil
	}
	ctx := context.Background()
	r.a.checkIn(ctx, 0)
	r.a.sample(ctx)
	snap := r.a.ring.Snapshot()
	res := map[string]string{}
	for _, c := range snap[len(snap)-1].Checks {
		res[c.Key] = c.Status + "|" + c.Detail
	}
	if !strings.HasPrefix(res["chk_signed"], "ok|ok-signed") {
		t.Fatalf("signed script check did not run: %v", res)
	}
	for _, k := range []string{"chk_unsigned", "chk_forged"} {
		if !strings.HasPrefix(res[k], "unknown|") || !strings.Contains(res[k], "refused") {
			t.Fatalf("%s must be refused: %v", k, res[k])
		}
	}
}

// Regression: the real server sends device_id as a JSON INTEGER, both in the
// enroll response and inside signed jobs.
func TestIntegerDeviceIDEverywhere(t *testing.T) {
	r := newRig(t)
	r.enroll()
	if st, _ := r.st.LoadState(); st.DeviceID != "7" || st.SigningKeyID != "key-1" {
		t.Fatalf("%+v", st)
	}
	now := time.Now().UTC()
	mk := func(id string, dev any) json.RawMessage {
		return r.s.signJob(map[string]any{"job_id": id, "device_id": dev, "attempt": 1, "type": jobs.ScriptType(), "script": "echo ran-" + id,
			"params": map[string]any{}, "timeout_s": 20, "max_output_bytes": 4096,
			"issued_at": now.Format(time.RFC3339), "expires_at": now.Add(time.Hour).Format(time.RFC3339)})
	}
	good, wrong, asString := mk("j-int", 7), mk("j-wrong", 8), mk("j-str", "7")
	if !strings.Contains(string(good), `"device_id":7,`) {
		t.Fatalf("test job should carry an integer: %s", good)
	}
	r.s.jobsOut = []json.RawMessage{good, wrong, asString}
	r.s.checkinFn = func(int, []byte) (int, any, http.Header) {
		return 200, map[string]any{"ok": true, "next_check_in_s": 60, "jobs_pending": 3, "signing_key_id": "key-1"}, nil
	}
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	r.a.newExecutor(ctx)
	r.a.checkIn(ctx, 0)
	deadline := time.Now().Add(20 * time.Second)
	for {
		r.s.mu.Lock()
		n := len(r.s.reports)
		r.s.mu.Unlock()
		if n >= 4 {
			break
		}
		if time.Now().After(deadline) {
			t.Fatal("jobs never reported")
		}
		time.Sleep(20 * time.Millisecond)
	}
	r.s.mu.Lock()
	defer r.s.mu.Unlock()
	seen := map[string]bool{}
	for _, rep := range r.s.reports {
		seen[rep.JobID] = true
	}
	if !seen["j-int"] || !seen["j-str"] || seen["j-wrong"] {
		t.Fatalf("reports %v (wrong-device job must not run)", seen)
	}
}

func TestTLSRequired426KeepsInflightAndBacksOff(t *testing.T) {
	r := newRig(t)
	r.enroll()
	r.s.checkinFn = func(int, []byte) (int, any, http.Header) {
		return 426, map[string]any{"error": "tls required", "code": "tls_required"}, nil
	}
	out := r.a.checkIn(context.Background(), 0)
	if out.ok || out.delay < time.Minute {
		t.Fatalf("delay %v", out.delay)
	}
	if in, _ := r.st.LoadInflight(); in == nil {
		t.Fatal("in-flight check-in must be kept (not dropped as poison)")
	}
}

func TestUpdateResultReportedOnceAndSigningKeyRotationFlagged(t *testing.T) {
	r := newRig(t)
	r.enroll()
	_ = r.st.Update(func(s *store.State) error {
		s.UpdateResult = &store.UpdateResultS{Version: "2.0.0", State: "failed", Detail: "sha256 mismatch"}
		return nil
	})
	r.s.checkinFn = func(int, []byte) (int, any, http.Header) {
		return 200, map[string]any{"ok": true, "next_check_in_s": 60, "jobs_pending": 0, "signing_key_id": "key-2", "status": "linked", "matched_asset_id": 5}, nil
	}
	ctx := context.Background()
	r.a.checkIn(ctx, 0)
	r.a.checkIn(ctx, 0)
	bs := r.s.checkinBodies()
	ur, _ := decodeCheckin(t, bs[0])["update_result"].(map[string]any)
	if ur == nil || ur["version"] != "2.0.0" || ur["state"] != "failed" {
		t.Fatalf("update_result missing: %s", bs[0])
	}
	if decodeCheckin(t, bs[1])["update_result"] != nil {
		t.Fatal("update_result repeated after acknowledgement")
	}
	st, _ := r.st.LoadState()
	if !strings.Contains(st.LastError, "signing key rotated") || st.MatchedAssetID == nil || *st.MatchedAssetID != 5 {
		t.Fatalf("%+v", st)
	}
}

func TestPendingIntervalOverride(t *testing.T) {
	r := newRig(t)
	r.s.enrollFn = r.s.enrollOK("pending_approval")
	r.enroll()
	r.a.o.PendingInterval = 15 * time.Millisecond * 2
	out := r.a.checkIn(context.Background(), 0)
	if !out.ok || out.delay > time.Minute {
		t.Fatalf("override ignored: %v", out.delay)
	}
	// a response carrying status linked ends the low-rate mode
	r.s.checkinFn = func(int, []byte) (int, any, http.Header) {
		return 200, map[string]any{"ok": true, "next_check_in_s": 60, "jobs_pending": 0, "status": "linked", "matched_asset_id": 9}, nil
	}
	r.a.o.PendingInterval = 300 * time.Second
	r.a.checkIn(context.Background(), 0)
	if out := r.a.checkIn(context.Background(), 0); out.delay != 60*time.Second {
		t.Fatalf("linked device still at low rate: %v", out.delay)
	}
}
