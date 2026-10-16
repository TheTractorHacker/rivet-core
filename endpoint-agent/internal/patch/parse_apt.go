package patch

import (
	"regexp"
	"strings"
)

// aptPkg is one line of `apt list --upgradable`.
type aptPkg struct {
	Name, Arch, New, Old string
	Suites               []string
}

// `name/jammy-updates,jammy-security 3.0.2-0ubuntu1.15 amd64 [upgradable from: 3.0.2-0ubuntu1.12]`
var aptListRe = regexp.MustCompile(`^([a-z0-9][a-z0-9+.\-]*)/(\S+) (\S+) (\S+) \[upgradable from: (\S+)\]$`)

// parseAptList parses `apt list --upgradable` (LC_ALL=C). The "Listing..." banner, warnings and lines of any other shape are ignored.
func parseAptList(out string) []aptPkg {
	var res []aptPkg
	for _, l := range strings.Split(out, "\n") {
		m := aptListRe.FindStringSubmatch(strings.TrimRight(l, "\r "))
		if m == nil {
			continue
		}
		res = append(res, aptPkg{Name: m[1], Suites: strings.Split(m[2], ","), New: m[3], Arch: m[4], Old: m[5]})
	}
	return res
}

// parseHolds reads `apt-mark showhold` (one package per line, possibly name:arch).
func parseHolds(out string) map[string]bool {
	h := map[string]bool{}
	for _, l := range strings.Split(out, "\n") {
		if l = strings.TrimSpace(l); l != "" && ValidLinuxID(l) {
			h[l] = true
		}
	}
	return h
}

// aptPolicy is what `apt-cache policy` says about one package: its candidate and whether that candidate is served from a security origin.
type aptPolicy struct {
	Installed, Candidate string
	Security             bool
}

func allDigits(s string) bool {
	if s == "" {
		return false
	}
	for _, c := range s {
		if c < '0' || c > '9' {
			return false
		}
	}
	return true
}

func securityOrigin(line string) bool {
	return strings.Contains(line, "-security") || strings.Contains(line, "security.ubuntu.com") || strings.Contains(line, "security.debian.org")
}

// parseAptPolicy parses `apt-cache policy a b c` into a map by the header name (name or name:arch). The candidate counts as a security update when
// one of the origin lines under the candidate's version names a security suite or archive.
func parseAptPolicy(out string) map[string]aptPolicy {
	res := map[string]aptPolicy{}
	var cur string
	var p aptPolicy
	inCand := false
	flush := func() {
		if cur != "" {
			res[cur] = p
		}
	}
	for _, raw := range strings.Split(out, "\n") {
		l := strings.TrimRight(raw, "\r ")
		if l == "" {
			continue
		}
		if l[0] != ' ' { // a package header: "name:" or "name:arch:"
			flush()
			cur, p, inCand = strings.TrimSuffix(strings.TrimSpace(l), ":"), aptPolicy{}, false
			continue
		}
		t := strings.TrimSpace(l)
		switch {
		case strings.HasPrefix(t, "Installed:"):
			p.Installed = strings.TrimSpace(strings.TrimPrefix(t, "Installed:"))
			continue
		case strings.HasPrefix(t, "Candidate:"):
			p.Candidate = strings.TrimSpace(strings.TrimPrefix(t, "Candidate:"))
			continue
		case t == "Version table:":
			continue
		}
		f := strings.Fields(strings.TrimPrefix(t, "*** "))
		switch {
		case len(f) == 2 && allDigits(f[1]): // a version line: "3.0.2-0ubuntu1.15 500"
			inCand = f[0] == p.Candidate && p.Candidate != ""
		case len(f) >= 2 && allDigits(f[0]): // an origin line: "500 http://... jammy-security/main amd64 Packages"
			if inCand && securityOrigin(t) {
				p.Security = true
			}
		}
	}
	flush()
	return res
}

// aptSim is the result of `apt-get -s install --only-upgrade ...`.
type aptSim struct {
	Inst   map[string]bool // packages that would be installed or upgraded
	Remove []string        // packages that would be removed
}

var (
	aptInstRe = regexp.MustCompile(`^Inst (\S+)`)
	aptRemvRe = regexp.MustCompile(`^Remv (\S+)`)
)

func parseAptSim(out string) aptSim {
	s := aptSim{Inst: map[string]bool{}}
	for _, l := range strings.Split(out, "\n") {
		if m := aptInstRe.FindStringSubmatch(l); m != nil {
			s.Inst[m[1]] = true
		} else if m := aptRemvRe.FindStringSubmatch(l); m != nil {
			s.Remove = append(s.Remove, m[1])
		}
	}
	return s
}

// parseDpkgVersions parses `dpkg-query -W -f='${Package}:${Architecture} ${Version}\n'` into name -> versions and name:arch -> versions.
func parseDpkgVersions(out string) map[string][]string {
	res := map[string][]string{}
	for _, l := range strings.Split(out, "\n") {
		f := strings.Fields(l)
		if len(f) != 2 {
			continue
		}
		res[f[0]] = append(res[f[0]], f[1])
		if i := strings.LastIndexByte(f[0], ':'); i > 0 {
			res[f[0][:i]] = append(res[f[0][:i]], f[1])
		}
	}
	return res
}
