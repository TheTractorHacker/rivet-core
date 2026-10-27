//go:build windows

package agent

import (
	"fmt"
	"log/slog"
	"os"
	"os/exec"
	"path/filepath"
	"syscall"
	"time"

	"rivetit-agent/internal/jobs"
)

type winRebooter struct{ log *slog.Logger }

// NewRebooter schedules `shutdown.exe /r /t <delay>` (UNVERIFIED on real
// Windows). Fixed argv; only the integer delay is variable.
func NewRebooter(log *slog.Logger) jobs.Rebooter { return winRebooter{log} }

func (r winRebooter) Schedule(d time.Duration) error {
	secs := int(d.Seconds())
	if secs < 5 {
		secs = 5
	}
	exe := filepath.Join(os.Getenv("SystemRoot"), "System32", "shutdown.exe")
	cmd := exec.Command(exe, "/r", "/t", fmt.Sprint(secs), "/c", "RivetIT maintenance reboot", "/d", "p:4:1")
	cmd.SysProcAttr = &syscall.SysProcAttr{HideWindow: true}
	out, err := cmd.CombinedOutput()
	if err != nil {
		return fmt.Errorf("shutdown.exe: %v: %s", err, out)
	}
	r.log.Warn("reboot scheduled", "in_seconds", secs)
	return nil
}
