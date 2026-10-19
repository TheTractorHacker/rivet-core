package change

import (
	"context"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"log/slog"
	"sort"
	"strings"
	"sync"
	"time"

	"rivetit-agent/internal/api"
	"rivetit-agent/internal/remote"
)

// SendFunc posts one agent_changes body.
type SendFunc func(ctx context.Context, body []byte) (*api.ChangeAck, error)

// Options configure a Manager.
type Options struct {
	Registry   Registry      // the Windows registry (nil off Windows)
	Runner     remote.Runner // net.exe, powershell.exe and wevtutil.exe (Windows)
	Send       SendFunc
	StatePath  string // change_state.json ("" = nothing is persisted)
	Now        func() time.Time
	Redact     func(string) string
	Log        *slog.Logger
	Backoff    api.Backoff
	OnRevoked  func(err error) // a 401 revoked from agent_changes
	GOOS       string          // "windows" or "linux"
	ExtraRoots func() []string // extra file roots allowed by the local configuration (read when a configuration is parsed and when files are read)
	MapPath    func(string) string
	TaskRoot   string // the Windows task definitions folder
	Verify     VerifyFunc
	// StartNotifier starts the registry change notification (Windows); nil = polling only.
	StartNotifier func(roots []NotifyRoot, wake func()) func()
}

// Tuning of the engine, not of the server.
const (
	pendingCap        = 2000
	perPollCap        = 500
	defaultMaxItems   = 5000
	maxBodyBytes      = 200000
	statusEvery       = 6 * time.Hour
	minNotifyGap      = 10 * time.Second
	defaultPauseOnOff = 15 * time.Minute
	// withdrawGrace is how long the snapshots and the unsent records are kept after the server stops offering monitoring: a server under load
	// stops offering it for a while, and a reading thrown away then would miss every change made in the gap. After it, nothing stays on disk.
	withdrawGrace = 24 * time.Hour
)

type engine struct {
	o      Options
	hasher Hasher

	mu   sync.Mutex
	want *Config
	wake chan struct{}

	active   bool
	version  string
	state    *stateFile
	maxItems int
	maxValue int

	lastPoll      time.Time
	forcePoll     bool
	notifyWake    chan struct{}
	notifyStop    func()
	winStart      time.Time
	winSent       int
	pauseTill     time.Time
	fail          int
	statusJSON    string
	statusSentAt  time.Time
	statusPending bool
	actorOffUntil time.Time
	warned        map[string]bool
	baselineAt    string
	highSeq       int64 // the highest configuration seq accepted (mu); a lower one is a replay
	withdrawn     bool  // Configure(nil) was called since the last Tick
	wipeReq       bool  // Wipe was called since the last Tick
}

// Manager owns the change-monitoring loop. Configure is the only method other
// goroutines call; everything else runs on the goroutine of Run (or a test's Tick).
type Manager struct{ *engine }

// New builds a Manager and loads the persisted state.
func New(o Options) *Manager {
	if o.Now == nil {
		o.Now = time.Now
	}
	if o.Log == nil {
		o.Log = slog.Default()
	}
	if o.Backoff.Base == 0 {
		o.Backoff = api.DefaultBackoff()
	}
	if o.StartNotifier == nil {
		o.StartNotifier = StartNotifier
	}
	e := &engine{o: o, wake: make(chan struct{}, 1), notifyWake: make(chan struct{}, 1), maxItems: defaultMaxItems, maxValue: MaxValueBytes, warned: map[string]bool{}}
	e.state = loadState(o.StatePath)
	e.highSeq = e.state.Seq
	if k, err := hex.DecodeString(e.state.Key); err == nil {
		e.hasher = Hasher{Key: k}
	}
	return &Manager{e}
}

func (e *engine) now() time.Time { return e.o.Now() }

func (e *engine) extraRoots() []string {
	if e.o.ExtraRoots == nil {
		return nil
	}
	return e.o.ExtraRoots()
}

// Configure sets (or with nil clears) the configuration, verifying it first. It is
// called after every successful check-in. A configuration that fails to verify or
// does not fit this agent is refused and the last good one stays in force.
func (e *engine) Configure(raw json.RawMessage) error {
	if len(raw) == 0 {
		e.set(nil)
		return nil
	}
	cfg, err := Parse(raw, ParseOptions{Verify: e.o.Verify, Platform: e.o.GOOS, ExtraRoots: e.extraRoots()})
	if err != nil {
		if !e.warned[err.Error()] {
			e.warned[err.Error()] = true
			e.o.Log.Error("change-monitoring configuration refused; keeping the last good one", "err", err)
		}
		return err
	}
	e.mu.Lock()
	if cfg.Seq < e.highSeq {
		e.mu.Unlock()
		err := fmt.Errorf("change configuration seq %d is older than %d already accepted (a replay)", cfg.Seq, e.highSeq)
		if !e.warned[err.Error()] {
			e.warned[err.Error()] = true
			e.o.Log.Error("change-monitoring configuration refused; keeping the last good one", "err", err)
		}
		return err
	}
	e.highSeq = cfg.Seq
	e.mu.Unlock()
	e.set(cfg)
	return nil
}

func (e *engine) set(c *Config) {
	e.mu.Lock()
	changed := (c == nil) != (e.want == nil) || (c != nil && e.want != nil && c.Version != e.want.Version)
	e.want = c
	if c == nil {
		e.withdrawn = true
	}
	e.mu.Unlock()
	if changed {
		select {
		case e.wake <- struct{}{}:
		default:
		}
	}
}

// Active reports whether monitoring is currently switched on.
func (e *engine) Active() bool {
	e.mu.Lock()
	defer e.mu.Unlock()
	return e.want != nil
}

func (e *engine) config() *Config {
	e.mu.Lock()
	defer e.mu.Unlock()
	return e.want
}

// Run blocks until ctx is done, ticking while a configuration is set.
func (e *engine) Run(ctx context.Context) {
	for ctx.Err() == nil {
		d := e.Tick(ctx)
		t := time.NewTimer(d)
		select {
		case <-ctx.Done():
			t.Stop()
			e.stopNotifier()
			return
		case <-e.wake:
			t.Stop()
		case <-e.notifyWake:
			t.Stop()
			e.forcePoll = true
		case <-t.C:
		}
	}
	e.stopNotifier()
}

func (e *engine) stopNotifier() {
	if e.notifyStop != nil {
		e.notifyStop()
		e.notifyStop = nil
	}
}

// idle runs while nothing is offered. The first pass after the offer was withdrawn stops the reading and starts the clock; what was kept (snapshots, unsent
// records) is deleted when the offer has not come back within withdrawGrace.
func (e *engine) idle() time.Duration {
	e.mu.Lock()
	withdrawn, wipe := e.withdrawn, e.wipeReq
	e.withdrawn, e.wipeReq = false, false
	e.mu.Unlock()
	if wipe {
		if len(e.state.Watches) > 0 || len(e.state.Pending) > 0 || e.state.Dropped > 0 || e.active {
			e.wipe()
		}
		return time.Hour
	}
	if e.active {
		e.active = false
		e.version = ""
		e.stopNotifier()
	}
	if len(e.state.Watches) == 0 && len(e.state.Pending) == 0 && e.state.Dropped == 0 {
		e.state.WithdrawnAt = ""
		return time.Hour
	}
	now := e.now()
	if e.state.WithdrawnAt == "" {
		if !withdrawn {
			return time.Hour // no offer yet since the agent started: nothing was withdrawn
		}
		e.state.WithdrawnAt = now.UTC().Format(time.RFC3339)
		e.save()
	}
	t, err := time.Parse(time.RFC3339, e.state.WithdrawnAt)
	if err != nil || now.Sub(t) >= withdrawGrace {
		e.wipe()
		return time.Hour
	}
	return min(time.Hour, withdrawGrace-now.Sub(t))
}

// Wipe asks the loop to forget everything now: snapshots, unsent records, the drop counter. The local kill switch (disable_change) calls it on every check-in
// (it is a no-op once there is nothing left); a withdrawn offer waits withdrawGrace first. Like Configure it is safe to call from another goroutine.
func (e *engine) Wipe() {
	e.mu.Lock()
	e.want = nil
	e.wipeReq = true
	e.mu.Unlock()
	select {
	case e.wake <- struct{}{}:
	default:
	}
}

func (e *engine) wipe() {
	e.active = false
	e.version = ""
	e.stopNotifier()
	e.state.Watches = map[string]*watchState{}
	e.state.Pending, e.state.Dropped, e.state.WithdrawnAt = nil, 0, ""
	e.statusJSON, e.statusPending = "", false
	e.save()
}

func (e *engine) activate(cfg *Config) {
	e.active, e.version = true, cfg.Version
	e.maxValue = cfg.MaxValueBytes
	e.statusPending = true
	e.lastPoll = time.Time{}
	// drop the snapshots of watches the configuration no longer names (nothing is kept for a watch that is gone)
	named := map[string]bool{}
	for _, w := range cfg.Watches {
		named[w.ID] = true
	}
	for id := range e.state.Watches {
		if !named[id] {
			delete(e.state.Watches, id)
		}
	}
	for _, r := range cfg.Refused {
		e.o.Log.Warn("change monitoring: part of the signed configuration was not used", "why", r)
	}
	e.stopNotifier()
	if e.o.GOOS == "windows" && e.o.Registry != nil && e.o.StartNotifier != nil {
		var roots []NotifyRoot
		for _, w := range cfg.Watches {
			if w.Kind != "registry" {
				continue
			}
			for _, p := range w.Paths {
				if !strings.Contains(p.Key, "*") {
					roots = append(roots, NotifyRoot{Key: p.Key, Subtree: p.Depth > 0})
				}
			}
		}
		if len(roots) > 0 {
			e.notifyStop = e.o.StartNotifier(roots, func() {
				select {
				case e.notifyWake <- struct{}{}:
				default:
				}
			})
		}
	}
	e.save()
}

func (e *engine) save() {
	e.mu.Lock()
	e.state.Seq = e.highSeq
	e.mu.Unlock()
	if err := e.state.save(e.o.StatePath); err != nil {
		e.o.Log.Warn("cannot persist change-monitoring state", "err", err)
	}
}

// Tick does one poll-and-ship pass and returns how long to wait before the next.
func (e *engine) Tick(ctx context.Context) time.Duration {
	cfg := e.config()
	if cfg == nil {
		return e.idle()
	}
	e.state.WithdrawnAt = ""
	if !e.active || cfg.Version != e.version {
		e.activate(cfg)
	}
	now := e.now()
	if now.Before(e.pauseTill) {
		return e.pauseTill.Sub(now)
	}
	due := e.lastPoll.IsZero() || now.Sub(e.lastPoll) >= cfg.Interval || (e.forcePoll && now.Sub(e.lastPoll) >= minNotifyGap)
	if due {
		e.forcePoll = false
		e.poll(ctx, cfg)
		e.lastPoll = now
	}
	wait := cfg.Interval - e.now().Sub(e.lastPoll)
	if d, failed := e.ship(ctx, cfg); failed {
		wait = d // a back-off (or a pause the server asked for) is waited in full; polling resumes with it
	} else if len(e.state.Pending) > 0 {
		wait = min(wait, e.rateWait())
	}
	return max(wait, time.Second)
}

// rateWait is how long until the per-minute budget has room again.
func (e *engine) rateWait() time.Duration {
	left := time.Minute - e.now().Sub(e.winStart)
	if left < time.Second {
		left = time.Second
	}
	return left
}

// poll reads every watch, compares it with the snapshot and queues the changes.
func (e *engine) poll(ctx context.Context, cfg *Config) {
	now := e.now()
	var statuses []watchStatus
	var fresh []Record
	for _, w := range cfg.Watches {
		if ctx.Err() != nil {
			return
		}
		snap, unreadable, notes, ok := e.read(ctx, w)
		st := watchStatus{ID: w.ID, State: "ok", Items: len(snap), Error: firstNote(notes)}
		if !ok {
			st.State = "error"
			statuses = append(statuses, st)
			continue
		}
		if len(notes) > 0 {
			st.State = "partial"
		}
		ws := e.state.Watches[w.ID]
		if ws == nil || ws.Spec != w.Hash() {
			// first sight of this watch (or its specification changed): the baseline, silently
			e.state.Watches[w.ID] = &watchState{Spec: w.Hash(), At: now.UTC().Format(time.RFC3339), Snap: snap}
			if e.baselineAt == "" {
				e.baselineAt = now.UTC().Format(time.RFC3339)
			}
			st.State = "baseline"
			statuses = append(statuses, st)
			continue
		}
		carry(w.Kind, ws.Snap, snap, unreadable)
		st.Items = len(snap)
		recs := Diff(w, ws.Snap, snap, now, cfg.MaxValueBytes)
		ws.Snap, ws.At = snap, now.UTC().Format(time.RFC3339)
		if len(recs) > perPollCap {
			e.state.Dropped += len(recs) - perPollCap
			recs = recs[:perPollCap]
			st.State, st.Error = "partial", fmt.Sprintf("more than %d changes in one poll; the rest were not reported", perPollCap)
		}
		fresh = append(fresh, recs...)
		statuses = append(statuses, st)
	}
	if len(fresh) > 0 {
		e.findActors(ctx, fresh)
		for _, r := range fresh {
			r = e.finish(r)
			if len(e.state.Pending) >= pendingCap {
				e.state.Dropped++
				continue
			}
			e.state.Pending = append(e.state.Pending, r)
		}
	}
	sj := statusBody(statuses, e.baselineAt)
	if sj != e.statusJSON {
		e.statusJSON, e.statusPending = sj, true
	}
	e.save()
}

// finish applies the last guard to a record just before it is queued: the texts are
// cleaned, redacted and cut to the server's limits again.
func (e *engine) finish(r Record) Record {
	cl := func(s string, max int) string {
		t, _, _ := Display(s, max, e.o.Redact)
		return t
	}
	r.Target = Truncate(Clean(r.Target), 300)
	r.Item = Truncate(Clean(r.Item), 200)
	if r.Kind == "file" {
		// the lines of a file were redacted when the snapshot was taken (the sudoers tags NOPASSWD: and PASSWD: are exempt there); redacting the joined text
		// again would mask them: only the cleaning and the cut are repeated, and the server redacts once more
		r.Old, r.New = Truncate(Clean(r.Old), e.maxValue), Truncate(Clean(r.New), e.maxValue)
		r.Summary = Truncate(Clean(r.Summary), 200)
	} else {
		r.Old, r.New = cl(r.Old, e.maxValue), cl(r.New, e.maxValue)
		r.Summary = cl(r.Summary, 200)
	}
	r.Actor = Truncate(Clean(r.Actor), 120)
	r.Process = cl(r.Process, 200)
	return r
}

// read dispatches on the watch kind. It never panics out of a watch: an
// unexpected failure is a watch in error, not a dead agent.
func (e *engine) read(ctx context.Context, w Watch) (snap Snapshot, unreadable, notes []string, ok bool) {
	defer func() {
		if r := recover(); r != nil {
			e.o.Log.Error("change monitoring: a watch failed unexpectedly", "watch", w.ID, "panic", fmt.Sprint(r))
			snap, unreadable, notes, ok = nil, nil, []string{"the watch failed unexpectedly"}, false
		}
	}()
	switch w.Kind {
	case "registry":
		if e.o.Registry == nil {
			return nil, nil, []string{"the registry is not available on this OS"}, false
		}
		snap, unreadable, notes = e.readRegistry(w)
	case "file":
		snap, unreadable, notes = e.readFiles(w)
	case "group":
		if e.o.Runner == nil {
			return nil, nil, []string{"no command runner"}, false
		}
		snap, unreadable, notes = e.readGroup(ctx, w)
	case "task":
		root := e.o.TaskRoot
		if root == "" {
			return nil, nil, []string{"no task folder"}, false
		}
		snap, unreadable, notes = e.readTasks(root)
	default:
		return nil, nil, []string{"unknown watch kind"}, false
	}
	return snap, unreadable, notes, true
}

// carry keeps, in the new snapshot, what the previous one knew under a place the
// new read could not look into: an unreadable key or file is not a removed one.
func carry(kind string, prev, cur Snapshot, unreadable []string) {
	if len(unreadable) == 0 {
		return
	}
	for k, it := range prev {
		if _, ok := cur[k]; ok {
			continue
		}
		for _, u := range unreadable {
			if under(kind, it, u) {
				cur[k] = it
				break
			}
		}
	}
}

func under(kind string, it Item, prefix string) bool {
	switch kind {
	case "registry":
		t, p := strings.ToLower(it.Target), strings.ToLower(prefix)
		return t == p || strings.HasPrefix(t, p+`\`)
	case "task":
		n, p := strings.ToLower(it.Name), strings.ToLower(prefix)
		return p == `\` || n == p || strings.HasPrefix(n, p+`\`)
	case "group":
		return it.Target == prefix
	}
	return it.Target == prefix || strings.HasPrefix(it.Target, prefix+"/") || strings.HasPrefix(it.Target, prefix+`\`)
}

type watchStatus struct {
	ID    string `json:"id"`
	State string `json:"state"`
	Items int    `json:"items"`
	Error string `json:"error,omitempty"`
}

func firstNote(notes []string) string {
	if len(notes) == 0 {
		return ""
	}
	return Truncate(Clean(notes[0]), 160)
}

func statusBody(st []watchStatus, baselineAt string) string {
	sort.Slice(st, func(i, j int) bool { return st[i].ID < st[j].ID })
	b, _ := json.Marshal(struct {
		Watches    []watchStatus `json:"watches"`
		BaselineAt string        `json:"baseline_at,omitempty"`
	}{st, baselineAt})
	return string(b)
}

// ---- shipping ----

type batchBody struct {
	Changes       []Record        `json:"changes"`
	Dropped       int             `json:"dropped"`
	ConfigVersion string          `json:"config_version"`
	Status        json.RawMessage `json:"status,omitempty"`
}

// ship posts the queue in batches within the per-minute budget. Records over the
// budget wait for the next minute (they are not dropped); only a full queue drops.
// It returns the back-off delay on failure.
func (e *engine) ship(ctx context.Context, cfg *Config) (time.Duration, bool) {
	for ctx.Err() == nil {
		statusDue := e.statusPending || (e.statusJSON != "" && e.now().Sub(e.statusSentAt) >= statusEvery)
		if len(e.state.Pending) == 0 && e.state.Dropped == 0 && !statusDue {
			return 0, false
		}
		now := e.now()
		if e.winStart.IsZero() || now.Sub(e.winStart) >= time.Minute {
			e.winStart, e.winSent = now, 0
		}
		budget := cfg.RatePerMin - e.winSent
		if budget <= 0 && len(e.state.Pending) > 0 {
			return 0, false // wait for the next minute
		}
		n := min(cfg.BatchMax, max(budget, 0), len(e.state.Pending))
		body, n, withStatus := e.encode(cfg, n, statusDue)
		_, err := e.o.Send(ctx, body)
		if err != nil {
			return e.onSendError(err, n)
		}
		e.fail = 0
		e.state.Pending = append([]Record(nil), e.state.Pending[n:]...)
		e.winSent += n
		e.state.Dropped = 0
		if withStatus {
			e.statusPending, e.statusSentAt = false, now
		}
		e.save()
	}
	return 0, false
}

// encode builds the request body for the first n pending records, shrinking n until
// it is under maxBodyBytes.
func (e *engine) encode(cfg *Config, n int, withStatus bool) ([]byte, int, bool) {
	for {
		b := batchBody{Changes: append([]Record{}, e.state.Pending[:n]...), Dropped: e.state.Dropped, ConfigVersion: cfg.Version}
		if withStatus && e.statusJSON != "" {
			b.Status = json.RawMessage(e.statusJSON)
		} else {
			withStatus = false
		}
		body, _ := json.Marshal(b)
		if len(body) <= maxBodyBytes || n <= 1 {
			return body, n, withStatus
		}
		n /= 2
	}
}

func (e *engine) onSendError(err error, n int) (time.Duration, bool) {
	var ae *api.APIError
	if !errors.As(err, &ae) {
		e.fail++
		return e.o.Backoff.Delay(e.fail - 1), true
	}
	now := e.now()
	switch {
	case ae.ModuleDisabled():
		if ae.Code == api.CodeFeatureDisabled {
			e.o.Log.Warn("server answered that change monitoring is switched off; forgetting what was kept")
			e.wipe()
		}
		d := max(defaultPauseOnOff, ae.RetryAfterRaw)
		e.pauseTill = now.Add(d)
		e.o.Log.Warn("server answered module/feature disabled for change reports; pausing", "for", d)
		return d, true
	case ae.Revoked():
		if e.o.OnRevoked != nil {
			e.o.OnRevoked(err)
		}
		e.pauseTill = now.Add(time.Minute)
		return time.Minute, true
	case ae.Transient() || ae.AuthRejected():
		e.fail++
		return e.o.Backoff.DelayWithRetryAfter(e.fail-1, ae.RetryAfter), true
	}
	// Any other 4xx will never be accepted: drop this batch so the loop cannot wedge.
	e.o.Log.Warn("server rejected a change batch; dropping it", "records", n, "err", err)
	e.state.Pending = append([]Record(nil), e.state.Pending[min(n, len(e.state.Pending)):]...)
	e.state.Dropped += n
	e.fail++
	e.save()
	return e.o.Backoff.Delay(e.fail - 1), true
}
