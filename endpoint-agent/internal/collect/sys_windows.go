//go:build windows

package collect

import (
	"errors"
	"os/exec"
	"syscall"
)

func goos() string { return "windows" }

// hideWindow keeps a console window from flashing when the agent runs a helper program.
func hideWindow(cmd *exec.Cmd) {
	cmd.SysProcAttr = &syscall.SysProcAttr{HideWindow: true, CreationFlags: 0x08000000} // CREATE_NO_WINDOW
}

func statfsInodes(string) (uint64, uint64, error) {
	return 0, 0, errors.New("inodes are a Linux concept")
}
