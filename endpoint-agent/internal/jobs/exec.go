package jobs

import (
	"bytes"
	"context"
	"fmt"
	"os/exec"
	"sync"
	"time"
	"unicode/utf8"
)

// ExecSpec describes one bounded child process. Name/Args are passed to the
// OS directly (argv, never through a shell string built from server fields).
type ExecSpec struct {
	Name      string
	Args      []string
	Env       []string // appended to a minimal inherited environment
	Timeout   time.Duration
	MaxOutput int
	Secrets   []string // literal values to redact
	Cleanup   func()   // optional; called once the process has ended (temp script files)
}

// ExecResult is the outcome of RunBounded.
type ExecResult struct {
	ExitCode  int
	HaveExit  bool
	Output    string // redacted, truncated to MaxOutput (+marker)
	Truncated bool
	TimedOut  bool
	Cancelled bool
	StartErr  error
}

// redactSlack is extra capture beyond MaxOutput so a secret straddling the cap
// is redacted before it is cut.
const redactSlack = 8 << 10

type capWriter struct {
	mu    sync.Mutex
	buf   bytes.Buffer
	limit int
	total int64
}

func (w *capWriter) Write(p []byte) (int, error) {
	w.mu.Lock()
	defer w.mu.Unlock()
	w.total += int64(len(p))
	if room := w.limit - w.buf.Len(); room > 0 {
		if len(p) > room {
			w.buf.Write(p[:room])
		} else {
			w.buf.Write(p)
		}
	}
	return len(p), nil // always accept so the child never blocks on a full pipe
}

// RunBounded runs the process with a hard timeout, killing the whole process
// tree on timeout/cancellation, with combined stdout+stderr capped.
func RunBounded(ctx context.Context, s ExecSpec) ExecResult {
	if s.MaxOutput <= 0 {
		s.MaxOutput = DefaultMaxOutputBytes
	}
	if s.Cleanup != nil {
		defer s.Cleanup()
	}
	tctx, cancel := context.WithTimeout(ctx, s.Timeout)
	defer cancel()
	cmd := exec.CommandContext(tctx, s.Name, s.Args...)
	cmd.Env = append(minimalEnv(), s.Env...)
	configureProc(cmd)
	w := &capWriter{limit: s.MaxOutput + redactSlack}
	cmd.Stdout, cmd.Stderr = w, w
	cmd.WaitDelay = 3 * time.Second
	var res ExecResult
	if err := cmd.Start(); err != nil {
		res.StartErr = err
		return res
	}
	err := cmd.Wait()
	switch {
	case tctx.Err() == context.DeadlineExceeded:
		res.TimedOut = true
	case ctx.Err() != nil:
		res.Cancelled = true
	}
	if cmd.ProcessState != nil && cmd.ProcessState.Exited() {
		res.ExitCode, res.HaveExit = cmd.ProcessState.ExitCode(), true
	} else if err != nil && !res.TimedOut && !res.Cancelled {
		res.StartErr = err
	}
	w.mu.Lock()
	out := w.buf.String()
	total := w.total
	w.mu.Unlock()
	out = Redact(out, s.Secrets...)
	if len(out) > s.MaxOutput || total > int64(s.MaxOutput) {
		res.Truncated = true
		if len(out) > s.MaxOutput {
			out = out[:s.MaxOutput]
			for !utf8.ValidString(out) && len(out) > 0 {
				out = out[:len(out)-1]
			}
		}
		out += fmt.Sprintf("\n[output truncated: limit %d bytes, %d bytes produced]", s.MaxOutput, total)
	}
	res.Output = out
	return res
}
