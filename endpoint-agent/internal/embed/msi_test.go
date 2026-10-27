package embed

import (
	"bytes"
	"os"
	"path/filepath"
	"testing"
)

// An .msi is an OLE compound file; the server appends the SAME payload || footer trailer to a COPY of the template. The reader looks only at the last 52 bytes,
// so the container format in front of the trailer does not matter to it. These tests prove that for a synthetic compound file always, and for a real wixl-built
// template when RIVETIT_TEST_MSI names one (packaging/msi/build.sh does, in the build image).

var ole = []byte{0xD0, 0xCF, 0x11, 0xE0, 0xA1, 0xB1, 0x1A, 0xE1}

func TestStampedOLEFileRoundTrip(t *testing.T) {
	template := append(append([]byte{}, ole...), bytes.Repeat([]byte{0x00, 0xFE, 0xFF, 0x09}, 20000)...)
	p := goodPayload()
	payload, err := Marshal(p, t0)
	if err != nil {
		t.Fatal(err)
	}
	stamped := Build(template, payload)
	if !bytes.HasPrefix(stamped, ole) || !bytes.Equal(stamped[:len(template)], template) {
		t.Fatal("the template bytes must stay exactly as they were")
	}
	got, err := ParseBytes(stamped, t0)
	if err != nil || got.ServerURL != p.ServerURL || got.EnrollmentToken != p.EnrollmentToken {
		t.Fatalf("%v %+v", err, got)
	}
	n, err := UnstampedSize(bytes.NewReader(stamped), int64(len(stamped)))
	if err != nil || n != int64(len(template)) {
		t.Fatalf("unstamped size %d (%v), want %d", n, err, len(template))
	}
	// the unstamped template reads as "not stamped" (the MSI upgrade path then keeps the existing enrollment)
	if _, err := ParseBytes(template, t0); err != ErrNotStamped {
		t.Fatalf("%v", err)
	}
}

func TestRealWixlMSIRoundTrip(t *testing.T) {
	path := os.Getenv("RIVETIT_TEST_MSI")
	if path == "" {
		t.Skip("RIVETIT_TEST_MSI not set (packaging/msi/build.sh sets it for its own check)")
	}
	template, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	if !bytes.HasPrefix(template, ole) {
		t.Fatalf("%s is not an OLE compound file", path)
	}
	if _, err := ParseBytes(template, t0); err != ErrNotStamped {
		t.Fatalf("a freshly built template must read as not stamped: %v", err)
	}
	payload, err := Marshal(goodPayload(), t0)
	if err != nil {
		t.Fatal(err)
	}
	copyPath := filepath.Join(t.TempDir(), "stamped.msi")
	if err := os.WriteFile(copyPath, Build(template, payload), 0o644); err != nil {
		t.Fatal(err)
	}
	got, err := Read(copyPath, t0)
	if err != nil {
		t.Fatalf("the payload appended to the real MSI template must read back: %v", err)
	}
	if got.Department != goodPayload().Department || got.InstallerID != goodPayload().InstallerID {
		t.Fatalf("%+v", got)
	}
	out := filepath.Join(t.TempDir(), "unstamped.msi")
	if n, err := WriteUnstamped(copyPath, out); err != nil || n != int64(len(template)) {
		t.Fatalf("%d %v", n, err)
	}
	b, _ := os.ReadFile(out)
	if !bytes.Equal(b, template) {
		t.Fatal("stripping the payload must give back the template, byte for byte")
	}
}

func TestPropertyValidatorsApplyThePayloadRules(t *testing.T) {
	if ValidateServerURL("https://rmm.example.com") != nil || ValidateServerURL("http://rmm.example.com") == nil || ValidateServerURL("https://rmm.example.com/?a=1") == nil {
		t.Fatal("server url rules")
	}
	if ValidateToken("rvte1.sel_ABC.sec-123") != nil || ValidateToken("rvte1.only") == nil || ValidateToken("") == nil {
		t.Fatal("token rules")
	}
	if ValidateCAPEM(testCA(t)) != nil || ValidateCAPEM("not a pem") == nil || ValidateCAPEM("") == nil {
		t.Fatal("ca rules")
	}
}
