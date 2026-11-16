package agent

import (
	"crypto/ed25519"
	"crypto/rand"
	"encoding/base64"
	"encoding/json"
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
		if _, err := acceptLogsConfig(c.raw, c.required, v.VerifyDomain, "7", 0); (err == nil) != c.ok {
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

// CORE-DS-16 (review CRR-2): a signed config.logs is bound to its device and cannot be rolled back.
func TestAcceptLogsConfigDeviceAndSequence(t *testing.T) {
	pub, priv, _ := ed25519.GenerateKey(rand.Reader)
	v := &jobs.Verifier{PublicKey: pub}
	cfg := func(dev any, seq int) json.RawMessage {
		return signedLogs(t, priv, map[string]any{"version": "v1", "sources": []any{}, "device_id": dev, "seq": seq})
	}
	if seq, err := acceptLogsConfig(cfg(7, 5), true, v.VerifyDomain, "7", 3); err != nil || seq != 5 {
		t.Fatalf("own device, newer seq: %d %v", seq, err)
	}
	if _, err := acceptLogsConfig(cfg(8, 5), true, v.VerifyDomain, "7", 3); err == nil {
		t.Error("device A's signed logs config was accepted on device B")
	}
	if _, err := acceptLogsConfig(cfg(7, 2), true, v.VerifyDomain, "7", 3); err == nil {
		t.Error("a rolled-back logs config (seq 2 after 3) was accepted")
	}
	if seq, err := acceptLogsConfig(cfg(7, 3), true, v.VerifyDomain, "7", 3); err != nil || seq != 3 {
		t.Errorf("the same seq again must be fine: %d %v", seq, err)
	}
}
