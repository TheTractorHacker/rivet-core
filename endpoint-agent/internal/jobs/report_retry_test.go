package jobs

import (
	"context"
	"encoding/json"
	"fmt"
	"strings"
	"sync"
	"testing"
	"time"

	"rivetit-agent/internal/api"
)

type errReporter struct {
	mu    sync.Mutex
	calls int
	err   error
}

func (r *errReporter) ReportJob(context.Context, api.JobReport) error {
	r.mu.Lock()
	defer r.mu.Unlock()
	r.calls++
	return r.err
}

func unreportedRecord(t *testing.T, e *Executor, id string) {
	t.Helper()
	if err := e.Store.Put(Record{JobID: id, State: StateSucceeded}); err != nil {
		t.Fatal(err)
	}
}

// CORE-DS-4: the jobs feature switched off (503 feature_disabled, Retry-After 3600) must not stall the check-in loop that calls Flush.
func TestFlushDoesNotSleepWhenTheModuleIsDisabled(t *testing.T) {
	k := newKeys(t)
	rep := &errReporter{err: &api.APIError{Status: 503, Code: api.CodeFeatureDisabled, RetryAfter: time.Hour}}
	e := newExec(t, k, OpenStore(""), rep)
	unreportedRecord(t, e, "a")
	unreportedRecord(t, e, "b")
	done := make(chan struct{})
	go func() { e.Flush(context.Background()); close(done) }()
	select {
	case <-done:
	case <-time.After(2 * time.Second):
		t.Fatal("Flush is sleeping on a Retry-After while the module is disabled")
	}
	if rep.calls > 2 {
		t.Errorf("%d report calls for 2 records: the disabled answer should end the attempt", rep.calls)
	}
	if len(e.Store.Unreported()) != 2 {
		t.Error("records must stay unreported so they are sent once the module is back")
	}
}

// CORE-DS-4: a long Retry-After is capped for report retries, and there is no sleep after the final attempt.
func TestReportRetryAfterIsCappedAndFinalAttemptDoesNotSleep(t *testing.T) {
	k := newKeys(t)
	rep := &errReporter{err: &api.APIError{Status: 429, RetryAfter: time.Hour}}
	e := newExec(t, k, OpenStore(""), rep)
	e.Attempts = 3
	e.RetryAfterCap = 20 * time.Millisecond
	unreportedRecord(t, e, "a")
	start := time.Now()
	e.Flush(context.Background())
	if d := time.Since(start); d > 2*time.Second {
		t.Fatalf("took %v", d)
	}
	if rep.calls != 3 {
		t.Fatalf("calls=%d", rep.calls)
	}
}

// CORE-DS-4: Flush has a total time budget so job-report trouble cannot hold the check-in goroutine.
func TestFlushHasATimeBudget(t *testing.T) {
	k := newKeys(t)
	rep := &errReporter{err: &api.APIError{Status: 503, Code: "unavailable", RetryAfter: 50 * time.Millisecond}}
	e := newExec(t, k, OpenStore(""), rep)
	e.Attempts = 100
	e.FlushBudget = 200 * time.Millisecond
	e.RetryAfterCap = 50 * time.Millisecond
	unreportedRecord(t, e, "a")
	unreportedRecord(t, e, "b")
	start := time.Now()
	e.Flush(context.Background())
	if d := time.Since(start); d > 2*time.Second {
		t.Fatalf("Flush ignored its budget: %v", d)
	}
}

// CORE-DS-23: output whose JSON escaping (6 bytes per control byte) pushes the report over the request cap is shortened to fit, never sent over the cap.
func TestToReportShrinksOutputThatEncodesOverTheCap(t *testing.T) {
	e := newExec(t, newKeys(t), OpenStore(""), &errReporter{})
	out := strings.Repeat("\x00", 200000) // 200000 bytes, about 1.2 MB once JSON-escaped
	rep := e.toReport(Record{JobID: "j", State: StateSucceeded, Output: out}, 1)
	b, err := json.Marshal(rep)
	if err != nil {
		t.Fatal(err)
	}
	if len(b) > api.MaxRequestBytes-reportMargin {
		t.Fatalf("encoded report is %d bytes", len(b))
	}
	if len(rep.Output) < 100000 || !strings.Contains(rep.Output, "truncated") {
		t.Fatalf("kept %d bytes, marker missing: %q", len(rep.Output), rep.Output[len(rep.Output)-60:])
	}
	// a normal report is untouched
	if rep := e.toReport(Record{JobID: "j", State: StateSucceeded, Output: "hello"}, 1); rep.Output != "hello" {
		t.Fatal(rep.Output)
	}
}

// CORE-DS-23: a report the client refuses as too large can never succeed; the record is acknowledged locally instead of retried for ever.
func TestReportTooLargeIsGivenUp(t *testing.T) {
	k := newKeys(t)
	rep := &errReporter{err: fmt.Errorf("%w: 1 bytes", api.ErrRequestTooLarge)}
	e := newExec(t, k, OpenStore(""), rep)
	unreportedRecord(t, e, "a")
	e.Flush(context.Background())
	if len(e.Store.Unreported()) != 0 || rep.calls != 1 {
		t.Fatalf("unreported=%d calls=%d", len(e.Store.Unreported()), rep.calls)
	}
}
