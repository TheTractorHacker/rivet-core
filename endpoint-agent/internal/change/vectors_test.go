package change

import (
	"crypto/ed25519"
	"encoding/base64"
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"rivetit-agent/internal/jobs"
)

type policyRow struct {
	Target  string `json:"target"`
	Allowed bool   `json:"allowed"`
}

type vectorFile struct {
	TestKey struct {
		PublicKey string `json:"public_key_base64"`
	} `json:"test_key"`
	Policy struct {
		Registry []policyRow `json:"registry"`
		File     []policyRow `json:"file"`
		Extra    struct {
			Root string      `json:"root"`
			Rows []policyRow `json:"rows"`
		} `json:"file_extra_root"`
	} `json:"policy"`
	SecretNames []struct {
		Name   string `json:"name"`
		Secret bool   `json:"secret"`
	} `json:"secret_names"`
	Redaction []struct {
		Input  string `json:"input"`
		Output string `json:"output"`
	} `json:"redaction"`
	Signed struct {
		Domain  string          `json:"sign_domain"`
		Message string          `json:"signed_message"`
		Config  json.RawMessage `json:"config"`
		IDs     []string        `json:"expected_watch_ids"`
	} `json:"signed_config"`
}

func loadVectors(t *testing.T) vectorFile {
	t.Helper()
	b, err := os.ReadFile(filepath.Join("..", "..", "testdata", "vectors", "agent_change_monitor_vectors.json"))
	if err != nil {
		t.Fatal(err)
	}
	var v vectorFile
	if err := json.Unmarshal(b, &v); err != nil {
		t.Fatal(err)
	}
	return v
}

// The server's watch policy and the agent's must agree on every row: the agent is the last line of defence, so it is allowed to be
// stricter, but it must never allow what the server refuses.
func TestThePolicyAgreesWithTheServerOnEveryVector(t *testing.T) {
	v := loadVectors(t)
	if len(v.Policy.Registry) < 40 || len(v.Policy.File) < 60 {
		t.Fatalf("the vector file looks truncated: %d, %d", len(v.Policy.Registry), len(v.Policy.File))
	}
	for _, r := range v.Policy.Registry {
		if got := RegistryKeyAllowed(r.Target); got != r.Allowed {
			t.Errorf("registry %q: agent %v, server %v", r.Target, got, r.Allowed)
		}
	}
	for _, r := range v.Policy.File {
		if got := FileAllowed(r.Target, nil); got != r.Allowed {
			t.Errorf("file %q: agent %v, server %v", r.Target, got, r.Allowed)
		}
	}
	for _, r := range v.Policy.Extra.Rows {
		if got := FileAllowed(r.Target, []string{v.Policy.Extra.Root}); got != r.Allowed {
			t.Errorf("file with extra root %q: agent %v, server %v", r.Target, got, r.Allowed)
		}
	}
}

func TestTheSecretNameRuleAgreesWithTheServer(t *testing.T) {
	for _, n := range loadVectors(t).SecretNames {
		if got := SecretName(n.Name); got != n.Secret {
			t.Errorf("%q: agent %v, server %v", n.Name, got, n.Secret)
		}
	}
}

// A configuration the server signed (PHP, canonical JSON, the domain line, Ed25519) verifies in Go, and one changed character breaks it.
func TestAConfigurationSignedByTheServerVerifiesAndOneChangedCharacterBreaksIt(t *testing.T) {
	v := loadVectors(t)
	pub, err := base64.StdEncoding.DecodeString(v.TestKey.PublicKey)
	if err != nil || len(pub) != ed25519.PublicKeySize {
		t.Fatal("bad test key")
	}
	ver := &jobs.Verifier{PublicKey: ed25519.PublicKey(pub)}
	opt := ParseOptions{Verify: ver.VerifyDomain, Platform: "windows"}
	c, err := Parse(v.Signed.Config, opt)
	if err != nil {
		t.Fatalf("the server's signed configuration was refused: %v", err)
	}
	var ids []string
	for _, w := range c.Watches {
		ids = append(ids, w.ID)
	}
	if strings.Join(ids, ",") != strings.Join(v.Signed.IDs, ",") {
		t.Errorf("watches %v; the server sent %v", ids, v.Signed.IDs)
	}
	if v.Signed.Domain != SignDomain || !strings.HasPrefix(v.Signed.Message, SignDomain+"\n{") {
		t.Errorf("domain %q", v.Signed.Domain)
	}
	// the Go canonical form of the object is exactly the message the server signed
	canon, err := jobs.Canonical(v.Signed.Config)
	if err != nil || SignDomain+"\n"+string(canon) != v.Signed.Message {
		t.Errorf("canonical forms differ:\n go  %s\n php %s", canon, v.Signed.Message)
	}
	for _, from := range []string{`"interval_s": 300`, `"rate_per_min": 120`, `"id": "win.tasks"`, `"depth": 2`} {
		raw := strings.Replace(string(v.Signed.Config), from, strings.Replace(from, "0", "1", 1)+"", 1)
		if raw == string(v.Signed.Config) {
			raw = strings.Replace(raw, from, strings.ToUpper(from), 1)
		}
		if _, err := Parse(json.RawMessage(raw), opt); err == nil {
			t.Errorf("a changed configuration verified: %s", from)
		}
	}
	// the same object with another platform is refused after the signature check
	if _, err := Parse(v.Signed.Config, ParseOptions{Verify: ver.VerifyDomain, Platform: "linux"}); err == nil {
		t.Error("a Windows configuration on a Linux agent")
	}
	// a job-style verification (no domain line) of the same signature fails
	var m map[string]any
	json.Unmarshal(v.Signed.Config, &m)
	sig, _ := m["signature"].(string)
	if err := ver.VerifyObject(v.Signed.Config, sig); err == nil {
		t.Error("the signature of a configuration must not verify as a job or a check")
	}
}

// rc.31 (security review F2): the agent's Display() and the server's ChangeProtocol::redact() give the same text for every row, so a secret the agent keeps
// is a secret the server would have masked too, and harmless text (an SSH public key, a long path, ExecStart=) is never hidden by either.
func TestRedactionAgreesWithTheServerOnEveryVector(t *testing.T) {
	v := loadVectors(t)
	if len(v.Redaction) < 30 {
		t.Fatalf("the vector file looks truncated: %d redaction rows", len(v.Redaction))
	}
	for _, r := range v.Redaction {
		got, _, _ := Display(r.Input, 2000, nil)
		if got != r.Output {
			t.Errorf("redact %q: agent %q, server %q", r.Input, got, r.Output)
		}
	}
}
