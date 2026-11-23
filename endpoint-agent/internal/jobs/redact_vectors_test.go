package jobs

import (
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// redactionVector is one row of the shared redaction vector file (src/Rmm/Crypto/redaction-vectors.json). The server's
// Rmm\Crypto\Redactor reads the SAME file in its PHP test, so job output, log lines and change text are masked the same way on
// the device and on the server (CORE-DATA-2, NEW-3, Chain E). Some inputs spell one character as a JSON escape (@, _,
// \/) so the repository's secret scanners do not mistake an invented fixture for a credential; the decoded text is the real shape.
type redactionVector struct {
	Name         string   `json:"name"`
	Input        string   `json:"input"`
	ExpectMasked []string `json:"expect_masked"`
	ExpectKept   []string `json:"expect_kept"`
}

func loadRedactionVectors(t *testing.T) []redactionVector {
	t.Helper()
	b, err := os.ReadFile(filepath.Join("..", "..", "..", "src", "Rmm", "Crypto", "redaction-vectors.json"))
	if err != nil {
		if os.Getenv("CI") == "" {
			t.Skip("shared redaction vectors not found (agent built outside the repository)")
		}
		t.Fatalf("shared redaction vectors: %v", err)
	}
	var v []redactionVector
	if err := json.Unmarshal(b, &v); err != nil {
		t.Fatal(err)
	}
	if len(v) < 25 {
		t.Fatalf("the vector file looks truncated: %d rows", len(v))
	}
	return v
}

func TestRedactAgreesWithTheSharedVectors(t *testing.T) {
	for _, v := range loadRedactionVectors(t) {
		got := Redact(v.Input)
		for _, m := range v.ExpectMasked {
			if strings.Contains(got, m) {
				t.Errorf("%s: %q survived redaction: %q", v.Name, m, got)
			}
		}
		for _, k := range v.ExpectKept {
			if !strings.Contains(got, k) {
				t.Errorf("%s: %q was lost (over-redaction): %q", v.Name, k, got)
			}
		}
	}
}

// The remote-tool path redacts every JSON string on its own; the shared shapes must be masked there too.
func TestRedactJSONStringsAgreesWithTheSharedVectors(t *testing.T) {
	for _, v := range loadRedactionVectors(t) {
		doc, _ := json.Marshal(map[string]string{"line": v.Input})
		var back map[string]string
		if err := json.Unmarshal([]byte(RedactJSONStrings(string(doc))), &back); err != nil {
			t.Fatalf("%s: result is not JSON: %v", v.Name, err)
		}
		for _, m := range v.ExpectMasked {
			if strings.Contains(back["line"], m) {
				t.Errorf("%s: %q survived redaction: %q", v.Name, m, back["line"])
			}
		}
	}
}
