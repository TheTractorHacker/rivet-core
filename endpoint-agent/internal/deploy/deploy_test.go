package deploy

import (
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"testing"
	"time"

	"rivetit-agent/internal/jobs"
	"rivetit-agent/internal/remote"
)

// ---- shared contract vectors (written by the server's generator; the agent must be exactly as strict as the file) ----

type vectorCase struct {
	Name   string          `json:"name"`
	Params json.RawMessage `json:"params"`
	Valid  bool            `json:"valid"`
}

func loadVectors(t *testing.T) (install, detect []vectorCase) {
	t.Helper()
	b, err := os.ReadFile(filepath.Join("..", "..", "testdata", "vectors", "agent_software_deploy_vectors.json"))
	if err != nil {
		t.Fatal(err)
	}
	var f struct {
		Install []vectorCase `json:"install"`
		Detect  []vectorCase `json:"detect"`
	}
	if err := json.Unmarshal(b, &f); err != nil {
		t.Fatal(err)
	}
	return f.Install, f.Detect
}

func TestInstallParametersFollowTheSharedVectors(t *testing.T) {
	install, _ := loadVectors(t)
	if len(install) < 100 {
		t.Fatalf("only %d vectors", len(install))
	}
	for _, c := range install {
		_, err := ParseInstallParams(c.Params)
		if (err == nil) != c.Valid {
			t.Errorf("%s: valid=%v but the agent says err=%v", c.Name, c.Valid, err)
		}
	}
}

func TestDetectParametersFollowTheSharedVectors(t *testing.T) {
	_, detect := loadVectors(t)
	for _, c := range detect {
		_, err := ParseDetectParams(c.Params)
		if (err == nil) != c.Valid {
			t.Errorf("%s: valid=%v but the agent says err=%v", c.Name, c.Valid, err)
		}
	}
}

func TestParametersRefuseDuplicateKeysTrailingDataAndNonObjects(t *testing.T) {
	for _, raw := range []string{
		`{"action":"install","action":"uninstall","fmt":"apt","pkg":"x"}`,
		`{"action":"install","fmt":"apt","pkg":"x"} {}`,
		`["x"]`, `"x"`, `null5`, ``,
		`{"rules":"[{\"key\":\"p1\",\"kind\":\"package\",\"value\":\"x\",\"min\":\"\"}] trailing"}`,
		`{"rules":"[{\"key\":\"p1\",\"key\":\"p2\",\"kind\":\"package\",\"value\":\"x\",\"min\":\"\"}]"}`,
	} {
		if _, err := ParseInstallParams(json.RawMessage(raw)); err == nil && strings.Contains(raw, "action") {
			t.Errorf("install accepted %s", raw)
		}
		if _, err := ParseDetectParams(json.RawMessage(raw)); err == nil && strings.Contains(raw, "rules") {
			t.Errorf("detect accepted %s", raw)
		}
	}
}

func TestWireNamesMatchTheJobsPackage(t *testing.T) {
	if JobDetect != jobs.TypeSoftwareDetect || JobInstall != jobs.TypeSoftwareInstall {
		t.Fatal("job type names differ from internal/jobs")
	}
	if !jobs.IsSoftwareType(JobDetect) || !jobs.IsSoftwareType(JobInstall) || jobs.IsSoftwareType("patch_scan") {
		t.Fatal("IsSoftwareType")
	}
	for _, typ := range []string{JobDetect, JobInstall} {
		found := false
		for _, t2 := range jobs.JobTypes() {
			found = found || t2 == typ
		}
		if !found && jobs.SupportsType(typ) {
			t.Errorf("%s is supported but not in JobTypes()", typ)
		}
	}
}

func TestCompareVersions(t *testing.T) {
	for _, c := range []struct {
		a, b string
		want int
	}{
		{"1.0", "1.0.0", 0}, {"1.0", "1.0.1", -1}, {"2.10", "2.9", 1}, {"1.0~rc1", "1.0", -1}, {"1:1.0", "2.0", 1}, {"2.4.1-3ubuntu1", "2.4.1", 1},
		{"1.0", "1.0beta", -1}, {"1.0a", "1.0B", -1}, {"10.0.19045", "10.0.2", 1}, {"001.2", "1.2", 0}, {"1.5-1", "1.5-1", 0},
	} {
		if got := CompareVersions(c.a, c.b); got != c.want {
			t.Errorf("CompareVersions(%q,%q) = %d, want %d", c.a, c.b, got, c.want)
		}
		if got := CompareVersions(c.b, c.a); got != -c.want {
			t.Errorf("CompareVersions(%q,%q) = %d, want %d", c.b, c.a, got, -c.want)
		}
	}
	if satisfied(true, "", "1.0") || !satisfied(true, "", "") || satisfied(false, "9", "") || !satisfied(true, "1.2", "1.2") {
		t.Error("satisfied")
	}
}

// ---- Linux, with a scripted runner ----

type call struct {
	prog string
	args []string
}

type fakeRunner struct {
	mu    sync.Mutex
	calls []call
	fn    func(c call) (remote.RunResult, error)
}

func (f *fakeRunner) Run(ctx context.Context, name string, args []string, maxOut int, timeout time.Duration) (remote.RunResult, error) {
	c := call{name, append([]string{}, args...)}
	f.mu.Lock()
	f.calls = append(f.calls, c)
	f.mu.Unlock()
	return f.fn(c)
}

func (f *fakeRunner) joined() []string {
	var out []string
	for _, c := range f.calls {
		out = append(out, c.prog+" "+strings.Join(c.args, " "))
	}
	return out
}

func (f *fakeRunner) ran(sub string) bool {
	for _, l := range f.joined() {
		if strings.Contains(l, sub) {
			return true
		}
	}
	return false
}

type fakeFS map[string]bool

func (f fakeFS) Exists(p string) bool { return f[p] }

var debFS = fakeFS{"/usr/bin/apt-get": true, "/usr/bin/dpkg-query": true, "/usr/bin/env": true}
var rpmFS = fakeFS{"/usr/bin/dnf": true, "/usr/bin/rpm": true, "/usr/bin/env": true}

func res(out string, code int) remote.RunResult {
	return remote.RunResult{Stdout: []byte(out), ExitCode: code}
}

// aptHost simulates one apt machine: the installed version of rivetit-dummy (empty = absent) and what the simulation prints.
type aptHost struct {
	installed string
	sim       string
	simCode   int
	runOut    string
	runCode   int
	dependsOn string
	lockOnRun bool
	afterRun  string // version present after a successful run ("" = removed)
}

func (h *aptHost) runner() *fakeRunner {
	r := &fakeRunner{}
	r.fn = func(c call) (remote.RunResult, error) {
		cmd := strings.Join(c.args, " ")
		switch {
		case strings.Contains(cmd, "dpkg-query"):
			if h.installed == "" {
				return res("", 1), nil
			}
			return res("ii |"+h.installed+"\n", 0), nil
		case strings.Contains(cmd, "apt-get") && strings.Contains(cmd, " -s "):
			return res(h.sim, h.simCode), nil
		case strings.Contains(cmd, "apt-get"):
			if h.lockOnRun {
				return remote.RunResult{Stderr: []byte("E: Could not get lock /var/lib/dpkg/lock-frontend"), ExitCode: 100}, nil
			}
			if h.runCode == 0 {
				h.installed = h.afterRun
			}
			return res(h.runOut, h.runCode), nil
		}
		return res("", 127), nil
	}
	return r
}

func aptParams(t *testing.T, over string) InstallParams {
	t.Helper()
	base := map[string]any{"action": "install", "mode": "run", "fmt": "apt", "pkg": "rivetit-dummy", "version": "1.5"}
	if over != "" {
		var o map[string]any
		if err := json.Unmarshal([]byte(over), &o); err != nil {
			t.Fatal(err)
		}
		for k, v := range o {
			if v == nil {
				delete(base, k)
			} else {
				base[k] = v
			}
		}
	}
	b, _ := json.Marshal(base)
	p, err := ParseInstallParams(b)
	if err != nil {
		t.Fatalf("%s: %v", b, err)
	}
	return p
}

func runLinux(h *aptHost, p InstallParams) (outcome, *fakeRunner) {
	r := h.runner()
	e := Engine{GOOS: "linux", R: r, FS: debFS}
	return e.installLinux(context.Background(), p), r
}

const simInstall = "Inst rivetit-dummy (1.5 stable [all])\nConf rivetit-dummy (1.5 stable [all])\n"

func TestLinuxInstallRunsOnlyThePinnedPackageAfterASimulationAndVerifies(t *testing.T) {
	h := &aptHost{sim: simInstall, afterRun: "1.5"}
	o, r := runLinux(h, aptParams(t, ""))
	if o.result != ResInstalled || o.version != "1.5" {
		t.Fatalf("%+v", o)
	}
	l := r.joined()
	var aptCalls []string
	for _, x := range l {
		if strings.Contains(x, "apt-get") {
			aptCalls = append(aptCalls, x)
		}
	}
	if len(aptCalls) != 2 || !strings.Contains(aptCalls[0], " -s ") || strings.Contains(aptCalls[1], " -s ") {
		t.Fatalf("a simulation first, then the real run: %v", aptCalls)
	}
	if !strings.HasSuffix(aptCalls[1], "install -- rivetit-dummy=1.5") || !strings.Contains(aptCalls[1], "--no-install-recommends") {
		t.Errorf("real run: %s", aptCalls[1])
	}
}

func TestLinuxNeverBuildsAnUnsafeCommand(t *testing.T) {
	forbidden := []string{"--allow-unauthenticated", "--force-yes", "--nogpgcheck", "AllowUnauthenticated", "gpgcheck=0", "http://", "https://", ".deb", ".rpm", "purge", "autoremove", "dpkg -i", "sh -c", "bash"}
	cases := []struct {
		fs   fakeFS
		host *aptHost
		over string
	}{
		{debFS, &aptHost{sim: simInstall, afterRun: "1.5"}, ""},
		{debFS, &aptHost{sim: simInstall, afterRun: "1.5"}, `{"mode":"dry_run"}`},
		{debFS, &aptHost{installed: "1.5", sim: "Remv rivetit-dummy [1.5]\n"}, `{"action":"uninstall","version":null}`},
		{debFS, &aptHost{installed: "1.0", sim: simInstall, afterRun: "1.5"}, `{"version":null}`},
	}
	for i, c := range cases {
		r := c.host.runner()
		e := Engine{GOOS: "linux", R: r, FS: c.fs}
		e.installLinux(context.Background(), aptParams(t, c.over))
		for _, line := range r.joined() {
			for _, f := range forbidden {
				if strings.Contains(line, f) {
					t.Errorf("case %d built %q containing %q", i, line, f)
				}
			}
			if strings.Contains(line, "apt-get") && !strings.Contains(line, " -- rivetit-dummy") {
				t.Errorf("case %d: the package name does not follow --: %s", i, line)
			}
		}
	}
}

func TestLinuxDryRunChangesNothing(t *testing.T) {
	h := &aptHost{sim: simInstall, afterRun: "1.5"}
	o, r := runLinux(h, aptParams(t, `{"mode":"dry_run"}`))
	if o.result != ResWouldInstall || o.version != "1.5" || h.installed != "" {
		t.Fatalf("%+v installed=%q", o, h.installed)
	}
	for _, l := range r.joined() {
		if strings.Contains(l, "apt-get") && !strings.Contains(l, " -s ") {
			t.Errorf("a real apt-get call in a dry run: %s", l)
		}
	}
}

func TestLinuxDoesNothingWhenAlreadyInTheWantedState(t *testing.T) {
	h := &aptHost{installed: "1.5"}
	o, r := runLinux(h, aptParams(t, ""))
	if o.result != ResAlready || r.ran("apt-get") {
		t.Fatalf("%+v %v", o, r.joined())
	}
	o, r = runLinux(&aptHost{}, aptParams(t, `{"action":"uninstall","version":null}`))
	if o.result != ResNotInstalled || r.ran("apt-get") {
		t.Fatalf("%+v %v", o, r.joined())
	}
}

func TestLinuxRefusesATransactionThatWouldRemoveAnotherPackage(t *testing.T) {
	h := &aptHost{installed: "1.0", sim: "Remv rivetit-app [1.0]\nRemv rivetit-base [1.0]\n", afterRun: ""}
	o, r := runLinux(h, aptParams(t, `{"action":"uninstall","version":null}`))
	if o.result != ResFailed || o.code != "would_remove_other" || h.installed != "1.0" {
		t.Fatalf("%+v", o)
	}
	for _, l := range r.joined() {
		if strings.Contains(l, "apt-get") && !strings.Contains(l, " -s ") {
			t.Errorf("ran for real: %s", l)
		}
	}
	// the target itself in the removal list is fine
	h = &aptHost{installed: "1.0", sim: "Remv rivetit-dummy:amd64 [1.0]\n", afterRun: ""}
	if o, _ = runLinux(h, aptParams(t, `{"action":"uninstall","version":null}`)); o.result != ResUninstalled {
		t.Fatalf("%+v", o)
	}
}

func TestLinuxLockNotFoundAndFailureMapping(t *testing.T) {
	h := &aptHost{sim: simInstall, lockOnRun: true}
	if o, _ := runLinux(h, aptParams(t, "")); o.result != ResLocked {
		t.Errorf("lock: %+v", o)
	}
	h = &aptHost{sim: "E: Unable to locate package rivetit-dummy\n", simCode: 100}
	if o, _ := runLinux(h, aptParams(t, "")); o.result != ResNotFound {
		t.Errorf("not found: %+v", o)
	}
	h = &aptHost{sim: simInstall, runCode: 100, runOut: "E: Sub-process /usr/bin/dpkg returned an error code (1)"}
	if o, _ := runLinux(h, aptParams(t, "")); o.result != ResFailed || o.code != "exit_100" {
		t.Errorf("failure: %+v", o)
	}
	// the run "succeeds" but the package is not there afterwards
	h = &aptHost{sim: simInstall, afterRun: ""}
	if o, _ := runLinux(h, aptParams(t, "")); o.result != ResVerifyFailed {
		t.Errorf("verify: %+v", o)
	}
	// nothing offered
	h = &aptHost{sim: "0 upgraded, 0 newly installed\n", installed: "1.0"}
	if o, _ := runLinux(h, aptParams(t, "")); o.result != ResFailed || o.code != "nothing_to_install" {
		t.Errorf("nothing: %+v", o)
	}
}

func TestLinuxRebootMarkerIsReportedNeverActedOn(t *testing.T) {
	h := &aptHost{sim: simInstall, afterRun: "1.5"}
	r := h.runner()
	fs := fakeFS{"/usr/bin/apt-get": true, "/usr/bin/dpkg-query": true, "/usr/bin/env": true, "/run/reboot-required": true}
	o := Engine{GOOS: "linux", R: r, FS: fs}.installLinux(context.Background(), aptParams(t, ""))
	if o.result != ResInstalled || !o.reboot || r.ran("reboot") || r.ran("shutdown") {
		t.Fatalf("%+v", o)
	}
}

func TestDnfRefusesToRemoveWhatOthersRequire(t *testing.T) {
	r := &fakeRunner{}
	r.fn = func(c call) (remote.RunResult, error) {
		cmd := strings.Join(c.args, " ")
		switch {
		case strings.Contains(cmd, "--whatrequires"):
			return res("rivetit-app\n", 0), nil
		case strings.Contains(cmd, "rpm -q --qf") || strings.Contains(cmd, "rpm -q"):
			return res("1.0-1\n", 0), nil
		}
		return res("", 1), nil
	}
	p, err := ParseInstallParams(json.RawMessage(`{"action":"uninstall","mode":"run","fmt":"dnf","pkg":"rivetit-base"}`))
	if err != nil {
		t.Fatal(err)
	}
	o := Engine{GOOS: "linux", R: r, FS: rpmFS}.installLinux(context.Background(), p)
	if o.result != ResFailed || o.code != "would_remove_other" || r.ran("dnf") {
		t.Fatalf("%+v %v", o, r.joined())
	}
}

func TestInstallOnTheWrongPlatformIsRefusedBeforeAnythingRuns(t *testing.T) {
	r := &fakeRunner{fn: func(call) (remote.RunResult, error) { t.Fatal("something ran"); return remote.RunResult{}, nil }}
	for _, c := range []struct{ goos, raw string }{
		{"linux", `{"action":"install","fmt":"msi","url":"https://dl.example.com/a.msi","sha256":"` + strings.Repeat("a", 64) + `","size":5,"product_code":"{12345678-ABCD-1234-ABCD-1234567890AB}"}`},
		{"windows", `{"action":"install","fmt":"apt","pkg":"x"}`},
		{"darwin", `{"action":"install","fmt":"apt","pkg":"x"}`},
	} {
		res := Engine{GOOS: c.goos, R: r, FS: debFS}.Install(context.Background(), json.RawMessage(c.raw), 65536)
		if res.OK || !strings.Contains(res.Detail+res.Output, "unsupported_platform") {
			t.Errorf("%s: %+v", c.goos, res)
		}
	}
	res := Engine{GOOS: "linux", R: r, FS: debFS}.Install(context.Background(), json.RawMessage(`{"action":"install","fmt":"apt","pkg":"-oAPT::Foo"}`), 65536)
	if res.OK || !strings.Contains(res.Detail+res.Output, "invalid_params") {
		t.Errorf("hostile name: %+v", res)
	}
}

func TestDetectOnLinuxAndTheOutputDocument(t *testing.T) {
	r := &fakeRunner{fn: func(c call) (remote.RunResult, error) {
		if strings.Contains(strings.Join(c.args, " "), "openssl") {
			return res("ii |3.0.2-0ubuntu1.18\n", 0), nil
		}
		return res("", 1), nil
	}}
	raw := json.RawMessage(`{"rules":"[{\"key\":\"p1\",\"kind\":\"package\",\"value\":\"openssl\",\"min\":\"3.0.2\"},{\"key\":\"p2\",\"kind\":\"package\",\"value\":\"missing\",\"min\":\"\"},{\"key\":\"p3\",\"kind\":\"package\",\"value\":\"openssl\",\"min\":\"4.0\"},{\"key\":\"p4\",\"kind\":\"uninstall_name\",\"value\":\"x\",\"min\":\"\"}]"}`)
	out := Engine{GOOS: "linux", R: r, FS: debFS}.Detect(context.Background(), raw, 65536)
	if !out.OK {
		t.Fatalf("%+v", out)
	}
	var d DetectOut
	if err := json.Unmarshal([]byte(out.Output), &d); err != nil || len(d.Items) != 4 {
		t.Fatalf("%v %s", err, out.Output)
	}
	want := []struct{ inst, sat bool }{{true, true}, {false, false}, {true, false}, {false, false}}
	for i, w := range want {
		if d.Items[i].Installed != w.inst || d.Items[i].Satisfied != w.sat {
			t.Errorf("item %d: %+v", i, d.Items[i])
		}
	}
	if d.Items[3].Detail != "unsupported_kind" {
		t.Errorf("a Windows kind on Linux: %+v", d.Items[3])
	}
	for _, l := range r.joined() {
		if !strings.Contains(l, " -- ") {
			t.Errorf("detection query without --: %s", l)
		}
	}
}

// ---- Windows: a local TLS server, a fake registry and a fake process runner ----

type fakeReg struct {
	mu      sync.Mutex
	entries []UninstallEntry
}

func (f *fakeReg) Uninstall(v View) ([]UninstallEntry, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	if v == View32 {
		return nil, nil
	}
	return append([]UninstallEntry{}, f.entries...), nil
}

const code = "{12345678-ABCD-1234-ABCD-1234567890AB}"

func sum(b []byte) string { h := sha256.Sum256(b); return hex.EncodeToString(h[:]) }

type winRig struct {
	t      *testing.T
	srv    *httptest.Server
	body   []byte
	reg    *fakeReg
	runner *fakeRunner
	state  string
	ran    []string
	exit   int
	onRun  func()
	e      Engine
}

func newWinRig(t *testing.T, handler func(w http.ResponseWriter, r *http.Request, body []byte)) *winRig {
	t.Helper()
	w := &winRig{t: t, body: []byte("MSI-PAYLOAD-" + strings.Repeat("x", 4000)), reg: &fakeReg{}, state: t.TempDir()}
	w.srv = httptest.NewTLSServer(http.HandlerFunc(func(rw http.ResponseWriter, r *http.Request) {
		if handler != nil {
			handler(rw, r, w.body)
			return
		}
		rw.Write(w.body)
	}))
	t.Cleanup(w.srv.Close)
	w.runner = &fakeRunner{fn: func(c call) (remote.RunResult, error) {
		w.ran = append(w.ran, c.prog+" "+strings.Join(c.args, " "))
		if w.onRun != nil {
			w.onRun()
		}
		return res("", w.exit), nil
	}}
	w.e = Engine{GOOS: "windows", R: w.runner, Reg: w.reg, HTTP: w.srv.Client(), StateDir: w.state, Getenv: func(k string) string {
		if k == "SystemRoot" {
			return `C:\Windows`
		}
		return ""
	}}
	return w
}

func (w *winRig) params(fmtName, action string) InstallParams {
	p := InstallParams{Action: action, Mode: "run", Fmt: fmtName, URL: w.srv.URL + "/pkg", SHA256: sum(w.body), Size: int64(len(w.body)), MaxMinutes: 5,
		Detect: Item{Key: "d", Kind: KindProductCode, Value: code, Min: ""}}
	if fmtName == FmtMsi {
		p.ProductCode = code
	} else {
		p.Detect = Item{Key: "d", Kind: KindUninstallNam, Value: "Acme Agent", Min: "2.4"}
		p.Args = []string{"/S", "/norestart"}
	}
	if action == "uninstall" {
		p.URL, p.SHA256, p.Size = "", "", 0
	}
	return p
}

func (w *winRig) stagingLeft() int {
	ents, _ := os.ReadDir(w.state)
	n := 0
	for _, e := range ents {
		if strings.HasPrefix(e.Name(), "deploy-") {
			n++
		}
	}
	return n
}

func TestWindowsMsiInstallDownloadsVerifiesRunsAndCleansUp(t *testing.T) {
	w := newWinRig(t, nil)
	var dirMode os.FileMode
	var fileMode os.FileMode
	w.e.beforeEx = func(p string) {
		if st, err := os.Stat(filepath.Dir(p)); err == nil {
			dirMode = st.Mode().Perm()
		}
		if st, err := os.Stat(p); err == nil {
			fileMode = st.Mode().Perm()
		}
	}
	w.onRun = func() {
		w.reg.entries = []UninstallEntry{{KeyName: code, DisplayName: "Acme", DisplayVersion: "2.4.1"}}
	}
	o := w.e.installWindows(context.Background(), w.params(FmtMsi, "install"))
	if o.result != ResInstalled || o.version != "2.4.1" || o.reboot {
		t.Fatalf("%+v", o)
	}
	if len(w.ran) != 1 || !strings.HasPrefix(w.ran[0], `C:\Windows\System32\msiexec.exe /i `) || !strings.HasSuffix(w.ran[0], ` /qn /norestart`) || !strings.Contains(w.ran[0], "package.msi") {
		t.Fatalf("%v", w.ran)
	}
	if dirMode != 0o700 || fileMode != 0o600 {
		t.Errorf("staging modes %v %v", dirMode, fileMode)
	}
	if w.stagingLeft() != 0 {
		t.Error("the staging directory was not removed")
	}
}

func TestWindowsHashMismatchRunsNothingAndRemovesTheStaging(t *testing.T) {
	w := newWinRig(t, nil)
	p := w.params(FmtMsi, "install")
	p.SHA256 = strings.Repeat("0", 64)
	o := w.e.installWindows(context.Background(), p)
	if o.result != ResHashMismatch || len(w.ran) != 0 || w.stagingLeft() != 0 {
		t.Fatalf("%+v ran=%v left=%d", o, w.ran, w.stagingLeft())
	}
}

func TestWindowsAFileSwappedAfterTheDownloadIsNotRun(t *testing.T) {
	w := newWinRig(t, nil)
	w.e.beforeEx = func(p string) { _ = os.WriteFile(p, bytes.Repeat([]byte("E"), len(w.body)), 0o600) }
	o := w.e.installWindows(context.Background(), w.params(FmtMsi, "install"))
	if o.result != ResHashMismatch || len(w.ran) != 0 || w.stagingLeft() != 0 {
		t.Fatalf("%+v ran=%v", o, w.ran)
	}
}

func TestWindowsDownloadProblemsNeverReachTheInstaller(t *testing.T) {
	cases := map[string]func(rw http.ResponseWriter, r *http.Request, body []byte){
		"too many bytes": func(rw http.ResponseWriter, r *http.Request, b []byte) {
			rw.Write(append(append([]byte{}, b...), "EXTRA"...))
		},
		"too few bytes": func(rw http.ResponseWriter, r *http.Request, b []byte) { rw.Write(b[:len(b)-10]) },
		"content-length lies": func(rw http.ResponseWriter, r *http.Request, b []byte) {
			rw.Header().Set("Content-Length", strconv.Itoa(len(b)+7))
			rw.Write(b)
		},
		"404": func(rw http.ResponseWriter, r *http.Request, b []byte) { http.NotFound(rw, r) },
		"redirect to http": func(rw http.ResponseWriter, r *http.Request, b []byte) {
			http.Redirect(rw, r, "http://downloads.example.com/x", 302)
		},
		"redirect to another host": func(rw http.ResponseWriter, r *http.Request, b []byte) {
			http.Redirect(rw, r, "https://evil.example.net/x", 302)
		},
		"compressed": func(rw http.ResponseWriter, r *http.Request, b []byte) {
			rw.Header().Set("Content-Encoding", "gzip")
			rw.Write(b)
		},
	}
	for name, h := range cases {
		w := newWinRig(t, h)
		o := w.e.installWindows(context.Background(), w.params(FmtMsi, "install"))
		if o.result != ResDownloadFailed || len(w.ran) != 0 || w.stagingLeft() != 0 {
			t.Errorf("%s: %+v ran=%v left=%d", name, o, w.ran, w.stagingLeft())
		}
		if strings.Contains(o.detail+o.code, "http") && strings.Contains(o.detail, w.srv.URL) {
			t.Errorf("%s: the detail names the URL: %s", name, o.detail)
		}
	}
}

func TestWindowsASlowServerHitsTheDeadline(t *testing.T) {
	w := newWinRig(t, func(rw http.ResponseWriter, r *http.Request, b []byte) {
		rw.Write(b[:100])
		rw.(http.Flusher).Flush()
		select {
		case <-r.Context().Done():
		case <-time.After(5 * time.Second):
		}
	})
	ctx, cancel := context.WithTimeout(context.Background(), 300*time.Millisecond)
	defer cancel()
	o := w.e.installWindows(ctx, w.params(FmtMsi, "install"))
	if o.result != ResDownloadFailed || len(w.ran) != 0 || w.stagingLeft() != 0 {
		t.Fatalf("%+v", o)
	}
}

func TestWindowsExitCodes(t *testing.T) {
	for _, c := range []struct {
		exit     int
		want     string
		reboot   bool
		present  bool
		wantCode string
	}{
		{0, ResInstalled, false, true, ""}, {3010, ResNeedsReboot, true, true, ""}, {1641, ResNeedsReboot, true, true, ""},
		{1618, ResLocked, false, false, "exit_1618"}, {1603, ResFailed, false, false, "exit_1603"}, {0, ResVerifyFailed, false, false, "not_detected"},
	} {
		w := newWinRig(t, nil)
		w.exit = c.exit
		w.onRun = func() {
			if c.present {
				w.reg.entries = []UninstallEntry{{KeyName: code, DisplayVersion: "2.4.1"}}
			}
		}
		o := w.e.installWindows(context.Background(), w.params(FmtMsi, "install"))
		if o.result != c.want || o.reboot != c.reboot || (c.wantCode != "" && o.code != c.wantCode) {
			t.Errorf("exit %d: %+v", c.exit, o)
		}
		for _, r := range w.ran {
			if strings.Contains(strings.ToLower(r), "shutdown") || strings.Contains(r, "/forcerestart") {
				t.Errorf("a restart was requested: %s", r)
			}
		}
	}
}

func TestWindowsExeRunsFromAnArgumentListWithoutAShell(t *testing.T) {
	w := newWinRig(t, nil)
	w.onRun = func() {
		w.reg.entries = []UninstallEntry{{KeyName: "Acme_is1", DisplayName: "acme agent", DisplayVersion: "2.5"}}
	}
	o := w.e.installWindows(context.Background(), w.params(FmtExe, "install"))
	if o.result != ResInstalled {
		t.Fatalf("%+v", o)
	}
	if len(w.runner.calls) != 1 || !strings.HasSuffix(w.runner.calls[0].prog, "package.exe") || strings.Join(w.runner.calls[0].args, "|") != "/S|/norestart" {
		t.Fatalf("%+v", w.runner.calls)
	}
	for _, c := range w.runner.calls {
		l := strings.ToLower(c.prog)
		if strings.Contains(l, "cmd.exe") || strings.Contains(l, "powershell") {
			t.Errorf("a shell was used: %s", c.prog)
		}
	}
}

func TestWindowsMsiUninstallAndTheNoOps(t *testing.T) {
	w := newWinRig(t, nil)
	w.reg.entries = []UninstallEntry{{KeyName: strings.ToLower(code), DisplayVersion: "2.4.1"}}
	w.onRun = func() { w.reg.entries = nil }
	o := w.e.installWindows(context.Background(), w.params(FmtMsi, "uninstall"))
	if o.result != ResUninstalled || len(w.ran) != 1 || !strings.HasSuffix(w.ran[0], ` /x `+code+` /qn /norestart`) {
		t.Fatalf("%+v %v", o, w.ran)
	}
	if o = w.e.installWindows(context.Background(), w.params(FmtMsi, "uninstall")); o.result != ResNotInstalled || len(w.ran) != 1 {
		t.Fatalf("removing what is absent: %+v", o)
	}
	w.reg.entries = []UninstallEntry{{KeyName: code, DisplayVersion: "2.4.1"}}
	if o = w.e.installWindows(context.Background(), w.params(FmtMsi, "install")); o.result != ResAlready || len(w.ran) != 1 {
		t.Fatalf("installing what is there: %+v", o)
	}
}

func TestWindowsDryRunDownloadsNothing(t *testing.T) {
	hit := 0
	w := newWinRig(t, func(rw http.ResponseWriter, r *http.Request, b []byte) { hit++; rw.Write(b) })
	p := w.params(FmtMsi, "install")
	p.Mode = "dry_run"
	o := w.e.installWindows(context.Background(), p)
	if o.result != ResWouldInstall || hit != 0 || len(w.ran) != 0 || w.stagingLeft() != 0 {
		t.Fatalf("%+v hits=%d", o, hit)
	}
}

func TestWindowsDetectionBothViewsAndCaseInsensitive(t *testing.T) {
	w := newWinRig(t, nil)
	w.reg.entries = []UninstallEntry{{KeyName: "{AAAAAAAA-AAAA-AAAA-AAAA-AAAAAAAAAAAA}", DisplayName: "Acme Agent (x64)", DisplayVersion: "2.4.1"}, {KeyName: "other", DisplayName: "Other", DisplayVersion: "1"}}
	raw := json.RawMessage(`{"rules":"[{\"key\":\"a\",\"kind\":\"product_code\",\"value\":\"{aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa}\",\"min\":\"2.4\"},{\"key\":\"b\",\"kind\":\"uninstall_name\",\"value\":\"ACME AGENT (X64)\",\"min\":\"3\"},{\"key\":\"c\",\"kind\":\"uninstall_name\",\"value\":\"Acme\",\"min\":\"\"},{\"key\":\"d\",\"kind\":\"package\",\"value\":\"x\",\"min\":\"\"}]"}`)
	out := w.e.Detect(context.Background(), raw, 65536)
	var d DetectOut
	if err := json.Unmarshal([]byte(out.Output), &d); err != nil || len(d.Items) != 4 {
		t.Fatalf("%v %s", err, out.Output)
	}
	got := []bool{d.Items[0].Satisfied, d.Items[1].Installed, d.Items[1].Satisfied, d.Items[2].Installed, d.Items[3].Installed}
	want := []bool{true, true, false, false, false}
	for i := range want {
		if got[i] != want[i] {
			t.Errorf("%d: got %v want %v (%+v)", i, got[i], want[i], d.Items)
		}
	}
}

func TestOnlyOneInstallRunsAtATime(t *testing.T) {
	installMu.Lock()
	defer installMu.Unlock()
	res := Engine{GOOS: "linux", R: &fakeRunner{fn: func(call) (remote.RunResult, error) { t.Fatal("ran"); return remote.RunResult{}, nil }}, FS: debFS}.
		Install(context.Background(), json.RawMessage(`{"action":"install","fmt":"apt","pkg":"x"}`), 65536)
	if res.OK || !strings.Contains(res.Output, DetailBusy) {
		t.Fatalf("%+v", res)
	}
}
