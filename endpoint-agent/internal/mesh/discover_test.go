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

func TestDiscoverWindowsTable(t *testing.T) {
	svc := func(name string) string {
		return "\r\nHKEY_LOCAL_MACHINE\\SYSTEM\\CurrentControlSet\\Services\\" + name + "\r\n\r\nEnd of search: 1 match(es) found.\r\n"
	}
	img := func(v string) string {
		return "\r\nHKEY_LOCAL_MACHINE\\SYSTEM\\CurrentControlSet\\Services\\x\r\n    ImagePath    REG_EXPAND_SZ    " + v + "\r\n"
	}
	uninstall := `reg.exe query HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\Mesh Agent `
	for _, c := range []struct {
		name  string
		f     fakeWin
		want  string
		wantN int
	}{
		{"renamed exe behind a custom service name",
			fakeWin{reg: map[string]string{
				svcQuery: svc("AcmeMeshHelper"),
				`reg.exe query HKLM\SYSTEM\CurrentControlSet\Services\AcmeMeshHelper /v ImagePath`: img(`"D:\Acme\helper.exe" --meshServiceName=AcmeMeshHelper`)},
				files: map[string]bool{`D:\Acme\helper.exe`: true}}, `D:\Acme\helper.exe`, 1},
		{"unquoted image path",
			fakeWin{reg: map[string]string{
				svcQuery: svc("MeshAgent"),
				`reg.exe query HKLM\SYSTEM\CurrentControlSet\Services\MeshAgent /v ImagePath`: img(`C:\Apps\MA\MeshAgent.exe -svc`)},
				files: map[string]bool{`C:\Apps\MA\MeshAgent.exe`: true}}, `C:\Apps\MA\MeshAgent.exe`, 1},
		{"service points at a user profile: never followed",
			fakeWin{reg: map[string]string{
				svcQuery: svc("MeshAgent"),
				`reg.exe query HKLM\SYSTEM\CurrentControlSet\Services\MeshAgent /v ImagePath`: img(`"C:\Users\bob\AppData\Roaming\MeshAgent.exe"`)},
				files: map[string]bool{`C:\Users\bob\AppData\Roaming\MeshAgent.exe`: true}}, ``, 0},
		{"service name without mesh in it is ignored",
			fakeWin{reg: map[string]string{
				svcQuery: svc("Spooler"),
				`reg.exe query HKLM\SYSTEM\CurrentControlSet\Services\Spooler /v ImagePath`: img(`"C:\Windows\spoolsv.exe"`)},
				files: map[string]bool{`C:\Windows\spoolsv.exe`: true}}, ``, 0},
		{"32-bit uninstall view",
			fakeWin{reg: map[string]string{
				uninstall + `/reg:32`: "\r\n    InstallLocation    REG_SZ    C:\\Program Files (x86)\\Mesh Agent\r\n"},
				files: map[string]bool{`C:\Program Files (x86)\Mesh Agent\MeshAgent.exe`: true}}, `C:\Program Files (x86)\Mesh Agent\MeshAgent.exe`, 1},
		{"display icon of the 64-bit view",
			fakeWin{reg: map[string]string{
				uninstall + `/reg:64`: "\r\n    DisplayIcon    REG_SZ    \"D:\\Mesh\\MeshAgent.exe\"\r\n"},
				files: map[string]bool{`D:\Mesh\MeshAgent.exe`: true}}, `D:\Mesh\MeshAgent.exe`, 1},
		{"portable copy in a Mesh directory under ProgramData, renamed",
			fakeWin{env: map[string]string{"ProgramData": `C:\ProgramData`},
				dirs: map[string][]DirEntry{
					`C:\ProgramData`:              {{Name: "Microsoft", Dir: true}, {Name: "MeshPortable", Dir: true}},
					`C:\ProgramData\MeshPortable`: {{Name: "MeshAgent-old.exe"}, {Name: "MeshAgent.msh"}, {Name: "MeshAgent.db"}}},
				files: map[string]bool{`C:\ProgramData\MeshPortable\MeshAgent-old.exe`: true}}, `C:\ProgramData\MeshPortable\MeshAgent-old.exe`, 1},
		{"nothing installed", fakeWin{}, ``, 0},
	} {
		t.Run(c.name, func(t *testing.T) {
			p := c.f.probe("windows")
			p.Timeout = 1e9
			if got := p.Found(); got != c.want {
				t.Fatalf("Found: got %q want %q (runs %v)", got, c.want, c.f.runs)
			}
			if got := len(p.sweep()); got != c.wantN && c.want != "" {
				t.Fatalf("sweep found %d, want %d", got, c.wantN)
			}
		})
	}
}

func TestDiscoverIsOffForExplicitPathsAndNeverRunsAnything(t *testing.T) {
	f := &fakeWin{reg: map[string]string{svcQuery: "\r\nHKEY_LOCAL_MACHINE\\SYSTEM\\CurrentControlSet\\Services\\MeshAgent\r\n"}}
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
