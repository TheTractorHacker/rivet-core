package mesh

import (
	"fmt"
	"sync"

	"rivetit-agent/internal/winacl"
)

// OnUntrusted, when set, is told (once per path and reason) that a Mesh agent executable was found but refused, so the refusal is logged loudly
// instead of the Mesh integration silently doing nothing.
var OnUntrusted func(path, reason string)

var untrustedSeen sync.Map

func reportUntrusted(path string, err error) {
	if OnUntrusted == nil {
		return
	}
	key := path + "|" + err.Error()
	if _, dup := untrustedSeen.LoadOrStore(key, struct{}{}); dup {
		return
	}
	OnUntrusted(path, err.Error())
}

// inspectFunc reads one object (winacl.Inspect on Windows, a table in the tests).
type inspectFunc func(path string, wantDir bool) (winacl.Info, error)

// meshTrust is the Windows policy for running a Mesh agent executable as SYSTEM (CORE-16), written so it runs on any OS: the file must lie below one of the
// allowed roots (the Program Files directories, the same places DefaultPaths searches), and every directory between the root and the file, and the file
// itself, must be a real (non-reparse) object owned by SYSTEM/Administrators/TrustedInstaller that grants write access to nobody else. The roots
// themselves are the operating system's. There is no Authenticode check in the agent today, so location plus ownership is the whole policy.
func meshTrust(path string, roots []string, inspect inspectFunc) error {
	root, ok := winacl.UnderRoot(path, roots)
	if !ok {
		return fmt.Errorf("%s is not below an allowed install location (%v)", path, roots)
	}
	chain := winacl.ChainBelow(root, path)
	if chain == nil {
		return fmt.Errorf("%s is not a clean path below %s", path, root)
	}
	for n, p := range chain {
		info, err := inspect(p, n < len(chain)-1)
		if err != nil {
			return err
		}
		if err := winacl.CheckAllowAdminOwner(info); err != nil {
			return err
		}
	}
	return nil
}
