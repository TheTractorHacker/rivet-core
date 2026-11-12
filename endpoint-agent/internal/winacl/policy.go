// Package winacl decides whether a Windows file or directory may be trusted by an agent that runs as SYSTEM (CORE-16). The decision is a pure
// function of the object's owner, DACL and reparse-point state, so it is unit-tested on every OS; reading those from a real path is
// inspect_windows.go.
package winacl

import (
	"fmt"
	"strings"
)

// Well-known SIDs that may own, and may write to, an object the agent trusts.
const (
	SIDSystem        = "S-1-5-18"
	SIDAdministrator = "S-1-5-32-544"
	SIDTrustedInst   = "S-1-5-80-956008885-3418522649-1831038044-1853292631-2271478464"
)

// Access-mask bits that let a principal change the object or its parent/ownership. Read, execute and attribute-read bits are harmless.
const (
	fileWriteData   = 0x00000002 // create files (directory) / write data (file)
	fileAppendData  = 0x00000004 // create subdirectories / append
	fileDeleteChild = 0x00000040
	deleteRight     = 0x00010000
	writeDAC        = 0x00040000
	writeOwner      = 0x00080000
	maxAllowed      = 0x02000000
	genericAll      = 0x10000000
	genericWrite    = 0x40000000

	// WriteMask is every bit that counts as "can modify".
	WriteMask = fileWriteData | fileAppendData | fileDeleteChild | deleteRight | writeDAC | writeOwner | maxAllowed | genericAll | genericWrite
)

// ACE is one access-control entry of a DACL.
type ACE struct {
	SID         string
	Allow       bool // false: a deny entry (never makes an object less safe, so it is ignored)
	Mask        uint32
	InheritOnly bool // applies only to children, not to the object itself
}

// Info is what was read from one object.
type Info struct {
	Path     string
	Owner    string
	HasDACL  bool // false: a NULL DACL, which grants everyone full control
	ACEs     []ACE
	Reparse  bool // symbolic link, junction or other reparse point
	IsDir    bool
	WantDir  bool // the caller expects a directory (false: a file)
	NotFound bool
}

// Trusted reports whether sid is SYSTEM, the Administrators group or TrustedInstaller.
func Trusted(sid string) bool {
	switch strings.ToUpper(sid) {
	case SIDSystem, SIDAdministrator, SIDTrustedInst:
		return true
	}
	return false
}

// Check returns nil when the object is safe to read from and to act on as SYSTEM: not a reparse point, the right kind of object, owned by a trusted
// principal, with a real DACL that grants write/delete/take-ownership to nobody else. The error says exactly what is wrong.
func Check(i Info) error {
	switch {
	case i.NotFound:
		return fmt.Errorf("%s does not exist", i.Path)
	case i.Reparse:
		return fmt.Errorf("%s is a reparse point (symbolic link or junction)", i.Path)
	case i.WantDir && !i.IsDir:
		return fmt.Errorf("%s is not a directory", i.Path)
	case !i.WantDir && i.IsDir:
		return fmt.Errorf("%s is a directory, not a file", i.Path)
	case !Trusted(i.Owner):
		return fmt.Errorf("%s is owned by %s, not SYSTEM or Administrators (an owner can always rewrite the permissions)", i.Path, orUnknown(i.Owner))
	case !i.HasDACL:
		return fmt.Errorf("%s has no DACL, which gives every user full control", i.Path)
	}
	for _, a := range i.ACEs {
		if a.Allow && !a.InheritOnly && !Trusted(a.SID) && a.Mask&WriteMask != 0 {
			return fmt.Errorf("%s grants %s write access (mask 0x%X)", i.Path, a.SID, a.Mask)
		}
	}
	return nil
}

// Verdict is the outcome of Classify.
type Verdict int

const (
	// Refuse: the object may have been created or changed by an untrusted principal (untrusted owner, reparse point, wrong kind, missing).
	Refuse Verdict = iota
	// VerdictTrusted: usable as is.
	VerdictTrusted
	// Repairable: the owner is trusted and it is a real object of the right kind, but the DACL is looser than wanted (the shape an older agent left for
	// %ProgramData%\RivetIT, created by MkdirAll with the ProgramData ACL inherited). Whoever owns it can tighten the DACL; the contents must be verified (CheckTree).
	Repairable
)

// Classify separates the attack shapes (Refuse) from the benign legacy shape (Repairable). Ownership, not the DACL, is what proves who created the object:
// a user who pre-creates a folder owns it, whereas a folder created by SYSTEM or an elevated administrator is owned by SYSTEM or Administrators even when it inherited a broad ACL.
func Classify(i Info) (Verdict, error) {
	err := Check(i)
	if err == nil {
		return VerdictTrusted, nil
	}
	if i.NotFound || i.Reparse || i.WantDir != i.IsDir || !Trusted(i.Owner) {
		return Refuse, err
	}
	return Repairable, err
}

// CheckTree verifies the contents of a directory about to be adopted: every entry must be a real (non-reparse) object owned by a trusted principal.
// A file a user planted (owned by that user) makes the whole directory untrustworthy, because anything in it (config.json) is read as the agent's own.
func CheckTree(entries []Info) error {
	for _, e := range entries {
		switch {
		case e.Reparse:
			return fmt.Errorf("%s inside the directory is a reparse point (symbolic link or junction)", e.Path)
		case !Trusted(e.Owner):
			return fmt.Errorf("%s inside the directory is owned by %s, not SYSTEM or Administrators (planted by a standard user?)", e.Path, orUnknown(e.Owner))
		}
	}
	return nil
}

func orUnknown(s string) string {
	if s == "" {
		return "an unknown owner"
	}
	return s
}

// CheckChain checks each object of an ordered list (outermost first). It stops at the first failure.
func CheckChain(infos []Info) error {
	for _, i := range infos {
		if err := Check(i); err != nil {
			return err
		}
	}
	return nil
}

// UnderRoot reports whether path lies strictly below one of the roots, comparing case-insensitively with either separator, and returns the matched
// root. Neither path nor roots are resolved: callers pass cleaned absolute paths.
func UnderRoot(path string, roots []string) (string, bool) {
	p := normalize(path)
	for _, r := range roots {
		n := normalize(r)
		if n != "" && strings.HasPrefix(p, n+`\`) {
			return strings.TrimRight(strings.ReplaceAll(r, "/", `\`), `\`), true
		}
	}
	return "", false
}

func normalize(p string) string {
	return strings.TrimRight(strings.ToLower(strings.ReplaceAll(p, "/", `\`)), `\`)
}

// ChainBelow lists the paths to check for target below root: every directory under root, outermost first, then target itself. Paths use backslashes.
// It returns nil when target is not below root.
func ChainBelow(root, target string) []string {
	r := strings.TrimRight(strings.ReplaceAll(root, "/", `\`), `\`)
	t := strings.TrimRight(strings.ReplaceAll(target, "/", `\`), `\`)
	if !strings.HasPrefix(strings.ToLower(t), strings.ToLower(r)+`\`) {
		return nil
	}
	rest := t[len(r)+1:]
	parts := strings.Split(rest, `\`)
	out := make([]string, 0, len(parts))
	cur := r
	for _, p := range parts {
		if p == "" || p == "." || p == ".." {
			return nil
		}
		cur += `\` + p
		out = append(out, cur)
	}
	return out
}

// RepairHint is the operator-facing advice appended to a refusal about a state directory.
func RepairHint(dir string) string {
	return fmt.Sprintf(`The directory (or a parent) was probably created by a standard user before the agent was installed, so nothing in it can be trusted `+
		`(config.json, mesh_agent_path, server_url, ca_file, device.token). To repair it, run from an elevated PowerShell: `+
		`Stop-Service RivetITAgent -ErrorAction SilentlyContinue; takeown /F "%[1]s" /R /D Y; Remove-Item -Recurse -Force "%[1]s"; `+
		`then re-run the installer or "rivetit-agent install" (the agent re-creates the directory with the correct owner and permissions and must be enrolled again). `+
		`If you have verified the contents yourself and only want to keep them, take ownership instead: `+
		`takeown /F "%[1]s" /R /D Y; icacls "%[1]s" /setowner "*S-1-5-18" /T /C; icacls "%[1]s" /inheritance:r /grant:r "*S-1-5-18:(OI)(CI)F" "*S-1-5-32-544:(OI)(CI)F" /T /C`, dir)
}
