package remote

import (
	"errors"
	"strings"

	"rivetit-agent/internal/winacl"
)

// Windows file attribute bits used by the directory pin check (declared here so the decision is testable on every OS).
const (
	attrDirectory    = 0x10
	attrReparsePoint = 0x400
)

// checkPinnedDir decides whether a directory handle opened for a file_put is the directory the path policy approved: it must be a real directory,
// not a name-surrogate reparse point (symbolic link, junction, mount point), and its own final path must be the approved path (compared case-insensitively, as
// Windows does). A junction swapped in between the policy check and the open fails the second test or the first (CORE-DS-13).
func checkPinnedDir(attrs, reparseTag uint32, finalPath, approved string) error {
	switch {
	case attrs&attrDirectory == 0:
		return errors.New("destination is not a directory")
	case attrs&reparsePointMask != 0 && winacl.IsNameSurrogate(reparseTag):
		// only name-surrogate tags (symlink, junction, mount point) are links; a Cloud Files / OneDrive placeholder directory carries a reparse
		// attribute too and must keep working (review CR2). The final-path comparison below catches any redirection anyway.
		return errors.New("destination directory is a link")
	case !strings.EqualFold(normWinPath(finalPath), normWinPath(approved)):
		return errors.New("destination directory changed after the policy check")
	}
	return nil
}

const reparsePointMask = attrReparsePoint

// normWinPath makes the separators uniform and drops a trailing one, so the comparison does not depend on the host OS.
func normWinPath(p string) string {
	return strings.TrimRight(strings.ReplaceAll(p, "/", `\`), `\`)
}
