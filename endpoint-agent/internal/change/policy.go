// Package change implements RMM change monitoring: it watches the Windows
// registry, configuration files, the local Administrators group and the
// scheduled tasks (Windows), and configuration files (Linux), keeps a snapshot
// of what it read, and reports what CHANGED to POST agent_changes.
//
// Everything it reads is named by a signed configuration (config.change); the
// agent verifies the signature against the key it pinned at enrolment before it
// reads anything, applies its own policy (policy.go) on top, never reads the
// credential hives or credential files, never stores or sends a value whose name
// looks like a secret, and bounds everything it keeps and sends.
//
// The platform-neutral parts (policy, redaction, diffing, file and task and
// group readers, the engine) are covered by Linux unit tests with fakes. The
// Windows registry reader and the registry change notification are
// cross-compiled and vetted only: they have never run on a Windows host.
package change

import (
	"regexp"
	"strings"
	"unicode"
	"unicode/utf8"
)

// maxTargetLen mirrors the server policy.
const maxTargetLen = 600

// LinuxRoots are the folders a file watch may sit strictly below (the same list as the server's default policy).
var LinuxRoots = []string{"/etc", "/usr/lib/systemd", "/usr/local/lib/systemd", "/lib/systemd", "/var/spool/cron", "/root/.ssh"}

// WindowsRoots are the Windows folders (below any drive) a file watch may sit in.
var WindowsRoots = []string{`\Windows\System32\drivers\etc`, `\Windows\System32\GroupPolicy`, `\Windows\System32\GroupPolicyUsers`}

var (
	reDriveLetter  = regexp.MustCompile(`^[A-Za-z]:\\`)
	reUserSID      = regexp.MustCompile(`^S-1-[0-9-]{1,80}$`)
	reControlSet   = regexp.MustCompile(`^(?:CURRENTCONTROLSET|CONTROLSET\d{3})$`)
	reHomeAuthKeys = regexp.MustCompile(`^/home/[A-Za-z0-9._-]{1,64}/\.ssh/authorized_keys2?$`)
	reShadow       = regexp.MustCompile(`^/etc/(?:shadow|gshadow)-?(?:/|$)`)
	rePrivateDir   = regexp.MustCompile(`/private(?:/|$)`)
	reSecretBase   = regexp.MustCompile(`^(?:id_(?:rsa|dsa|ecdsa|ed25519)(?:_sk)?|\.netrc|\.pgpass|\.htpasswd|\.git-credentials|credentials|secrets?(?:\..*)?)$`)
	reSecretExt    = regexp.MustCompile(`\.(?:key|pem|p12|pfx|jks|keystore|keytab|kdbx|gpg|asc|secret|secrets)$`)
	reWinSecretExt = regexp.MustCompile(`\.(?:pfx|p12|key|pem|kdbx)$`)
)

var linuxDeniedNeedles = []string{"/ssl/private", "/letsencrypt", "/networkmanager/system-connections", "/wireguard", "/openvpn", "/ppp/chap-secrets", "/ppp/pap-secrets",
	"/samba/private", "/stunnel", "/davfs2/secrets", "/apt/auth.conf", "/krb5.keytab", "/mysql/debian.cnf", "/ipsec.secrets", "/ipsec.d/private", "/etc/ssh/ssh_host_"}

// commonOK is the check every target passes first: no control, format or
// line/paragraph separator character (a zero-width space makes a lookalike), no
// padding, valid UTF-8. A name is judged exactly as it is, never cleaned first.
func commonOK(s string) bool {
	if s == "" || len(s) > maxTargetLen || !utf8.ValidString(s) {
		return false
	}
	for _, r := range s {
		if r < 0x20 || r == 0x7f || unicode.In(r, unicode.Cc, unicode.Cf, unicode.Zl, unicode.Zp) {
			return false
		}
	}
	first, _ := utf8.DecodeRuneInString(s)
	last, _ := utf8.DecodeLastRuneInString(s)
	return !unicode.IsSpace(first) && !unicode.Is(unicode.Z, first) && !unicode.IsSpace(last) && !unicode.Is(unicode.Z, last)
}

func hiveOf(s string) string {
	switch strings.ToUpper(s) {
	case "HKLM", "HKEY_LOCAL_MACHINE":
		return "HKLM"
	case "HKU", "HKEY_USERS":
		return "HKU"
	}
	return ""
}

// RegistryKeyAllowed reports whether a registry key may be read. The same rules
// as the server's ChangeWatchPolicy::registryAllowed: HKLM and HKU only, at least
// three segments deep, no empty or dot segment, never SAM, SECURITY, the boot
// configuration or the LSA key parts that hold the boot key, in any spelling of
// the hive or of the control set. A `*` user segment (HKU\*) is allowed.
func RegistryKeyAllowed(key string) bool {
	if !commonOK(key) || strings.Contains(key, "/") {
		return false
	}
	seg := strings.Split(key, `\`)
	if len(seg) < 3 {
		return false
	}
	for _, s := range seg {
		if s == "" || s == "." || s == ".." || s != strings.TrimSpace(s) {
			return false
		}
	}
	hive := hiveOf(seg[0])
	if hive == "" {
		return false
	}
	up := make([]string, len(seg))
	for i, s := range seg {
		up[i] = strings.ToUpper(s)
	}
	if hive == "HKU" {
		if !(up[1] == "*" || up[1] == ".DEFAULT" || reUserSID.MatchString(up[1])) {
			return false
		}
		for _, s := range up {
			if s == "SECRETS" || s == "PROTECTED STORAGE SYSTEM PROVIDER" || s == "CREDENTIALS" {
				return false
			}
		}
		return true
	}
	if up[1] == "SAM" || up[1] == "SECURITY" || strings.HasPrefix(up[1], "BCD0") || up[1] == "HARDWARE" {
		return false
	}
	if up[1] != "SOFTWARE" && up[1] != "SYSTEM" {
		return false
	}
	if up[1] == "SYSTEM" {
		if !reControlSet.MatchString(up[2]) {
			return false
		}
		if len(up) > 5 && up[3] == "CONTROL" && up[4] == "LSA" {
			switch up[5] {
			case "JD", "SKEW1", "GBG", "DATA":
				return false
			}
		}
	}
	for i, s := range up {
		if i >= 2 && (s == "SECRETS" || s == "SAM" || s == "SECURITY") {
			return false
		}
	}
	return true
}

// FileAllowed reports whether a file or folder may be read: an absolute path
// strictly below a configuration root, never a credential file. extraRoots are
// absolute folders a local administrator added to config.json.
func FileAllowed(path string, extraRoots []string) bool {
	if !commonOK(path) {
		return false
	}
	if reDriveLetter.MatchString(path) {
		return windowsFileAllowed(path)
	}
	return linuxFileAllowed(path, extraRoots)
}

func linuxFileAllowed(path string, extra []string) bool {
	if path[0] != '/' || strings.Contains(path, `\`) || strings.HasSuffix(path, "/") || strings.Contains(path, "//") {
		return false
	}
	for _, s := range strings.Split(path[1:], "/") {
		if s == "" || s == "." || s == ".." {
			return false
		}
	}
	ok := false
	roots := append(append([]string(nil), LinuxRoots...), extra...)
	for _, root := range roots {
		r := strings.TrimRight(root, "/")
		if r == "" {
			continue
		}
		if strings.HasPrefix(path, r+"/") || (path == root && root != "/etc") {
			ok = true
			break
		}
	}
	if !ok && reHomeAuthKeys.MatchString(path) {
		ok = true
	}
	return ok && !LinuxDenied(path)
}

// LinuxDenied reports whether a path is a credential store whatever folder it is in.
func LinuxDenied(path string) bool {
	lower := strings.ToLower(path)
	i := strings.LastIndex(lower, "/")
	base := lower[i+1:]
	if reShadow.MatchString(lower) || strings.HasPrefix(lower, "/etc/security/opasswd") {
		return true
	}
	for _, n := range linuxDeniedNeedles {
		if strings.Contains(lower, n) && !(n == "/etc/ssh/ssh_host_" && strings.HasSuffix(lower, ".pub")) {
			return true
		}
	}
	if rePrivateDir.MatchString(lower) {
		return true
	}
	if reSecretBase.MatchString(base) {
		return true
	}
	if reSecretExt.MatchString(base) && !strings.HasSuffix(base, ".pub") {
		return true
	}
	return strings.HasSuffix(base, "_history") || strings.Contains(base, "password")
}

func windowsFileAllowed(path string) bool {
	if strings.Contains(path, "/") {
		return false
	}
	rest := path[2:] // starts with a backslash
	seg := strings.Split(rest[1:], `\`)
	for _, s := range seg {
		if s == "" || s == "." || s == ".." || s != strings.TrimSpace(s) || strings.Contains(s, ":") {
			return false
		}
	}
	lower := strings.ToLower(rest)
	ok := false
	for _, root := range WindowsRoots {
		if strings.HasPrefix(lower, strings.ToLower(root)+`\`) {
			ok = true
			break
		}
	}
	if !ok {
		return false
	}
	for _, s := range seg {
		switch strings.ToLower(s) {
		case "config", "ntds", "sam", "security", "system":
			return false
		}
	}
	return !reWinSecretExt.MatchString(lower)
}
