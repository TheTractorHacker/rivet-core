//go:build linux

package collect

import (
	"os/exec"
	"syscall"
)

func goos() string { return "linux" }

func hideWindow(*exec.Cmd) {}

func statfsInodes(path string) (uint64, uint64, error) {
	var s syscall.Statfs_t
	if err := syscall.Statfs(path, &s); err != nil {
		return 0, 0, err
	}
	return uint64(s.Files), uint64(s.Ffree), nil
}
