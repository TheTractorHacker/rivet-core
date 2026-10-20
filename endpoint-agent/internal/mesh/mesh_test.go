package mesh

import (
	"context"
	"crypto/sha256"
	"crypto/sha512"
	"encoding/hex"
	"encoding/json"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"sync"
	"testing"
	"time"
)

const (
	testGroup = "4a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f9"
	// the same 48 bytes in MeshCentral's URL form (base64, '+' written '@', '/' written '$'): the form the download address carries
	testGroupURL = "ShssPU5fYHGCk6S1xtfo@QobLD1OX2BxgpOktcbX6PkKGyw9Tl9gcYKTpLXG1@j5"
	testNode     = "node//AbCdEfGhIjKlMnOpQrStUvWxYz0123456789@$AbCdEfGhIjKlMnOpQrStUvWxYz01"
)

func quiet() *slog.Logger {
	return slog.New(slog.NewTextHandler(os.Stderr, &slog.HandlerOptions{Level: slog.LevelError}))
}

func rawSpec(t *testing.T, srvURL, pin string) *Spec {
	t.Helper()
	b, _ := json.Marshal(map[string]any{"url": srvURL, "meshid": testGroup, "pin": pin})
	s, err := ParseSpec(json.RawMessage(`true`), b)
	if err != nil {
		t.Fatal(err)
	}
	return s
}

type mock struct {
	*httptest.Server
	mu   sync.Mutex
	hits []string
	uris []string // the request line target exactly as the server received it
	// /meshsettings answer (defaults: 200 and a small .msh)
	settingsStatus int
	settings       string
}

const defaultMSH = "MeshName=Test\nMeshType=2\nStartupType=9\nmeshServiceName=Mesh Agent\n"

func (m *mock) lastURI() string {
	m.mu.Lock()
	defer m.mu.Unlock()
	if len(m.uris) == 0 {
		return ""
	}
	return m.uris[len(m.uris)-1]
}

func (m *mock) allHits() []string {
	m.mu.Lock()
	defer m.mu.Unlock()
	return append([]string(nil), m.hits...)
}

func (m *mock) lastQuery() string {
	m.mu.Lock()
	defer m.mu.Unlock()
	if len(m.hits) == 0 {
		return ""
	}
	return m.hits[len(m.hits)-1]
}

// newMock serves `body` on /meshagents like a Mesh server (self-signed TLS).
func newMock(t *testing.T, status int, ctype string, body []byte) *mock {
	m := &mock{}
	m.Server = httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		m.mu.Lock()
		m.hits = append(m.hits, r.URL.Path+"?"+r.URL.RawQuery)
		m.uris = append(m.uris, r.RequestURI)
		m.mu.Unlock()
		if r.URL.Path == "/meshsettings" {
			st, body := m.settingsStatus, m.settings
			if st == 0 {
				st, body = 200, defaultMSH
			}
			w.WriteHeader(st)
			_, _ = w.Write([]byte(body))
			return
		}
		if r.URL.Path != "/meshagents" {
			http.NotFound(w, r)
			return
		}
		if ctype != "" {
			w.Header().Set("Content-Type", ctype)
		}
		w.WriteHeader(status)
		_, _ = w.Write(body)
	}))
	t.Cleanup(m.Close)
	return m
}

func pinOf(m *mock, sha384 bool) string {
	der := m.Certificate().Raw
	if sha384 {
		s := sha512.Sum384(der)
		return hex.EncodeToString(s[:])
	}
	s := sha256.Sum256(der)
	return hex.EncodeToString(s[:])
}

// fakeInstaller is a stub meshagent: it records its arguments and, unless
// MESH_TEST_NOINSTALL is set, creates the files `-fullinstall` would.
func fakeInstaller(t *testing.T, marker string, exit int) []byte {
	t.Helper()
	return []byte("#!/bin/sh\necho \"ran with $@\" > " + marker + "\ncp ./meshagent.msh \"" + marker + ".msh\" 2>/dev/null\n" +
		"if [ -z \"$MESH_TEST_NOINSTALL\" ] && [ -n \"$MESH_TEST_DIR\" ]; then mkdir -p \"$MESH_TEST_DIR\"; cp ./meshagent \"$MESH_TEST_DIR/meshagent\"; cp ./meshagent.msh \"$MESH_TEST_DIR/meshagent.msh\"; fi\n" +
		"echo installer-output\nexit " + string(rune('0'+exit)) + "\n")
}

// linuxHooks makes the Linux flow hermetic: a temp install dir (exported to the stub as MESH_TEST_DIR),
// a fake systemd and short verification timings. The fake service is "registered" once the stub installed the files.
type linuxRig struct {
	dir      string
	state    string // what is-active prints
	unitless bool   // list-unit-files finds nothing
	ctl      [][]string
}

func (l *linuxRig) apply(o *InstallOptions) {
	o.LinuxInstallDir = l.dir
	o.StartupType = func() int { return 1 }
	o.SystemdRunDir = filepath.Dir(l.dir) // exists
	o.PollInterval, o.VerifyTimeout = 5*time.Millisecond, 100*time.Millisecond
	o.Systemctl = func(_ context.Context, a ...string) (string, int) {
		l.ctl = append(l.ctl, a)
		switch a[0] {
		case "list-unit-files":
			if l.unitless {
				return "", 0
			}
			return "meshagent.service enabled enabled\n", 0
		case "is-active":
			return l.state + "\n", 0
		}
		return "", 1
	}
}

func newLinuxRig(t *testing.T) *linuxRig {
	l := &linuxRig{dir: filepath.Join(t.TempDir(), "mesh_services", "meshagent"), state: "active"}
	t.Setenv("MESH_TEST_DIR", l.dir)
	t.Setenv("MESH_TEST_NOINSTALL", "")
	return l
}

func opts(t *testing.T) InstallOptions {
	o := InstallOptions{GOOS: "linux", GOARCH: "amd64", WorkDir: t.TempDir(), Log: quiet()}
	newLinuxRig(t).apply(&o)
	return o
}

func TestInstallSuccessPinnedSHA384(t *testing.T) {
	if runtime.GOOS == "windows" {
		t.Skip("shell script installer")
	}
	marker := filepath.Join(t.TempDir(), "marker")
	m := newMock(t, 200, "application/octet-stream", fakeInstaller(t, marker, 0))
	res := Install(context.Background(), rawSpec(t, m.URL, pinOf(m, true)), opts(t))
	if !res.Installed || res.Error != "" || res.ExitCode != 0 {
		t.Fatalf("result %+v", res)
	}
	if got, _ := os.ReadFile(marker); !strings.Contains(string(got), "ran with -fullinstall --copy-msh=1") {
		t.Fatalf("installer did not run with the Mesh script args: %q", got)
	}
	if q := m.lastQuery(); q != "/meshsettings?id="+testGroupURL { // last request: the settings
		t.Fatalf("settings URL %q", q)
	}
	if h := m.allHits(); len(h) != 2 || h[0] != "/meshagents?id=6" {
		t.Fatalf("download URLs %q", h)
	}
	if res.SHA256 == "" || res.AgentID != 6 || !strings.Contains(res.Log, "installer-output") {
		t.Fatalf("result %+v", res)
	}
	// the private download directory is removed afterwards
	left, _ := filepath.Glob(filepath.Join(opts(t).WorkDir, "mesh-install-*"))
	if len(left) != 0 {
		t.Fatal("work directory left behind")
	}
}

func TestInstallSuccessPinnedSHA256AndArm64Table(t *testing.T) {
	marker := filepath.Join(t.TempDir(), "marker")
	m := newMock(t, 200, "", fakeInstaller(t, marker, 0))
	o := opts(t)
	o.GOARCH = "arm64"
	res := Install(context.Background(), rawSpec(t, m.URL, pinOf(m, false)), o)
	if !res.Installed || res.AgentID != 26 {
		t.Fatalf("result %+v", res)
	}
	if h := m.allHits(); h[0] != "/meshagents?id=26" {
		t.Fatal(h)
	}
}

func TestPayloadTableOverridesTheBuiltInIDs(t *testing.T) {
	m := newMock(t, 200, "", fakeInstaller(t, filepath.Join(t.TempDir(), "x"), 0))
	b, _ := json.Marshal(map[string]any{"url": m.URL, "meshid": testGroup, "pin": pinOf(m, true), "agent_ids": map[string]int{"linux/amd64": 99}, "install_args": map[string][]string{"linux": {"-install", "--installPath=/opt/x"}}})
	s, err := ParseSpec(json.RawMessage(`true`), b)
	if err != nil {
		t.Fatal(err)
	}
	if res := Install(context.Background(), s, opts(t)); !res.Installed || res.AgentID != 99 {
		t.Fatalf("%+v", res)
	}
	if h := m.allHits(); h[0] != "/meshagents?id=99" {
		t.Fatal(h)
	}
}

func TestPinMismatchIsRefusedAndNothingRuns(t *testing.T) {
	marker := filepath.Join(t.TempDir(), "marker")
	m := newMock(t, 200, "", fakeInstaller(t, marker, 0))
	bad := strings.Repeat("ab", 48)
	res := Install(context.Background(), rawSpec(t, m.URL, bad), opts(t))
	if res.Installed || !strings.Contains(res.Error, "pinned") {
		t.Fatalf("%+v", res)
	}
	if _, err := os.Stat(marker); err == nil {
		t.Fatal("the installer ran despite a pin mismatch")
	}
	if strings.Contains(res.Error+res.Log, testGroup) || strings.Contains(res.Error+res.Log, testGroupURL) {
		t.Fatal("group id leaked into the result")
	}
}

func TestSelfSignedWithoutPinIsRefused(t *testing.T) {
	m := newMock(t, 200, "", fakeInstaller(t, filepath.Join(t.TempDir(), "x"), 0))
	res := Install(context.Background(), rawSpec(t, m.URL, ""), opts(t))
	if res.Installed || res.Error == "" {
		t.Fatalf("a certificate outside the system roots must be refused without a pin: %+v", res)
	}
	if strings.Contains(res.Error, testGroup) {
		t.Fatal("group id leaked")
	}
}

func TestNonHTTPSAndBadSpecsAreRefused(t *testing.T) {
	for name, body := range map[string]string{
		"http":      `{"url":"http://mesh.example.com","meshid":"` + testGroup + `"}`,
		"userinfo":  `{"url":"https://u:p@mesh.example.com","meshid":"` + testGroup + `"}`,
		"query":     `{"url":"https://mesh.example.com/?x=1","meshid":"` + testGroup + `"}`,
		"nogroup":   `{"url":"https://mesh.example.com","meshid":""}`,
		"badgroup":  `{"url":"https://mesh.example.com","meshid":"zz11"}`,
		"badpin":    `{"url":"https://mesh.example.com","meshid":"` + testGroup + `","pin":"abc"}`,
		"badid":     `{"url":"https://mesh.example.com","meshid":"` + testGroup + `","agent_ids":{"linux/amd64":0}}`,
		"badargs":   `{"url":"https://mesh.example.com","meshid":"` + testGroup + `","install_args":{"linux":["-install; rm -rf /"]}}`,
		"badflags":  `{"url":"https://mesh.example.com","meshid":"` + testGroup + `","installflags":9}`,
		"notobject": `[1,2]`,
	} {
		if _, err := ParseSpec(json.RawMessage(`true`), json.RawMessage(body)); err == nil || err == ErrDisabled {
			t.Errorf("%s: want a validation error, got %v", name, err)
		}
	}
	if _, err := ParseSpec(json.RawMessage(`false`), json.RawMessage(`{}`)); err != ErrDisabled {
		t.Error("false must be disabled")
	}
	if _, err := ParseSpec(json.RawMessage(`"yes"`), json.RawMessage(`{}`)); err != ErrDisabled {
		t.Error("a non-boolean must be disabled, never an error")
	}
	if _, err := ParseSpec(nil, nil); err != ErrDisabled {
		t.Error("absent must be disabled")
	}
	if _, err := ParseSpec(json.RawMessage(`true`), nil); err == nil || err == ErrDisabled {
		t.Error("requested without a source is an error")
	}
}

func TestOversizeIsRefused(t *testing.T) {
	m := newMock(t, 200, "", append([]byte("#!/bin/sh\n"), make([]byte, 5000)...))
	o := opts(t)
	o.MaxBytes = 1000
	res := Install(context.Background(), rawSpec(t, m.URL, pinOf(m, true)), o)
	if res.Installed || !strings.Contains(res.Error, "larger than") {
		t.Fatalf("%+v", res)
	}
}

func TestChunkedOversizeWithoutContentLengthIsRefused(t *testing.T) {
	srv := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		fl := w.(http.Flusher)
		for i := 0; i < 20; i++ {
			_, _ = w.Write([]byte("#!/bin/sh\n" + strings.Repeat("x", 100)))
			fl.Flush()
		}
	}))
	t.Cleanup(srv.Close)
	m := &mock{Server: srv}
	o := opts(t)
	o.MaxBytes = 500
	res := Install(context.Background(), rawSpec(t, srv.URL, pinOf(m, true)), o)
	if res.Installed || !strings.Contains(res.Error, "larger than") {
		t.Fatalf("%+v", res)
	}
}

func TestHTTPErrorsHTMLAndEmpty(t *testing.T) {
	for name, c := range map[string]struct {
		status int
		ctype  string
		body   string
		want   string
	}{
		"404":   {404, "text/plain", "not found", "HTTP 404"},
		"500":   {500, "", "boom", "HTTP 500"},
		"html":  {200, "text/html; charset=utf-8", "<html>login</html>", "web page"},
		"empty": {200, "application/octet-stream", "", "empty"},
		"text":  {200, "application/octet-stream", "hello, not a program", "not a Linux program"},
	} {
		m := newMock(t, c.status, c.ctype, []byte(c.body))
		res := Install(context.Background(), rawSpec(t, m.URL, pinOf(m, true)), opts(t))
		if res.Installed || !strings.Contains(res.Error, c.want) {
			t.Errorf("%s: %+v", name, res)
		}
	}
}

func TestRedirectToAnotherHostIsRefused(t *testing.T) {
	other := newMock(t, 200, "", fakeInstaller(t, filepath.Join(t.TempDir(), "x"), 0))
	srv := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		http.Redirect(w, r, other.URL+"/meshagents", http.StatusFound)
	}))
	t.Cleanup(srv.Close)
	res := Install(context.Background(), rawSpec(t, srv.URL, pinOf(&mock{Server: srv}, true)), opts(t))
	if res.Installed || !strings.Contains(res.Error, "redirect") {
		t.Fatalf("%+v", res)
	}
}

func TestInstallerFailureIsAResultNotAPanicOrError(t *testing.T) {
	marker := filepath.Join(t.TempDir(), "marker")
	m := newMock(t, 200, "", fakeInstaller(t, marker, 3))
	res := Install(context.Background(), rawSpec(t, m.URL, pinOf(m, true)), opts(t))
	if res.Installed || res.ExitCode != 3 || !strings.Contains(res.Error, "code 3") || !strings.Contains(res.Log, "installer-output") {
		t.Fatalf("%+v", res)
	}
}

func TestInstallerTimeoutKillsIt(t *testing.T) {
	m := newMock(t, 200, "", []byte("#!/bin/sh\nsleep 30\n"))
	o := opts(t)
	o.RunTimeout = 300 * time.Millisecond
	start := time.Now()
	res := Install(context.Background(), rawSpec(t, m.URL, pinOf(m, true)), o)
	if res.Installed || time.Since(start) > 10*time.Second {
		t.Fatalf("%+v after %s", res, time.Since(start))
	}
}

func TestWindowsFlowUsesTheWindowsIDAndArgsWithAFakeRunner(t *testing.T) {
	exe := append([]byte("MZ"), make([]byte, 64)...)
	m := newMock(t, 200, "application/octet-stream", exe)
	var gotPath string
	var gotArgs []string
	o := opts(t)
	o.GOOS, o.GOARCH = "windows", "amd64"
	o.Run = func(ctx context.Context, dir, path string, args []string) (int, string, error) {
		gotPath, gotArgs = path, args
		return 0, "ok", nil
	}
	res := Install(context.Background(), rawSpec(t, m.URL, pinOf(m, true)), o)
	if !res.Installed || res.AgentID != 4 || filepath.Base(gotPath) != "MeshAgent.exe" || strings.Join(gotArgs, " ") != "-fullinstall" {
		t.Fatalf("%+v %q %v", res, gotPath, gotArgs)
	}
	// an ELF/HTML body is not a Windows program
	m2 := newMock(t, 200, "", []byte("\x7fELF...."))
	if res := Install(context.Background(), rawSpec(t, m2.URL, pinOf(m2, true)), o); res.Installed || !strings.Contains(res.Error, "Windows program") {
		t.Fatalf("%+v", res)
	}
}

func TestUnknownPlatformHasNoAgentID(t *testing.T) {
	m := newMock(t, 200, "", nil)
	o := opts(t)
	o.GOARCH = "mips"
	if res := Install(context.Background(), rawSpec(t, m.URL, pinOf(m, true)), o); res.Installed || !strings.Contains(res.Error, "no Mesh agent id") {
		t.Fatalf("%+v", res)
	}
}

func TestPinMatches(t *testing.T) {
	der := []byte("certificate bytes")
	a, b := sha256.Sum256(der), sha512.Sum384(der)
	if !PinMatches(hex.EncodeToString(a[:]), der) || !PinMatches(hex.EncodeToString(b[:]), der) {
		t.Fatal("matching pins must match")
	}
	if PinMatches(hex.EncodeToString(a[:]), []byte("other")) || PinMatches("abcd", der) || PinMatches("", der) || PinMatches(strings.Repeat("zz", 48), der) {
		t.Fatal("non-matching pins must not match")
	}
}

func TestPinNormalisationAcceptsColonsAndUpperCase(t *testing.T) {
	var parts []string
	for i := 0; i < 32; i++ {
		parts = append(parts, "AB")
	}
	s := Spec{URL: "https://mesh.example.com", MeshID: strings.ToUpper(testGroup), Pin: strings.Join(parts, ":")}
	if err := s.Validate(); err != nil || s.Pin != strings.Repeat("ab", 32) || s.MeshID != testGroup {
		t.Fatalf("%v %q", err, s.Pin)
	}
}

// ---- node id parsing from fixtures

func fixture(t *testing.T, name string) string {
	b, err := os.ReadFile(filepath.Join("testdata", name))
	if err != nil {
		t.Fatal(err)
	}
	return string(b)
}

func TestParseNodeIDOutput(t *testing.T) {
	if id, ok := ParseNodeIDOutput(fixture(t, "nodeid_stdout.txt")); !ok || id != testNode {
		t.Fatalf("%q %v", id, ok)
	}
	for in, want := range map[string]string{
		"NodeID: " + testNode + "\n":         testNode,
		"node id = AbCdEfGhIjKlMnOpQrSt\r\n": "node//AbCdEfGhIjKlMnOpQrSt",
		"\"AbCdEfGhIjKlMnOpQrSt\"":           "node//AbCdEfGhIjKlMnOpQrSt",
		"  node//AbCdEfGhIjKlMnOpQrSt  \n":   "node//AbCdEfGhIjKlMnOpQrSt",
	} {
		if id, ok := ParseNodeIDOutput(in); !ok || id != want {
			t.Errorf("%q: %q %v", in, id, ok)
		}
	}
	for _, in := range []string{"", "MeshCentral Agent started\n", "short\n", "has space AbCdEfGhIjKlMnOpQrSt\n", "node//" + strings.Repeat("A", 200)} {
		if id, ok := ParseNodeIDOutput(in); ok {
			t.Errorf("%q must not parse, got %q", in, id)
		}
	}
}

func TestParseRegistryText(t *testing.T) {
	if id, ok := ParseRegistryText(fixture(t, "reg_query.txt")); !ok || id != testNode {
		t.Fatalf("%q %v", id, ok)
	}
	if _, ok := ParseRegistryText("    ServiceName    REG_SZ    Mesh Agent\n"); ok {
		t.Fatal("no NodeId value")
	}
}

func TestParseMSH(t *testing.T) {
	m := ParseMSH(fixture(t, "MeshAgent.msh"))
	if m.NodeID != "" || m.MeshID != testGroup || m.Server != "wss://mesh.example.com:443/agent.ashx" || m.Values["MeshName"] != "Acme Laptops" {
		t.Fatalf("%+v", m)
	}
	m = ParseMSH(fixture(t, "MeshAgent_with_node.msh"))
	if m.NodeID != testNode || m.MeshID != testGroup {
		t.Fatalf("%+v", m)
	}
	if got := ParseMSH("garbage\n\n=\n#c\n"); len(got.Values) != 1 {
		t.Fatalf("%+v", got) // only "=" produced an (empty) key
	}
}

func TestProbeLinuxRunsNodeidAndFallsBackToMSH(t *testing.T) {
	if runtime.GOOS == "windows" {
		t.Skip()
	}
	dir := t.TempDir()
	exe := filepath.Join(dir, "meshagent")
	script := "#!/bin/sh\nif [ \"$1\" = \"-nodeid\" ]; then cat " + filepath.Join("testdata", "nodeid_stdout.txt") + "; exit 0; fi\nexit 9\n"
	abs, _ := filepath.Abs(".")
	script = strings.ReplaceAll(script, filepath.Join("testdata", "nodeid_stdout.txt"), filepath.Join(abs, "testdata", "nodeid_stdout.txt"))
	if err := os.WriteFile(exe, []byte(script), 0o755); err != nil {
		t.Fatal(err)
	}
	p := Probe{GOOS: "linux", Paths: []string{"/nonexistent/meshagent", exe}}
	if p.Found() != exe {
		t.Fatal("Found")
	}
	if id, src := p.NodeID(context.Background()); id != testNode || src != "nodeid" {
		t.Fatalf("%q %q", id, src)
	}
	// the agent prints nothing usable: fall back to a .msh that records the node
	if err := os.WriteFile(exe, []byte("#!/bin/sh\nexit 1\n"), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(dir, "meshagent.msh"), []byte(fixture(t, "MeshAgent_with_node.msh")), 0o644); err != nil {
		t.Fatal(err)
	}
	if id, src := p.NodeID(context.Background()); id != testNode || src != "msh" {
		t.Fatalf("%q %q", id, src)
	}
	if id, _ := (Probe{GOOS: "linux", Paths: []string{"/nonexistent"}}).NodeID(context.Background()); id != "" {
		t.Fatal("no agent, no id")
	}
}

func TestProbeWindowsRegistryFallbackWithFakeRunner(t *testing.T) {
	exe := `C:\Program Files\Mesh Agent\MeshAgent.exe`
	calls := []string{}
	p := Probe{GOOS: "windows", Paths: []string{exe},
		Exists:   func(s string) bool { return s == exe },
		ReadFile: func(string) ([]byte, error) { return nil, os.ErrNotExist },
		Run: func(ctx context.Context, dir, path string, args []string) (int, string, error) {
			calls = append(calls, path+" "+strings.Join(args, " "))
			if path == "reg.exe" {
				return 0, fixture(t, "reg_query.txt"), nil
			}
			return 1, "", nil
		}}
	if id, src := p.NodeID(context.Background()); id != testNode || src != "registry" {
		t.Fatalf("%q %q", id, src)
	}
	if len(calls) != 2 || !strings.HasSuffix(calls[0], "-nodeid") || !strings.HasPrefix(calls[1], "reg.exe query HKLM") {
		t.Fatal(calls)
	}
}

func TestReporter(t *testing.T) {
	dir := t.TempDir()
	now := time.Date(2026, 10, 11, 12, 0, 0, 0, time.UTC)
	runs := 0
	exeOK := true
	rp := &Reporter{StateDir: dir, Now: func() time.Time { return now }, Probe: Probe{GOOS: "linux", Paths: []string{"/x/meshagent"},
		Exists: func(string) bool { return exeOK },
		Run: func(context.Context, string, string, []string) (int, string, error) {
			runs++
			return 0, testNode + "\n", nil
		}}}
	exeOK = false
	if rp.Current(context.Background()) != nil {
		t.Fatal("nothing installed and nothing attempted: no report")
	}
	// an attempt that failed, no agent on disk
	_ = SaveResult(dir, Result{Error: "the Mesh server answered HTTP 404"})
	rp.at = time.Time{}
	if r := rp.Current(context.Background()); r == nil || r.Installed || r.Error == "" || r.NodeID != "" {
		t.Fatalf("%+v", r)
	}
	// installed now: id read once, cached for the day
	exeOK = true
	now = now.Add(2 * time.Hour)
	r := rp.Current(context.Background())
	if r == nil || !r.Installed || r.NodeID != testNode || r.Error != "" {
		t.Fatalf("%+v", r)
	}
	h := r.Hash()
	for i := 0; i < 5; i++ {
		now = now.Add(time.Hour)
		rp.Current(context.Background())
	}
	if runs != 1 {
		t.Fatalf("the agent was run %d times within a day", runs)
	}
	now = now.Add(25 * time.Hour)
	if r2 := rp.Current(context.Background()); runs != 2 || r2.Hash() != h {
		t.Fatalf("runs=%d", runs)
	}
	b, _ := json.Marshal(r)
	if string(b) != `{"installed":true,"node_id":"`+testNode+`"}` {
		t.Fatal(string(b))
	}
}
