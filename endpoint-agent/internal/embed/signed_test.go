package embed

import (
	"crypto/ed25519"
	"crypto/rand"
	"encoding/base64"
	"errors"
	"strings"
	"testing"
)

// CORE-DS-20: with a build-time trust anchor the installer trailer must carry a signature by the instance key; without one (every existing build) the
// unsigned trailer is read as before, and a signed trailer is still readable by an agent that knows no key.
func TestSignedTrailer(t *testing.T) {
	pub, priv, _ := ed25519.GenerateKey(rand.Reader)
	prev := EmbeddedTrustKeys
	defer func() { EmbeddedTrustKeys = prev }()

	unsigned := stamp(t, goodPayload())
	raw, err := Marshal(goodPayload(), t0)
	if err != nil {
		t.Fatal(err)
	}
	signedRaw, err := SignPayload(raw, priv)
	if err != nil {
		t.Fatal(err)
	}
	signed := Build(fakeExe, signedRaw)

	// no trust anchor: both parse (migration: existing installers keep working; an old agent already ignores the unknown member)
	EmbeddedTrustKeys = ""
	for name, f := range map[string][]byte{"unsigned": unsigned, "signed": signed} {
		if _, err := ParseBytes(f, t0); err != nil {
			t.Errorf("%s without a trust anchor: %v", name, err)
		}
	}

	EmbeddedTrustKeys = base64.StdEncoding.EncodeToString(pub)
	if _, err := ParseBytes(signed, t0); err != nil {
		t.Fatalf("signed with the trusted key: %v", err)
	}
	if _, err := ParseBytes(unsigned, t0); !errors.Is(err, ErrUnsigned) {
		t.Fatalf("an unsigned trailer was accepted by an agent with a trust anchor: %v", err)
	}
	// re-stamped by an attacker: valid footer hash, valid JSON, attacker's server URL, but the old signature (or their own key)
	tampered := strings.Replace(string(signedRaw), "rivet.example.com", "evil.example.net", 1)
	if _, err := ParseBytes(Build(fakeExe, []byte(tampered)), t0); !errors.Is(err, ErrUnsigned) {
		t.Fatalf("a re-stamped payload with the old signature was accepted: %v", err)
	}
	_, otherPriv, _ := ed25519.GenerateKey(rand.Reader)
	forged, _ := SignPayload([]byte(tampered), otherPriv)
	if _, err := ParseBytes(Build(fakeExe, forged), t0); !errors.Is(err, ErrUnsigned) {
		t.Fatalf("a payload signed by an untrusted key was accepted: %v", err)
	}
}

func TestSignPayloadCoversEveryMember(t *testing.T) {
	_, priv, _ := ed25519.GenerateKey(rand.Reader)
	raw, _ := Marshal(goodPayload(), t0)
	a, _ := SignPayload(raw, priv)
	b, _ := SignPayload([]byte(strings.Replace(string(raw), "Sales", "Other", 1)), priv)
	if string(a) == string(b) {
		t.Fatal("signatures equal for different payloads")
	}
}
