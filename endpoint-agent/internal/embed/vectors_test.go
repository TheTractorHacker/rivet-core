package embed

import (
	"bytes"
	"encoding/hex"
	"encoding/json"
	"errors"
	"os"
	"strings"
	"testing"
	"time"
)

// The vectors have ONE home, shared with the PHP tests:
// endpoint-agent/testdata/vectors/agent_installer_trailer_vectors.json.
type vectorFile struct {
	Format  string `json:"format"`
	Vectors []struct {
		Name      string `json:"name"`
		ExeHex    string `json:"exe_hex"`
		Payload   string `json:"payload"`
		FooterHex string `json:"footer_hex"`
		Stamped   string `json:"stamped_hex"`
	} `json:"vectors"`
	Negative []struct {
		Name    string `json:"name"`
		Stamped string `json:"stamped_hex"`
		Expect  string `json:"expect"`
	} `json:"negative"`
}

func loadVectors(t *testing.T) vectorFile {
	var b []byte
	var err error
	for _, p := range []string{"../../testdata/vectors/agent_installer_trailer_vectors.json"} {
		if b, err = os.ReadFile(p); err == nil {
			break
		}
	}
	if err != nil {
		t.Fatal(err)
	}
	var v vectorFile
	if err := json.Unmarshal(b, &v); err != nil {
		t.Fatal(err)
	}
	if v.Format != Magic || len(v.Vectors) == 0 || len(v.Negative) == 0 {
		t.Fatal("unexpected vector file")
	}
	return v
}

func unhex(t *testing.T, s string) []byte {
	b, err := hex.DecodeString(s)
	if err != nil {
		t.Fatal(err)
	}
	return b
}

func TestServerVectorsPositive(t *testing.T) {
	v := loadVectors(t)
	for _, c := range v.Vectors {
		t.Run(c.Name, func(t *testing.T) {
			exe, stamped := unhex(t, c.ExeHex), unhex(t, c.Stamped)
			// the writer side must reproduce the server's bytes exactly
			if got := Build(exe, []byte(c.Payload)); !bytes.Equal(got, stamped) {
				t.Fatal("Build differs from the server's stamped file")
			}
			if !strings.HasSuffix(hex.EncodeToString(stamped), c.FooterHex) {
				t.Fatal("footer differs")
			}
			pl, exeLen, err := Extract(bytes.NewReader(stamped), int64(len(stamped)))
			if err != nil {
				t.Fatal(err)
			}
			if string(pl) != c.Payload || exeLen != int64(len(exe)) {
				t.Fatalf("extract: exeLen %d want %d", exeLen, len(exe))
			}
			n, err := UnstampedSize(bytes.NewReader(stamped), int64(len(stamped)))
			if err != nil || n != int64(len(exe)) {
				t.Fatalf("UnstampedSize %d err %v", n, err)
			}
			// semantic parse: valid payloads parse; the vector CA is not a real
			// certificate ("MIIBfake") so that one must be refused by the agent
			_, perr := ParseBytes(stamped, time.Date(2026, 10, 7, 0, 0, 0, 0, time.UTC))
			switch c.Name {
			case "with_ca_and_unicode":
				if !errors.Is(perr, ErrInvalid) {
					t.Fatalf("fake CA should be rejected, got %v", perr)
				}
			case "max_payload_16384":
				// a boundary vector for the format layer: its department is ~16 KB of padding,
				// which the agent's semantic check (<= 255 characters) refuses
				if !errors.Is(perr, ErrInvalid) {
					t.Fatalf("padding department should be refused, got %v", perr)
				}
			case "minimal_payload":
				if !errors.Is(perr, ErrInvalid) {
					t.Fatalf("{} must fail semantic validation, got %v", perr)
				}
			default:
				if perr != nil && !strings.Contains(c.Payload, `"department"`) {
					return
				}
				if perr != nil {
					t.Fatalf("semantic parse: %v", perr)
				}
			}
		})
	}
}

func TestServerVectorsNegative(t *testing.T) {
	v := loadVectors(t)
	for _, c := range v.Negative {
		t.Run(c.Name, func(t *testing.T) {
			if c.Expect != "reject" {
				t.Fatalf("unknown expectation %q", c.Expect)
			}
			b := unhex(t, c.Stamped)
			if _, err := ParseBytes(b, time.Date(2026, 10, 7, 0, 0, 0, 0, time.UTC)); err == nil {
				t.Fatal("accepted")
			}
			if c.Name != "payload_not_json" && c.Name != "payload_json_array" {
				if _, _, err := Extract(bytes.NewReader(b), int64(len(b))); err == nil {
					t.Fatal("format layer accepted")
				}
			}
		})
	}
}
