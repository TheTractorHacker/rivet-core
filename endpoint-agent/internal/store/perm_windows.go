//go:build windows

package store

import (
	"encoding/base64"
	"errors"
	"fmt"
	"io/fs"
	"path/filepath"
	"strings"
	"unsafe"

	"golang.org/x/sys/windows"

	"rivetit-agent/internal/winacl"
)

// Directory DACL: protected (no inherited ACEs), SYSTEM and Administrators
// full control, nobody else. UNVERIFIED on real Windows (built, not run).
const dirSDDL = "D:PAI(A;OICI;FA;;;SY)(A;OICI;FA;;;BA)"

// ownedDirSDDL is dirSDDL plus an explicit owner (Administrators): a directory created with it can never be owned by the creating standard user.
const ownedDirSDDL = "O:BA" + dirSDDL

// ensureDir returns a state directory that only SYSTEM and Administrators own and can write (CORE-16). A directory that already exists is not
// trusted merely because it is there: a standard user may create folders under %ProgramData% and owns what it creates, so it could have pre-created
// RivetIT\Agent and filled it with a config.json (mesh_agent_path, server_url, ca_file ...) before the agent was installed. Such a directory, a
// reparse point, or one whose parent RivetIT directory is untrusted, is refused with an actionable error and nothing is read from it.
func ensureDir(dir string) error {
	dir = filepath.Clean(dir)
	chain := []string{dir}
	if parent := filepath.Dir(dir); strings.EqualFold(filepath.Base(parent), "RivetIT") {
		chain = []string{parent, dir} // the default layout %ProgramData%\RivetIT\Agent: both levels are the agent's
	}
	for _, d := range chain {
		if err := ensureTrustedDir(d); err != nil {
			return fmt.Errorf("refusing to use the agent state directory: %w. %s", err, winacl.RepairHint(chain[0]))
		}
	}
	return nil
}

func ensureTrustedDir(dir string) error {
	info, err := winacl.Inspect(dir, true)
	if err != nil {
		return err
	}
	if info.NotFound {
		// Create it atomically with an explicit owner and DACL, so there is no window in which it is owned by someone else. The parent of
		// the first level is %ProgramData% (or a directory chosen with --state-dir) and must exist.
		if err := createSecureDir(dir); err != nil && !errors.Is(err, windows.ERROR_ALREADY_EXISTS) {
			return fmt.Errorf("create %s: %w", dir, err)
		}
		if info, err = winacl.Inspect(dir, true); err != nil {
			return err
		}
	}
	verdict, why := winacl.Classify(info)
	if verdict == winacl.Refuse {
		return why // untrusted owner, reparse point, wrong kind: created or changed by somebody else
	}
	// Tighten first, verify afterwards: once the protected DACL is on, nobody else can add anything, so the tree check below sees everything that could
	// ever have been planted. Older agents created %ProgramData%\RivetIT with the ProgramData ACL inherited (users may create files and folders there);
	// the owner is SYSTEM or Administrators, which is what separates that benign shape from a folder a user pre-created.
	if err := setDirDACL(dir); err != nil {
		return fmt.Errorf("tighten permissions of %s: %w", dir, err)
	}
	if verdict == winacl.Repairable {
		if info, err = winacl.Inspect(dir, true); err != nil {
			return err
		}
		if err := winacl.Check(info); err != nil {
			return fmt.Errorf("permissions of %s are still not safe after tightening: %w", dir, err)
		}
	}
	entries, err := treeInfos(dir)
	if err != nil {
		return err
	}
	if err := winacl.CheckTree(entries); err != nil {
		return err
	}
	if verdict == winacl.Repairable && OnRepair != nil {
		OnRepair(fmt.Sprintf("repaired the permissions of %s in place (%v); owner is SYSTEM/Administrators and all %d entries inside are owned by SYSTEM/Administrators", dir, why, len(entries)))
	}
	return nil
}

// treeInfos inspects every entry below dir without following links (a reparse point is reported, not entered).
func treeInfos(dir string) ([]winacl.Info, error) {
	var out []winacl.Info
	err := filepath.WalkDir(dir, func(p string, d fs.DirEntry, err error) error {
		if err != nil {
			return err
		}
		if p == dir {
			return nil
		}
		i, err := winacl.Inspect(p, d.IsDir())
		if err != nil {
			return err
		}
		out = append(out, i)
		if i.Reparse && d.IsDir() {
			return filepath.SkipDir
		}
		return nil
	})
	return out, err
}

func createSecureDir(dir string) error {
	// Missing ancestors (a custom --state-dir several levels deep) are created the same way, outermost first.
	if parent := filepath.Dir(dir); parent != dir {
		if pi, err := winacl.Inspect(parent, true); err == nil && pi.NotFound {
			if err := createSecureDir(parent); err != nil && !errors.Is(err, windows.ERROR_ALREADY_EXISTS) {
				return err
			}
		}
	}
	sd, err := windows.SecurityDescriptorFromString(ownedDirSDDL)
	if err != nil {
		return err
	}
	p, err := windows.UTF16PtrFromString(dir)
	if err != nil {
		return err
	}
	sa := &windows.SecurityAttributes{Length: uint32(unsafe.Sizeof(windows.SecurityAttributes{})), SecurityDescriptor: sd}
	return windows.CreateDirectory(p, sa)
}

// setDirDACL re-applies the protected DACL of an already verified directory (idempotent; also repairs an agent-created directory from an older version).
func setDirDACL(dir string) error {
	sd, err := windows.SecurityDescriptorFromString(dirSDDL)
	if err != nil {
		return err
	}
	dacl, _, err := sd.DACL()
	if err != nil {
		return err
	}
	return windows.SetNamedSecurityInfo(dir, windows.SE_FILE_OBJECT,
		windows.DACL_SECURITY_INFORMATION|windows.PROTECTED_DACL_SECURITY_INFORMATION,
		nil, nil, dacl, nil)
}

func syncDir(string)                       {}
func tightenPerms(string)                  {}
func looseFiles(string, []string) []string { return nil }

var dpapiEntropy = []byte("RivetIT-Agent-v1")

func blob(b []byte) *windows.DataBlob {
	if len(b) == 0 {
		return &windows.DataBlob{}
	}
	return &windows.DataBlob{Size: uint32(len(b)), Data: &b[0]}
}

// CRYPTPROTECT_LOCAL_MACHINE lets the service (SYSTEM) read a token written
// by the installing administrator; the directory DACL is the primary control.
const cryptProtectLocalMachine = 0x4

func protect(b []byte) (string, string, error) {
	var out windows.DataBlob
	if err := windows.CryptProtectData(blob(b), nil, blob(dpapiEntropy), 0, nil, cryptProtectLocalMachine, &out); err != nil {
		return "", "", err
	}
	defer windows.LocalFree(windows.Handle(unsafe.Pointer(out.Data)))
	enc := unsafe.Slice(out.Data, out.Size)
	return schemeDPAPI, base64.StdEncoding.EncodeToString(enc), nil
}

func unprotect(scheme, s string) ([]byte, error) {
	if scheme != schemeDPAPI {
		return nil, errors.New("unsupported credential scheme: " + scheme)
	}
	raw, err := base64.StdEncoding.DecodeString(s)
	if err != nil {
		return nil, err
	}
	var out windows.DataBlob
	if err := windows.CryptUnprotectData(blob(raw), nil, blob(dpapiEntropy), 0, nil, cryptProtectLocalMachine, &out); err != nil {
		return nil, err
	}
	defer windows.LocalFree(windows.Handle(unsafe.Pointer(out.Data)))
	res := make([]byte, out.Size)
	copy(res, unsafe.Slice(out.Data, out.Size))
	return res, nil
}
