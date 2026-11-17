//go:build linux

package collect

import (
	"os"

	"golang.org/x/sys/unix"
)

// Kernel pseudo file systems whose "regular" files can block for ever or consume data when read (/proc/kmsg, trace pipes, ...).
var pseudoFSMagic = map[int64]bool{
	0x9fa0:     true, // PROC_SUPER_MAGIC
	0x62656572: true, // SYSFS_MAGIC
	0x64626720: true, // DEBUGFS_MAGIC
	0x74726163: true, // TRACEFS_MAGIC
	0x63677270: true, // CGROUP2_SUPER_MAGIC
	0x27e0eb:   true, // CGROUP_SUPER_MAGIC
	0x73636673: true, // SECURITYFS_MAGIC
	0x1cd1:     true, // DEVPTS_SUPER_MAGIC
}

// onPseudoFS reports whether the open file lives on a kernel pseudo file system.
func onPseudoFS(f *os.File) bool {
	var st unix.Statfs_t
	if err := unix.Fstatfs(int(f.Fd()), &st); err != nil {
		return true // cannot tell: refuse
	}
	return pseudoFSMagic[int64(st.Type)]
}
