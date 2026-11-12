package winacl

import (
	"strings"
	"testing"
)

const (
	sidUsers    = "S-1-5-32-545"
	sidAuthed   = "S-1-5-11"
	sidEveryone = "S-1-1-0"
	sidAttacker = "S-1-5-21-1-2-3-1001"
	sidCreator  = "S-1-3-0"

	maskFull   = 0x1F01FF
	maskRX     = 0x1200A9
	maskModify = 0x1301BF
)

func good() Info {
	return Info{Path: `C:\ProgramData\RivetIT\Agent`, WantDir: true, IsDir: true, Owner: SIDSystem, HasDACL: true, ACEs: []ACE{
		{SID: SIDSystem, Allow: true, Mask: maskFull},
		{SID: SIDAdministrator, Allow: true, Mask: maskFull},
	}}
}

// The decision CORE-16 depends on: a directory a standard user created (and so owns) must be refused even after the agent rewrote its DACL.
func TestCheckTable(t *testing.T) {
	cases := []struct {
		name string
		mod  func(*Info)
		want string // substring of the error; "" = trusted
	}{
		{"agent-created directory", func(i *Info) {}, ""},
		{"owned by Administrators", func(i *Info) { i.Owner = SIDAdministrator }, ""},
		{"owned by TrustedInstaller", func(i *Info) { i.Owner = SIDTrustedInst }, ""},
		{"owner SID case-insensitive", func(i *Info) { i.Owner = strings.ToLower(SIDSystem) }, ""},
		{"users may read and execute", func(i *Info) { i.ACEs = append(i.ACEs, ACE{SID: sidUsers, Allow: true, Mask: maskRX}) }, ""},
		{"CREATOR OWNER inherit-only full control is a template", func(i *Info) {
			i.ACEs = append(i.ACEs, ACE{SID: sidCreator, Allow: true, Mask: 0x10000000, InheritOnly: true})
		}, ""},
		{"deny entries are ignored", func(i *Info) { i.ACEs = append(i.ACEs, ACE{SID: sidUsers, Allow: false, Mask: maskFull}) }, ""},

		{"pre-created by a standard user (attacker is owner, DACL rewritten)", func(i *Info) { i.Owner = sidAttacker }, "owned by " + sidAttacker},
		{"owner unknown", func(i *Info) { i.Owner = "" }, "unknown owner"},
		{"owned by Users", func(i *Info) { i.Owner = sidUsers }, "owned by"},
		{"junction or symlink", func(i *Info) { i.Reparse = true }, "reparse point"},
		{"regular file where a directory is expected", func(i *Info) { i.IsDir = false }, "not a directory"},
		{"directory where a file is expected", func(i *Info) { i.WantDir = false }, "is a directory"},
		{"NULL DACL", func(i *Info) { i.HasDACL = false; i.ACEs = nil }, "no DACL"},
		{"Users can create files", func(i *Info) { i.ACEs = append(i.ACEs, ACE{SID: sidUsers, Allow: true, Mask: 0x2}) }, "write access"},
		{"Authenticated Users modify", func(i *Info) { i.ACEs = append(i.ACEs, ACE{SID: sidAuthed, Allow: true, Mask: maskModify}) }, "write access"},
		{"Everyone full control", func(i *Info) { i.ACEs = append(i.ACEs, ACE{SID: sidEveryone, Allow: true, Mask: maskFull}) }, "write access"},
		{"attacker keeps WRITE_DAC", func(i *Info) { i.ACEs = append(i.ACEs, ACE{SID: sidAttacker, Allow: true, Mask: writeDAC}) }, "write access"},
		{"attacker keeps WRITE_OWNER", func(i *Info) { i.ACEs = append(i.ACEs, ACE{SID: sidAttacker, Allow: true, Mask: writeOwner}) }, "write access"},
		{"attacker may delete children", func(i *Info) { i.ACEs = append(i.ACEs, ACE{SID: sidAttacker, Allow: true, Mask: fileDeleteChild}) }, "write access"},
		{"attacker has GENERIC_WRITE", func(i *Info) { i.ACEs = append(i.ACEs, ACE{SID: sidAttacker, Allow: true, Mask: genericWrite}) }, "write access"},
		{"attacker has GENERIC_ALL", func(i *Info) { i.ACEs = append(i.ACEs, ACE{SID: sidAttacker, Allow: true, Mask: genericAll}) }, "write access"},
		{"missing", func(i *Info) { i.NotFound = true }, "does not exist"},
	}
	for _, c := range cases {
		i := good()
		c.mod(&i)
		err := Check(i)
		switch {
		case c.want == "" && err != nil:
			t.Errorf("%s: unexpectedly refused: %v", c.name, err)
		case c.want != "" && err == nil:
			t.Errorf("%s: trusted, want refusal containing %q", c.name, c.want)
		case c.want != "" && !strings.Contains(err.Error(), c.want):
			t.Errorf("%s: error %q lacks %q", c.name, err, c.want)
		}
	}
}

func TestCheckChainStopsAtTheFirstBadLink(t *testing.T) {
	root := good()
	bad := good()
	bad.Path = `C:\ProgramData\RivetIT`
	bad.Owner = sidAttacker
	if err := CheckChain([]Info{bad, root}); err == nil || !strings.Contains(err.Error(), `C:\ProgramData\RivetIT `) {
		t.Errorf("parent owned by a user was not refused first: %v", err)
	}
	if err := CheckChain([]Info{root, root}); err != nil {
		t.Error(err)
	}
}

func TestUnderRootAndChainBelow(t *testing.T) {
	roots := []string{`C:\Program Files`, `C:\Program Files (x86)`}
	for path, ok := range map[string]bool{
		`C:\Program Files\Mesh Agent\MeshAgent.exe`:       true,
		`c:/program files/mesh agent/meshagent.exe`:       true,
		`C:\Program Files (x86)\Mesh Agent\MeshAgent.exe`: true,
		`C:\Program Files`:                                false, // the root itself is not a file below it
		`C:\Program Files Evil\meshagent.exe`:             false, // prefix without a separator
		`C:\Users\Public\meshagent.exe`:                   false,
		`C:\ProgramData\RivetIT\Agent\meshagent.exe`:      false,
		`D:\Program Files\meshagent.exe`:                  false,
	} {
		if _, got := UnderRoot(path, roots); got != ok {
			t.Errorf("UnderRoot(%q) = %v, want %v", path, got, ok)
		}
	}
	got := ChainBelow(`C:\Program Files`, `c:\program files\Mesh Agent\MeshAgent.exe`)
	want := []string{`C:\Program Files\Mesh Agent`, `C:\Program Files\Mesh Agent\MeshAgent.exe`}
	if len(got) != 2 || got[0] != want[0] || !strings.EqualFold(got[1], want[1]) {
		t.Errorf("ChainBelow = %v", got)
	}
	if ChainBelow(`C:\Program Files`, `C:\Program Files\..\Users\x`) != nil || ChainBelow(`C:\Program Files`, `C:\Users\x`) != nil {
		t.Error("a path outside or with .. must yield no chain")
	}
}

func TestRepairHintNamesTheDirectoryAndTheCommands(t *testing.T) {
	h := RepairHint(`C:\ProgramData\RivetIT`)
	for _, s := range []string{`takeown /F "C:\ProgramData\RivetIT"`, "Remove-Item", "standard user", "enrolled again"} {
		if !strings.Contains(h, s) {
			t.Errorf("hint lacks %q", s)
		}
	}
}
