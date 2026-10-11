package remote

import (
	"bytes"
	"context"
	"errors"
	"os"
	"os/exec"
	"time"
)

// RunResult is what a Runner reports about one finished command.
type RunResult struct {
	Stdout    []byte
	Stderr    []byte
	ExitCode  int
	Truncated bool // output exceeded the byte cap and was cut
}

// Runner executes a program from an argv LIST (never a shell string). It is the
// seam that keeps systemctl, journalctl and wevtutil out of the unit tests.
type Runner interface {
	Run(ctx context.Context, name string, args []string, maxOut int, timeout time.Duration) (RunResult, error)
}

// ExecRunner is the production Runner: LC_ALL=C, a bounded runtime, bounded output.
type ExecRunner struct{}

type capBuf struct {
	b   bytes.Buffer
	max int
	cut bool
}

func (c *capBuf) Write(p []byte) (int, error) {
	if room := c.max - c.b.Len(); room > 0 {
		if len(p) > room {
			c.b.Write(p[:room])
			c.cut = true
		} else {
			c.b.Write(p)
		}
	} else if len(p) > 0 {
		c.cut = true
	}
	return len(p), nil // never fail the child: it keeps draining
}

// Run implements Runner. A non-zero exit is NOT an error (ExitCode is set); an
// error means the program could not be started or the context ended.
func (ExecRunner) Run(ctx context.Context, name string, args []string, maxOut int, timeout time.Duration) (RunResult, error) {
	if timeout <= 0 {
		timeout = 30 * time.Second
	}
	if maxOut <= 0 {
		maxOut = 1 << 20
	}
	cctx, cancel := context.WithTimeout(ctx, timeout)
	defer cancel()
	cmd := exec.CommandContext(cctx, name, args...)
	cmd.Env = append(os.Environ(), "LC_ALL=C", "LANG=C")
	cmd.Stdin = nil
	out, errb := &capBuf{max: maxOut}, &capBuf{max: 16 << 10}
	cmd.Stdout, cmd.Stderr = out, errb
	cmd.WaitDelay = 2 * time.Second
	err := cmd.Run()
	res := RunResult{Stdout: out.b.Bytes(), Stderr: errb.b.Bytes(), Truncated: out.cut}
	if err != nil {
		var ee *exec.ExitError
		if errors.As(err, &ee) && cctx.Err() == nil {
			res.ExitCode = ee.ExitCode()
			return res, nil
		}
		if cctx.Err() != nil {
			return res, cctx.Err()
		}
		return res, err
	}
	return res, nil
}
