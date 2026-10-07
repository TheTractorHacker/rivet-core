//go:build linux

package agent

import (
	"errors"
	"fmt"
	"log/slog"
	"os/exec"
	"strings"
	"time"

	"rivetit-agent/internal/jobs"
)

type linuxRebooter struct {
	log *slog.Logger
	// run executes a fixed argv and returns combined output; lookPath finds a
	// program. Both are swapped by tests so nothing is ever rebooted.
	run      func(name string, args ...string) ([]byte, error)
	lookPath func(string) (string, error)
}

// NewRebooter schedules the reboot with systemd. Only the integer delay is
// variable; there is no shell and no server text in the command line.
func NewRebooter(log *slog.Logger) jobs.Rebooter {
	return linuxRebooter{log: log, lookPath: exec.LookPath,
		run: func(name string, args ...string) ([]byte, error) { return exec.Command(name, args...).CombinedOutput() }}
}

// Schedule arranges `systemctl reboot` after d (minimum 5 s). A transient
// systemd timer is preferred because it survives an agent restart; without
// systemd-run the classic `shutdown -r +N` (whole minutes, rounded up) is used.
func (r linuxRebooter) Schedule(d time.Duration) error {
	secs := int(d.Seconds())
	if secs < 5 {
		secs = 5
	}
	var tried []string
	if p, err := r.lookPath("systemd-run"); err == nil {
		// replace an earlier pending reboot timer instead of failing on the duplicate unit name
		_, _ = r.run("systemctl", "stop", "rivetit-agent-reboot.timer")
		out, err := r.run(p, "--quiet", "--unit=rivetit-agent-reboot", fmt.Sprintf("--on-active=%ds", secs), "systemctl", "reboot")
		if err == nil {
			r.log.Warn("reboot scheduled (systemd timer)", "in_seconds", secs)
			return nil
		}
		tried = append(tried, fmt.Sprintf("systemd-run: %v: %s", err, strings.TrimSpace(string(out))))
	}
	if p, err := r.lookPath("shutdown"); err == nil {
		mins := (secs + 59) / 60
		out, err := r.run(p, "-r", fmt.Sprintf("+%d", mins), "RivetIT maintenance reboot")
		if err == nil {
			r.log.Warn("reboot scheduled (shutdown)", "in_minutes", mins)
			return nil
		}
		tried = append(tried, fmt.Sprintf("shutdown: %v: %s", err, strings.TrimSpace(string(out))))
	}
	if len(tried) == 0 {
		return errors.New("neither systemd-run nor shutdown is installed")
	}
	return errors.New(strings.Join(tried, "; "))
}
