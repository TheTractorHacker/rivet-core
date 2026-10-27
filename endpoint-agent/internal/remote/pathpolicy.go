// Package remote implements the RMM Phase 6 remote tools of the agent: service
// control, process listing and killing, and file transfer. Every function that
// decides what is allowed (names, pids, paths) is pure and takes the target OS
// as data so the hostile-input tests run on any host; the code that touches the
// operating system sits behind small interfaces (Runner, ServiceBackend,
// ProcBackend, api transport) with platform files for Linux and Windows.
//
// The Windows implementations are cross-compiled and vetted only. They are never
// executed in this repository's test runs.
package remote

import (
	"errors"
	"io/fs"
	"os"
	"path"
	"path/filepath"
	"regexp"
	"runtime"
	"strings"
	"unicode/utf8"
)

// MaxPathLen bounds a path accepted from a job.
const MaxPathLen = 1000

// maxRoots bounds the allow list.
const maxRoots = 64

// Stable refusal reasons ("path_denied:<reason>").
const (
	ReasonNotAbsolute     = "not_absolute"
	ReasonTraversal       = "traversal"
	ReasonNotClean        = "not_clean"
	ReasonBadChars        = "bad_chars"
	ReasonTooLong         = "too_long"
	ReasonNotAllowed      = "not_allowed"
	ReasonDenyList        = "deny_list"
	ReasonNoAllowedRoots  = "no_allowed_roots"
	ReasonResolveFailed   = "resolve_failed"
	ReasonADS             = "alternate_stream"
	ReasonShortName       = "short_name"
	ReasonReservedName    = "reserved_name"
	ReasonUNC             = "unc_path"
	reasonUnsupportedGOOS = "unsupported_os"
)

// PathError is a path-policy refusal. Error() is the job detail code; it never
// contains file contents.
type PathError struct{ Reason string }

func (e *PathError) Error() string { return "path_denied:" + e.Reason }

// PathPolicy decides whether a job may touch a path. GOOS selects the path
// syntax and the deny list ("linux" or "windows"; "" = the running OS; every
// other value is treated as unsupported and refuses all paths).
type PathPolicy struct {
	GOOS string
	// ExtraDeny are absolute files or directories (in the syntax of GOOS) that are
	// always off limits: the agent's own state directory.
	ExtraDeny []string
	// Resolve returns the symlink-free form of an EXISTING path (filepath.EvalSymlinks
	// in production). nil skips symlink resolution (pure tests).
	Resolve func(string) (string, error)
}

// NewHostPolicy is the policy of this machine: real symlink resolution, and the
// agent's state directory (device token, enrollment secrets) always denied.
func NewHostPolicy(stateDir string) PathPolicy {
	p := PathPolicy{GOOS: runtime.GOOS, Resolve: hostResolve}
	if stateDir != "" {
		if abs, err := filepath.Abs(stateDir); err == nil {
			p.ExtraDeny = append(p.ExtraDeny, abs)
			if r, err := hostResolve(abs); err == nil {
				p.ExtraDeny = append(p.ExtraDeny, r)
			}
		}
	}
	if runtime.GOOS == "windows" {
		pd := os.Getenv("ProgramData")
		if pd == "" {
			pd = `C:\ProgramData`
		}
		p.ExtraDeny = append(p.ExtraDeny, pd+`\RivetIT`)
	}
	return p
}

func (p PathPolicy) goos() string {
	if p.GOOS == "" {
		return runtime.GOOS
	}
	return p.GOOS
}

// parsedPath is a validated absolute path split into elements. For Windows the
// root is the lower-case drive ("c:") and elems keep their original case.
type parsedPath struct {
	root  string
	elems []string
	win   bool
}

func (pp parsedPath) String() string {
	if pp.win {
		return strings.ToUpper(pp.root[:1]) + pp.root[1:] + `\` + strings.Join(pp.elems, `\`)
	}
	return "/" + strings.Join(pp.elems, "/")
}

func (pp parsedPath) key() []string {
	k := make([]string, len(pp.elems))
	for i, e := range pp.elems {
		if pp.win {
			e = strings.ToLower(e)
		}
		k[i] = e
	}
	return k
}

var (
	winDriveRE    = regexp.MustCompile(`^[A-Za-z]:\\`)
	winShortRE    = regexp.MustCompile(`~[0-9]`)
	winReservedRE = regexp.MustCompile(`(?i)^(con|prn|aux|nul|com[0-9]|lpt[0-9])$`)
)

const winBadChars = `<>"|?*`

func hasControl(s string) bool {
	if !utf8.ValidString(s) {
		return true
	}
	for _, r := range s {
		if r < 0x20 || r == 0x7f || (r >= 0x80 && r < 0xa0) {
			return true
		}
	}
	return false
}

// parse validates the syntax of an absolute path WITHOUT normalising it: a
// path whose cleaned form differs from the input (other than one trailing
// separator) is refused, as is any ".." element.
func parse(goos, s string) (parsedPath, string) {
	switch goos {
	case "linux":
		return parseLinux(s)
	case "windows":
		return parseWindows(s)
	}
	return parsedPath{}, reasonUnsupportedGOOS
}

func parseLinux(s string) (parsedPath, string) {
	if len(s) > MaxPathLen {
		return parsedPath{}, ReasonTooLong
	}
	if hasControl(s) {
		return parsedPath{}, ReasonBadChars
	}
	if !strings.HasPrefix(s, "/") {
		return parsedPath{}, ReasonNotAbsolute
	}
	t := s
	if len(t) > 1 {
		t = strings.TrimSuffix(t, "/")
	}
	for _, e := range strings.Split(t, "/") {
		if e == ".." {
			return parsedPath{}, ReasonTraversal
		}
	}
	if path.Clean(t) != t {
		return parsedPath{}, ReasonNotClean
	}
	pp := parsedPath{root: "/"}
	if t != "/" {
		pp.elems = strings.Split(t[1:], "/")
	}
	return pp, ""
}

func parseWindows(s string) (parsedPath, string) {
	if len(s) > MaxPathLen {
		return parsedPath{}, ReasonTooLong
	}
	if hasControl(s) {
		return parsedPath{}, ReasonBadChars
	}
	if strings.HasPrefix(s, `\\`) || strings.HasPrefix(s, "//") {
		return parsedPath{}, ReasonUNC
	}
	if !winDriveRE.MatchString(s) {
		return parsedPath{}, ReasonNotAbsolute
	}
	if strings.Contains(s, "/") {
		return parsedPath{}, ReasonNotClean
	}
	pp := parsedPath{root: strings.ToLower(s[:2]), win: true}
	rest := s[3:]
	rest = strings.TrimSuffix(rest, `\`)
	if rest == "" {
		return pp, ""
	}
	for _, e := range strings.Split(rest, `\`) {
		switch {
		case e == "":
			return parsedPath{}, ReasonNotClean
		case e == ".":
			return parsedPath{}, ReasonNotClean
		case e == "..":
			return parsedPath{}, ReasonTraversal
		case strings.Contains(e, ":"):
			return parsedPath{}, ReasonADS
		case strings.ContainsAny(e, winBadChars):
			return parsedPath{}, ReasonBadChars
		case strings.HasSuffix(e, ".") || strings.HasSuffix(e, " "):
			return parsedPath{}, ReasonNotClean
		case winShortRE.MatchString(e):
			return parsedPath{}, ReasonShortName
		}
		base := e
		if i := strings.IndexByte(base, '.'); i >= 0 {
			base = base[:i]
		}
		if winReservedRE.MatchString(strings.TrimSpace(base)) {
			return parsedPath{}, ReasonReservedName
		}
		pp.elems = append(pp.elems, e)
	}
	return pp, ""
}

// under reports whether p lies at or below root, compared by whole elements.
func under(p, root parsedPath) bool {
	if p.win != root.win || p.root != root.root || len(root.elems) > len(p.elems) {
		return false
	}
	pk, rk := p.key(), root.key()
	for i := range rk {
		if pk[i] != rk[i] {
			return false
		}
	}
	return true
}

var (
	linuxDenyExact = [][]string{
		{"etc", "shadow"}, {"etc", "shadow-"}, {"etc", "gshadow"}, {"etc", "gshadow-"},
		{"etc", "sudoers"}, {"etc", "security", "opasswd"},
	}
	linuxDenyTrees = [][]string{
		{"etc", "sudoers.d"}, {"proc"}, {"sys"}, {"dev"}, {"run", "secrets"}, {"root", ".ssh"},
	}
	secretPrefixes = []string{"id_rsa", "id_ed25519", "id_ecdsa", "id_dsa"}
	secretSuffixes = []string{".pem", ".key", ".p12", ".pfx", ".kdbx", ".keystore"}
)

func hasPrefixElems(k []string, prefix []string) bool {
	if len(prefix) > len(k) {
		return false
	}
	for i := range prefix {
		if k[i] != prefix[i] {
			return false
		}
	}
	return true
}

func secretName(lower string) bool {
	for _, pre := range secretPrefixes {
		if strings.HasPrefix(lower, pre) {
			return true
		}
	}
	for _, suf := range secretSuffixes {
		if strings.HasSuffix(lower, suf) {
			return true
		}
	}
	return false
}

// denied is the agent-side deny list; it is always on and independent of the
// server. Deny wins over allow.
func (p PathPolicy) denied(pp parsedPath) bool {
	k := pp.key()
	for _, d := range p.ExtraDeny {
		if dp, why := parse(p.goos(), d); why == "" && under(pp, dp) {
			return true
		}
	}
	if pp.win {
		return windowsDenied(k)
	}
	return linuxDenied(k)
}

func linuxDenied(k []string) bool {
	for _, x := range linuxDenyExact {
		if hasPrefixElems(k, x) && len(k) == len(x) {
			return true
		}
	}
	for _, x := range linuxDenyTrees {
		if hasPrefixElems(k, x) {
			return true
		}
	}
	if len(k) >= 2 && k[0] == "etc" && strings.HasPrefix(k[1], "rivetit") {
		return true
	}
	if len(k) >= 3 && k[0] == "etc" && k[1] == "ssh" && strings.HasPrefix(k[2], "ssh_host_") {
		return true
	}
	for _, e := range k {
		if e == ".ssh" || e == ".gnupg" || secretName(strings.ToLower(e)) {
			return true
		}
	}
	return false
}

func windowsDenied(k []string) bool {
	for i, e := range k {
		switch e {
		case ".ssh", ".gnupg", "ntds.dit", "pagefile.sys", "hiberfil.sys", "swapfile.sys":
			return true
		}
		if secretName(e) {
			return true
		}
		if i+1 < len(k) {
			n := k[i+1]
			if (e == "system32" || e == "sysnative") && n == "config" {
				return true
			}
			if e == "windows" && (n == "ntds" || n == "repair") {
				return true
			}
			if e == "programdata" && n == "ssh" {
				return true
			}
		}
	}
	return false
}

// parseRoots reads the newline-separated allow list. Lines that are not valid
// absolute paths are ignored (they allow nothing).
func (p PathPolicy) parseRoots(allow string) []parsedPath {
	var out []parsedPath
	for _, line := range strings.Split(allow, "\n") {
		line = strings.TrimSpace(strings.TrimSuffix(line, "\r"))
		if line == "" {
			continue
		}
		rp, why := parse(p.goos(), line)
		if why != "" {
			continue
		}
		out = append(out, rp)
		if len(out) >= maxRoots {
			break
		}
	}
	return out
}

// resolvePrefix resolves the symlinks of the longest existing prefix of pp and
// re-appends the rest (which does not exist, so holds no links).
func (p PathPolicy) resolvePrefix(pp parsedPath) (string, error) {
	sep, root := "/", "/"
	if pp.win {
		sep, root = `\`, pp.root+`\`
	}
	var lastErr error
	for i := len(pp.elems); i >= 0; i-- {
		cur := root + strings.Join(pp.elems[:i], sep)
		r, err := p.Resolve(cur)
		if err == nil {
			if i < len(pp.elems) {
				r = strings.TrimRight(r, sep) + sep + strings.Join(pp.elems[i:], sep)
			}
			return r, nil
		}
		if !errors.Is(err, fs.ErrNotExist) {
			return "", err
		}
		lastErr = err
	}
	return "", lastErr
}

// Check applies the whole policy to path against the allow list and returns the
// symlink-resolved path that the caller must use for all I/O. The error is a
// *PathError.
func (p PathPolicy) Check(rawPath, allow string) (string, error) {
	goos := p.goos()
	pp, why := parse(goos, rawPath)
	if why != "" {
		return "", &PathError{why}
	}
	roots := p.parseRoots(allow)
	if len(roots) == 0 {
		return "", &PathError{ReasonNoAllowedRoots}
	}
	if p.denied(pp) {
		return "", &PathError{ReasonDenyList}
	}
	if p.Resolve != nil {
		rs, err := p.resolvePrefix(pp)
		if err != nil {
			return "", &PathError{ReasonResolveFailed}
		}
		rp, why := parse(goos, rs)
		if why != "" {
			return "", &PathError{why}
		}
		if p.denied(rp) {
			return "", &PathError{ReasonDenyList}
		}
		pp = rp
		for i, r := range roots {
			if rr, err := p.resolvePrefix(r); err == nil {
				if np, why := parse(goos, rr); why == "" {
					roots[i] = np
				}
			}
		}
	}
	for _, r := range roots {
		if under(pp, r) {
			return pp.String(), nil
		}
	}
	return "", &PathError{ReasonNotAllowed}
}
