//go:build windows

package jobs

import (
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"syscall"
)

// UNVERIFIED on real Windows. The child is started in its own process group
// without a console window; on timeout the whole tree is killed with
// `taskkill /T /F` (a Job Object with KILL_ON_JOB_CLOSE is a documented
// follow-up).
func configureProc(cmd *exec.Cmd) {
	cmd.SysProcAttr = &syscall.SysProcAttr{HideWindow: true, CreationFlags: 0x08000000 | 0x00000200} // CREATE_NO_WINDOW | CREATE_NEW_PROCESS_GROUP
	cmd.Cancel = func() error {
		if cmd.Process == nil {
			return nil
		}
		tk := filepath.Join(os.Getenv("SystemRoot"), "System32", "taskkill.exe")
		kill := exec.Command(tk, "/T", "/F", "/PID", strconv.Itoa(cmd.Process.Pid))
		kill.SysProcAttr = &syscall.SysProcAttr{HideWindow: true}
		_ = kill.Run()
		return cmd.Process.Kill()
	}
}

func minimalEnv() []string {
	sr := os.Getenv("SystemRoot")
	return []string{"SystemRoot=" + sr, "windir=" + sr, "SystemDrive=" + os.Getenv("SystemDrive"),
		"ProgramData=" + os.Getenv("ProgramData"), "ProgramFiles=" + os.Getenv("ProgramFiles"),
		"TEMP=" + os.Getenv("TEMP"), "TMP=" + os.Getenv("TMP"), "PATH=" + filepath.Join(sr, "System32") + ";" + sr}
}
