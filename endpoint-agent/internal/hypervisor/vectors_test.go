package hypervisor

import (
	"bytes"
	"crypto/ed25519"
	"encoding/base64"
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"rivetit-agent/internal/api"
	"rivetit-agent/internal/jobs"
)

type vectorFile struct {
	TestKey struct {
		PublicKey string `json:"public_key_base64"`
	} `json:"test_key"`
	Signed struct {
		Domain  string          `json:"domain"`
		Message string          `json:"message"`
		Config  json.RawMessage `json:"config"`
	} `json:"signed_config"`
	Reports []struct {
		Name        string          `json:"name"`
		AgentShaped bool            `json:"agent_shaped"`
		Input       json.RawMessage `json:"input"`
	} `json:"reports"`
}

func loadVectors(t *testing.T) vectorFile {
	t.Helper()
	b, err := os.ReadFile(filepath.Join("..", "..", "testdata", "vectors", "agent_hypervisor_vectors.json"))
	if err != nil {
		t.Fatal(err)
	}
	var v vectorFile
	if err := json.Unmarshal(b, &v); err != nil {
		t.Fatal(err)
	}
	return v
}

// The configuration the server signed (PHP, canonical JSON, the domain line, Ed25519) verifies in Go; one changed character breaks it.
func TestAConfigurationSignedByTheServerVerifiesAndOneChangedCharacterBreaksIt(t *testing.T) {
	v := loadVectors(t)
	pub, err := base64.StdEncoding.DecodeString(v.TestKey.PublicKey)
	if err != nil || len(pub) != ed25519.PublicKeySize {
		t.Fatal("bad test key")
	}
	ver := &jobs.Verifier{PublicKey: ed25519.PublicKey(pub)}
	cfg, err := Parse(v.Signed.Config, "linux", ver.VerifyDomain)
	if err != nil {
		t.Fatalf("the server's signed configuration was refused: %v", err)
	}
	if cfg.FullInterval.Seconds() != 600 || cfg.SummaryInterval.Seconds() != 120 || cfg.MaxVMs != 1000 || cfg.SnapshotLookups != 300 {
		t.Errorf("%+v", cfg)
	}
	if v.Signed.Domain != SignDomain || !strings.HasPrefix(v.Signed.Message, SignDomain+"\n{") {
		t.Errorf("domain %q", v.Signed.Domain)
	}
	canon, err := jobs.Canonical(v.Signed.Config)
	if err != nil || SignDomain+"\n"+string(canon) != v.Signed.Message {
		t.Errorf("canonical forms differ:\n go  %s\n php %s", canon, v.Signed.Message)
	}
	for _, from := range []string{`"full_interval_s": 600`, `"max_vms": 1000`, `"platform": "linux"`, `"seq": 1760000000`} {
		raw := strings.Replace(string(v.Signed.Config), from, strings.Replace(from, "0", "1", 1), 1)
		if raw == string(v.Signed.Config) {
			raw = strings.Replace(raw, from, strings.ToUpper(from), 1)
		}
		if _, err := Parse(json.RawMessage(raw), "linux", ver.VerifyDomain); err == nil {
			t.Errorf("a changed configuration verified: %s", from)
		}
	}
	if _, err := Parse(v.Signed.Config, "windows", ver.VerifyDomain); err == nil {
		t.Error("a Linux configuration on a Windows agent")
	}
	var m map[string]any
	json.Unmarshal(v.Signed.Config, &m)
	if err := ver.VerifyObject(v.Signed.Config, m["signature"].(string)); err == nil {
		t.Error("the signature of a hypervisor configuration must not verify as a job or a check")
	}
	if err := ver.VerifyDomain("rivetit-change-config-v1", v.Signed.Config, m["signature"].(string)); err == nil {
		t.Error("nor as a change configuration")
	}
}

// Every report a collector produces decodes into the agent's type and encodes back to the same JSON: no field is lost or renamed.
func TestAgentShapedReportsRoundTripWithoutLosingAField(t *testing.T) {
	v := loadVectors(t)
	n := 0
	for _, c := range v.Reports {
		if !c.AgentShaped {
			continue
		}
		n++
		var r api.HypervisorReport
		dec := json.NewDecoder(bytes.NewReader(c.Input))
		dec.DisallowUnknownFields()
		if err := dec.Decode(&r); err != nil {
			t.Errorf("%s: the agent's type does not know a field of the report: %v", c.Name, err)
			continue
		}
		out, _ := json.Marshal(r)
		var a, b any
		json.Unmarshal(c.Input, &a)
		json.Unmarshal(out, &b)
		ja, _ := json.Marshal(a)
		jb, _ := json.Marshal(b)
		if string(ja) != string(jb) {
			t.Errorf("%s round trip differs:\n in  %s\n out %s", c.Name, ja, jb)
		}
	}
	if n < 4 {
		t.Fatalf("only %d agent-shaped reports", n)
	}
}
