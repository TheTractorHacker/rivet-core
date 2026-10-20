package agent

import (
	"crypto/ed25519"
	"encoding/base64"
	"encoding/json"
	"os"
	"path/filepath"
	"slices"
	"testing"

	"rivetit-agent/internal/api"
	"rivetit-agent/internal/jobs"
)

// Replays agent_check_os_vectors.json (written by the PHP server): the signed
// definitions, os member included, verify exactly as delivered, and the agent's
// own OS filter leaves the keys the server delivers to that OS.
func TestCheckOSVectors(t *testing.T) {
	b, err := os.ReadFile(filepath.Join("..", "..", "testdata", "vectors", "agent_check_os_vectors.json"))
	if err != nil {
		t.Fatal(err)
	}
	var v struct {
		TestKey struct {
			Pub string `json:"public_key_base64"`
		} `json:"test_key"`
		Signed    []api.CheckSpec     `json:"signed_checks"`
		Delivered map[string][]string `json:"delivered"`
	}
	if err := json.Unmarshal(b, &v); err != nil {
		t.Fatal(err)
	}
	pub, err := base64.StdEncoding.DecodeString(v.TestKey.Pub)
	if err != nil || len(pub) != ed25519.PublicKeySize {
		t.Fatalf("test key: %v", err)
	}
	ver := &jobs.Verifier{PublicKey: ed25519.PublicKey(pub)}
	sawOS := 0
	for _, c := range v.Signed {
		if err := ver.VerifyObject(c.Raw, c.Signature); err != nil {
			t.Errorf("%s: %v", c.Key, err)
		}
		if c.OS != "" {
			sawOS++
		}
	}
	if sawOS == 0 {
		t.Fatal("the vectors carry no os member")
	}
	specs := toStoreChecks(v.Signed)
	for goos, want := range v.Delivered {
		var got []string
		for _, c := range checksForOS(specs, goos) {
			got = append(got, c.Key)
		}
		if !slices.Equal(got, want) {
			t.Errorf("%s: agent keeps %v, server delivers %v", goos, got, want)
		}
	}
}
