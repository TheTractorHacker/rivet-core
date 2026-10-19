//go:build windows

package change

import "os"

// fileMeta on Windows is the read-only flag only (owners and ACLs are not read).
func fileMeta(fi os.FileInfo) string {
	if fi.Mode().Perm()&0o200 == 0 {
		return "read-only"
	}
	return "read-write"
}
