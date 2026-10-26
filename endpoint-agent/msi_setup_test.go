// Fixture note (secret scan): synthetic test value, not a credential: same fixture token and documentation UUID as setup_test.go. See secret-scan-allowlist.json at the repository root.

package main

import (
	"bytes"
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"rivetit-agent/internal/embed"
)

// oleHeader is the first eight bytes of every Windows Installer database (an OLE compound file).
var oleHeader = []byte{0xD0, 0xCF, 0x11, 0xE0, 0xA1, 0xB1, 0x1A, 0xE1}

// syntheticMSI is a stand-in for a template .msi: the OLE header, then filler. The real one is built by packaging/msi/build.sh and read in
// TestRealWixlMSIRoundTrip when RIVETIT_TEST_MSI points at it.
func syntheticMSI() []byte {
	return append(append([]byte{}, oleHeader...), bytes.Repeat([]byte("msi-body-"), 4000)...)
}

// msiEnv is an MSI install in the making: the (unstamped) agent is ALREADY in the install directory, as Windows Installer leaves it, and the stamped
// payload lives at the end of the .msi.
func (e *env) msiEnv(srv *enrollSrv, mutate func(*embed.Payload)) (msi string) {
	if err := os.MkdirAll(filepath.Join(e.state, "bin"), 0o755); err != nil {
		e.t.Fatal(err)
	}
	e.exe = e.installed()
	if err := os.WriteFile(e.exe, fakeExeBytes, 0o755); err != nil {
		e.t.Fatal(err)
	}
	ca := srv.caPEM()
	p := embed.Payload{Version: 1, InstallerID: "123e4567-e89b-42d3-a456-426614174000", ServerURL: srv.URL, EnrollmentToken: setupToken, Department: "Sales", CAPEM: &ca,
		CreatedAt: time.Now().Add(-time.Minute).UTC().Format(time.RFC3339), ExpiresAt: time.Now().Add(time.Hour).UTC().Format(time.RFC3339)}
	if mutate != nil {
		mutate(&p)
	}
	b, err := json.Marshal(p)
	if err != nil {
		e.t.Fatal(err)
	}
	msi = filepath.Join(e.dir, "RivetIT-Agent-sales-x64.msi")
	if err := os.WriteFile(msi, embed.Build(syntheticMSI(), b), 0o644); err != nil {
		e.t.Fatal(err)
	}

	return msi
}

func (e *env) msiSetup(extra ...string) int {
	args := append([]string{"--silent", "--from-msi", "--state-dir", e.state, "--install-dir", filepath.Join(e.state, "bin")}, extra...)
	return runSetup(args, e.ops(), time.Now)
}

func TestSetupFromMSIReadsThePayloadFromTheMSIFile(t *testing.T) {
	srv := newEnrollSrv(t)
	e := newEnv(t)
	msi := e.msiEnv(srv, nil)
	if c := e.msiSetup("--payload-from", msi); c != exitOK {
		t.Fatalf("exit %d", c)
	}
	if srv.enrolls.Load() != 1 {
		t.Fatalf("enrollments: %d", srv.enrolls.Load())
	}
	// the installed program was not touched (Windows Installer put it there) and the service was registered, but no Add/Remove entry (the MSI owns it)
	got, _ := os.ReadFile(e.installed())
	if !bytes.Equal(got, fakeExeBytes) {
		t.Fatal("the installed program changed")
	}
	joined := strings.Join(e.calls, ",")
	if !strings.Contains(joined, "install:") || !strings.Contains(joined, "start") || strings.Contains(joined, "arp") {
		t.Fatalf("service registered without a second Add/Remove entry: %v", e.calls)
	}
	cfg, _ := os.ReadFile(filepath.Join(e.state, "config.json"))
	if !strings.Contains(string(cfg), "Sales") {
		t.Fatalf("department from the payload not saved: %s", cfg)
	}
	noTokenAnywhere(t, e.state, setupToken, "fixturepart123", embed.Magic)
	// the .msi itself is never modified by setup
	after, _ := os.ReadFile(msi)
	if !bytes.HasPrefix(after, oleHeader) {
		t.Fatal("the msi header changed")
	}
}

func TestSetupFromMSIRefusesWhatItCannotTrust(t *testing.T) {
	srv := newEnrollSrv(t)
	for name, mutate := range map[string]func(*embed.Payload){
		"bad token": func(p *embed.Payload) { p.EnrollmentToken = "nope" },
		"http":      func(p *embed.Payload) { p.ServerURL = "http://rmm.example.com" },
		"expired": func(p *embed.Payload) {
			p.CreatedAt = time.Now().Add(-2 * time.Hour).UTC().Format(time.RFC3339)
			p.ExpiresAt = time.Now().Add(-time.Hour).UTC().Format(time.RFC3339)
		},
		"no version": func(p *embed.Payload) { p.Version = 2 },
	} {
		t.Run(name, func(t *testing.T) {
			e := newEnv(t)
			msi := e.msiEnv(srv, mutate)
			if c := e.msiSetup("--payload-from", msi); c != exitBadConfig {
				t.Fatalf("exit %d, want %d (a fresh install with an unusable payload fails: no enrollment to keep)", c, exitBadConfig)
			}
			if srv.enrolls.Load() != 0 {
				t.Fatal("the server was contacted")
			}
		})
	}
	t.Run("an unstamped msi", func(t *testing.T) {
		e := newEnv(t)
		e.msiEnv(srv, nil)
		plain := filepath.Join(e.dir, "plain.msi")
		os.WriteFile(plain, syntheticMSI(), 0o644)
		if c := e.msiSetup("--payload-from", plain); c != exitBadConfig {
			t.Fatalf("exit %d", c)
		}
	})
	t.Run("a payload file that does not exist", func(t *testing.T) {
		e := newEnv(t)
		e.msiEnv(srv, nil)
		if c := e.msiSetup("--payload-from", filepath.Join(e.dir, "missing.msi")); c == exitOK {
			t.Fatal("success without a payload")
		}
	})
}

func TestMSIOnlyFlagsAreRefusedOutsideTheMSI(t *testing.T) {
	srv := newEnrollSrv(t)
	e := newEnv(t)
	e.stampWith(srv, nil)
	for _, a := range [][]string{{"--payload-from", e.exe}, {"--server", srv.URL}, {"--token-file", e.exe}, {"--ca-file", e.exe}} {
		if c := runSetup(append([]string{"--silent", "--no-service", "--state-dir", e.state, "--install-dir", filepath.Join(e.state, "bin")}, a...), e.ops(), time.Now); c != exitBadConfig {
			t.Errorf("%v: exit %d", a, c)
		}
	}
	// and inside it the two sources exclude each other, and a token needs a server
	if c := e.msiSetup("--payload-from", e.exe, "--server", srv.URL); c != exitBadConfig {
		t.Errorf("both sources: %d", c)
	}
	if c := e.msiSetup("--token", setupToken); c != exitBadConfig {
		t.Errorf("token without server: %d", c)
	}
	if srv.enrolls.Load() != 0 {
		t.Fatal("the server was contacted")
	}
}

func TestSetupFromMSIPublicProperties(t *testing.T) {
	srv := newEnrollSrv(t)
	caFile := func(e *env) string {
		p := filepath.Join(e.dir, "ca.pem")
		os.WriteFile(p, []byte(srv.caPEM()), 0o600)
		return p
	}
	t.Run("server, token and CA file enroll the device", func(t *testing.T) {
		e := newEnv(t)
		e.msiEnv(srv, nil)
		before := srv.enrolls.Load()
		if c := e.msiSetup("--server", srv.URL, "--token", setupToken, "--ca-file", caFile(e)); c != exitOK {
			t.Fatalf("exit %d", c)
		}
		if srv.enrolls.Load() != before+1 {
			t.Fatal("not enrolled")
		}
		if _, err := os.Stat(filepath.Join(e.state, "ca.pem")); err != nil {
			t.Fatal("CA not saved")
		}
		noTokenAnywhere(t, e.state, setupToken, "fixturepart123")
	})
	t.Run("token from a file; the empty values msiexec formats for properties that were not given are ignored", func(t *testing.T) {
		e := newEnv(t)
		e.msiEnv(srv, nil)
		tf := filepath.Join(e.dir, "token.txt")
		os.WriteFile(tf, []byte(setupToken+"\n"), 0o600)
		before := srv.enrolls.Load()
		if c := e.msiSetup("--server", srv.URL, "--token", "", "--token-file", tf, "--ca-file", caFile(e)); c != exitOK {
			t.Fatalf("exit %d", c)
		}
		if srv.enrolls.Load() != before+1 {
			t.Fatal("not enrolled")
		}
		// the same command line with the CA property not given: no CA, nothing to read
		p, err := (setupOpts{server: srv.URL, token: setupToken, caFile: ""}).propertyPayload(time.Now())
		if err != nil || p.CAPEM != nil || p.EnrollmentToken != setupToken {
			t.Fatalf("%+v %v", p, err)
		}
	})
	t.Run("what a stamped installer could not carry is refused, without echoing it", func(t *testing.T) {
		for name, a := range map[string][]string{
			"http server":   {"--server", "http://rmm.example.com", "--token", setupToken},
			"server query":  {"--server", "https://rmm.example.com/?x=1", "--token", setupToken},
			"token shape":   {"--server", srv.URL, "--token", "rvte1.only-two-parts"},
			"missing CA":    {"--server", srv.URL, "--token", setupToken, "--ca-file", "/nonexistent/ca.pem"},
			"CA not PEM":    {"--server", srv.URL, "--token", setupToken, "--ca-file", os.Args[0]},
			"missing token": {"--server", srv.URL, "--token", ""},
		} {
			e := newEnv(t)
			e.msiEnv(srv, nil)
			before := srv.enrolls.Load()
			c := e.msiSetup(a...)
			if c == exitOK && name != "missing token" {
				t.Errorf("%s: accepted", name)
			}
			if name == "missing token" && c != exitBadConfig {
				t.Errorf("%s: exit %d (first install without a token)", name, c)
			}
			if srv.enrolls.Load() != before {
				t.Errorf("%s: the server was contacted", name)
			}
		}
	})
}

func TestMSIUpgradeKeepsTheEnrollmentWithoutAUsablePayload(t *testing.T) {
	srv := newEnrollSrv(t)
	e := newEnv(t)
	msi := e.msiEnv(srv, nil)
	if c := e.msiSetup("--payload-from", msi); c != exitOK {
		t.Fatalf("first install: %d", c)
	}
	id1, _ := os.ReadFile(filepath.Join(e.state, "state.json"))
	// the next version of the template, installed by IT without a stamp (or with an expired one): the device keeps its identity
	plain := filepath.Join(e.dir, "template.msi")
	os.WriteFile(plain, syntheticMSI(), 0o644)
	srv.mode.Store(1) // a second exchange would be rejected: it must not happen
	for _, p := range []string{plain, e.msiEnv(srv, func(p *embed.Payload) {
		p.CreatedAt = time.Now().Add(-2 * time.Hour).UTC().Format(time.RFC3339)
		p.ExpiresAt = time.Now().Add(-time.Hour).UTC().Format(time.RFC3339)
	})} {
		e.calls = nil
		if c := e.msiSetup("--payload-from", p); c != exitOK {
			t.Fatalf("upgrade with %s: exit %d", filepath.Base(p), c)
		}
		if !strings.Contains(strings.Join(e.calls, ","), "install:") {
			t.Fatalf("the service was not re-registered: %v", e.calls)
		}
	}
	id2, _ := os.ReadFile(filepath.Join(e.state, "state.json"))
	if !bytes.Equal(id1, id2) || srv.enrolls.Load() != 1 {
		t.Fatalf("identity changed or re-enrolled (%d exchanges)", srv.enrolls.Load())
	}
}

func TestMSIInstallWhileTheServerIsUnreachableIsNotAFailure(t *testing.T) {
	srv := newEnrollSrv(t)
	srv.mode.Store(2) // 503
	e := newEnv(t)
	msi := e.msiEnv(srv, nil)
	if c := e.msiSetup("--payload-from", msi); c != exitOK {
		t.Fatalf("exit %d: Windows Installer would roll the whole install back", c)
	}
	// ... while the exe installer keeps its documented exit code 4
	e2 := newEnv(t)
	e2.stampWith(srv, nil)
	if c := e2.setup(); c != exitTransient {
		t.Fatalf("exe installer exit %d", c)
	}
	// a rejection is a failure in both
	srv.mode.Store(1)
	e3 := newEnv(t)
	msi3 := e3.msiEnv(srv, nil)
	if c := e3.msiSetup("--payload-from", msi3); c != exitRejected {
		t.Fatalf("rejected: exit %d", c)
	}
}

func TestMSINotElevatedFailsInsteadOfRelaunching(t *testing.T) {
	srv := newEnrollSrv(t)
	e := newEnv(t)
	msi := e.msiEnv(srv, nil)
	e.elevated = false
	if c := e.msiSetup("--payload-from", msi); c != exitNotElevated {
		t.Fatalf("exit %d", c)
	}
	for _, c := range e.calls {
		if strings.HasPrefix(c, "elevate:") {
			t.Fatal("an MSI step must never relaunch itself")
		}
	}
}

func TestStampedMSIPayloadViaTheNormalExeInstallerPathIsUnaffected(t *testing.T) {
	// the exe installer: payload read from the program itself, program copied (stripped) into the install directory, Add/Remove entry registered
	srv := newEnrollSrv(t)
	e := newEnv(t)
	e.stampWith(srv, nil)
	if c := runSetup([]string{"--silent", "--state-dir", e.state, "--install-dir", filepath.Join(e.state, "bin")}, e.ops(), time.Now); c != exitOK {
		t.Fatal(c)
	}
	if !strings.Contains(strings.Join(e.calls, ","), "arp") {
		t.Fatalf("the exe installer still registers its Add/Remove entry: %v", e.calls)
	}
	got, _ := os.ReadFile(e.installed())
	if !bytes.Equal(got, fakeExeBytes) {
		t.Fatal("not the stripped program")
	}
}
