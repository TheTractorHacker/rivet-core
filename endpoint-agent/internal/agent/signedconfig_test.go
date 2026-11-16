package agent

import (
	"crypto/ed25519"
	"crypto/rand"
	"encoding/base64"
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"rivetit-agent/internal/api"
	"rivetit-agent/internal/jobs"
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
