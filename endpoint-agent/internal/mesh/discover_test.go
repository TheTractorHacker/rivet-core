package mesh

import (
	"context"
	"os"
	"strings"
	"testing"
)

func TestParseImagePath(t *testing.T) {
	for _, c := range []struct{ in, want string }{
		{`"C:\Program Files\Mesh Agent\MeshAgent.exe" --meshServiceName="Mesh Agent"`, `C:\Program Files\Mesh Agent\MeshAgent.exe`},
		{`C:\Tools\ma.exe -svc`, `C:\Tools\ma.exe`},
		{`C:\Tools\MA.EXE`, `C:\Tools\MA.EXE`},
		{`"C:\unterminated\a.exe`, ``},
		{`cmd /c something`, ``},
		{``, ``},
	} {
		if got := ParseImagePath(c.in); got != c.want {
			t.Errorf("%q: got %q want %q", c.in, got, c.want)
		}
	}
}

func TestParseUnitExec(t *testing.T) {
	for _, c := range []struct{ name, unit, want string }{
		{"absolute", "[Service]\nExecStart=/opt/acme-mesh/meshagent_x64 --foo\n", "/opt/acme-mesh/meshagent_x64"},
		{"prefix chars", "[Service]\nExecStart=-/usr/local/mesh_services/meshagent/meshagent\n", "/usr/local/mesh_services/meshagent/meshagent"},
		{"relative with workdir", "[Service]\nWorkingDirectory=/srv/mesh\nExecStart=./meshagent\n", "/srv/mesh/meshagent"},
		{"relative without workdir", "[Service]\nExecStart=meshagent\n", ""},
		{"no exec", "[Unit]\nDescription=x\n", ""},
	} {
		if got := ParseUnitExec(c.unit); got != c.want {
			t.Errorf("%s: got %q want %q", c.name, got, c.want)
		}
	}
}

// fakeWin is a Windows machine: the registry answers from a map keyed by the reg.exe argument list, the file system from a set.
type fakeWin struct {
	reg   map[string]string
	files map[string]bool
	dirs  map[string][]DirEntry
	env   map[string]string
	runs  []string
}

func (f *fakeWin) probe(goos string) Probe {
	return Probe{GOOS: goos, discover: true,
		Exists:   func(s string) bool { return f.files[s] },
		ReadFile: func(s string) ([]byte, error) { return nil, os.ErrNotExist },
		ReadDir: func(s string) ([]DirEntry, error) {
			if d, ok := f.dirs[s]; ok {
				return d, nil
			}
			return nil, os.ErrNotExist
		},
		Env: func(k string) string { return f.env[k] },
		Run: func(ctx context.Context, dir, path string, args []string) (int, string, error) {
			key := path + " " + strings.Join(args, " ")
			f.runs = append(f.runs, key)
			if out, ok := f.reg[key]; ok {
				return 0, out, nil
			}
			return 1, "", nil
		}}
}

const svcQuery = `reg.exe query HKLM\SYSTEM\CurrentControlSet\Services /f mesh /k`

var pfEnv = map[string]string{"ProgramFiles": `C:\Program Files`, "ProgramW6432": `C:\Program Files`, "ProgramFiles(x86)": `C:\Program Files (x86)`, "ProgramData": `C:\ProgramData`, "SystemDrive": `C:`}

func svcKey(name string) string {
	return "\r\nHKEY_LOCAL_MACHINE\\SYSTEM\\CurrentControlSet\\Services\\" + name + "\r\n\r\nEnd of search: 1 match(es) found.\r\n"
}

func imgVal(v string) string {
	return "\r\nHKEY_LOCAL_MACHINE\\SYSTEM\\CurrentControlSet\\Services\\x\r\n    ImagePath    REG_EXPAND_SZ    " + v + "\r\n"
}

// TestDiscoverWindowsDecisionTable is the security decision of the discovery (CORE-16 class): which discovered paths may be EXECUTED (below Program Files, and only
// then after the trust check) and which are only READ for a claim.
func TestDiscoverWindowsDecisionTable(t *testing.T) {
	uninstall := `reg.exe query HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\Mesh Agent `
	for _, c := range []struct {
		name      string
		f         fakeWin
		wantExec  []string
		wantClaim []string
	}{
		{"service below Program Files with a custom name and a renamed exe is runnable",
			fakeWin{reg: map[string]string{
				svcQuery: svcKey("AcmeMeshHelper"),
				`reg.exe query HKLM\SYSTEM\CurrentControlSet\Services\AcmeMeshHelper /v ImagePath`: imgVal(`"C:\Program Files\Acme\helper.exe" --meshServiceName=AcmeMeshHelper`)}},
			[]string{`C:\Program Files\Acme\helper.exe`}, nil},
		{"service ImagePath in a user-writable place is never run, only read",
			fakeWin{reg: map[string]string{
				svcQuery: svcKey("MeshAgent"),
				`reg.exe query HKLM\SYSTEM\CurrentControlSet\Services\MeshAgent /v ImagePath`: imgVal(`C:\Apps\MA\MeshAgent.exe -svc`)}},
			nil, []string{`C:\Apps\MA\MeshAgent.exe`}},
		{"a traversal out of Program Files is never run (here it also lands in a profile and is dropped)",
			fakeWin{reg: map[string]string{
				svcQuery: svcKey("MeshAgent"),
				`reg.exe query HKLM\SYSTEM\CurrentControlSet\Services\MeshAgent /v ImagePath`: imgVal(`"C:\Program Files\..\Users\x\MeshAgent.exe"`)}},
			nil, nil},
		{"a path in a profile is dropped altogether",
			fakeWin{reg: map[string]string{
				svcQuery: svcKey("MeshAgent"),
				`reg.exe query HKLM\SYSTEM\CurrentControlSet\Services\MeshAgent /v ImagePath`: imgVal(`"C:\Users\bob\AppData\Roaming\MeshAgent.exe"`)}},
			nil, nil},
		{"a service without mesh in its name is ignored",
			fakeWin{reg: map[string]string{
				svcQuery: svcKey("Spooler"),
				`reg.exe query HKLM\SYSTEM\CurrentControlSet\Services\Spooler /v ImagePath`: imgVal(`"C:\Program Files\x\spoolsv.exe"`)}},
			nil, nil},
		{"32-bit uninstall view below Program Files (x86) is runnable",
			fakeWin{reg: map[string]string{uninstall + `/reg:32`: "\r\n    InstallLocation    REG_SZ    C:\\Program Files (x86)\\Mesh Agent\r\n"}},
			[]string{`C:\Program Files (x86)\Mesh Agent\MeshAgent.exe`}, nil},
		{"uninstall DisplayIcon on another drive is a claim only",
			fakeWin{reg: map[string]string{uninstall + `/reg:64`: "\r\n    DisplayIcon    REG_SZ    \"D:\\Mesh\\MeshAgent.exe\"\r\n"}},
			nil, []string{`D:\Mesh\MeshAgent.exe`}},
		{"a renamed exe planted under ProgramData is a claim, never runnable",
			fakeWin{dirs: map[string][]DirEntry{
				`C:\ProgramData`:            {{Name: "Microsoft", Dir: true}, {Name: "Mesh Agent", Dir: true}},
				`C:\ProgramData\Mesh Agent`: {{Name: "MeshAgent.exe"}, {Name: "MeshAgent.msh"}}}},
			nil, []string{`C:\ProgramData\Mesh Agent\MeshAgent.exe`}},
		{"the same under the drive root",
			fakeWin{dirs: map[string][]DirEntry{`C:\`: {{Name: "MeshAgent", Dir: true}}, `C:\MeshAgent`: {{Name: "MeshAgent.exe"}}}},
			nil, []string{`C:\MeshAgent\MeshAgent.exe`}},
		{"a Mesh directory below Program Files is runnable",
			fakeWin{dirs: map[string][]DirEntry{`C:\Program Files`: {{Name: "Mesh Agent", Dir: true}}, `C:\Program Files\Mesh Agent`: {{Name: "MeshAgent-old.exe"}, {Name: "MeshAgent.db"}}}},
			[]string{`C:\Program Files\Mesh Agent\MeshAgent-old.exe`}, nil},
		{"nothing installed", fakeWin{}, nil, nil},
	} {
		t.Run(c.name, func(t *testing.T) {
			c.f.env = pfEnv
			p := c.f.probe("windows")
			p.Timeout = 1e9
			p.Env = func(k string) string { return pfEnv[k] }
			execs, claims := p.sweep()
			if strings.Join(execs, "|") != strings.Join(c.wantExec, "|") || strings.Join(claims, "|") != strings.Join(c.wantClaim, "|") {
				t.Fatalf("execs %q claims %q, want %q %q", execs, claims, c.wantExec, c.wantClaim)
			}
		})
	}
}

// TestFoundNeverRunsAnUntrustedOrOutsideCandidate: Found() returns a discovered executable only below Program Files AND when the trust check passes; a claim path is
// never returned, so it can never reach the code that runs `-nodeid`.
func TestFoundNeverRunsAnUntrustedOrOutsideCandidate(t *testing.T) {
	pf := `C:\Program Files\Acme\helper.exe`
	planted := `C:\ProgramData\Mesh Agent\MeshAgent.exe`
	f := &fakeWin{env: pfEnv, files: map[string]bool{pf: true, planted: true},
		reg: map[string]string{
			svcQuery: svcKey("AcmeMesh"),
			`reg.exe query HKLM\SYSTEM\CurrentControlSet\Services\AcmeMesh /v ImagePath`: imgVal(`"` + pf + `"`)},
		dirs: map[string][]DirEntry{`C:\ProgramData`: {{Name: "Mesh Agent", Dir: true}}, `C:\ProgramData\Mesh Agent`: {{Name: "MeshAgent.exe"}}}}
	var trustedAsked []string
	p := f.probe("windows")
	p.Timeout = 1e9
	p.Env = func(k string) string { return pfEnv[k] }
	p.Trusted = func(s string) bool { trustedAsked = append(trustedAsked, s); return s == pf }
	if got := p.Found(); got != pf {
		t.Fatalf("trusted exe below Program Files: %q", got)
	}
	// the owner/DACL check refuses it (a standard user owns it): nothing is found, and the planted copy is not even offered to the check
	p.Trusted = func(s string) bool { trustedAsked = append(trustedAsked, s); return false }
	trustedAsked = nil
	if got := p.Found(); got != "" {
		t.Fatalf("untrusted exe must not be found: %q", got)
	}
	for _, a := range trustedAsked {
		if a == planted {
			t.Fatal("a path under ProgramData reached the trust check as if it could be run")
		}
	}
}

// TestClaimFromAnMSHInAUserWritableDirIsReadOnlyAndNeverRuns: only reg.exe (reading the registry) is ever started; the planted .msh yields a claim.
func TestClaimFromAnMSHInAUserWritableDirIsReadOnlyAndNeverRuns(t *testing.T) {
	planted := `C:\ProgramData\Mesh Agent\MeshAgent.exe`
	msh := "NodeID=" + testNode + "\n"
	f := &fakeWin{env: pfEnv, files: map[string]bool{planted: true},
		dirs: map[string][]DirEntry{`C:\ProgramData`: {{Name: "Mesh Agent", Dir: true}}, `C:\ProgramData\Mesh Agent`: {{Name: "MeshAgent.exe"}, {Name: "MeshAgent.msh"}}}}
	p := f.probe("windows")
	p.Timeout = 1e9
	p.Env = func(k string) string { return pfEnv[k] }
	p.ReadFile = func(s string) ([]byte, error) {
		if s == `C:\ProgramData\Mesh Agent\MeshAgent.msh` {
			return []byte(msh), nil
		}
		return nil, os.ErrNotExist
	}
	ranOther := false
	run := p.Run
	p.Run = func(ctx context.Context, dir, path string, args []string) (int, string, error) {
		if path != "reg.exe" {
			ranOther = true
		}
		return run(ctx, dir, path, args)
	}
	if p.Found() != "" {
		t.Fatal("the planted agent must not be found as runnable")
	}
	if id := p.ClaimNodeID(); id != testNode {
		t.Fatalf("claim %q", id)
	}
	rep := (&Reporter{StateDir: t.TempDir(), Probe: p}).Current(context.Background())
	if rep == nil || !rep.Installed || rep.NodeID != testNode {
		t.Fatalf("report %+v", rep)
	}
	if ranOther {
		t.Fatal("a program other than reg.exe was started")
	}
	// no .msh, no claim, no report
	p.ReadFile = func(string) ([]byte, error) { return nil, os.ErrNotExist }
	if p.ClaimNodeID() != "" {
		t.Fatal("no .msh, no claim")
	}
}

func TestDiscoverIsOffForExplicitPathsAndNeverRunsAnything(t *testing.T) {
	f := &fakeWin{reg: map[string]string{svcQuery: svcKey("MeshAgent")}}
	p := f.probe("windows")
	p.discover = false
	p.Paths = []string{`C:\nowhere\MeshAgent.exe`}
	if p.Found() != "" || len(f.runs) != 0 {
		t.Fatalf("explicit paths must be hermetic: %v", f.runs)
	}
}

type fakeLinux struct {
	files map[string]string
	dirs  map[string][]DirEntry
	exist map[string]bool
}

func (f fakeLinux) probe() Probe {
	return Probe{GOOS: "linux", discover: true,
		Exists: func(s string) bool { return f.exist[s] },
		ReadFile: func(s string) ([]byte, error) {
			if b, ok := f.files[s]; ok {
				return []byte(b), nil
			}
			return nil, os.ErrNotExist
		},
		ReadDir: func(s string) ([]DirEntry, error) {
			if d, ok := f.dirs[s]; ok {
				return d, nil
			}
			return nil, os.ErrNotExist
		},
		Env: func(string) string { return "" },
		Run: func(context.Context, string, string, []string) (int, string, error) { return 1, "", nil }}
}

func TestDiscoverLinuxTable(t *testing.T) {
	for _, c := range []struct {
		name string
		f    fakeLinux
		want string
	}{
		{"custom systemd service name",
			fakeLinux{dirs: map[string][]DirEntry{"/etc/systemd/system": {{Name: "ssh.service"}, {Name: "acme-meshagent.service"}}},
				files: map[string]string{"/etc/systemd/system/acme-meshagent.service": "[Service]\nExecStart=/opt/acme/ma-linux64 --x\n"},
				exist: map[string]bool{"/opt/acme/ma-linux64": true}}, "/opt/acme/ma-linux64"},
		{"unit in /usr/lib/systemd/system",
			fakeLinux{dirs: map[string][]DirEntry{"/usr/lib/systemd/system": {{Name: "meshagent.service"}}},
				files: map[string]string{"/usr/lib/systemd/system/meshagent.service": "[Service]\nWorkingDirectory=/srv/mesh\nExecStart=./meshagent\n"},
				exist: map[string]bool{"/srv/mesh/meshagent": true}}, "/srv/mesh/meshagent"},
		{"sysv init script",
			fakeLinux{dirs: map[string][]DirEntry{"/etc/init.d": {{Name: "meshagent"}}},
				files: map[string]string{"/etc/init.d/meshagent": "#!/bin/sh\nDAEMON=/usr/local/mesh_services/meshagent/meshagent_arm\nPID=/var/run/meshagent.pid\n"},
				exist: map[string]bool{"/usr/local/mesh_services/meshagent/meshagent_arm": true}}, "/usr/local/mesh_services/meshagent/meshagent_arm"},
		{"renamed file in /usr/local/mesh_services/<dir>",
			fakeLinux{dirs: map[string][]DirEntry{"/usr/local/mesh_services": {{Name: "meshagent", Dir: true}},
				"/usr/local/mesh_services/meshagent": {{Name: "meshagent_x86-64"}, {Name: "meshagent.msh"}, {Name: "meshagent.db"}}},
				exist: map[string]bool{"/usr/local/mesh_services/meshagent/meshagent_x86-64": true}}, "/usr/local/mesh_services/meshagent/meshagent_x86-64"},
		{"portable under /opt",
			fakeLinux{dirs: map[string][]DirEntry{"/opt": {{Name: "mesh-portable", Dir: true}, {Name: "google", Dir: true}}, "/opt/mesh-portable": {{Name: "meshagent"}}},
				exist: map[string]bool{"/opt/mesh-portable/meshagent": true}}, "/opt/mesh-portable/meshagent"},
		{"a unit under /home is never followed",
			fakeLinux{dirs: map[string][]DirEntry{"/etc/systemd/system": {{Name: "meshagent.service"}}},
				files: map[string]string{"/etc/systemd/system/meshagent.service": "[Service]\nExecStart=/home/bob/meshagent\n"},
				exist: map[string]bool{"/home/bob/meshagent": true}}, ""},
		{"nothing", fakeLinux{}, ""},
	} {
		t.Run(c.name, func(t *testing.T) {
			p := c.f.probe()
			if got := p.Found(); got != c.want {
				t.Fatalf("got %q want %q", got, c.want)
			}
		})
	}
}

func TestMshNamesIncludeTheRenamedExecutable(t *testing.T) {
	for _, c := range []struct {
		exe  string
		want []string
	}{
		{`C:\Program Files\Mesh Agent\MeshAgent.exe`, []string{"MeshAgent.msh", "meshagent.msh"}},
		{`D:\Acme\helper.exe`, []string{"MeshAgent.msh", "meshagent.msh", "helper.msh"}},
		{"/opt/acme/ma-linux64", []string{"MeshAgent.msh", "meshagent.msh", "ma-linux64.msh"}},
		{"/usr/local/mesh_services/meshagent/meshagent", []string{"MeshAgent.msh", "meshagent.msh"}},
	} {
		got := mshNames(c.exe)
		if strings.Join(got, ",") != strings.Join(c.want, ",") {
			t.Errorf("%s: %v want %v", c.exe, got, c.want)
		}
	}
}

func TestNodeIDReadsTheRenamedMSHAndBothRegistryViews(t *testing.T) {
	const exe = `D:\Acme\helper.exe`
	msh := "NodeID=" + testNode + "\nMeshServer=wss://mesh.example/agent.ashx\n"
	p := Probe{GOOS: "windows", Paths: []string{exe}, Exists: func(s string) bool { return s == exe },
		ReadFile: func(s string) ([]byte, error) {
			if s == `D:\Acme`+string(os.PathSeparator)+"helper.msh" || strings.HasSuffix(s, "helper.msh") {
				return []byte(msh), nil
			}
			return nil, os.ErrNotExist
		},
		Run: func(context.Context, string, string, []string) (int, string, error) { return 1, "", nil }}
	if id, src := p.NodeID(context.Background()); id != testNode || src != "msh" {
		t.Fatalf("renamed .msh: %q %q", id, src)
	}
	// only the 32-bit view of the WOW6432Node key has the id
	var asked []string
	p2 := Probe{GOOS: "windows", Paths: []string{exe}, Exists: func(s string) bool { return s == exe },
		ReadFile: func(string) ([]byte, error) { return nil, os.ErrNotExist },
		Run: func(ctx context.Context, dir, path string, args []string) (int, string, error) {
			if path != "reg.exe" {
				return 1, "", nil
			}
			asked = append(asked, strings.Join(args, " "))
			if strings.Contains(strings.Join(args, " "), "WOW6432Node") && strings.HasSuffix(strings.Join(args, " "), "/reg:32") {
				return 0, "\r\n    NodeId    REG_SZ    " + testNode + "\r\n", nil
			}
			return 1, "", nil
		}}
	if id, src := p2.NodeID(context.Background()); id != testNode || src != "registry" {
		t.Fatalf("registry views: %q %q (asked %v)", id, src, asked)
	}
	if len(asked) != 6 {
		t.Fatalf("expected both keys in three views before the hit, asked %v", asked)
	}
}
