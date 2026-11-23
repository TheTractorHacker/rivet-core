package agent

import (
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"encoding/base64"
	"encoding/json"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"rivetit-agent/internal/api"
	"rivetit-agent/internal/jobs"
	"rivetit-agent/internal/store"
	"rivetit-agent/internal/update"
)

func signedLogs(t *testing.T, priv ed25519.PrivateKey, body map[string]any) json.RawMessage {
	t.Helper()
	raw, _ := json.Marshal(body)
	canon, err := jobs.Canonical(raw)
	if err != nil {
		t.Fatal(err)
	}
	body["signature"] = base64.StdEncoding.EncodeToString(ed25519.Sign(priv, append([]byte(LogsConfigSignDomain+"\n"), canon...)))
	raw, _ = json.Marshal(body)
	return raw
}

// CORE-DS-16: config.logs must carry a valid domain-separated signature when the server signs it, and an unsigned one is refused once the server has
// said it signs (signed_config >= 1) or the local config demands it.
func TestAcceptLogsConfig(t *testing.T) {
	pub, priv, _ := ed25519.GenerateKey(rand.Reader)
	v := &jobs.Verifier{PublicKey: pub}
	body := map[string]any{"version": "v1", "sources": []any{}}
	signed := signedLogs(t, priv, map[string]any{"version": "v1", "sources": []any{}})
	unsigned, _ := json.Marshal(body)
	tampered := json.RawMessage(strings.Replace(string(signed), `"v1"`, `"v2"`, 1))
	_, other, _ := ed25519.GenerateKey(rand.Reader)
	wrongKey := signedLogs(t, other, map[string]any{"version": "v1", "sources": []any{}})

	cases := []struct {
		name     string
		raw      json.RawMessage
		required bool
		ok       bool
	}{
		{"signed, not required", signed, false, true},
		{"signed, required", signed, true, true},
		{"unsigned, older server (tolerated)", unsigned, false, true},
		{"unsigned, signing required", unsigned, true, false},
		{"tampered", tampered, false, false},
		{"signed by another key", wrongKey, false, false},
	}
	for _, c := range cases {
		if _, err := acceptLogsConfig(c.raw, c.required, false, v.VerifyDomain, "7", 0); (err == nil) != c.ok {
			t.Errorf("%s: %v", c.name, err)
		}
	}
}

// CORE-DS-16: a signed check that names a device is only valid on that device (a signed check could otherwise be replayed to every device).
func TestCheckDeviceBinding(t *testing.T) {
	if err := checkBoundToDevice([]byte(`{"key":"k","device_id":7}`), "7"); err != nil {
		t.Fatal(err)
	}
	if err := checkBoundToDevice([]byte(`{"key":"k","device_id":"7"}`), "7"); err != nil {
		t.Fatal(err)
	}
	if err := checkBoundToDevice([]byte(`{"key":"k","device_id":8}`), "7"); err == nil {
		t.Fatal("a check for another device was accepted")
	}
	if err := checkBoundToDevice([]byte(`{"key":"k"}`), "7"); err != nil {
		t.Fatalf("a check without a device binding (older server) must still verify: %v", err)
	}
}

// CORE-DS-26 (review CRR-1): a signed rollover adds the new key AND retires the revoked ones, persists, cannot be replayed or skip ahead.
func TestReleaseRolloverAddsAndRevokes(t *testing.T) {
	r := newRig(t)
	oldPub, oldPriv, _ := ed25519.GenerateKey(rand.Reader)
	newPub, newPriv, _ := ed25519.GenerateKey(rand.Reader)
	b64 := base64.StdEncoding.EncodeToString
	prev := update.EmbeddedReleaseKeys
	update.EmbeddedReleaseKeys = b64(oldPub)
	defer func() { update.EmbeddedReleaseKeys = prev }()
	if n := len(r.a.releaseKeys()); n != 1 {
		t.Fatalf("embedded keys: %d", n)
	}
	mk := func(priv ed25519.PrivateKey, newKey ed25519.PublicKey, revoke []string, seq int) *api.ReleaseRollover {
		x := &api.ReleaseRollover{Kind: update.RolloverKind, NewKey: b64(newKey), Revoke: revoke, Seq: seq}
		x.Signature = b64(ed25519.Sign(priv, update.RolloverMessage(*x)))
		return x
	}
	// 1: add the new key but keep the old one
	r.a.applyRollover(mk(oldPriv, newPub, nil, 1))
	if n := len(r.a.releaseKeys()); n != 2 {
		t.Fatalf("keys after rollover 1: %d", n)
	}
	// a skip-ahead sequence is refused and does not block the next legitimate one
	r.a.applyRollover(mk(oldPriv, newPub, nil, 1<<40))
	// 2: signed by the new key, revokes the (leaked) old one
	r.a.applyRollover(mk(newPriv, newPub, []string{b64(oldPub)}, 2))
	keys := r.a.releaseKeys()
	if len(keys) != 1 || !keys[0].Equal(newPub) {
		t.Fatalf("the revoked key is still trusted: %d keys", len(keys))
	}
	// the revoked key can no longer sign a rollover (nor an update)
	r.a.applyRollover(mk(oldPriv, oldPub, nil, 3))
	if n := len(r.a.releaseKeys()); n != 1 {
		t.Fatalf("a revoked key signed a rollover: %d keys", n)
	}
	// persisted across a restart
	if n := len(r.newAgent().releaseKeys()); n != 1 {
		t.Fatalf("keys after restart: %d", n)
	}
}

// CORE-DS-26: the local switch disable_update stops self-update completely.
func TestDisableUpdateSwitch(t *testing.T) {
	r := newRig(t)
	cfg, _ := r.st.LoadConfig()
	cfg.DisableUpdate = true
	r.st.SaveConfig(cfg)
	a := r.newAgent()
	if !a.updateDisabled() {
		t.Fatal("disable_update not honoured")
	}
}

// CORE-DS-16 (review CRR-2): once binding is required (signed_config >= 2 or the local switch) a signed config.logs MUST carry a string device_id equal to
// this device and a seq that does not go back.
func TestAcceptLogsConfigBinding(t *testing.T) {
	pub, priv, _ := ed25519.GenerateKey(rand.Reader)
	v := &jobs.Verifier{PublicKey: pub}
	mk := func(extra map[string]any) json.RawMessage {
		m := map[string]any{"version": "v1", "sources": []any{}}
		for k, x := range extra {
			m[k] = x
		}
		return signedLogs(t, priv, m)
	}
	cases := []struct {
		name  string
		raw   json.RawMessage
		bound bool
		ok    bool
	}{
		{"bound, newer seq", mk(map[string]any{"device_id": "7", "seq": 5}), true, true},
		{"same seq again (the current config)", mk(map[string]any{"device_id": "7", "seq": 3}), true, true},
		{"missing device_id", mk(map[string]any{"seq": 5}), true, false},
		{"null device_id", mk(map[string]any{"device_id": nil, "seq": 5}), true, false},
		{"wrong device_id", mk(map[string]any{"device_id": "8", "seq": 5}), true, false},
		{"integer device_id", mk(map[string]any{"device_id": 7, "seq": 5}), true, false},
		{"empty device_id", mk(map[string]any{"device_id": "", "seq": 5}), true, false},
		{"missing seq", mk(map[string]any{"device_id": "7"}), true, false},
		{"replayed (lower) seq", mk(map[string]any{"device_id": "7", "seq": 2}), true, false},
		{"string seq", mk(map[string]any{"device_id": "7", "seq": "5"}), true, false},
		// below signed_config 2 the members are optional, but checked strictly when present
		{"older server, members absent", mk(nil), false, true},
		{"older server, wrong device_id", mk(map[string]any{"device_id": "8", "seq": 5}), false, false},
		{"older server, non-string device_id", mk(map[string]any{"device_id": 7}), false, false},
		{"older server, replayed seq", mk(map[string]any{"device_id": "7", "seq": 1}), false, false},
	}
	for _, c := range cases {
		if _, err := acceptLogsConfig(c.raw, true, c.bound, v.VerifyDomain, "7", 3); (err == nil) != c.ok {
			t.Errorf("%s: err=%v, want ok=%v", c.name, err, c.ok)
		}
	}
	if seq, err := acceptLogsConfig(mk(map[string]any{"device_id": "7", "seq": 9}), true, true, v.VerifyDomain, "7", 3); err != nil || seq != 9 {
		t.Fatalf("seq to remember: %d %v", seq, err)
	}
	// an unsigned block is refused once binding is required, even if signed_config 1 was never announced
	unsigned, _ := json.Marshal(map[string]any{"version": "v1", "sources": []any{}})
	if _, err := acceptLogsConfig(unsigned, false, true, v.VerifyDomain, "7", 3); err == nil {
		t.Error("an unsigned config was accepted while binding is required")
	}
}

// bindingRequired follows the announced version: 1 does not require it, 2 does, and an unreadable state fails closed.
func TestBindingRequiredFollowsTheAnnouncedVersion(t *testing.T) {
	r := newRig(t)
	if r.a.bindingRequired() {
		t.Fatal("required on a server that announced nothing")
	}
	r.a.noteSignedConfig(&api.CheckinResponse{Config: &api.ServerConfig{SignedConfig: 1}})
	if r.a.bindingRequired() {
		t.Fatal("signed_config 1 must not require the binding")
	}
	r.a.noteSignedConfig(&api.CheckinResponse{Config: &api.ServerConfig{SignedConfig: 2}})
	if !r.a.bindingRequired() {
		t.Fatal("signed_config 2 must require the binding")
	}
}

// CORE-DS-16 (review CRR-3): an unreadable state.json must not switch the signing requirement off.
func TestSigningRequiredFailsClosedWhenStateIsUnreadable(t *testing.T) {
	r := newRig(t)
	if r.a.signingRequired() {
		t.Fatal("required on a fresh install of an old server")
	}
	if err := os.WriteFile(filepath.Join(r.st.Dir, "state.json"), []byte("{corrupt"), 0o600); err != nil {
		t.Fatal(err)
	}
	if !r.a.signingRequired() {
		t.Fatal("an unreadable state made the agent accept unsigned configuration")
	}
}

// ... and the highest announced version is remembered in memory as well.
func TestSigningRequiredIsRememberedInMemory(t *testing.T) {
	r := newRig(t)
	r.a.noteSignedConfig(&api.CheckinResponse{Config: &api.ServerConfig{SignedConfig: 1}})
	os.WriteFile(filepath.Join(r.st.Dir, "state.json"), []byte(`{"signed_config_v":0}`), 0o600)
	if !r.a.signingRequired() {
		t.Fatal("the announced version was forgotten")
	}
}

// review R3-2: an agent that missed a rollover catches up from the chain in ONE response, in order.
func TestRolloverChainCatchesUp(t *testing.T) {
	r := newRig(t)
	k1, p1, _ := ed25519.GenerateKey(rand.Reader)
	k2, p2, _ := ed25519.GenerateKey(rand.Reader)
	k3, _, _ := ed25519.GenerateKey(rand.Reader)
	b64 := base64.StdEncoding.EncodeToString
	prev := update.EmbeddedReleaseKeys
	update.EmbeddedReleaseKeys = b64(k1)
	defer func() { update.EmbeddedReleaseKeys = prev }()
	mk := func(priv ed25519.PrivateKey, nk ed25519.PublicKey, revoke []string, seq int) api.ReleaseRollover {
		x := api.ReleaseRollover{Kind: update.RolloverKind, NewKey: b64(nk), Revoke: revoke, Seq: seq}
		x.Signature = b64(ed25519.Sign(priv, update.RolloverMessage(x)))
		return x
	}
	// seq 1 (k1 adds k2) is MISSING from the single message; the chain carries 2 then 1, out of order
	chain := []api.ReleaseRollover{mk(p2, k3, []string{b64(k1)}, 2), mk(p1, k2, nil, 1)}
	r.a.applyRollovers(&api.CheckinResponse{ReleaseRollovers: chain})
	keys := r.a.releaseKeys()
	if len(keys) != 2 || keys[0].Equal(k1) || keys[1].Equal(k1) {
		t.Fatalf("after the chain %d keys, k1 still trusted", len(keys))
	}
}

// review CRR-1: an unreadable state trusts nothing and still requires verification; a revoked-everything state never means "verification off".
func TestReleaseStateErrorFailsClosed(t *testing.T) {
	r := newRig(t)
	prev := update.EmbeddedReleaseKeys
	k, _, _ := ed25519.GenerateKey(rand.Reader)
	update.EmbeddedReleaseKeys = base64.StdEncoding.EncodeToString(k)
	defer func() { update.EmbeddedReleaseKeys = prev }()
	if len(r.a.releaseKeys()) != 1 || !r.a.releaseRequired() {
		t.Fatal("embedded key not trusted / not required")
	}
	os.WriteFile(filepath.Join(r.st.Dir, "state.json"), []byte("{corrupt"), 0o600)
	if len(r.a.releaseKeys()) != 0 {
		t.Fatal("an unreadable state still trusted keys (revoked filter dropped)")
	}
	if !r.a.releaseRequired() {
		t.Fatal("an unreadable state switched verification off")
	}
}

// review R3-3: the per-server signing memory (logs sequence, announced signed_config version) is reset when the device enrols, so a move to another
// server does not inherit the old server's high-water marks.
func TestEnrolResetsPerServerSigningState(t *testing.T) {
	r := newRig(t)
	_ = r.st.Update(func(s *store.State) error { s.LogsConfigSeq, s.SignedConfigV = 99, 2; return nil })
	r.a.signedSeen.Store(2)
	r.enroll()
	st, _ := r.st.LoadState()
	if st.LogsConfigSeq != 0 || st.SignedConfigV != 0 || r.a.signedSeen.Load() != 0 {
		t.Fatalf("not reset: logs_seq=%d signed_v=%d seen=%d", st.LogsConfigSeq, st.SignedConfigV, r.a.signedSeen.Load())
	}
}

// review R4-2: the enrolment response's signed_config announcement applies immediately (its checks run before the first check-in).
func TestEnrolAppliesTheSignedConfigAnnouncement(t *testing.T) {
	r := newRig(t)
	r.s.enrollFn = func(api.EnrollRequest) (int, any, http.Header) {
		return 201, map[string]any{"device_id": 7, "signing_key_id": "key-1", "device_token": "tok", "check_in_interval_s": 60,
			"status": "linked", "config": map[string]any{"checks": []any{}, "signed_config": 1}}, nil
	}
	r.enroll()
	if !r.a.signingRequired() {
		t.Fatal("unsigned checks are still accepted after an enrolment that announced signed_config")
	}
}

// review R4-2 polish: the enrolment's signed_config is PERSISTED in the same state update, so a restart before the first check-in keeps it.
func TestEnrolSignedConfigSurvivesARestart(t *testing.T) {
	r := newRig(t)
	r.s.enrollFn = func(api.EnrollRequest) (int, any, http.Header) {
		return 201, map[string]any{"device_id": 7, "signing_key_id": "key-1", "device_token": "tok", "check_in_interval_s": 60,
			"status": "linked", "config": map[string]any{"checks": []any{}, "signed_config": 2}}, nil
	}
	r.enroll()
	a2 := r.newAgent() // a fresh process on the same state directory: no in-memory announcement
	if !a2.signingRequired() || !a2.bindingRequired() {
		t.Fatal("the announcement was lost across a restart")
	}
}

// CORE-AGENT-1 / Chain C step 2: a release-stamped build with no embedded or learned release key requires release verification (and so refuses every
// update, see update.ErrNoReleaseKey); an unstamped build (dev, test, build host, every binary already deployed) keeps the old behaviour.
func TestReleaseBuildWithoutAnchorRequiresReleaseVerification(t *testing.T) {
	r := newRig(t)
	prevK, prevB := update.EmbeddedReleaseKeys, update.ReleaseBuild
	defer func() { update.EmbeddedReleaseKeys, update.ReleaseBuild = prevK, prevB }()
	update.EmbeddedReleaseKeys = ""
	update.ReleaseBuild = ""
	if r.a.releaseRequired() {
		t.Fatal("an unstamped build without keys must keep the legacy behaviour")
	}
	update.ReleaseBuild = "1"
	if !r.a.releaseRequired() || len(r.a.releaseKeys()) != 0 {
		t.Fatalf("a release build without an anchor: required=%v keys=%d", r.a.releaseRequired(), len(r.a.releaseKeys()))
	}
	keys, required, err := r.a.releaseTrust()
	if err != nil || !required || len(keys) != 0 {
		t.Fatalf("releaseTrust: keys=%d required=%v err=%v", len(keys), required, err)
	}
	k, _, _ := ed25519.GenerateKey(rand.Reader)
	update.EmbeddedReleaseKeys = base64.StdEncoding.EncodeToString(k)
	if keys := r.a.releaseKeys(); len(keys) != 1 || !r.a.releaseRequired() {
		t.Fatalf("a release build with an anchor: %d keys", len(keys))
	}
}

// checkResults runs one check-in (answered by checks) and one sample, and returns status|detail per check key.
func checkResults(t *testing.T, r *rig, checks ...json.RawMessage) map[string]string {
	t.Helper()
	r.s.checkinFn = func(int, []byte) (int, any, http.Header) {
		return 200, map[string]any{"ok": true, "next_check_in_s": 60, "jobs_pending": 0, "config": map[string]any{"checks": checks}}, nil
	}
	ctx := context.Background()
	r.a.checkIn(ctx, 0)
	r.a.sample(ctx)
	snap := r.a.ring.Snapshot()
	out := map[string]string{}
	for _, c := range snap[len(snap)-1].Checks {
		out[c.Key] = c.Status + "|" + c.Detail
	}
	return out
}

// CORE-AGENT-4 / Chain G (agent side): once this server has delivered a check list in which every check is signed (Core signs every check type even
// with signed_config off), an unsigned check can only have been injected on the way and is refused, without waiting for the administrator to switch
// signed_config on. A server that never signed everything keeps the old behaviour (older servers, the e2e fake server); allow_unsigned_checks undoes
// the learned requirement; a new enrolment forgets it.
func TestUnsignedChecksAreRefusedOnceTheServerSignedEveryCheck(t *testing.T) {
	r := newRig(t)
	r.enroll()
	unsigned, _ := json.Marshal(map[string]any{"key": "injected", "type": "pending_reboot", "params": map[string]any{}, "interval_s": 30})
	signed := r.s.signJob(map[string]any{"key": "real", "type": "pending_reboot", "params": map[string]any{}, "interval_s": 30})
	// an older server (or one that has not signed everything yet): unsigned checks still run
	if res := checkResults(t, r, unsigned); strings.HasPrefix(res["injected"], "unknown|") {
		t.Fatalf("an unsigned check from a server that never signed was refused: %v", res)
	}
	if res := checkResults(t, r, signed, unsigned); strings.HasPrefix(res["injected"], "unknown|") {
		t.Fatalf("a partly signed list must not switch the requirement on: %v", res)
	}
	// the server delivers a fully signed list: from now on an injected unsigned check is refused
	if res := checkResults(t, r, signed); !strings.HasPrefix(res["real"], "ok|") {
		t.Fatalf("the signed check did not run: %v", res)
	}
	res := checkResults(t, r, signed, unsigned)
	if !strings.HasPrefix(res["injected"], "unknown|") || !strings.Contains(res["injected"], "refused") || !strings.HasPrefix(res["real"], "ok|") {
		t.Fatalf("an unsigned check injected after a fully signed list ran: %v", res)
	}
	// it survives a restart
	r.a = r.newAgent()
	if res := checkResults(t, r, unsigned); !strings.HasPrefix(res["injected"], "unknown|") {
		t.Fatalf("after a restart: %v", res)
	}
	// the local switch turns the learned requirement off
	cfg, _ := r.st.LoadConfig()
	cfg.AllowUnsignedChecks = true
	r.st.SaveConfig(cfg)
	r.a = r.newAgent()
	if res := checkResults(t, r, unsigned); strings.HasPrefix(res["injected"], "unknown|") {
		t.Fatalf("allow_unsigned_checks did not apply: %v", res)
	}
	// ... but never overrides require_signed_config
	cfg.RequireSignedConfig = true
	r.st.SaveConfig(cfg)
	r.a = r.newAgent()
	if res := checkResults(t, r, unsigned); !strings.HasPrefix(res["injected"], "unknown|") {
		t.Fatalf("allow_unsigned_checks overrode require_signed_config: %v", res)
	}
	// a new enrolment (perhaps another server) forgets what the old server did
	cfg.AllowUnsignedChecks, cfg.RequireSignedConfig = false, false
	r.st.SaveConfig(cfg)
	r.a = r.newAgent()
	r.enroll()
	if st, _ := r.st.LoadState(); st.ChecksSignedSeen || r.a.checksSigned.Load() {
		t.Fatal("re-enrolment kept the learned requirement")
	}
}
