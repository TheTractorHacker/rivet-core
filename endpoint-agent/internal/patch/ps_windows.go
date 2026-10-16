//go:build windows

package patch

import (
	"context"
	"time"

	"rivetit-agent/internal/jobs"
)

// defaultPS runs the script through the same safe path as a PowerShell job: jobs.BuildScriptCommand (-EncodedCommand, no server text in the command
// line) and jobs.RunBounded (job environment with PATHEXT, process tree kill on timeout, CLIXML cleanup). The filters travel in the environment,
// appended after the minimal job environment; they were validated before and are only ever READ by the scripts as data.
func defaultPS() PSRunner {
	return func(ctx context.Context, script string, env []string, timeout time.Duration) jobs.ExecResult {
		sc, err := jobs.BuildScriptCommand(script, nil)
		if err != nil {
			return jobs.ExecResult{StartErr: err}
		}
		return jobs.RunBounded(ctx, jobs.ExecSpec{Name: sc.Name, Args: sc.Args, Env: append(append([]string{}, sc.Env...), env...), Cleanup: sc.Cleanup, Timeout: timeout, MaxOutput: psMaxOutput})
	}
}
