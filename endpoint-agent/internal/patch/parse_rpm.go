package patch

import (
	"regexp"
	"strings"
)

var rpmArches = map[string]bool{"x86_64": true, "aarch64": true, "noarch": true, "i386": true, "i486": true, "i586": true, "i686": true, "armv7hl": true, "armv7l": true, "armhfp": true, "ppc64": true, "ppc64le": true, "s390x": true, "riscv64": true, "src": true}

// rpmUpdate is one row of `dnf check-update`.
type rpmUpdate struct {
	Name, Arch, Version, Repo string
}

// ID is the dnf id of the package, name.arch.
func (r rpmUpdate) ID() string { return r.Name + "." + r.Arch }

// splitNameArch splits "openssl.x86_64" at the last dot when what follows is a known architecture.
func splitNameArch(s string) (name, arch string, ok bool) {
	i := strings.LastIndexByte(s, '.')
	if i <= 0 || !rpmArches[s[i+1:]] {
		return "", "", false
	}
	return s[:i], s[i+1:], true
}

// parseCheckUpdate parses `dnf -q check-update` / `yum -q check-update`: "name.arch  version  repo" rows. A row whose first column wrapped (older yum
// puts a long name on a line of its own) is joined with the next line. The "Obsoleting Packages" section ends the list; headers, "Security: ..."
// notes and anything else that does not start with a name.arch column are ignored.
func parseCheckUpdate(out string) []rpmUpdate {
	var res []rpmUpdate
	pending := ""
	for _, l := range strings.Split(out, "\n") {
		l = strings.TrimRight(l, "\r ")
		if strings.HasPrefix(l, "Obsoleting Packages") {
			break
		}
		f := strings.Fields(l)
		if pending != "" {
			f = append([]string{pending}, f...)
			pending = ""
		}
		if len(f) == 1 {
			if _, _, ok := splitNameArch(f[0]); ok {
				pending = f[0]
			}
			continue
		}
		if len(f) != 3 {
			continue
		}
		name, arch, ok := splitNameArch(f[0])
		if !ok || !mixedNameRe.MatchString(f[0]) {
			continue
		}
		res = append(res, rpmUpdate{Name: name, Arch: arch, Version: f[1], Repo: f[2]})
	}
	return res
}

// advisory is the best (highest severity) security advisory known for a package.
type advisory struct {
	ID, Severity string // severity: critical|important|moderate|low|unspecified
}

var sevRank = map[string]int{"unspecified": 0, "low": 1, "moderate": 2, "important": 3, "critical": 4}

func mapSeverity(s string) string {
	s = strings.ToLower(strings.TrimSuffix(strings.TrimSuffix(s, "/Sec."), "/sec."))
	switch s {
	case "critical", "important", "moderate", "low":
		return s
	}
	return "unspecified"
}

var nevraRe = regexp.MustCompile(`^(.+)-[^-]+-[^-]+\.([A-Za-z0-9_]+)$`)

// nevraToID turns "openssl-3.0.7-27.el9.x86_64" into "openssl.x86_64".
func nevraToID(s string) (string, bool) {
	m := nevraRe.FindStringSubmatch(s)
	if m == nil || !rpmArches[m[2]] {
		return "", false
	}
	return m[1] + "." + m[2], true
}

// parseUpdateinfo parses `dnf updateinfo list --security` (also yum's). dnf 4 prints "RHSA-2024:1234 Important/Sec. openssl-3.0.7-27.el9.x86_64",
// dnf 5 prints "FEDORA-2024-abc security Important openssl-3.2.1-1.fc40.x86_64"; the last column is the package in both. Only advisories of the
// security type count; the highest severity per package wins.
func parseUpdateinfo(out string) map[string]advisory {
	res := map[string]advisory{}
	for _, l := range strings.Split(out, "\n") {
		f := strings.Fields(l)
		if len(f) < 3 {
			continue
		}
		id, ok := nevraToID(f[len(f)-1])
		if !ok {
			continue
		}
		sev, security := "unspecified", false
		mid := f[1 : len(f)-1]
		if len(mid) == 1 && strings.Contains(strings.ToLower(mid[0]), "/sec") { // "Important/Sec."
			security, sev = true, mapSeverity(strings.SplitN(mid[0], "/", 2)[0])
		} else if len(mid) >= 2 && strings.EqualFold(mid[0], "security") { // "security Important"
			security, sev = true, mapSeverity(mid[1])
		} else if len(mid) == 1 && strings.EqualFold(mid[0], "security") {
			security = true
		}
		if !security {
			continue
		}
		if cur, has := res[id]; !has || sevRank[sev] > sevRank[cur.Severity] {
			res[id] = advisory{ID: f[0], Severity: sev}
		}
	}
	return res
}

// parseRpmVersions parses `rpm -q --qf '%{NAME}.%{ARCH} %|EPOCH?{%{EPOCH}:}|%{VERSION}-%{RELEASE}\n'` into id -> versions (a kernel has several).
// "package x is not installed" lines are skipped. A leading "0:" is dropped so that it compares equal to dnf's epoch-less form.
func parseRpmVersions(out string) map[string][]string {
	res := map[string][]string{}
	for _, l := range strings.Split(out, "\n") {
		f := strings.Fields(l)
		if len(f) != 2 {
			continue
		}
		if _, _, ok := splitNameArch(f[0]); !ok {
			continue
		}
		res[f[0]] = append(res[f[0]], strings.TrimPrefix(f[1], "0:"))
	}
	return res
}

// parseDnfSim looks for removals in the transaction summary of `dnf --assumeno upgrade`: the "Removing:" / "Removing dependent packages:" sections of
// the table and the "Remove  N Packages" line of the summary.
func dnfWouldRemove(out string) bool {
	for _, l := range strings.Split(out, "\n") {
		t := strings.TrimSpace(l)
		if strings.HasPrefix(t, "Removing:") || strings.HasPrefix(t, "Removing dependent packages") || strings.HasPrefix(t, "Removing unused dependencies") {
			return true
		}
		if f := strings.Fields(t); len(f) >= 3 && f[0] == "Remove" && allDigits(f[1]) && strings.HasPrefix(f[2], "Package") {
			return true
		}
	}
	return false
}
