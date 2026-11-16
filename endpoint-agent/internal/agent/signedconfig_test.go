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
		if err := acceptLogsConfig(c.raw, c.required, v.VerifyDomain); (err == nil) != c.ok {
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

// CORE-DS-26: a signed rollover adds a release key to the agent's trusted set, persists, and cannot be replayed.
func TestReleaseRolloverIsAppliedAndPersisted(t *testing.T) {
	r := newRig(t)
	oldPub, oldPriv, _ := ed25519.GenerateKey(rand.Reader)
	newPub, _, _ := ed25519.GenerateKey(rand.Reader)
	prev := update.EmbeddedReleaseKeys
	update.EmbeddedReleaseKeys = base64.StdEncoding.EncodeToString(oldPub)
	defer func() { update.EmbeddedReleaseKeys = prev }()
	if n := len(r.a.releaseKeys()); n != 1 {
		t.Fatalf("embedded keys: %d", n)
	}
	roll := &api.ReleaseRollover{Kind: update.RolloverKind, NewKey: base64.StdEncoding.EncodeToString(newPub), Seq: 1}
	roll.Signature = base64.StdEncoding.EncodeToString(ed25519.Sign(oldPriv, update.RolloverMessage(*roll)))
	r.a.applyRollover(roll)
	keys := r.a.releaseKeys()
	if len(keys) != 2 {
		t.Fatalf("keys after rollover: %d", len(keys))
	}
	// after a restart on the same state dir the new key is still trusted
	if n := len(r.newAgent().releaseKeys()); n != 2 {
		t.Fatalf("keys after restart: %d", n)
	}
	r.a.applyRollover(roll) // replay: ignored
	if n := len(r.a.releaseKeys()); n != 2 {
		t.Fatalf("replay changed the key set: %d", n)
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
