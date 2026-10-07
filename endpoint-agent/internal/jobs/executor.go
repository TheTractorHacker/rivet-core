package jobs

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"log/slog"
	"sync"
	"time"

	"rivetit-agent/internal/api"
)

// Reporter sends job state transitions to the server.
type Reporter interface {
	ReportJob(ctx context.Context, r api.JobReport) error
}

// Rebooter schedules a reboot. Injected so tests never reboot anything.
type Rebooter interface {
	Schedule(delay time.Duration) error
}

// Executor serialises job execution (Concurrency workers, default 1).
type Executor struct {
	Store       *Store
	Verifier    *Verifier
	Reporter    Reporter
	Rebooter    Rebooter
	Collect     func(ctx context.Context) (string, error) // "collect" job body
	Secrets     func() []string                           // literals to redact
	OnRevoked   func(err error)                           // called on a 401 revoked from the report endpoint
	Concurrency int
	Now         func() time.Time
	Backoff     api.Backoff
	Attempts    int // report attempts per transition (default 5)
	Log         *slog.Logger
	Disabled    bool // config.disable_jobs: never execute

	mu       sync.Mutex
	active   map[string]bool
	rejected map[string]bool
	queue    chan api.Job
	wg       sync.WaitGroup
	runCtx   context.Context
	halt     context.CancelFunc
	started  bool
}

func (e *Executor) log() *slog.Logger {
	if e.Log != nil {
		return e.Log
	}
	return slog.Default()
}

func (e *Executor) now() time.Time {
	if e.Now != nil {
		return e.Now()
	}
	return time.Now()
}

func ts(t time.Time) string { return t.UTC().Format(time.RFC3339) }

// Start launches the workers. Cancelling ctx (or calling Halt) kills running
// jobs and drops the queue.
func (e *Executor) Start(ctx context.Context) {
	e.mu.Lock()
	defer e.mu.Unlock()
	if e.started {
		return
	}
	e.started = true
	n := e.Concurrency
	if n <= 0 {
		n = 1
	}
	e.active = map[string]bool{}
	e.rejected = map[string]bool{}
	e.queue = make(chan api.Job, 256)
	e.runCtx, e.halt = context.WithCancel(ctx)
	for i := 0; i < n; i++ {
		e.wg.Add(1)
		go e.worker()
	}
}

// Halt stops all job execution immediately (credential revoked / shutdown).
func (e *Executor) Halt() {
	if e.halt != nil {
		e.halt()
	}
}

// Wait blocks until the workers exit (after Halt/ctx cancel).
func (e *Executor) Wait() { e.wg.Wait() }

// Idle reports whether no job is queued or running.
func (e *Executor) Idle() bool {
	e.mu.Lock()
	defer e.mu.Unlock()
	return len(e.active) == 0
}

// Recover is called once at startup, before any new job is accepted: every
// job left `running` by a crash is marked failed (agent_restarted) and is
// NEVER executed again. Unreported terminal states are re-sent by Flush.
func (e *Executor) Recover() {
	for _, r := range e.Store.Running() {
		r.State, r.Reason = StateFailed, "agent_restarted"
		r.Output = "agent_restarted: the agent stopped while this job was running; it was not re-run"
		r.FinishedAt = ts(e.now())
		r.Reported = false
		if err := e.Store.Put(r); err != nil {
			e.log().Error("persist recovery", "job", r.JobID, "err", err)
		}
		e.log().Warn("job found running after restart; marked failed", "job", r.JobID)
	}
}

// Submit verifies each job and queues the authentic, new ones. Unverifiable
// jobs are logged once and never executed or acknowledged.
func (e *Executor) Submit(jobs []api.Job) {
	for _, j := range jobs {
		e.submitOne(j)
	}
}

func (e *Executor) submitOne(j api.Job) {
	if e.Disabled {
		return
	}
	if err := e.Verifier.Verify(j); err != nil {
		e.mu.Lock()
		first := !e.rejected[j.JobID+"|"+err.Error()]
		e.rejected[j.JobID+"|"+err.Error()] = true
		e.mu.Unlock()
		if first {
			e.log().Warn("rejected job (not executed)", "job", j.JobID, "reason", err.Error())
		}
		return
	}
	e.mu.Lock()
	if e.active[j.JobID] {
		e.mu.Unlock()
		return
	}
	e.active[j.JobID] = true
	e.mu.Unlock()
	select {
	case e.queue <- j:
	default:
		e.mu.Lock()
		delete(e.active, j.JobID) // queue full: it will be fetched again
		e.mu.Unlock()
	}
}

func (e *Executor) worker() {
	defer e.wg.Done()
	for {
		select {
		case <-e.runCtx.Done():
			return
		case j := <-e.queue:
			if e.runCtx.Err() != nil { // halted (revoked/shutdown): never start another job
				return
			}
			e.process(j)
			e.mu.Lock()
			delete(e.active, j.JobID)
			e.mu.Unlock()
		}
	}
}

func (e *Executor) secrets() []string {
	if e.Secrets == nil {
		return nil
	}
	return e.Secrets()
}

func (e *Executor) process(j api.Job) {
	ctx := e.runCtx
	if prev, seen := e.Store.Get(j.JobID); seen {
		// Never re-execute a known job_id, whatever its attempt number.
		if isTerminal(prev.State) && !prev.Reported {
			e.reportRecord(ctx, prev, j.Attempt)
		}
		return
	}
	started := e.now()
	rec := Record{JobID: j.JobID, Attempt: j.Attempt, Type: j.Type, State: StateRunning, StartedAt: ts(started)}
	// Durable BEFORE launch: a crash after this point can only lead to
	// "failed/agent_restarted", never to a second execution.
	if err := e.Store.Put(rec); err != nil {
		e.log().Error("cannot persist job state; refusing to run", "job", j.JobID, "err", err)
		return
	}
	e.reportOnce(ctx, rec, j.Attempt)

	timeout, maxOut := Limits(j)
	var state, reason, output string
	var exit *int
	if !SupportsType(j.Type) {
		// Authentic but not runnable here (a PowerShell job on Linux, a shell job on
		// Windows): report a clean failure, execute nothing.
		state, reason, output = StateFailed, ReasonUnsupportedPlatform, unsupportedMessage(j.Type)
		e.log().Warn("job type not supported on this platform", "job", j.JobID, "type", j.Type)
		e.finish(ctx, j, rec, state, reason, output, exit)
		return
	}
	switch j.Type {
	case TypePowerShell, TypeShell:
		state, reason, output, exit = e.runScript(ctx, j, timeout, maxOut)
	case "collect":
		state, reason, output = StateSucceeded, "", ""
		if e.Collect == nil {
			state, reason, output = StateFailed, "unsupported", "collect not available"
		} else if out, err := e.Collect(ctx); err != nil {
			state, reason, output = StateFailed, "collect_error", err.Error()
		} else {
			output = capText(Redact(out, e.secrets()...), maxOut)
		}
	case "reboot":
		e.processReboot(ctx, j, rec)
		return
	}
	if ctx.Err() != nil && state != StateTimedOut {
		state, reason = StateCancelled, "agent_stopping"
	}
	e.finish(ctx, j, rec, state, reason, output, exit)
}

// finish persists the terminal record and reports it.
func (e *Executor) finish(ctx context.Context, j api.Job, rec Record, state, reason, output string, exit *int) {
	rec.State, rec.Reason, rec.Output, rec.ExitCode = state, reason, output, exit
	rec.FinishedAt = ts(e.now())
	if err := e.Store.Put(rec); err != nil {
		e.log().Error("persist terminal state", "job", j.JobID, "err", err)
	}
	e.reportRecord(ctx, rec, j.Attempt)
}

func capText(s string, max int) string {
	if len(s) <= max {
		return s
	}
	return s[:max] + "\n[output truncated]"
}

func (e *Executor) runScript(ctx context.Context, j api.Job, timeout time.Duration, maxOut int) (state, reason, output string, exit *int) {
	sc, err := BuildScriptCommand(j.Script, j.Params)
	if err != nil {
		return StateFailed, "cannot_launch", err.Error(), nil
	}
	res := RunBounded(ctx, ExecSpec{Name: sc.Name, Args: sc.Args, Env: sc.Env, Cleanup: sc.Cleanup, Timeout: timeout, MaxOutput: maxOut, Secrets: e.secrets()})
	if res.HaveExit {
		c := res.ExitCode
		exit = &c
	}
	switch {
	case res.StartErr != nil && !res.HaveExit:
		return StateFailed, "cannot_launch", Redact(res.StartErr.Error()), nil
	case res.TimedOut:
		return StateTimedOut, "timeout", res.Output, exit
	case res.Cancelled:
		return StateCancelled, "agent_stopping", res.Output, exit
	case res.HaveExit && res.ExitCode == 0:
		return StateSucceeded, "", res.Output, exit
	default:
		return StateFailed, "nonzero_exit", res.Output, exit
	}
}

// RebootParams are the reboot job params.
type RebootParams struct {
	DelayS int `json:"delay_s"`
}

// processReboot: persist + REPORT succeeded first, then schedule. A reboot is
// destructive and is never retried automatically.
func (e *Executor) processReboot(ctx context.Context, j api.Job, rec Record) {
	var p RebootParams
	if len(j.Params) > 0 {
		_ = json.Unmarshal(j.Params, &p)
	}
	if p.DelayS == 0 {
		p.DelayS = 30
	}
	if p.DelayS < 5 {
		p.DelayS = 5
	}
	if p.DelayS > 3600 {
		p.DelayS = 3600
	}
	rec.State, rec.Output = StateSucceeded, fmt.Sprintf("reboot scheduled in %d seconds", p.DelayS)
	zero := 0
	rec.ExitCode = &zero
	rec.FinishedAt = ts(e.now())
	if err := e.Store.Put(rec); err != nil {
		e.log().Error("persist terminal state; not rebooting", "job", j.JobID, "err", err)
		return
	}
	e.reportRecord(ctx, rec, j.Attempt) // report BEFORE acting (best effort, bounded retries)
	if e.Rebooter == nil {
		e.log().Error("no rebooter configured", "job", j.JobID)
		return
	}
	if err := e.Rebooter.Schedule(time.Duration(p.DelayS) * time.Second); err != nil {
		e.log().Error("reboot scheduling failed after success was reported", "job", j.JobID, "err", err)
	}
}

func (e *Executor) toReport(r Record, attempt int) api.JobReport {
	rep := api.JobReport{JobID: r.JobID, Attempt: attempt, State: r.State, ExitCode: r.ExitCode, Output: r.Output}
	if r.StartedAt != "" {
		s := r.StartedAt
		rep.StartedAt = &s
	}
	if r.FinishedAt != "" {
		f := r.FinishedAt
		rep.FinishedAt = &f
	}
	return rep
}

// reportOnce sends the non-terminal `running` transition, best effort.
func (e *Executor) reportOnce(ctx context.Context, r Record, attempt int) {
	rctx, cancel := context.WithTimeout(ctx, 30*time.Second)
	defer cancel()
	if err := e.Reporter.ReportJob(rctx, e.toReport(r, attempt)); err != nil {
		e.noteReportErr(err)
	}
}

func (e *Executor) noteReportErr(err error) bool {
	var ae *api.APIError
	if errors.As(err, &ae) && ae.Revoked() {
		if e.OnRevoked != nil {
			e.OnRevoked(err)
		}
		return true
	}
	return false
}

// reportRecord reports a terminal transition with retries on transient
// failure and marks the record acknowledged.
func (e *Executor) reportRecord(ctx context.Context, r Record, attempt int) {
	attempts := e.Attempts
	if attempts <= 0 {
		attempts = 5
	}
	bo := e.Backoff
	if bo.Base == 0 {
		bo = api.Backoff{Base: time.Second, Max: 30 * time.Second}
	}
	for i := 0; i < attempts; i++ {
		rctx, cancel := context.WithTimeout(context.WithoutCancel(ctx), 30*time.Second)
		err := e.Reporter.ReportJob(rctx, e.toReport(r, attempt))
		cancel()
		if err == nil {
			r.Reported, r.Output = true, ""
			_ = e.Store.Put(r)
			return
		}
		if e.noteReportErr(err) {
			return
		}
		var ae *api.APIError
		if errors.As(err, &ae) && !ae.Transient() && !ae.AuthRejected() {
			e.log().Warn("server rejected job report; giving up", "job", r.JobID, "err", err)
			r.Reported, r.Output = true, ""
			_ = e.Store.Put(r)
			return
		}
		d := bo.Delay(i)
		if ae != nil && ae.RetryAfter > d {
			d = ae.RetryAfter
		}
		select {
		case <-time.After(d):
		case <-ctx.Done():
			// shutting down: leave unreported; Flush resends next start
			return
		}
	}
	e.log().Warn("job report not acknowledged; will retry later", "job", r.JobID)
}

// Flush re-sends terminal states whose acknowledgement was lost.
func (e *Executor) Flush(ctx context.Context) {
	for _, r := range e.Store.Unreported() {
		e.mu.Lock()
		busy := e.active[r.JobID]
		e.mu.Unlock()
		if busy {
			continue
		}
		e.reportRecord(ctx, r, r.Attempt)
	}
}
