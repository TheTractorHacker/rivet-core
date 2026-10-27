//go:build !windows

package change

import (
	"fmt"
	"os"
	"syscall"
)

// fileMeta is the permissions and numeric owner of a file; a change of either is
// reported even when the content did not change.
func fileMeta(fi os.FileInfo) string {
	if st, ok := fi.Sys().(*syscall.Stat_t); ok {
		return fmt.Sprintf("%04o %d:%d", fi.Mode().Perm(), st.Uid, st.Gid)
	}
	return fmt.Sprintf("%04o", fi.Mode().Perm())
}
