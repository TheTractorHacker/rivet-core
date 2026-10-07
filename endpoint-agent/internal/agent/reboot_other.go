//go:build !windows && !linux

package agent

import (
	"log/slog"
	"time"

	"rivetit-agent/internal/jobs"
)

type logRebooter struct{ log *slog.Logger }

// NewRebooter on unsupported unix flavours never reboots the machine: it only logs what it
// would have done.
func NewRebooter(log *slog.Logger) jobs.Rebooter { return logRebooter{log} }

func (r logRebooter) Schedule(d time.Duration) error {
	r.log.Warn("TEST MODE: reboot requested but not performed on this OS", "delay", d)
	return nil
}
