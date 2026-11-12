package mesh

import (
	"context"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"sync"
	"time"
)

// Discovery of an ALREADY-INSTALLED MeshAgent that is not in a default location (renamed or portable installs, a custom service name, 32-bit registry views).
// It only widens where the agent is looked for: what is reported is still the existing `mesh_agent` block (installed, version, node_id), and a found executable
// goes through the same checks as a default one (the trust check on Linux, the same -nodeid / .msh / registry reading). Nothing here installs, changes or contacts
// anything: it reads the service manager's records and directory listings.
//
// UNVERIFIED against real machines: the service and unit name patterns and the .msh/registry locations below come from MeshCentral's documented installers and
// the agent's behaviour, and are covered by table-driven tests on fixtures only.

// DirEntry is one name of a directory listing (a hook so the tests need no real file system).
type DirEntry struct {
	Name string
	Dir  bool
}

const (
	maxDiscovered    = 12
	maxDirEntries    = 200
	maxUnitBytes     = 64 * 1024
	discoveryRefresh = 10 * time.Minute
)

var (
	// the registry key lines `reg query ... /k` prints, and the value lines of `reg query <key> /v <name>`
	regKeyLineRe   = regexp.MustCompile(`(?i)^\s*HKEY_LOCAL_MACHINE\\SYSTEM\\CurrentControlSet\\Services\\([^\\\r\n]+)\s*$`)
	regValueLineRe = regexp.MustCompile(`(?i)^\s*(ImagePath|InstallLocation|DisplayIcon|UninstallString)\s+REG_(?:EXPAND_)?SZ\s+(.*?)\s*$`)
	execStartRe    = regexp.MustCompile(`(?m)^\s*ExecStart\s*=\s*[-@+!:]*\s*(\S+)`)
	workDirRe      = regexp.MustCompile(`(?m)^\s*WorkingDirectory\s*=\s*(\S+)`)
	initScriptRe   = regexp.MustCompile(`(/[A-Za-z0-9_./-]*[Mm]esh[Aa]gent[A-Za-z0-9_.-]*)`)
	serviceNameRe  = regexp.MustCompile(`^[A-Za-z0-9 ._-]{1,100}$`)
)

// meshNamed reports whether a name looks like a Mesh agent's (the only names that are ever followed).
func meshNamed(s string) bool { return strings.Contains(strings.ToLower(s), "mesh") }

// ParseImagePath returns the executable of a Windows service's ImagePath: a quoted path, or the text up to the first ".exe". Environment references are not expanded.
func ParseImagePath(v string) string {
	v = strings.TrimSpace(v)
	if strings.HasPrefix(v, `"`) {
		if end := strings.Index(v[1:], `"`); end >= 0 {
			return v[1 : 1+end]
		}
		return ""
	}
	if i := strings.Index(strings.ToLower(v), ".exe"); i >= 0 {
		return v[:i+4]
	}
	return ""
}

// ParseRegistryValues reads `reg query <key>` output into value name (lower case) -> text.
func ParseRegistryValues(out string) map[string]string {
	m := map[string]string{}
	for _, l := range strings.Split(out, "\n") {
		if r := regValueLineRe.FindStringSubmatch(strings.TrimRight(l, "\r")); r != nil && len(m) < 16 {
			m[strings.ToLower(r[1])] = r[2]
		}
	}
	return m
}

// ParseUnitExec returns the executable a systemd unit runs (its ExecStart, resolved against WorkingDirectory when relative), or "".
func ParseUnitExec(unit string) string {
	m := execStartRe.FindStringSubmatch(unit)
	if m == nil {
		return ""
	}
	p := strings.Trim(m[1], `"'`)
	if filepath.IsAbs(p) {
		return filepath.Clean(p)
	}
	if w := workDirRe.FindStringSubmatch(unit); w != nil && filepath.IsAbs(strings.Trim(w[1], `"'`)) {
		return filepath.Join(strings.Trim(w[1], `"'`), p)
	}
	return ""
}

// candidateOK keeps a discovered path to an absolute path that is not under a user's profile or a temporary directory (Windows has no owner check, so the
// location is the check; Linux additionally runs the trust check before anything is executed).
func (p *Probe) candidateOK(path string) bool {
	if path == "" || len(path) > 400 {
		return false
	}
	if p.GOOS == "windows" {
		l := strings.ToLower(path)
		if len(l) < 3 || l[1] != ':' || (l[2] != '\\' && l[2] != '/') || strings.HasSuffix(l, ".exe") == false {
			return false
		}
		for _, bad := range []string{`\users\`, `\temp\`, `\appdata\`, `\downloads\`} {
			if strings.Contains(l, bad) {
				return false
			}
		}
		return true
	}
	return filepath.IsAbs(path) && !strings.HasPrefix(path, "/tmp/") && !strings.HasPrefix(path, "/var/tmp/") && !strings.HasPrefix(path, "/home/") && !strings.HasPrefix(path, "/dev/shm/")
}

type discoveryMemo struct {
	mu   sync.Mutex
	at   time.Time
	goos string
	list []string
}

// realDiscovery caches the discovery of the real machine: the agent's check-in loop calls Found() often, and a registry or directory sweep must not run each time.
var realDiscovery discoveryMemo

func (p Probe) discovered() []string {
	if !p.discover {
		return nil
	}
	if !p.realFS {
		return p.sweep()
	}
	realDiscovery.mu.Lock()
	defer realDiscovery.mu.Unlock()
	if realDiscovery.goos == p.GOOS && !realDiscovery.at.IsZero() && time.Since(realDiscovery.at) < discoveryRefresh {
		return realDiscovery.list
	}
	realDiscovery.list = p.sweep()
	realDiscovery.at, realDiscovery.goos = time.Now(), p.GOOS
	return realDiscovery.list
}

// sweep lists the candidate executables the service manager and the usual directories point at, most specific first, without duplicates.
func (p Probe) sweep() []string {
	var out []string
	seen := map[string]bool{}
	add := func(c string) {
		if len(out) < maxDiscovered && p.candidateOK(c) && !seen[strings.ToLower(c)] {
			seen[strings.ToLower(c)] = true
			out = append(out, c)
		}
	}
	if p.GOOS == "windows" {
		p.sweepWindows(add)
	} else {
		p.sweepLinux(add)
	}
	return out
}

func (p Probe) reg(args ...string) string {
	ctx, cancel := context.WithTimeout(context.Background(), p.Timeout)
	defer cancel()
	exit, out, err := p.Run(ctx, "", "reg.exe", args)
	if err != nil || exit != 0 {
		return ""
	}
	return out
}

func (p Probe) sweepWindows(add func(string)) {
	// 1. Services whose name contains "mesh" (a custom --meshServiceName included): their ImagePath is the executable, wherever it was put or whatever it was renamed to.
	var names []string
	for _, l := range strings.Split(p.reg("query", `HKLM\SYSTEM\CurrentControlSet\Services`, "/f", "mesh", "/k"), "\n") {
		if m := regKeyLineRe.FindStringSubmatch(strings.TrimRight(l, "\r")); m != nil && serviceNameRe.MatchString(m[1]) && meshNamed(m[1]) && len(names) < 8 {
			names = append(names, m[1])
		}
	}
	for _, n := range names {
		v := ParseRegistryValues(p.reg("query", `HKLM\SYSTEM\CurrentControlSet\Services\`+n, "/v", "ImagePath"))
		add(ParseImagePath(v["imagepath"]))
	}
	// 2. The uninstall entry, in the 64-bit and the 32-bit registry view.
	for _, view := range []string{"/reg:64", "/reg:32"} {
		v := ParseRegistryValues(p.reg("query", `HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\Mesh Agent`, view))
		if d := strings.TrimSpace(v["installlocation"]); d != "" {
			add(strings.TrimRight(d, `\/`) + `\MeshAgent.exe`)
		}
		if i := strings.TrimSpace(strings.Trim(v["displayicon"], `"`)); strings.HasSuffix(strings.ToLower(i), ".exe") {
			add(i)
		}
	}
	// 3. Directories named like a Mesh agent below the program folders, holding an executable named like one (a renamed MeshAgent.exe, a second install).
	for _, e := range []string{"ProgramFiles", "ProgramW6432", "ProgramFiles(x86)", "ProgramData", "SystemDrive"} {
		root := p.Env(e)
		if root == "" {
			continue
		}
		if e == "SystemDrive" {
			root += `\`
		}
		p.scanMeshDirs(root, `\`, add)
	}
}

func (p Probe) scanMeshDirs(root, sep string, add func(string)) {
	ds, err := p.ReadDir(root)
	if err != nil {
		return
	}
	n := 0
	for _, d := range ds {
		if !d.Dir || !meshNamed(d.Name) || n >= 10 {
			continue
		}
		n++
		dir := strings.TrimRight(root, sep) + sep + d.Name
		if fs, err := p.ReadDir(dir); err == nil {
			for i, f := range fs {
				l := strings.ToLower(f.Name)
				if i < maxDirEntries && !f.Dir && strings.Contains(l, "meshagent") && (strings.HasSuffix(l, ".exe") || sep == "/") && !strings.HasSuffix(l, ".msh") && !strings.HasSuffix(l, ".db") && !strings.HasSuffix(l, ".log") {
					add(dir + sep + f.Name)
				}
			}
		}
	}
}

func (p Probe) sweepLinux(add func(string)) {
	// 1. systemd units named like a Mesh agent (meshagent.service, or the name given with --serviceName): their ExecStart.
	for _, d := range []string{"/etc/systemd/system", "/lib/systemd/system", "/usr/lib/systemd/system"} {
		ds, err := p.ReadDir(d)
		if err != nil {
			continue
		}
		n := 0
		for _, f := range ds {
			if f.Dir || !strings.HasSuffix(f.Name, ".service") || !meshNamed(f.Name) || n >= 6 {
				continue
			}
			n++
			if b, err := p.ReadFile(d + "/" + f.Name); err == nil && len(b) < maxUnitBytes {
				add(ParseUnitExec(string(b)))
			}
		}
	}
	// 2. SysV init scripts named like a Mesh agent: the first path in them that names a meshagent file (not the script itself).
	if ds, err := p.ReadDir("/etc/init.d"); err == nil {
		n := 0
		for _, f := range ds {
			if f.Dir || !meshNamed(f.Name) || n >= 4 {
				continue
			}
			n++
			if b, err := p.ReadFile("/etc/init.d/" + f.Name); err == nil && len(b) < maxUnitBytes {
				for _, m := range initScriptRe.FindAllStringSubmatch(string(b), 8) {
					if !strings.HasPrefix(m[1], "/etc/init.d/") && !strings.HasSuffix(m[1], ".msh") && !strings.HasSuffix(m[1], ".pid") && !strings.HasSuffix(m[1], ".log") {
						add(m[1])
					}
				}
			}
		}
	}
	// 3. Directories named like a Mesh agent in the usual roots, holding a file named like one (a renamed or portable copy).
	for _, root := range []string{"/usr/local/mesh_services", "/usr/local", "/opt", "/usr/local/mesh"} {
		if root == "/usr/local/mesh" {
			if fs, err := p.ReadDir(root); err == nil {
				for i, f := range fs {
					if i < maxDirEntries && !f.Dir && strings.Contains(strings.ToLower(f.Name), "meshagent") && !strings.HasSuffix(f.Name, ".msh") {
						add(root + "/" + f.Name)
					}
				}
			}
			continue
		}
		p.scanMeshDirs(root, "/", add)
	}
}

// mshNames are the settings file names looked for next to an agent executable: the two conventional ones and the one named after the (possibly renamed) executable,
// which is how the agent finds its own `.msh`.
func mshNames(exe string) []string {
	base := exe
	if i := strings.LastIndexAny(base, `\/`); i >= 0 {
		base = base[i+1:] // either separator: the executable of a Windows machine is named with backslashes whatever this code runs on
	}
	if i := strings.LastIndex(base, "."); i > 0 && strings.EqualFold(base[i:], ".exe") {
		base = base[:i]
	}
	names := []string{"MeshAgent.msh", "meshagent.msh"}
	if base != "" && !strings.EqualFold(base, "meshagent") && !strings.Contains(base, "..") {
		names = append(names, base+".msh")
	}
	return names
}

func envFromOS(k string) string { return os.Getenv(k) }

func readDirOS(p string) ([]DirEntry, error) {
	ds, err := os.ReadDir(p)
	if err != nil {
		return nil, err
	}
	out := make([]DirEntry, 0, len(ds))
	for i, d := range ds {
		if i >= 2000 {
			break
		}
		out = append(out, DirEntry{Name: d.Name(), Dir: d.Type().IsDir()})
	}
	return out, nil
}
