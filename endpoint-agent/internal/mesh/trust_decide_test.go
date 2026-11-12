package mesh

import (
	"errors"
	"strings"
	"testing"

	"rivetit-agent/internal/winacl"
)

func fakeFS(objs map[string]winacl.Info) inspectFunc {
	return func(p string, wantDir bool) (winacl.Info, error) {
		i, ok := objs[strings.ToLower(p)]
		if !ok {
			return winacl.Info{Path: p, NotFound: true}, nil
		}
		i.Path, i.WantDir = p, wantDir
		return i, nil
	}
}

func obj(owner string, isDir bool, extra ...winacl.ACE) winacl.Info {
	return winacl.Info{Owner: owner, IsDir: isDir, HasDACL: true, ACEs: append([]winacl.ACE{
		{SID: winacl.SIDSystem, Allow: true, Mask: 0x1F01FF},
		{SID: winacl.SIDAdministrator, Allow: true, Mask: 0x1F01FF},
		{SID: "S-1-5-32-545", Allow: true, Mask: 0x1200A9},
	}, extra...)}
}

func TestMeshTrustTable(t *testing.T) {
	roots := []string{`C:\Program Files`, `C:\Program Files (x86)`}
	const exe = `C:\Program Files\Mesh Agent\MeshAgent.exe`
	good := func() map[string]winacl.Info {
		return map[string]winacl.Info{
			`c:\program files\mesh agent`:               obj(winacl.SIDTrustedInst, true),
			`c:\program files\mesh agent\meshagent.exe`: obj(winacl.SIDAdministrator, false),
		}
	}
	userWrite := winacl.ACE{SID: "S-1-5-11", Allow: true, Mask: 0x2}
	cases := []struct {
		name string
		path string
		mod  func(map[string]winacl.Info)
		want string
	}{
		{"default install", exe, func(m map[string]winacl.Info) {}, ""},
		{"case and slashes", `c:/program files/mesh agent/meshagent.exe`, func(m map[string]winacl.Info) {}, ""},
		{"the CORE-16 exploit: mesh_agent_path in a public folder", `C:\Users\Public\meshagent.exe`, func(m map[string]winacl.Info) {}, "allowed install location"},
		{"ProgramData is not an install location", `C:\ProgramData\RivetIT\Agent\meshagent.exe`, func(m map[string]winacl.Info) {}, "allowed install location"},
		{"look-alike root", `C:\Program Files Evil\meshagent.exe`, func(m map[string]winacl.Info) {}, "allowed install location"},
		{"dot-dot escape", `C:\Program Files\..\Users\Public\meshagent.exe`, func(m map[string]winacl.Info) {}, "clean path"},
		{"folder created by a user inside Program Files", exe, func(m map[string]winacl.Info) {
			m[`c:\program files\mesh agent`] = obj("S-1-5-21-1-2-3-1001", true)
		}, "owned by"},
		{"folder writable by Authenticated Users", exe, func(m map[string]winacl.Info) {
			m[`c:\program files\mesh agent`] = obj(winacl.SIDSystem, true, userWrite)
		}, "write access"},
		{"exe writable by a user", exe, func(m map[string]winacl.Info) {
			m[`c:\program files\mesh agent\meshagent.exe`] = obj(winacl.SIDSystem, false, userWrite)
		}, "write access"},
		{"folder is a junction", exe, func(m map[string]winacl.Info) {
			i := obj(winacl.SIDSystem, true)
			i.Reparse = true
			m[`c:\program files\mesh agent`] = i
		}, "reparse point"},
		{"missing file", exe, func(m map[string]winacl.Info) { delete(m, `c:\program files\mesh agent\meshagent.exe`) }, "does not exist"},
	}
	for _, c := range cases {
		m := good()
		c.mod(m)
		err := meshTrust(c.path, roots, fakeFS(m))
		switch {
		case c.want == "" && err != nil:
			t.Errorf("%s: refused: %v", c.name, err)
		case c.want != "" && err == nil:
			t.Errorf("%s: trusted, want refusal containing %q", c.name, c.want)
		case c.want != "" && !strings.Contains(err.Error(), c.want):
			t.Errorf("%s: error %q lacks %q", c.name, err, c.want)
		}
	}
	if err := meshTrust(exe, roots, func(string, bool) (winacl.Info, error) { return winacl.Info{}, errors.New("access denied") }); err == nil {
		t.Error("an unreadable ACL must not be trusted")
	}
}

func TestReportUntrustedOncePerReason(t *testing.T) {
	var got []string
	old := OnUntrusted
	OnUntrusted = func(p, r string) { got = append(got, p+": "+r) }
	defer func() { OnUntrusted = old }()
	for i := 0; i < 3; i++ {
		reportUntrusted(`C:\x\once.exe`, errors.New("bad owner"))
	}
	if len(got) != 1 {
		t.Errorf("reports: %v", got)
	}
}

func TestMeshTrustAcceptsAdminMemberOwnerButNotStandardUser(t *testing.T) {
	roots := []string{`C:\Program Files`}
	const exe = `C:\Program Files\Mesh Agent\MeshAgent.exe`
	for _, admin := range []bool{true, false} {
		d, f := obj("S-1-5-21-1-2-3-1001", true), obj("S-1-5-21-1-2-3-1001", false)
		d.OwnerIsAdmin, f.OwnerIsAdmin = admin, admin
		err := meshTrust(exe, roots, fakeFS(map[string]winacl.Info{`c:\program files\mesh agent`: d, `c:\program files\mesh agent\meshagent.exe`: f}))
		if admin && err != nil || !admin && err == nil {
			t.Errorf("admin member=%v: err=%v", admin, err)
		}
	}
}
