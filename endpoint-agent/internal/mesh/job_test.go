//go:build !windows

package mesh

import (
	"context"
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"sync/atomic"
	"testing"
	"time"
)

// jobParams renders the parameters a mesh_install job carries (what the server builds from its stored Mesh settings).
func jobParams(srvURL, pin string, extra map[string]any) json.RawMessage {
	m := map[string]any{
		"url":          srvURL,
		"meshid":       testGroupURL,
		"agent_ids":    `{"linux/amd64":6,"linux/arm64":26,"windows/amd64":4}`,
		"install_args": `{"linux":["-install"],"windows":["-fullinstall"]}`,
	}
	if pin != "" {
		m["pin"] = pin
	}
	for k, v := range extra {
		if v == nil {
			delete(m, k)
		} else {
			m[k] = v
		}
	}
	b, _ := json.Marshal(m)
	return b
}

type jobRig struct {
	t      *testing.T
	deps   JobDeps
	agent  string // the "installed Mesh agent" path: the fake installer creates it
	state  string
	marker string
	runs   []string
	node   string
	change atomic.Int32
}

// newJobRig wires a JobDeps whose "installed agent" exists only once the fake installer script has run.
func newJobRig(t *testing.T) *jobRig {
	r := &jobRig{t: t, state: t.TempDir(), node: testNode}
	dir := t.TempDir()
	r.agent = filepath.Join(dir, "meshagent")
	r.marker = filepath.Join(dir, "ran")
	r.deps = JobDeps{
		StateDir: r.state, GOOS: "linux", GOARCH: "amd64", Log: quiet(), Settle: 50 * time.Millisecond,
		Probe: func() Probe {
			return Probe{GOOS: "linux", Paths: []string{r.agent},
				Run: func(_ context.Context, _ string, path string, args []string) (int, string, error) {
					r.runs = append(r.runs, strings.Join(args, " "))
					if len(args) == 1 && args[0] == "-nodeid" {
						return 0, r.node + "\n", nil
					}
					return 0, "uninstalled\n", nil
				}}
		},
		OnChange: func() { r.change.Add(1) },
	}
	return r
}

// installer is a shell script that behaves like Mesh's installer: it "installs" the agent file.
func (r *jobRig) installer(exit int) []byte {
	return []byte("#!/bin/sh\necho \"args $@\" > " + r.marker + "\n: > " + r.agent + "\necho installer-output\nexit " + string(rune('0'+exit)) + "\n")
}

func decodeDoc(t *testing.T, out string) map[string]any {
	t.Helper()
	var m map[string]any
	if err := json.Unmarshal([]byte(out), &m); err != nil {
		t.Fatalf("output is not JSON: %v: %q", err, out)
	}
	return m
}

func TestSpecFromParams(t *testing.T) {
	good := jobParams("https://mesh.example.com", "", nil)
	s, force, err := SpecFromParams(good)
	if err != nil || force || s.URL != "https://mesh.example.com" || s.WireMeshID() != testGroupURL {
		t.Fatalf("%v %v %+v", err, force, s)
	}
	if id, ok := s.AgentID("linux", "arm64"); !ok || id != 26 {
		t.Fatalf("agent id table not honoured: %d", id)
	}
	bad := map[string]json.RawMessage{
		"unknown member":        jobParams("https://mesh.example.com", "", map[string]any{"command": "calc.exe"}),
		"http":                  jobParams("http://mesh.example.com", "", nil),
		"url with query":        jobParams("https://mesh.example.com/?x=1", "", nil),
		"bad pin":               jobParams("https://mesh.example.com", "zz", nil),
		"bad group":             jobParams("https://mesh.example.com", "", map[string]any{"meshid": "nope"}),
		"no group":              jobParams("https://mesh.example.com", "", map[string]any{"meshid": nil}),
		"table not json":        jobParams("https://mesh.example.com", "", map[string]any{"agent_ids": "{"}),
		"hostile argument":      jobParams("https://mesh.example.com", "", map[string]any{"install_args": `{"linux":["-install; rm -rf /"]}`}),
		"agent id out of range": jobParams("https://mesh.example.com", "", map[string]any{"agent_ids": `{"linux/amd64":999}`}),
		"force wrong type":      jobParams("https://mesh.example.com", "", map[string]any{"force": "yes"}),
		"not an object":         json.RawMessage(`[1]`),
	}
	for name, p := range bad {
		if _, _, err := SpecFromParams(p); err == nil {
			t.Errorf("%s: accepted", name)
		}
	}
	if _, f, err := SpecFromParams(jobParams("https://mesh.example.com", "", map[string]any{"force": true})); err != nil || !f {
		t.Fatalf("force: %v %v", f, err)
	}
}

func TestInstallJobPushesTheSameInstallAndMapsTheNode(t *testing.T) {
	r := newJobRig(t)
	m := newMock(t, 200, "application/octet-stream", r.installer(0))
	res := r.deps.Handlers()[JobInstall](context.Background(), jobParams(m.URL, pinOf(m, true), nil), 65536)
	if !res.OK || res.Detail != "installed" {
		t.Fatalf("%+v", res)
	}
	d := decodeDoc(t, res.Output)
	if d["installed"] != true || d["node_id"] != testNode || d["agent_id"] != float64(6) || d["host"] == "" {
		t.Fatalf("%v", d)
	}
	if got, _ := os.ReadFile(r.marker); !strings.Contains(string(got), "args -install") {
		t.Fatalf("installer arguments: %q", got)
	}
	if q := m.lastQuery(); q != "/meshagents?id=6&meshid="+testGroupURL+"&installflags=0" {
		t.Fatalf("download address %q", q)
	}
	if strings.Contains(res.Output, testGroupURL) || strings.Contains(res.Output, testGroup) {
		t.Fatal("the device group id must never be echoed in a job result")
	}
	// the saved state is what the next check-in reports; the reporter was told to look again
	saved := LoadResult(r.state)
	if saved == nil || !saved.Installed || r.change.Load() != 1 {
		t.Fatalf("saved %+v, changes %d", saved, r.change.Load())
	}
	rep := (&Reporter{StateDir: r.state, Probe: r.deps.Probe()}).Current(context.Background())
	if rep == nil || !rep.Installed || rep.NodeID != testNode {
		t.Fatalf("check-in state %+v", rep)
	}
}

func TestInstallJobIsIdempotentUnlessForced(t *testing.T) {
	r := newJobRig(t)
	if err := os.WriteFile(r.agent, nil, 0o700); err != nil {
		t.Fatal(err)
	}
	m := newMock(t, 200, "", r.installer(0))
	p := jobParams(m.URL, pinOf(m, true), nil)
	res := r.deps.Handlers()[JobInstall](context.Background(), p, 65536)
	if !res.OK || res.Detail != "already_installed" || m.lastQuery() != "" {
		t.Fatalf("an installed agent must be left alone without a download: %+v hit=%q", res, m.lastQuery())
	}
	if d := decodeDoc(t, res.Output); d["node_id"] != testNode {
		t.Fatalf("%v", d)
	}
	res = r.deps.Handlers()[JobInstall](context.Background(), jobParams(m.URL, pinOf(m, true), map[string]any{"force": true}), 65536)
	if !res.OK || res.Detail != "installed" || m.lastQuery() == "" {
		t.Fatalf("force must run the installer again: %+v", res)
	}
}

func TestInstallJobFailuresAreReportedAndNothingIsLeftBehind(t *testing.T) {
	cases := []struct {
		name   string
		status int
		ctype  string
		body   func(*jobRig) []byte
		pin    func(*mock) string
		opts   func(*InstallOptions)
		want   string
	}{
		{"pin mismatch", 200, "", func(r *jobRig) []byte { return r.installer(0) }, func(*mock) string { return strings.Repeat("ab", 48) }, nil, "pinned hash"},
		{"404", 404, "", func(r *jobRig) []byte { return []byte("nope") }, func(m *mock) string { return pinOf(m, true) }, nil, "HTTP 404"},
		{"oversize", 200, "", func(r *jobRig) []byte { return []byte(strings.Repeat("#", 4096)) }, func(m *mock) string { return pinOf(m, true) }, func(o *InstallOptions) { o.MaxBytes = 1024 }, "larger than"},
		{"web page", 200, "text/html; charset=utf-8", func(r *jobRig) []byte { return []byte("<html>") }, func(m *mock) string { return pinOf(m, true) }, nil, "web page"},
		{"not a program", 200, "", func(r *jobRig) []byte { return []byte("MZ-not-for-linux") }, func(m *mock) string { return pinOf(m, true) }, nil, "not a Linux program"},
		{"installer exits 3", 200, "", func(r *jobRig) []byte { return r.installer(3) }, func(m *mock) string { return pinOf(m, true) }, nil, "code 3"},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			r := newJobRig(t)
			if c.opts != nil {
				r.deps.Options = c.opts
			}
			m := newMock(t, c.status, c.ctype, c.body(r))
			res := r.deps.Handlers()[JobInstall](context.Background(), jobParams(m.URL, c.pin(m), nil), 65536)
			if res.OK || res.Detail != "install_failed" || res.Cannot {
				t.Fatalf("%+v", res)
			}
			d := decodeDoc(t, res.Output)
			if d["installed"] != false || !strings.Contains(d["error"].(string), c.want) {
				t.Fatalf("%v (want %q)", d, c.want)
			}
			if strings.Contains(res.Output, testGroupURL) || strings.Contains(res.Output, testGroup) {
				t.Fatal("the group id leaked into the job result")
			}
			// the failure is also the saved state the next check-in reports
			saved := LoadResult(r.state)
			if saved == nil || saved.Installed || saved.Error == "" || r.change.Load() != 1 {
				t.Fatalf("saved %+v", saved)
			}
			if left, _ := filepath.Glob(filepath.Join(r.state, "mesh-install-*")); len(left) != 0 {
				t.Fatal("work directory left behind")
			}
		})
	}
}

func TestInstallJobRefusesBadParametersBeforeAnyNetworkCall(t *testing.T) {
	r := newJobRig(t)
	m := newMock(t, 200, "", r.installer(0))
	res := r.deps.Handlers()[JobInstall](context.Background(), jobParams(m.URL, "", map[string]any{"command": "x"}), 65536)
	if res.OK || !res.Cannot || res.Detail != "invalid_params" || m.lastQuery() != "" {
		t.Fatalf("%+v", res)
	}
	if LoadResult(r.state) != nil || r.change.Load() != 0 {
		t.Fatal("a refused job must not change the saved Mesh state")
	}
}

func TestInstallJobSurvivesAPanicInTheInstaller(t *testing.T) {
	r := newJobRig(t)
	r.deps.Install = func(context.Context, *Spec, InstallOptions) Result { panic("boom") }
	m := newMock(t, 200, "", nil)
	res := r.deps.Handlers()[JobInstall](context.Background(), jobParams(m.URL, pinOf(m, true), nil), 65536)
	if res.OK || res.Detail != "internal_error" {
		t.Fatalf("%+v", res)
	}
}

func TestUninstallJob(t *testing.T) {
	r := newJobRig(t)
	h := r.deps.Handlers()[JobUninstall]
	if res := h(context.Background(), json.RawMessage(`{"x":1}`), 65536); res.OK || !res.Cannot {
		t.Fatalf("parameters are refused: %+v", res)
	}
	// nothing installed
	res := h(context.Background(), json.RawMessage(`{}`), 65536)
	if !res.OK || res.Detail != "not_installed" {
		t.Fatalf("%+v", res)
	}
	// installed: the agent's own uninstaller runs with -uninstall on Linux
	if err := os.WriteFile(r.agent, nil, 0o700); err != nil {
		t.Fatal(err)
	}
	res = h(context.Background(), json.RawMessage(`{}`), 65536)
	if !res.OK || res.Detail != "uninstalled" {
		t.Fatalf("%+v", res)
	}
	found := false
	for _, a := range r.runs {
		found = found || a == "-uninstall"
	}
	if !found {
		t.Fatalf("uninstall flag not used: %v", r.runs)
	}
	// the saved state now says "not installed", which is what lets the server clear its record
	saved := LoadResult(r.state)
	if saved == nil || saved.Installed {
		t.Fatalf("%+v", saved)
	}
}
