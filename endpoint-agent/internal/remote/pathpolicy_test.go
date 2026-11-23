package remote

import (
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func reasonOf(err error) string {
	var pe *PathError
	if errors.As(err, &pe) {
		return pe.Reason
	}
	if err == nil {
		return ""
	}
	return "other:" + err.Error()
}

func TestLinuxPathPolicyTable(t *testing.T) {
	p := PathPolicy{GOOS: "linux", ExtraDeny: []string{"/var/lib/rivetit-agent"}}
	allow := "/srv/data\n/var/log"
	type c struct{ path, allow, want string }
	for _, x := range []c{
		{"/srv/data/report.csv", allow, ""},
		{"/srv/data/a/b/c.txt", allow, ""},
		{"/srv/data", allow, ""},
		{"/srv/data/", allow, ""},
		{"/var/log/syslog", allow, ""},
		{"srv/data/a", allow, ReasonNotAbsolute},
		{"./srv/data/a", allow, ReasonNotAbsolute},
		{"", allow, ReasonNotAbsolute},
		{"/srv/data/../../etc/hostname", allow, ReasonTraversal},
		{"/srv/data/..", allow, ReasonTraversal},
		{"/etc/../etc/shadow", allow, ReasonTraversal},
		{"/srv/data/./a", allow, ReasonNotClean},
		{"/srv//data/a", allow, ReasonNotClean},
		{"/srv/data//a", allow, ReasonNotClean},
		{"/srv/data2/a", allow, ReasonNotAllowed},
		{"/srv/dat", allow, ReasonNotAllowed},
		{"/srv", allow, ReasonNotAllowed},
		{"/", allow, ReasonNotAllowed},
		{"/etc/hostname", allow, ReasonNotAllowed},
		{"/srv/data/a\x00b", allow, ReasonBadChars},
		{"/srv/data/a\nb", allow, ReasonBadChars},
		{"/srv/data/a\x1bb", allow, ReasonBadChars},
		{"/srv/data/\xff\xfe", allow, ReasonBadChars},
		{"/srv/data/" + strings.Repeat("a", MaxPathLen), allow, ReasonTooLong},
		{"/srv/data/a", "", ReasonNoAllowedRoots},
		{"/srv/data/a", "relative/root\n\n  \n", ReasonNoAllowedRoots},
		{"/srv/data/a", "/srv/../etc", ReasonNoAllowedRoots},
		// the deny list wins over the allow list
		{"/etc/shadow", "/etc", ReasonDenyList},
		{"/etc/gshadow", "/etc", ReasonDenyList},
		{"/etc/sudoers", "/etc", ReasonDenyList},
		{"/etc/sudoers.d/x", "/etc", ReasonDenyList},
		{"/etc/ssh/ssh_host_rsa_key", "/etc/ssh", ReasonDenyList},
		{"/etc/ssh/ssh_host_ed25519_key.pub", "/etc/ssh", ReasonDenyList},
		{"/etc/ssh/sshd_config", "/etc/ssh", ""},
		{"/etc/security/opasswd", "/etc", ReasonDenyList},
		{"/proc/1/environ", "/proc", ReasonDenyList},
		{"/sys/kernel/x", "/sys", ReasonDenyList},
		{"/dev/sda", "/dev", ReasonDenyList},
		{"/run/secrets/db", "/run", ReasonDenyList},
		{"/root/.ssh/authorized_keys", "/root", ReasonDenyList},
		{"/home/a/.ssh/id_rsa", "/home", ReasonDenyList},
		{"/home/a/.gnupg/pubring.kbx", "/home", ReasonDenyList},
		{"/srv/data/id_rsa", allow, ReasonDenyList},
		{"/srv/data/id_ed25519.pub", allow, ReasonDenyList},
		{"/srv/data/ID_ECDSA", allow, ReasonDenyList},
		{"/srv/data/site.pem", allow, ReasonDenyList},
		{"/srv/data/site.KEY", allow, ReasonDenyList},
		{"/srv/data/store.p12", allow, ReasonDenyList},
		{"/srv/data/store.pfx", allow, ReasonDenyList},
		{"/srv/data/vault.kdbx", allow, ReasonDenyList},
		{"/srv/data/app.keystore", allow, ReasonDenyList},
		{"/srv/data/app.JKS", allow, ReasonDenyList},           // CORE-DS-77: the server denies .jks too
		{"/etc/ssl/private/site", "/etc", ReasonDenyList},      // suffix-less private key directory
		{"/var/lib/rivetit/state", "/var/lib", ReasonDenyList}, // the server's PathPolicy denies /var/lib/rivetit
		{"/srv/data/ssh_host_rsa_key", allow, ReasonDenyList},  // host keys outside /etc/ssh
		{"/srv/data/keynote.txt", allow, ""},
		{"/var/lib/rivetit-agent/device.token", "/var/lib", ReasonDenyList},
		{"/var/lib/rivetit-agent", "/var/lib", ReasonDenyList},
		{"/var/lib/rivetit-agent2/x", "/var/lib", ""},
		{"/etc/rivetit/agent.json", "/etc", ReasonDenyList},
		{"/etc/rivetit-agent/x", "/etc", ReasonDenyList},
	} {
		_, err := p.Check(x.path, x.allow)
		if got := reasonOf(err); got != x.want {
			t.Errorf("Check(%q, %q) = %q; want %q", x.path, x.allow, got, x.want)
		}
	}
}

func TestWindowsPathPolicyTable(t *testing.T) {
	p := PathPolicy{GOOS: "windows", ExtraDeny: []string{`C:\ProgramData\RivetIT`}}
	allow := "C:\\Data\nD:\\Shares\\Public"
	type c struct{ path, allow, want string }
	for _, x := range []c{
		{`C:\Data\report.csv`, allow, ""},
		{`c:\data\Report.CSV`, allow, ""},
		{`D:\Shares\Public\a\b.txt`, allow, ""},
		{`C:\Data`, allow, ""},
		{`C:\Data\`, allow, ""},
		{`E:\Data\a`, allow, ReasonNotAllowed},
		{`C:\Data2\a`, allow, ReasonNotAllowed},
		{`C:\Windows\win.ini`, allow, ReasonNotAllowed},
		{`C:/Data/a.txt`, allow, ReasonNotAbsolute},
		{`C:\Data/a.txt`, allow, ReasonNotClean},
		{`\\server\share\a`, allow, ReasonUNC},
		{`//server/share/a`, allow, ReasonUNC},
		{`\\?\C:\Data\a`, allow, ReasonUNC},
		{`\\.\C:\Data\a`, allow, ReasonUNC},
		{`Data\a`, allow, ReasonNotAbsolute},
		{`\Data\a`, allow, ReasonNotAbsolute},
		{`C:Data\a`, allow, ReasonNotAbsolute},
		{`C:\Data\..\Windows\win.ini`, allow, ReasonTraversal},
		{`C:\Data\.\a`, allow, ReasonNotClean},
		{`C:\Data\\a`, allow, ReasonNotClean},
		{`C:\Data\a.txt:secret`, allow, ReasonADS},
		{`C:\Data\a.txt:$DATA`, allow, ReasonADS},
		{`C:\Data\PROGRA~1\a`, allow, ReasonShortName},
		{`C:\Data\a.`, allow, ReasonNotClean},
		{`C:\Data\a `, allow, ReasonNotClean},
		{`C:\Data\*.txt`, allow, ReasonBadChars},
		{`C:\Data\a?.txt`, allow, ReasonBadChars},
		{`C:\Data\a|b`, allow, ReasonBadChars},
		{`C:\Data\con`, allow, ReasonReservedName},
		{`C:\Data\NUL.txt`, allow, ReasonReservedName},
		{`C:\Data\com1`, allow, ReasonReservedName},
		{"C:\\Data\\a\x00b", allow, ReasonBadChars},
		{`C:\Data\a`, "", ReasonNoAllowedRoots},
		{`C:\Data\a`, `\\srv\share`, ReasonNoAllowedRoots},
		{`C:\Windows\System32\config\SAM`, `C:\Windows`, ReasonDenyList},
		{`c:\windows\system32\CONFIG\system`, `C:\Windows`, ReasonDenyList},
		{`C:\Windows\Sysnative\config\SOFTWARE`, `C:\Windows`, ReasonDenyList},
		{`C:\Windows\NTDS\ntds.dit`, `C:\Windows`, ReasonDenyList},
		{`C:\Windows\repair\sam`, `C:\Windows`, ReasonDenyList},
		{`C:\Windows\System32\drivers\etc\hosts`, `C:\Windows`, ""},
		{`C:\Data\ntds.dit`, allow, ReasonDenyList},
		{`C:\Data\PAGEFILE.SYS`, allow, ReasonDenyList},
		{`C:\Data\hiberfil.sys`, allow, ReasonDenyList},
		{`C:\Data\site.PFX`, allow, ReasonDenyList},
		{`C:\Data\cert.pem`, allow, ReasonDenyList},
		{`C:\Data\vault.kdbx`, allow, ReasonDenyList},
		{`C:\Data\.ssh\id_rsa`, allow, ReasonDenyList},
		{`C:\ProgramData\RivetIT\device.token`, `C:\ProgramData`, ReasonDenyList},
		{`C:\programdata\rivetit\x`, `C:\ProgramData`, ReasonDenyList},
		{`C:\ProgramData\Other\x`, `C:\ProgramData`, ""},
		{`C:\ProgramData\ssh\ssh_host_rsa_key`, `C:\ProgramData`, ReasonDenyList},
	} {
		_, err := p.Check(x.path, x.allow)
		if got := reasonOf(err); got != x.want {
			t.Errorf("Check(%q, %q) = %q; want %q", x.path, x.allow, got, x.want)
		}
	}
}

func TestUnknownGOOSRefusesEverything(t *testing.T) {
	_, err := PathPolicy{GOOS: "plan9"}.Check("/srv/data/a", "/srv/data")
	if reasonOf(err) != reasonUnsupportedGOOS {
		t.Errorf("%v", err)
	}
}

func TestSymlinksAreResolvedBeforeTheDecision(t *testing.T) {
	dir, err := filepath.EvalSymlinks(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	allowed := filepath.Join(dir, "shared")
	secret := filepath.Join(dir, "secret")
	for _, d := range []string{allowed, secret, filepath.Join(allowed, "sub")} {
		if err := os.MkdirAll(d, 0o755); err != nil {
			t.Fatal(err)
		}
	}
	os.WriteFile(filepath.Join(allowed, "ok.txt"), []byte("ok"), 0o644)
	os.WriteFile(filepath.Join(secret, "plain.txt"), []byte("secret"), 0o644)
	os.WriteFile(filepath.Join(secret, "id_rsa"), []byte("key"), 0o600)
	must := func(err error) {
		t.Helper()
		if err != nil {
			t.Fatal(err)
		}
	}
	must(os.Symlink(secret, filepath.Join(allowed, "link-dir"))) // a link out of the allowed folder
	must(os.Symlink(filepath.Join(secret, "plain.txt"), filepath.Join(allowed, "link-file")))
	must(os.Symlink(filepath.Join(allowed, "ok.txt"), filepath.Join(allowed, "link-inside"))) // a link that stays inside is fine
	must(os.Symlink("/etc/shadow", filepath.Join(allowed, "shadow-link")))                    // a link to a denied file
	must(os.Symlink(filepath.Join(allowed, "sub"), filepath.Join(dir, "alias")))              // the ALLOWED root itself reached through a link

	p := NewHostPolicy("")
	p.GOOS = "linux"
	if _, err := p.Check(filepath.Join(allowed, "ok.txt"), allowed); err != nil {
		t.Errorf("plain file: %v", err)
	}
	if got, err := p.Check(filepath.Join(allowed, "link-inside"), allowed); err != nil || got != filepath.Join(allowed, "ok.txt") {
		t.Errorf("a link that stays inside resolves to its target: %q %v", got, err)
	}
	for _, name := range []string{"link-dir/plain.txt", "link-file", "shadow-link", "link-dir"} {
		if _, err := p.Check(filepath.Join(allowed, name), allowed); err == nil {
			t.Errorf("%s: a link out of the allowed folder was accepted", name)
		}
	}
	if _, err := p.Check(filepath.Join(allowed, "link-dir", "id_rsa"), allowed+"\n"+secret); reasonOf(err) != ReasonDenyList {
		t.Errorf("a denied name behind a link even when the target folder is allowed: %v", err)
	}
	// the allow root is resolved too: allowing a link to a folder allows that folder
	if _, err := p.Check(filepath.Join(dir, "alias", "x.txt"), filepath.Join(dir, "alias")); err != nil {
		t.Errorf("allowed root given as a link: %v", err)
	}
	// a path that does not exist yet (an upload destination) resolves through its existing prefix
	if got, err := p.Check(filepath.Join(allowed, "link-dir", "new.txt"), allowed); err == nil {
		t.Errorf("a not-yet-existing file under a link out of the folder was accepted as %q", got)
	}
	if got, err := p.Check(filepath.Join(allowed, "sub", "new.txt"), allowed); err != nil || got != filepath.Join(allowed, "sub", "new.txt") {
		t.Errorf("new file in an allowed folder: %q %v", got, err)
	}
}

func TestAgentStateDirectoryIsAlwaysDenied(t *testing.T) {
	dir := t.TempDir()
	state := filepath.Join(dir, "state")
	os.MkdirAll(state, 0o700)
	os.WriteFile(filepath.Join(state, "device.token"), []byte("tok"), 0o600)
	p := NewHostPolicy(state)
	p.GOOS = "linux"
	// even when the administrator allowed the parent, and through a link to it
	os.Symlink(state, filepath.Join(dir, "innocent"))
	for _, path := range []string{filepath.Join(state, "device.token"), filepath.Join(dir, "innocent", "device.token")} {
		if _, err := p.Check(path, dir); reasonOf(err) != ReasonDenyList {
			t.Errorf("%s: %v", path, err)
		}
	}
}

// CORE-AGENT-6 / Chain J: file jobs can never touch the agent's own program files, service unit or the Mesh agent's identity, whatever the allow list
// says. The red-team PoC (t_put.sh) replaced /opt/rivetit-agent/rivetit-agent with allow=/opt.
func TestAgentOwnFilesAreAlwaysDenied(t *testing.T) {
	type c struct{ goos, path, allow string }
	p := map[string]PathPolicy{"linux": {GOOS: "linux"}, "windows": {GOOS: "windows"}}
	for _, x := range []c{
		{"linux", "/opt/rivetit-agent/rivetit-agent", "/opt"},
		{"linux", "/opt/rivetit-agent/rivetit-agent.new", "/opt"},
		{"linux", "/opt/rivetit-agent/anything", "/opt/rivetit-agent"},
		{"linux", "/etc/systemd/system/rivetit-agent.service", "/etc/systemd"},
		{"linux", "/etc/systemd/system/rivetit-agent.service.d/override.conf", "/etc/systemd"},
		{"linux", "/etc/systemd/system/multi-user.target.wants/rivetit-agent.service", "/etc/systemd"},
		{"linux", "/lib/systemd/system/rivetit-agent.service", "/lib/systemd"},
		{"linux", "/usr/lib/systemd/system/rivetit-agent.service", "/usr/lib"},
		{"linux", "/usr/local/bin/rivetit-agent", "/usr/local"},
		{"linux", "/usr/local/bin/rivetit-agent.prev", "/usr/local"},
		{"linux", "/usr/local/bin/rivetit-agent.failed", "/usr/local"},
		{"linux", "/usr/local/mesh_services/meshagent/meshagent", "/usr/local"},
		{"linux", "/usr/local/mesh_services/meshagent/meshagent.db", "/usr/local"},
		{"linux", "/opt/meshagent-acme/meshagent", "/opt"},
		{"linux", "/srv/elsewhere/meshagent.msh", "/srv"},
		{"linux", "/etc/systemd/system/meshagent.service", "/etc/systemd"},
		{"windows", `C:\Program Files\RivetIT\Agent\rivetit-agent.exe`, `C:\Program Files`},
		{"windows", `C:\Program Files\RivetIT\Agent\rivetit-agent.exe.new`, `C:\Program Files`},
		{"windows", `C:\Program Files\RivetIT\Agent\other.dll`, `C:\Program Files`},
		{"windows", `C:\Program Files\Mesh Agent\MeshAgent.exe`, `C:\Program Files`},
		{"windows", `C:\Program Files (x86)\Mesh Agent\MeshAgent.db`, `C:\Program Files (x86)`},
		{"windows", `D:\Tools\RIVETIT-AGENT.EXE.PREV`, `D:\Tools`},
	} {
		if _, err := p[x.goos].Check(x.path, x.allow); reasonOf(err) != ReasonDenyList {
			t.Errorf("%s %s (allow %s): %v", x.goos, x.path, x.allow, err)
		}
	}
	// not over-broad: the rest of a shared folder, other units and other programs stay usable when allowed
	for _, x := range []c{
		{"linux", "/usr/local/bin/other-tool", "/usr/local"},
		{"linux", "/etc/systemd/system/backup.service", "/etc/systemd"},
		{"linux", "/opt/app/rivetit-agent.conf", "/opt/app"},
		{"windows", `C:\Program Files\Other\app.exe`, `C:\Program Files`},
	} {
		if _, err := p[x.goos].Check(x.path, x.allow); err != nil {
			t.Errorf("%s %s (allow %s) refused: %v", x.goos, x.path, x.allow, err)
		}
	}
}

// The install folder is found from the running executable, so a non-default --install-dir is covered; a binary in a shared folder denies only itself.
func TestAgentProgramDenyFollowsTheExecutable(t *testing.T) {
	custom := PathPolicy{GOOS: "linux", ExtraDeny: AgentProgramDeny("linux", "/srv/tools/rivetit-custom/agent-bin")}
	for _, path := range []string{"/srv/tools/rivetit-custom/agent-bin", "/srv/tools/rivetit-custom/agent-bin.new", "/srv/tools/rivetit-custom/agent-bin.prev",
		"/srv/tools/rivetit-custom/agent-bin.failed", "/srv/tools/rivetit-custom/helper.sh"} {
		if _, err := custom.Check(path, "/srv/tools"); reasonOf(err) != ReasonDenyList {
			t.Errorf("%s: %v", path, err)
		}
	}
	shared := PathPolicy{GOOS: "linux", ExtraDeny: AgentProgramDeny("linux", "/usr/bin/agent-bin")}
	if _, err := shared.Check("/usr/bin/agent-bin.prev", "/usr/bin"); reasonOf(err) != ReasonDenyList {
		t.Errorf("sibling of a binary in a shared folder: %v", err)
	}
	if _, err := shared.Check("/usr/bin/other", "/usr/bin"); err != nil {
		t.Errorf("a shared folder was denied as a whole: %v", err)
	}
	win := PathPolicy{GOOS: "windows", ExtraDeny: AgentProgramDeny("windows", `E:\Apps\RivetIT\Agent\rivetit-agent.exe`)}
	if _, err := win.Check(`E:\Apps\RivetIT\Agent\x.txt`, `E:\Apps`); reasonOf(err) != ReasonDenyList {
		t.Errorf("custom Windows install folder: %v", err)
	}
	if _, err := win.Check(`E:\Apps\Other\x.txt`, `E:\Apps`); err != nil {
		t.Errorf("a neighbouring folder was denied: %v", err)
	}
	// the host policy adds the running executable (here the test binary) and its siblings
	exe, err := os.Executable()
	if err != nil {
		t.Skip(err)
	}
	host := NewHostPolicy("")
	host.GOOS = "linux"
	for _, path := range []string{exe, exe + ".new", exe + ".prev"} {
		if _, err := host.Check(path, filepath.Dir(exe)); reasonOf(err) != ReasonDenyList {
			t.Errorf("host policy, running executable %s: %v", path, err)
		}
	}
}
