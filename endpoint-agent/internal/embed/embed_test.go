package embed

import (
	"bytes"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/sha256"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/binary"
	"encoding/pem"
	"errors"
	"math/big"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

var t0 = time.Date(2026, 10, 6, 12, 0, 0, 0, time.UTC)

const testToken = "rvte1.sel123.sEcReTsEcReT_-x"

func testCA(t testing.TB) string {
	k, _ := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	tpl := &x509.Certificate{SerialNumber: big.NewInt(1), Subject: pkix.Name{CommonName: "test ca"},
		NotBefore: t0.Add(-time.Hour), NotAfter: t0.Add(time.Hour), IsCA: true, BasicConstraintsValid: true}
	der, err := x509.CreateCertificate(rand.Reader, tpl, tpl, &k.PublicKey, k)
	if err != nil {
		t.Fatal(err)
	}
	return string(pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der}))
}

func goodPayload() Payload {
	return Payload{Version: 1, InstallerID: "123e4567-e89b-42d3-a456-426614174000", ServerURL: "https://rivet.example.com/prefix",
		EnrollmentToken: testToken, Department: "Sales",
		CreatedAt: t0.Format(time.RFC3339), ExpiresAt: t0.Add(24 * time.Hour).Format(time.RFC3339)}
}

var fakeExe = bytes.Repeat([]byte("MZ-fake-exe-bytes-"), 100)

func stamp(t *testing.T, p Payload) []byte {
	b, err := Marshal(p, t0)
	if err != nil {
		t.Fatal(err)
	}
	return Build(fakeExe, b)
}

func TestValid(t *testing.T) {
	ca := testCA(t)
	p := goodPayload()
	p.CAPEM = &ca
	got, err := ParseBytes(stamp(t, p), t0)
	if err != nil {
		t.Fatal(err)
	}
	if got.Department != "Sales" || got.ServerURL != p.ServerURL || got.EnrollmentToken != testToken || got.CAPEM == nil {
		t.Fatalf("bad decode: %v", got)
	}
	if !got.Expires().Equal(t0.Add(24 * time.Hour)) {
		t.Fatal("expiry")
	}
	if strings.Contains(got.String(), "sEcReT") || strings.Contains(got.GoString(), "sEcReT") {
		t.Fatal("String leaks token")
	}
}

func TestUnknownFieldsIgnored(t *testing.T) {
	raw := []byte(`{"version":1,"installer_id":"123e4567-e89b-42d3-a456-426614174000","server_url":"https://h.example","enrollment_token":"rvte1.a.b","department":"IT","ca_pem":null,"created_at":"2026-10-06T12:00:00Z","expires_at":"2026-10-07T12:00:00Z","future":{"x":1}}`)
	if _, err := ParseBytes(Build(fakeExe, raw), t0); err != nil {
		t.Fatal(err)
	}
}

func rawCase(t *testing.T, mutate func(*Payload)) error {
	p := goodPayload()
	mutate(&p)
	b, _ := jsonMarshalNoCheck(p)
	_, err := ParseBytes(Build(fakeExe, b), t0)
	return err
}

func TestValidationTable(t *testing.T) {
	ca := "-----BEGIN CERTIFICATE-----\nAAAA\n-----END CERTIFICATE-----\n"
	good := testCA(t)
	junk := good + "trailing junk"
	empty := ""
	cases := map[string]func(*Payload){
		"version 2":        func(p *Payload) { p.Version = 2 },
		"version 0":        func(p *Payload) { p.Version = 0 },
		"bad uuid":         func(p *Payload) { p.InstallerID = "nope" },
		"http":             func(p *Payload) { p.ServerURL = "http://rivet.example.com" },
		"ftp":              func(p *Payload) { p.ServerURL = "ftp://x" },
		"no host":          func(p *Payload) { p.ServerURL = "https://" },
		"userinfo":         func(p *Payload) { p.ServerURL = "https://u:p@h.example" },
		"query":            func(p *Payload) { p.ServerURL = "https://h.example/?a=b" },
		"fragment":         func(p *Payload) { p.ServerURL = "https://h.example/#x" },
		"space in url":     func(p *Payload) { p.ServerURL = "https://h.example/a b" },
		"empty url":        func(p *Payload) { p.ServerURL = "" },
		"token prefix":     func(p *Payload) { p.EnrollmentToken = "rvte2.a.b" },
		"token 2 parts":    func(p *Payload) { p.EnrollmentToken = "rvte1.ab" },
		"token 4 parts":    func(p *Payload) { p.EnrollmentToken = "rvte1.a.b.c" },
		"token empty part": func(p *Payload) { p.EnrollmentToken = "rvte1..b" },
		"token bad chars":  func(p *Payload) { p.EnrollmentToken = "rvte1.a b.c" },
		"token too long":   func(p *Payload) { p.EnrollmentToken = "rvte1.a." + strings.Repeat("x", 300) },
		"dept empty":       func(p *Payload) { p.Department = "" },
		"dept long":        func(p *Payload) { p.Department = strings.Repeat("d", 256) },
		"dept control":     func(p *Payload) { p.Department = "a\nb" },
		"dept padded":      func(p *Payload) { p.Department = " a" },
		"ca not cert":      func(p *Payload) { p.CAPEM = &ca },
		"ca trailing junk": func(p *Payload) { p.CAPEM = &junk },
		"ca empty":         func(p *Payload) { p.CAPEM = &empty },
		"created bad":      func(p *Payload) { p.CreatedAt = "yesterday" },
		"expires bad":      func(p *Payload) { p.ExpiresAt = "2026-10-07" },
		"expires<=created": func(p *Payload) { p.ExpiresAt = p.CreatedAt },
	}
	for name, mut := range cases {
		t.Run(name, func(t *testing.T) {
			err := rawCase(t, mut)
			if !errors.Is(err, ErrInvalid) {
				t.Fatalf("want ErrInvalid, got %v", err)
			}
			if err != nil && strings.Contains(err.Error(), "sEcReT") {
				t.Fatal("error leaks token")
			}
		})
	}
	if err := rawCase(t, func(p *Payload) { p.CAPEM = &good }); err != nil {
		t.Fatalf("valid CA rejected: %v", err)
	}
}

func TestExpired(t *testing.T) {
	b := stamp(t, goodPayload())
	if _, err := ParseBytes(b, t0.Add(24*time.Hour)); !errors.Is(err, ErrExpired) {
		t.Fatalf("at expiry: %v", err)
	}
	if _, err := ParseBytes(b, t0.Add(24*time.Hour-time.Second)); err != nil {
		t.Fatal(err)
	}
	// Marshal refuses an already-expired payload.
	if _, err := Marshal(goodPayload(), t0.Add(48*time.Hour)); !errors.Is(err, ErrExpired) {
		t.Fatalf("marshal expired: %v", err)
	}
}

func TestFooterFailures(t *testing.T) {
	good := stamp(t, goodPayload())
	pl, _ := Marshal(goodPayload(), t0)

	wrongMagic := append([]byte{}, good...)
	wrongMagic[len(wrongMagic)-1] = 'X'

	junkAfter := append(append([]byte{}, good...), 0)

	truncated := good[:len(good)-1]

	lenLies := append([]byte{}, good...)
	binary.BigEndian.PutUint32(lenLies[len(lenLies)-52:], uint32(len(pl)+5))

	lenShort := append([]byte{}, good...)
	binary.BigEndian.PutUint32(lenShort[len(lenShort)-52:], uint32(len(pl)-5))

	huge := append([]byte{}, good...)
	binary.BigEndian.PutUint32(huge[len(huge)-52:], 0xFFFFFFFF)

	over := append([]byte{}, good...)
	binary.BigEndian.PutUint32(over[len(over)-52:], MaxPayload+1)

	zero := append([]byte{}, good...)
	binary.BigEndian.PutUint32(zero[len(zero)-52:], 0)

	// payload longer than the file allows (length > size-52)
	tiny := Build(nil, []byte("{}"))
	binary.BigEndian.PutUint32(tiny[len(tiny)-52:], 100)

	hashBad := append([]byte{}, good...)
	hashBad[len(hashBad)-52+4] ^= 0xff

	payloadTampered := append([]byte{}, good...)
	payloadTampered[len(fakeExe)+10] ^= 1

	big := bytes.Repeat([]byte("a"), MaxPayload+1)

	cases := []struct {
		name string
		file []byte
		want error
	}{
		{"wrong magic", wrongMagic, ErrNotStamped},
		{"junk after footer", junkAfter, ErrNotStamped},
		{"truncated", truncated, ErrNotStamped},
		{"empty", nil, ErrNotStamped},
		{"short file", []byte("MZ"), ErrNotStamped},
		{"length lies long", lenLies, ErrInvalid},
		{"length lies short", lenShort, ErrInvalid},
		{"huge length", huge, ErrInvalid},
		{"over max", over, ErrInvalid},
		{"zero length", zero, ErrInvalid},
		{"length > file", tiny, ErrInvalid},
		{"hash mismatch", hashBad, ErrInvalid},
		{"payload tampered", payloadTampered, ErrInvalid},
		{"payload > 16384", Build(fakeExe, big), ErrInvalid},
		{"bad json", Build(fakeExe, []byte("not json")), ErrInvalid},
		{"json array", Build(fakeExe, []byte("[1]")), ErrInvalid},
		{"json trailing", Build(fakeExe, append(pl, []byte(` {}`)...)), ErrInvalid},
		{"invalid utf8", Build(fakeExe, []byte{0xff, 0xfe}), ErrInvalid},
		{"empty object", Build(fakeExe, []byte("{}")), ErrInvalid},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			_, err := ParseBytes(c.file, t0)
			if !errors.Is(err, c.want) {
				t.Fatalf("want %v, got %v", c.want, err)
			}
		})
	}
}

func TestUnstampedSizeAndWrite(t *testing.T) {
	dir := t.TempDir()
	src := filepath.Join(dir, "stamped.exe")
	if err := os.WriteFile(src, stamp(t, goodPayload()), 0o644); err != nil {
		t.Fatal(err)
	}
	dst := filepath.Join(dir, "out.exe")
	n, err := WriteUnstamped(src, dst)
	if err != nil || n != int64(len(fakeExe)) {
		t.Fatalf("n=%d err=%v", n, err)
	}
	got, _ := os.ReadFile(dst)
	if !bytes.Equal(got, fakeExe) {
		t.Fatal("staged bytes differ from the original exe")
	}
	if bytes.Contains(got, []byte("rvte1.")) || bytes.Contains(got, []byte(Magic)) || bytes.Contains(got, []byte("sEcReT")) {
		t.Fatal("staged copy contains token material")
	}
	if fi, _ := os.Stat(dst); fi.Mode().Perm() != 0o755 {
		t.Fatalf("mode %v", fi.Mode())
	}
	// an unstamped source is copied whole
	n, err = WriteUnstamped(dst, filepath.Join(dir, "again.exe"))
	if err != nil || n != int64(len(fakeExe)) {
		t.Fatalf("plain copy n=%d err=%v", n, err)
	}
	// a double-stamped source is refused, and leaves nothing behind
	dbl := filepath.Join(dir, "dbl.exe")
	b := stamp(t, goodPayload())
	os.WriteFile(dbl, Build(b, []byte(`{"a":1}`)), 0o644)
	if _, err := WriteUnstamped(dbl, filepath.Join(dir, "dbl-out.exe")); err == nil {
		t.Fatal("double-stamped source must be refused")
	}
	if _, err := os.Stat(filepath.Join(dir, "dbl-out.exe")); err == nil {
		t.Fatal("partial output left behind")
	}
	// corrupt footer (magic present, bad length) is an error, not a plain copy
	bad := append([]byte{}, b...)
	binary.BigEndian.PutUint32(bad[len(bad)-52:], 0xFFFFFFFF)
	os.WriteFile(filepath.Join(dir, "bad.exe"), bad, 0o644)
	if _, err := WriteUnstamped(filepath.Join(dir, "bad.exe"), filepath.Join(dir, "bad-out.exe")); err == nil {
		t.Fatal("corrupt footer must not be copied as if unstamped")
	}
}

func TestReadFile(t *testing.T) {
	dir := t.TempDir()
	p := filepath.Join(dir, "x.exe")
	os.WriteFile(p, stamp(t, goodPayload()), 0o644)
	if _, err := Read(p, t0); err != nil {
		t.Fatal(err)
	}
	os.WriteFile(p, fakeExe, 0o644)
	if _, err := Read(p, t0); !errors.Is(err, ErrNotStamped) {
		t.Fatal(err)
	}
	if _, err := Read(filepath.Join(dir, "missing"), t0); err == nil {
		t.Fatal("missing file")
	}
}

func TestBuildLayout(t *testing.T) {
	pl := []byte(`{"x":1}`)
	b := Build([]byte("EXE"), pl)
	if len(b) != 3+len(pl)+52 {
		t.Fatal("size")
	}
	sum := sha256.Sum256(pl)
	if !bytes.Equal(b[len(b)-48:len(b)-16], sum[:]) || string(b[len(b)-16:]) != "RIVETIT-EMBED-v1" {
		t.Fatal("footer layout")
	}
	if binary.BigEndian.Uint32(b[len(b)-52:]) != uint32(len(pl)) {
		t.Fatal("length field")
	}
}

func FuzzEmbedded(f *testing.F) {
	ca := testCA(f)
	p := goodPayload()
	p.CAPEM = &ca
	pl, _ := jsonMarshalNoCheck(p)
	f.Add(Build(fakeExe, pl))
	f.Add(Build(nil, []byte("{}")))
	f.Add([]byte(Magic))
	f.Add(Build(fakeExe, []byte{0xff}))
	f.Fuzz(func(t *testing.T, b []byte) {
		got, err := ParseBytes(b, t0)
		if err != nil {
			if strings.Contains(err.Error(), "sEcReT") {
				t.Fatal("error leaks token")
			}
			return
		}
		// anything accepted must satisfy the invariants
		if got.Version != 1 || !strings.HasPrefix(got.EnrollmentToken, "rvte1.") || got.Department == "" || !strings.HasPrefix(got.ServerURL, "https://") {
			t.Fatalf("accepted bad payload: %v", got)
		}
		if len(b) < FooterSize {
			t.Fatal("accepted too-short file")
		}
	})
}
