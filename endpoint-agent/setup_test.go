package main

import (
	"bytes"
	"encoding/json"
	"encoding/pem"
	"errors"
	"io"
	"io/fs"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"rivetit-agent/internal/embed"
)

const setupToken = "rvte1.selabc.SECRETsecret123"

var fakeExeBytes = bytes.Repeat([]byte("MZ-pretend-agent-"), 200)

type enrollSrv struct {
	*httptest.Server
	enrolls atomic.Int32
	mode    atomic.Int32 // 0 ok, 1 reject(401, echoing the token), 2 server error
}

func newEnrollSrv(t *testing.T) *enrollSrv {
	s := &enrollSrv{}
	s.Server = httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/v1/agent_enroll" {
			http.NotFound(w, r)
			return
		}
		body, _ := io.ReadAll(r.Body)
		w.Header().Set("Content-Type", "application/json")
		switch s.mode.Load() {
		case 1:
			w.WriteHeader(401)
			// a hostile/buggy server that echoes the credential back
			json.NewEncoder(w).Encode(map[string]any{"error": "bad token " + string(body), "code": "invalid_token"})
			return
		case 2:
			w.WriteHeader(503)
			io.WriteString(w, `{"error":"down","code":"unavailable"}`)
			return
		}
		s.enrolls.Add(1)
		w.WriteHeader(201)
		json.NewEncoder(w).Encode(map[string]any{
			"device_id": 7, "device_token": "dev_secret_token_value", "check_in_interval_s": 300,
			"server_time": time.Now().UTC().Format(time.RFC3339), "status": "linked", "matched_asset_id": 1,
			"signing_public_key": "", "signing_key_id": "k",
			"config": map[string]any{"collect_interval_s": 60, "checks": []any{}},
		})
	}))
	t.Cleanup(s.Close)
	return s
}

func (s *enrollSrv) caPEM() string {
	return string(pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: s.Certificate().Raw}))
}

type env struct {
	t        *testing.T
	dir      string
	exe      string
	state    string
	calls    []string
	msgs     []string
	elevated bool
	elevCode int
	elevErr  error
	svcErr   error
}

func (e *env) ops() sysOps {
	return sysOps{
		isElevated: func() bool { return e.elevated },
		elevate: func(a []string) (int, error) {
			e.calls = append(e.calls, "elevate:"+strings.Join(a, " "))
			return e.elevCode, e.elevErr
		},
		executable:   func() (string, error) { return e.exe, nil },
		stopService:  func() error { e.calls = append(e.calls, "stop"); return nil },
		installSvc:   func(exe string, a []string) error { e.calls = append(e.calls, "install:"+exe); return e.svcErr },
		startService: func() error { e.calls = append(e.calls, "start"); return nil },
		registerARP:  func(string, string) error { e.calls = append(e.calls, "arp"); return nil },
		removeARP:    func() error { return nil },
		message:      func(title, text string, isErr bool) { e.msgs = append(e.msgs, text) },
	}
}

func newEnv(t *testing.T) *env {
	d := t.TempDir()
	return &env{t: t, dir: d, exe: filepath.Join(d, "installer.exe"), state: filepath.Join(d, "state"), elevated: true}
}

func (e *env) stampWith(srv *enrollSrv, mutate func(*embed.Payload)) {
	ca := srv.caPEM()
	p := embed.Payload{Version: 1, InstallerID: "123e4567-e89b-42d3-a456-426614174000", ServerURL: srv.URL,
		EnrollmentToken: setupToken, Department: "Sales", CAPEM: &ca,
		CreatedAt: time.Now().Add(-time.Minute).UTC().Format(time.RFC3339), ExpiresAt: time.Now().Add(time.Hour).UTC().Format(time.RFC3339)}
	if mutate != nil {
		mutate(&p)
	}
	b, err := json.Marshal(p) // not embed.Marshal: some tests need invalid payloads
	if err != nil {
		e.t.Fatal(err)
	}
	if err := os.WriteFile(e.exe, embed.Build(fakeExeBytes, b), 0o755); err != nil {
		e.t.Fatal(err)
	}
}

func (e *env) setup(extra ...string) int {
	args := append([]string{"--silent", "--no-service", "--state-dir", e.state, "--install-dir", filepath.Join(e.state, "bin")}, extra...)
	return runSetup(args, e.ops(), time.Now)
}

func (e *env) installed() string { return filepath.Join(e.state, "bin", exeName) }

func noTokenAnywhere(t *testing.T, root string, secrets ...string) {
	t.Helper()
	filepath.WalkDir(root, func(p string, d fs.DirEntry, err error) error {
		if err != nil || d.IsDir() {
			return nil
		}
		b, _ := os.ReadFile(p)
		for _, s := range secrets {
			if bytes.Contains(b, []byte(s)) {
				t.Errorf("%s contains secret material %q", p, s[:6])
			}
		}
		return nil
	})
}

func TestSetupInstallsStrippedBinary(t *testing.T) {
	srv := newEnrollSrv(t)
	e := newEnv(t)
	e.stampWith(srv, nil)
	if code := e.setup(); code != exitOK {
		t.Fatalf("exit %d", code)
	}
	if srv.enrolls.Load() != 1 {
		t.Fatal("enrollment did not happen")
	}
	got, err := os.ReadFile(e.installed())
	if err != nil {
		t.Fatal(err)
	}
	if !bytes.Equal(got, fakeExeBytes) {
		t.Fatal("installed bytes are not exactly the unstamped exe")
	}
	// the stamped source still has the token (documented), the installed tree must not
	src, _ := os.ReadFile(e.exe)
	if !bytes.Contains(src, []byte(setupToken)) {
		t.Fatal("test premise: stamped file holds the token")
	}
	noTokenAnywhere(t, e.state, setupToken, "SECRETsecret123", embed.Magic)
	if _, err := os.Stat(filepath.Join(e.state, "install.log")); err != nil {
		t.Fatal("install.log missing")
	}
	if _, err := os.Stat(filepath.Join(e.state, "device.token")); err != nil {
		t.Fatal("device token not stored")
	}
	if len(e.msgs) != 0 {
		t.Fatal("--silent must not show UI")
	}
	for _, c := range e.calls {
		if c == "start" || strings.HasPrefix(c, "install:") || c == "arp" {
			t.Fatalf("--no-service must not touch the service: %v", e.calls)
		}
	}
	cfg, _ := os.ReadFile(filepath.Join(e.state, "config.json"))
	if !bytes.Contains(cfg, []byte(`"department": "Sales"`)) && !bytes.Contains(cfg, []byte(`"department":"Sales"`)) {
		t.Fatalf("department not saved: %s", cfg)
	}
	if _, err := os.Stat(filepath.Join(e.state, "ca.pem")); err != nil {
		t.Fatal("embedded CA not saved")
	}
}

func TestSetupIdempotentKeepsIdentity(t *testing.T) {
	srv := newEnrollSrv(t)
	e := newEnv(t)
	e.stampWith(srv, nil)
	if code := e.setup(); code != 0 {
		t.Fatal(code)
	}
	id1, _ := os.ReadFile(filepath.Join(e.state, "state.json"))
	// "upgrade": a newer installer build with different exe bytes, same payload
	old := fakeExeBytes
	fakeExeBytes = append(append([]byte{}, old...), []byte("-v2")...)
	defer func() { fakeExeBytes = old }()
	e.stampWith(srv, nil)
	srv.mode.Store(1) // a second exchange would now be rejected: it must not happen
	if code := e.setup(); code != 0 {
		t.Fatalf("second run exit %d", code)
	}
	if srv.enrolls.Load() != 1 {
		t.Fatalf("re-enrolled: %d exchanges", srv.enrolls.Load())
	}
	var a, b struct {
		InstallID string `json:"install_id"`
		DeviceID  string `json:"device_id"`
	}
	json.Unmarshal(id1, &a)
	id2, _ := os.ReadFile(filepath.Join(e.state, "state.json"))
	json.Unmarshal(id2, &b)
	if a.InstallID == "" || a != b {
		t.Fatalf("identity changed: %+v -> %+v", a, b)
	}
	got, _ := os.ReadFile(e.installed())
	if !bytes.Equal(got, fakeExeBytes) {
		t.Fatal("binary was not upgraded")
	}
	if _, err := os.Stat(e.installed() + ".old"); err == nil {
		t.Fatal(".old left behind")
	}
}

func TestSetupExitCodes(t *testing.T) {
	t.Run("not stamped -> 2", func(t *testing.T) {
		srv := newEnrollSrv(t)
		e := newEnv(t)
		e.stampWith(srv, nil)
		os.WriteFile(e.exe, fakeExeBytes, 0o755)
		if c := e.setup(); c != exitBadConfig {
			t.Fatal(c)
		}
	})
	t.Run("expired -> 2", func(t *testing.T) {
		srv := newEnrollSrv(t)
		e := newEnv(t)
		e.stampWith(srv, func(p *embed.Payload) {
			p.CreatedAt = time.Now().Add(-2 * time.Hour).UTC().Format(time.RFC3339)
			p.ExpiresAt = time.Now().Add(-time.Hour).UTC().Format(time.RFC3339)
		})
		if c := e.setup(); c != exitBadConfig {
			t.Fatal(c)
		}
		if srv.enrolls.Load() != 0 {
			t.Fatal("contacted server with an expired installer")
		}
	})
	t.Run("invalid payload -> 2", func(t *testing.T) {
		srv := newEnrollSrv(t)
		e := newEnv(t)
		e.stampWith(srv, func(p *embed.Payload) { p.EnrollmentToken = "nope" })
		if c := e.setup(); c != exitBadConfig {
			t.Fatal(c)
		}
	})
	t.Run("interactive shows a message box for config errors", func(t *testing.T) {
		srv := newEnrollSrv(t)
		e := newEnv(t)
		e.stampWith(srv, nil)
		os.WriteFile(e.exe, fakeExeBytes, 0o755)
		c := runSetup([]string{"--no-service", "--state-dir", e.state, "--install-dir", filepath.Join(e.state, "bin")}, e.ops(), time.Now)
		if c != exitBadConfig || len(e.msgs) != 1 {
			t.Fatalf("code %d msgs %v", c, e.msgs)
		}
	})
	t.Run("rejected -> 3, token never in log or message", func(t *testing.T) {
		srv := newEnrollSrv(t)
		srv.mode.Store(1)
		e := newEnv(t)
		e.stampWith(srv, nil)
		c := runSetup([]string{"--no-service", "--state-dir", e.state, "--install-dir", filepath.Join(e.state, "bin")}, e.ops(), time.Now)
		if c != exitRejected {
			t.Fatal(c)
		}
		noTokenAnywhere(t, e.state, setupToken, "SECRETsecret123")
		for _, m := range e.msgs {
			if strings.Contains(m, "SECRETsecret123") {
				t.Fatal("message box leaks token")
			}
		}
		if _, err := os.Stat(e.installed()); err == nil {
			t.Fatal("binary installed despite a rejected token")
		}
	})
	t.Run("transient -> 4, installed, token kept protected for the service", func(t *testing.T) {
		srv := newEnrollSrv(t)
		srv.mode.Store(2)
		e := newEnv(t)
		e.stampWith(srv, nil)
		if c := e.setup(); c != exitTransient {
			t.Fatal(c)
		}
		got, _ := os.ReadFile(e.installed())
		if !bytes.Equal(got, fakeExeBytes) {
			t.Fatal("binary should still be installed when enrollment is deferred")
		}
		if _, err := os.Stat(filepath.Join(e.state, "enroll.token")); err != nil {
			t.Fatal("one-shot token not kept for the service")
		}
		noTokenAnywhere(t, filepath.Join(e.state, "bin"), setupToken)
		noTokenAnywhere(t, filepath.Join(e.state, "install.log"), setupToken)
		// recovery: server back, re-run enrolls
		srv.mode.Store(0)
		if c := e.setup(); c != 0 || srv.enrolls.Load() != 1 {
			t.Fatalf("rerun: %d enrolls=%d", c, srv.enrolls.Load())
		}
	})
	t.Run("install failure -> 5", func(t *testing.T) {
		srv := newEnrollSrv(t)
		e := newEnv(t)
		e.stampWith(srv, nil)
		blocker := filepath.Join(e.dir, "blocker")
		os.WriteFile(blocker, []byte("x"), 0o644)
		if c := e.setup("--install-dir", filepath.Join(blocker, "sub")); c != exitInstallFail {
			t.Fatal(c)
		}
	})
	t.Run("service failure -> 5", func(t *testing.T) {
		srv := newEnrollSrv(t)
		e := newEnv(t)
		e.stampWith(srv, nil)
		e.svcErr = errors.New("scm refused")
		c := runSetup([]string{"--silent", "--state-dir", e.state, "--install-dir", filepath.Join(e.state, "bin")}, e.ops(), time.Now)
		if c != exitInstallFail {
			t.Fatal(c)
		}
	})
	t.Run("full service path registers ARP", func(t *testing.T) {
		srv := newEnrollSrv(t)
		e := newEnv(t)
		e.stampWith(srv, nil)
		if c := runSetup([]string{"--silent", "--state-dir", e.state, "--install-dir", filepath.Join(e.state, "bin")}, e.ops(), time.Now); c != 0 {
			t.Fatal(c)
		}
		if !strings.Contains(strings.Join(e.calls, ","), "arp") || !strings.Contains(strings.Join(e.calls, ","), "start") {
			t.Fatal(e.calls)
		}
	})
	t.Run("not elevated + silent -> 6, no elevation attempt", func(t *testing.T) {
		srv := newEnrollSrv(t)
		e := newEnv(t)
		e.stampWith(srv, nil)
		e.elevated = false
		if c := e.setup(); c != exitNotElevated {
			t.Fatal(c)
		}
		for _, c := range e.calls {
			if strings.HasPrefix(c, "elevate") {
				t.Fatal("silent mode must never prompt")
			}
		}
		if srv.enrolls.Load() != 0 {
			t.Fatal("did work without elevation")
		}
	})
	t.Run("not elevated interactive: relaunch and surface child exit code", func(t *testing.T) {
		srv := newEnrollSrv(t)
		e := newEnv(t)
		e.stampWith(srv, nil)
		e.elevated, e.elevCode = false, 3
		c := runSetup([]string{"--no-service"}, e.ops(), time.Now)
		if c != 3 {
			t.Fatalf("got %d", c)
		}
		if len(e.calls) != 1 || e.calls[0] != "elevate:setup --elevated --no-service" {
			t.Fatalf("elevation args: %v", e.calls)
		}
		if len(e.msgs) != 0 {
			t.Fatal("parent must not double-report the child's result")
		}
	})
	t.Run("elevation refused -> 6", func(t *testing.T) {
		srv := newEnrollSrv(t)
		e := newEnv(t)
		e.stampWith(srv, nil)
		e.elevated, e.elevErr = false, errors.New("cancelled")
		if c := runSetup([]string{"--state-dir", e.state}, e.ops(), time.Now); c != exitNotElevated {
			t.Fatal(c)
		}
	})
	t.Run("relaunched child that is still not elevated does not loop", func(t *testing.T) {
		srv := newEnrollSrv(t)
		e := newEnv(t)
		e.stampWith(srv, nil)
		e.elevated = false
		if c := runSetup([]string{"--elevated", "--state-dir", e.state}, e.ops(), time.Now); c != exitNotElevated {
			t.Fatal(c)
		}
		if len(e.calls) != 0 {
			t.Fatal("child tried to elevate again")
		}
	})
	t.Run("bad flag -> 2", func(t *testing.T) {
		e := newEnv(t)
		if c := runSetup([]string{"--bogus"}, e.ops(), time.Now); c != exitBadConfig {
			t.Fatal(c)
		}
	})
}

func TestInstallBinaryDoesNotKeepStampedSelf(t *testing.T) {
	// Running setup from the already-installed path with a stamped file must refuse.
	srv := newEnrollSrv(t)
	e := newEnv(t)
	e.stampWith(srv, nil)
	inst := filepath.Join(e.state, "bin")
	os.MkdirAll(inst, 0o755)
	b, _ := os.ReadFile(e.exe)
	os.WriteFile(filepath.Join(inst, exeName), b, 0o755)
	e.exe = filepath.Join(inst, exeName)
	if c := e.setup(); c != exitInstallFail {
		t.Fatalf("got %d", c)
	}
}

func TestRedactWriter(t *testing.T) {
	var buf bytes.Buffer
	w := &redactWriter{w: &buf}
	w.Write([]byte("x token=rvte1.abc.DEF_-123 y rvte1.q.r\n"))
	if strings.Contains(buf.String(), "DEF") || strings.Contains(buf.String(), "rvte1.q.r") || !strings.Contains(buf.String(), "rvte1.[redacted]") {
		t.Fatal(buf.String())
	}
}

// argv parses a command line with the CommandLineToArgvW rules (for the
// escapeArg round-trip test).
func argv(s string) []string {
	var out []string
	i := 0
	for i < len(s) {
		for i < len(s) && (s[i] == ' ' || s[i] == '\t') {
			i++
		}
		if i >= len(s) {
			break
		}
		var cur strings.Builder
		inQ := false
		for i < len(s) {
			c := s[i]
			if c == '\\' {
				n := 0
				for i < len(s) && s[i] == '\\' {
					n++
					i++
				}
				if i < len(s) && s[i] == '"' {
					cur.WriteString(strings.Repeat(`\`, n/2))
					if n%2 == 1 {
						cur.WriteByte('"')
						i++
					}
				} else {
					cur.WriteString(strings.Repeat(`\`, n))
				}
				continue
			}
			if c == '"' {
				inQ = !inQ
				i++
				continue
			}
			if !inQ && (c == ' ' || c == '\t') {
				break
			}
			cur.WriteByte(c)
			i++
		}
		out = append(out, cur.String())
	}
	return out
}

func TestEscapeArgRoundTrip(t *testing.T) {
	cases := []string{
		`C:\ProgramData\RivetIT\Agent`, `C:\Program Files\RivetIT`, `a b`, `with "quotes"`, `trailing\`, `trailing slash space\ `,
		`C:\dir with space\`, `\\server\share name\x`, ``, `--flag=x y`, `"; calc.exe #`, `a\\"b`, "tab\there", `x" --state-dir "y`,
	}
	for _, c := range cases {
		line := joinArgs([]string{"setup", "--state-dir", c, "--no-service"})
		got := argv(line)
		if len(got) != 4 || got[2] != c {
			t.Errorf("round trip of %q via %q gave %q", c, line, got)
		}
	}
}

func TestElevatedArgsAllowlist(t *testing.T) {
	o := setupOpts{silent: true, noService: true, stateDir: `C:\x y\"; calc`, installDir: `C:\p`,
		explicit: map[string]bool{"state-dir": true, "install-dir": true}}
	a := o.elevatedArgs()
	want := []string{"setup", "--elevated", "--no-service"}
	if strings.Join(a, "\x00") != strings.Join(want, "\x00") {
		t.Fatalf("%q", a)
	}
}

func TestUnelevatedCustomPathsAreRefused(t *testing.T) {
	srv := newEnrollSrv(t)
	for _, flag := range []string{"--state-dir", "--install-dir"} {
		e := newEnv(t)
		e.stampWith(srv, nil)
		e.elevated = false
		args := []string{flag, e.dir}
		if flag == "--install-dir" {
			args = append(args, "--state-dir", e.state)
		}
		if c := runSetup(args, e.ops(), time.Now); c != exitNotElevated {
			t.Fatalf("%s: exit %d", flag, c)
		}
		if len(e.calls) != 0 {
			t.Fatalf("%s: tried to elevate: %v", flag, e.calls)
		}
	}
}
