package main

import (
	"context"
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"rivetit-agent/internal/embed"
	"rivetit-agent/internal/mesh"
)

const setupGroup = "4a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f9"

func meshPayload(install any, spec any) func(*embed.Payload) {
	return func(p *embed.Payload) {
		if install != nil {
			b, _ := json.Marshal(install)
			p.InstallMeshAgent = b
		}
		if spec != nil {
			b, _ := json.Marshal(spec)
			p.Mesh = b
		}
	}
}

func goodSpec() map[string]any {
	return map[string]any{"url": "https://mesh.example.com", "meshid": setupGroup, "pin": strings.Repeat("ab", 48)}
}

// withNoMeshOnDisk makes the "already installed" probe see no agent regardless of the machine running the tests.
func withNoMeshOnDisk(t *testing.T) {
	old := meshProbe
	meshProbe = func() mesh.Probe { return mesh.Probe{GOOS: "linux", Paths: []string{"/nonexistent/meshagent"}} }
	t.Cleanup(func() { meshProbe = old })
}

type fakeMesh struct {
	calls int
	spec  *mesh.Spec
	res   mesh.Result
	panic bool
}

func (f *fakeMesh) install(ctx context.Context, s *mesh.Spec, o mesh.InstallOptions) mesh.Result {
	f.calls++
	f.spec = s
	if f.panic {
		panic("boom")
	}
	return f.res
}

func (e *env) setupWithMesh(f *fakeMesh, extra ...string) int {
	args := append([]string{"--silent", "--no-service", "--state-dir", e.state, "--install-dir", filepath.Join(e.state, "bin")}, extra...)
	ops := e.ops()
	ops.meshInstall = f.install
	return runSetup(args, ops, time.Now)
}

func TestSetupInstallsTheMeshAgentWhenAsked(t *testing.T) {
	withNoMeshOnDisk(t)
	srv := newEnrollSrv(t)
	e := newEnv(t)
	e.stampWith(srv, meshPayload(true, goodSpec()))
	f := &fakeMesh{res: mesh.Result{Installed: true, AgentID: 6, Host: "mesh.example.com"}}
	if code := e.setupWithMesh(f); code != exitOK {
		t.Fatalf("exit %d", code)
	}
	if f.calls != 1 || f.spec.URL != "https://mesh.example.com" || f.spec.MeshID != setupGroup {
		t.Fatalf("calls=%d spec=%+v", f.calls, f.spec)
	}
	r := mesh.LoadResult(e.state)
	if r == nil || !r.Installed {
		t.Fatalf("saved result %+v", r)
	}
	if _, err := os.Stat(e.installed()); err != nil {
		t.Fatal("the RivetIT agent must be installed")
	}
	// the saved result never holds the group id
	b, _ := os.ReadFile(filepath.Join(e.state, mesh.ResultFile))
	if strings.Contains(string(b), setupGroup) {
		t.Fatal("group id in the saved result")
	}
}

func TestMeshFailureNeverFailsTheMainInstall(t *testing.T) {
	withNoMeshOnDisk(t)
	cases := map[string]*fakeMesh{
		"failed result":  {res: mesh.Result{Installed: false, Error: "the Mesh server answered HTTP 404", ExitCode: 0}},
		"installer exit": {res: mesh.Result{Installed: false, ExitCode: 3, Error: "the Mesh installer exited with code 3"}},
	}
	for name, f := range cases {
		srv := newEnrollSrv(t)
		e := newEnv(t)
		e.stampWith(srv, meshPayload(true, goodSpec()))
		if code := e.setupWithMesh(f); code != exitOK {
			t.Fatalf("%s: exit %d, a Mesh failure must not change it", name, code)
		}
		if r := mesh.LoadResult(e.state); r == nil || r.Installed || r.Error == "" {
			t.Fatalf("%s: %+v", name, r)
		}
		if got, _ := os.ReadFile(e.installed()); len(got) == 0 {
			t.Fatalf("%s: agent not installed", name)
		}
	}
}

func TestUnusableMeshSourceIsRecordedAndIgnored(t *testing.T) {
	withNoMeshOnDisk(t)
	for name, mut := range map[string]func(*embed.Payload){
		"http":      meshPayload(true, map[string]any{"url": "http://mesh.example.com", "meshid": setupGroup}),
		"no source": meshPayload(true, nil),
		"bad group": meshPayload(true, map[string]any{"url": "https://mesh.example.com", "meshid": "xyz"}),
		"mesh is 5": meshPayload(true, 5),
	} {
		srv := newEnrollSrv(t)
		e := newEnv(t)
		e.stampWith(srv, mut)
		f := &fakeMesh{}
		if code := e.setupWithMesh(f); code != exitOK {
			t.Fatalf("%s: exit %d", name, code)
		}
		if f.calls != 0 {
			t.Fatalf("%s: the installer must not run with an unusable source", name)
		}
		if r := mesh.LoadResult(e.state); r == nil || r.Installed || r.Error == "" {
			t.Fatalf("%s: %+v", name, r)
		}
	}
}

func TestNotRequestedMeansNothingHappens(t *testing.T) {
	withNoMeshOnDisk(t)
	for name, mut := range map[string]func(*embed.Payload){
		"absent":       nil,
		"false":        meshPayload(false, goodSpec()),
		"string yes":   meshPayload("yes", goodSpec()),
		"number 1":     meshPayload(1, goodSpec()),
		"only members": meshPayload(nil, goodSpec()),
	} {
		srv := newEnrollSrv(t)
		e := newEnv(t)
		e.stampWith(srv, mut)
		f := &fakeMesh{res: mesh.Result{Installed: true}}
		if code := e.setupWithMesh(f); code != exitOK {
			t.Fatalf("%s: exit %d", name, code)
		}
		if f.calls != 0 || mesh.LoadResult(e.state) != nil {
			t.Fatalf("%s: Mesh must not be touched (calls=%d)", name, f.calls)
		}
	}
}

func TestNoMeshAgentFlagWins(t *testing.T) {
	withNoMeshOnDisk(t)
	srv := newEnrollSrv(t)
	e := newEnv(t)
	e.stampWith(srv, meshPayload(true, goodSpec()))
	f := &fakeMesh{res: mesh.Result{Installed: true}}
	if code := e.setupWithMesh(f, "--no-mesh-agent"); code != exitOK || f.calls != 0 || mesh.LoadResult(e.state) != nil {
		t.Fatalf("exit/calls %d %d", code, f.calls)
	}
	if got := (setupOpts{noMesh: true}).elevatedArgs(); !strings.Contains(strings.Join(got, " "), "--no-mesh-agent") {
		t.Fatalf("the elevated child must keep the flag: %v", got)
	}
}

func TestAnAlreadyInstalledMeshAgentIsLeftAlone(t *testing.T) {
	old := meshProbe
	meshProbe = func() mesh.Probe {
		return mesh.Probe{GOOS: "linux", Paths: []string{"/present/meshagent"}, Exists: func(string) bool { return true }}
	}
	t.Cleanup(func() { meshProbe = old })
	srv := newEnrollSrv(t)
	e := newEnv(t)
	e.stampWith(srv, meshPayload(true, goodSpec()))
	f := &fakeMesh{}
	if code := e.setupWithMesh(f); code != exitOK || f.calls != 0 {
		t.Fatalf("exit %d calls %d", code, f.calls)
	}
	if r := mesh.LoadResult(e.state); r == nil || !r.Installed {
		t.Fatalf("%+v", r)
	}
}

func TestMeshIsSkippedWhenEnrollmentIsRejected(t *testing.T) {
	withNoMeshOnDisk(t)
	srv := newEnrollSrv(t)
	srv.mode.Store(1)
	e := newEnv(t)
	e.stampWith(srv, meshPayload(true, goodSpec()))
	f := &fakeMesh{res: mesh.Result{Installed: true}}
	if code := e.setupWithMesh(f); code != exitRejected || f.calls != 0 {
		t.Fatalf("exit %d calls %d", code, f.calls)
	}
}

func TestInstallFlagsBuildTheSamePayloadMembers(t *testing.T) {
	var m meshFlags
	if i, s := m.raw(); i != nil || s != nil {
		t.Fatal("off by default")
	}
	m = meshFlags{install: true, url: "https://mesh.example.com", group: setupGroup}
	i, s := m.raw()
	spec, err := mesh.ParseSpec(i, s)
	if err != nil || spec.URL != "https://mesh.example.com" {
		t.Fatalf("%v %+v", err, spec)
	}
	m.url = "http://nope"
	i, s = m.raw()
	if _, err := mesh.ParseSpec(i, s); err == nil || err == mesh.ErrDisabled {
		t.Fatal("an http address must be refused")
	}
}

// The server now writes the group id in MeshCentral's 64-character URL form: it reaches the Mesh installer unchanged.
func TestSetupCarriesTheURLFormGroupIDToTheMeshInstaller(t *testing.T) {
	const urlForm = "4Q3y1m6Cm1PFZ2UtTZGt1cYvRX$g1wwy@dIBLfVOt6rZ4Mq3gqfJmwmSMX4jXYSe"
	withNoMeshOnDisk(t)
	srv := newEnrollSrv(t)
	e := newEnv(t)
	spec := goodSpec()
	spec["meshid"] = urlForm
	e.stampWith(srv, meshPayload(true, spec))
	f := &fakeMesh{res: mesh.Result{Installed: true, AgentID: 4, Host: "mesh.example.com"}}
	if code := e.setupWithMesh(f); code != exitOK {
		t.Fatalf("exit %d", code)
	}
	if f.calls != 1 || f.spec.MeshID != urlForm || f.spec.WireMeshID() != urlForm {
		t.Fatalf("calls=%d spec=%+v", f.calls, f.spec)
	}
}
