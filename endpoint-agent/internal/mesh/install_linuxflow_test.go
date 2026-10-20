package mesh

import (
	"bytes"
	"context"
	"log/slog"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func linuxRun(t *testing.T, mutate func(*mock, *linuxRig, *InstallOptions), noInstall bool) (Result, *mock, *linuxRig, string, string) {
	t.Helper()
	marker := filepath.Join(t.TempDir(), "marker")
	m := newMock(t, 200, "application/octet-stream", fakeInstaller(t, marker, 0))
	l := newLinuxRig(t)
	if noInstall {
		t.Setenv("MESH_TEST_NOINSTALL", "1")
	}
	var logs bytes.Buffer
	o := InstallOptions{GOOS: "linux", GOARCH: "amd64", WorkDir: t.TempDir(), Log: slog.New(slog.NewTextHandler(&logs, nil))}
	l.apply(&o)
	if mutate != nil {
		mutate(m, l, &o)
	}
	res := Install(context.Background(), rawSpec(t, m.URL, pinOf(m, true)), o)
	return res, m, l, marker, logs.String()
}

func TestLinuxFollowsMeshCentralsScript(t *testing.T) {
	res, m, l, marker, logs := linuxRun(t, nil, false)
	if !res.Installed || res.Error != "" {
		t.Fatalf("%+v", res)
	}
	if h := m.allHits(); len(h) != 2 || h[0] != "/meshagents?id=6" || h[1] != "/meshsettings?id="+testGroupURL {
		t.Fatalf("download URLs %q", h)
	}
	if got, _ := os.ReadFile(marker); strings.TrimSpace(string(got)) != "ran with -fullinstall --copy-msh=1" {
		t.Fatalf("args %q", got)
	}
	msh, _ := os.ReadFile(marker + ".msh")
	if string(msh) != "MeshName=Test\nMeshType=2\nmeshServiceName=Mesh Agent\nStartupType=1\n" {
		t.Fatalf("the .msh the installer saw: %q", msh)
	}
	if st, err := os.Stat(filepath.Join(l.dir, "meshagent")); err != nil || st.IsDir() {
		t.Fatal("stub did not install")
	}
	if len(l.ctl) < 2 {
		t.Fatalf("systemctl calls %v", l.ctl)
	}
	if strings.Contains(logs+res.Log+res.Error, testGroupURL) || strings.Contains(logs+res.Log+res.Error, testGroup) {
		t.Fatal("the group id leaked")
	}
	if !strings.Contains(logs, "mesh_host=") || !strings.Contains(logs, "agent_id=6") {
		t.Fatalf("log %q", logs)
	}
}

func TestLinuxStartupTypeIsWrittenForOtherInitSystems(t *testing.T) {
	res, _, _, marker, _ := linuxRun(t, func(_ *mock, l *linuxRig, o *InstallOptions) {
		o.StartupType = func() int { return 3 }
		o.SystemdRunDir = filepath.Join(l.dir, "nope") // no systemd
		o.InitScriptPaths = []string{filepath.Join(l.dir, "..", "initscript")}
		_ = os.MkdirAll(l.dir, 0o755)
		_ = os.WriteFile(o.InitScriptPaths[0], nil, 0o755)
		o.AgentRunning = func(exe string) bool { return strings.HasSuffix(exe, "/meshagent/meshagent") }
	}, false)
	msh, _ := os.ReadFile(marker + ".msh")
	if !res.Installed || !strings.HasSuffix(string(msh), "StartupType=3\n") || strings.Contains(string(msh), "StartupType=9") {
		t.Fatalf("%+v %q", res, msh)
	}
}

func TestLinuxFailureReasons(t *testing.T) {
	for name, c := range map[string]struct {
		mut       func(*mock, *linuxRig, *InstallOptions)
		noInstall bool
		want      string
	}{
		"meshsettings 404": {func(m *mock, _ *linuxRig, _ *InstallOptions) { m.settingsStatus, m.settings = 404, "no" }, false,
			"download of meshsettings returned HTTP 404: check the device group id"},
		"nothing installed": {nil, true, "installer exited 0 but meshagent is missing from"},
		"no service registered": {func(_ *mock, l *linuxRig, _ *InstallOptions) { l.unitless = true }, false,
			"installer exited 0 but no meshagent service was registered"},
		"service not active": {func(_ *mock, l *linuxRig, _ *InstallOptions) { l.state = "failed" }, false,
			"service installed but not active: failed"},
		"service stuck activating": {func(_ *mock, l *linuxRig, _ *InstallOptions) { l.state = "activating" }, false,
			"service installed but not active: activating"},
		"non-systemd, no init script": {func(_ *mock, l *linuxRig, o *InstallOptions) {
			o.SystemdRunDir = filepath.Join(l.dir, "nope")
			o.InitScriptPaths = []string{filepath.Join(l.dir, "missing")}
		}, false, "no meshagent service was registered"},
	} {
		if name == "binary 500" {
			continue
		}
		t.Run(name, func(t *testing.T) {
			res, _, _, _, logs := linuxRun(t, c.mut, c.noInstall)
			if res.Installed || !strings.Contains(res.Error, c.want) {
				t.Fatalf("%+v", res)
			}
			if !strings.Contains(logs, c.want) {
				t.Fatalf("the reason must be in the log: %q", logs)
			}
			if strings.Contains(logs+res.Error, testGroupURL) {
				t.Fatal("group id leaked")
			}
		})
	}
}

func TestLinuxBinaryHTTPError(t *testing.T) {
	m := newMock(t, 404, "", []byte("nope"))
	res := Install(context.Background(), rawSpec(t, m.URL, pinOf(m, true)), opts(t))
	if res.Installed || !strings.Contains(res.Error, "download of the Mesh agent (id 6) returned HTTP 404") {
		t.Fatalf("%+v", res)
	}
}

func TestLinuxNonZeroExitStillFails(t *testing.T) {
	m := newMock(t, 200, "", fakeInstaller(t, filepath.Join(t.TempDir(), "x"), 3))
	res := Install(context.Background(), rawSpec(t, m.URL, pinOf(m, true)), opts(t))
	if res.Installed || res.ExitCode != 3 {
		t.Fatalf("%+v", res)
	}
}

func TestWithStartupType(t *testing.T) {
	got := string(withStartupType([]byte("A=1\r\nStartupType=2\nStartupType=7\n\nB=2"), 1))
	if got != "A=1\nB=2\nStartupType=1\n" {
		t.Fatalf("%q", got)
	}
}

func TestLinuxInstallerOutputIsCappedAndMasked(t *testing.T) {
	body := []byte("#!/bin/sh\nhead -c 20000 /dev/zero | tr '\\0' x\necho " + testGroupURL + "\nexit 0\n")
	m := newMock(t, 200, "", body)
	res := Install(context.Background(), rawSpec(t, m.URL, pinOf(m, true)), opts(t))
	if len(res.Log) > logTail || strings.Contains(res.Log, testGroupURL) || !strings.Contains(res.Log, "[meshid]") {
		t.Fatalf("log len %d %q", len(res.Log), res.Log[max(0, len(res.Log)-80):])
	}
}
