package logs

import (
	"context"
	"encoding/json"
	"errors"
	"log/slog"
	"sync"
	"time"

	"rivetit-agent/internal/api"
)

// Reader reads the events of one source kind after a position.
type Reader interface {
	Read(ctx context.Context, req ReadRequest) (ReadResult, error)
}

// SendFunc posts one agent_logs body.
type SendFunc func(ctx context.Context, body []byte) (*api.LogAck, error)

// Options configure a Manager.
type Options struct {
	Readers   map[string]Reader // by source kind ("journal", "eventlog")
	Send      SendFunc
	StatePath string // logs_state.json ("" = bookmarks are not persisted)
	Now       func() time.Time
	Redact    func(string) string
	Log       *slog.Logger
	Backoff   api.Backoff
	OnRevoked func(err error) // a 401 revoked from agent_logs
}

type pendingItem struct {
	key  string
	pos  string
	ev   api.LogEvent
	size int
}

// Manager owns the collection loop. Configure is the only method other
// goroutines call; everything else runs on the goroutine of Run (or a test's Tick).
type Manager struct {
	o Options

	mu        sync.Mutex
	want      *Config // the latest config the agent set (nil = collect nothing)
	wake      chan struct{}
	version   string
	active    bool
	committed map[string]string // acknowledged positions (persisted)
	read      map[string]string // positions read ahead of committed (memory)
	consumed  map[string]string // positions of dropped events, committed on the next ack
	backlog   []pendingItem
	dropped   int
	winStart  time.Time
	winSent   int
	pauseTill time.Time
	fail      int
	srcFail   map[string]int
	warned    map[string]bool
}

// New builds a Manager and loads the persisted bookmarks.
func New(o Options) *Manager {
	if o.Now == nil {
		o.Now = time.Now
	}
	if o.Log == nil {
		o.Log = slog.Default()
	}
	if o.Redact == nil {
		o.Redact = func(s string) string { return s }
	}
	if o.Backoff.Base == 0 {
		o.Backoff = api.DefaultBackoff()
	}
	m := &Manager{o: o, wake: make(chan struct{}, 1), committed: loadBookmarks(o.StatePath)}
	m.resetRun()
	return m
}

func (m *Manager) resetRun() {
	m.read = map[string]string{}
	m.consumed = map[string]string{}
	m.backlog = nil
	m.dropped = 0
	m.winStart, m.winSent = time.Time{}, 0
	m.srcFail = map[string]int{}
	m.warned = map[string]bool{}
}

// Configure sets (or with nil clears) the collection config. It is called after
// every successful check-in; it only wakes the loop when something changed.
func (m *Manager) Configure(c *Config) {
	m.mu.Lock()
	changed := (c == nil) != (m.want == nil) || (c != nil && m.want != nil && c.Version != m.want.Version)
	m.want = c
	m.mu.Unlock()
	if changed {
		select {
		case m.wake <- struct{}{}:
		default:
		}
	}
}

// Active reports whether collection is currently switched on.
func (m *Manager) Active() bool {
	m.mu.Lock()
	defer m.mu.Unlock()
	return m.want != nil
}

// Run blocks until ctx is done, ticking while a config is set.
func (m *Manager) Run(ctx context.Context) {
	for ctx.Err() == nil {
		d := m.Tick(ctx)
		t := time.NewTimer(d)
		select {
		case <-ctx.Done():
			t.Stop()
			return
		case <-m.wake:
			t.Stop()
		case <-t.C:
		}
	}
}

func (m *Manager) config() *Config {
	m.mu.Lock()
	defer m.mu.Unlock()
	return m.want
}

// Tick does one read-and-ship pass and returns how long to wait before the next.
func (m *Manager) Tick(ctx context.Context) time.Duration {
	cfg := m.config()
	if cfg == nil {
		if m.active { // switched off: stop reading, drop the backlog, keep the bookmarks
			m.active = false
			m.version = ""
			m.resetRun()
		}
		return time.Hour
	}
	if !m.active || cfg.Version != m.version {
		// New activation or a new config version: forget in-memory progress (the
		// persisted bookmarks stay) so removed or changed sources leave nothing behind.
		m.resetRun()
		m.active, m.version = true, cfg.Version
	}
	now := m.o.Now()
	if now.Before(m.pauseTill) {
		return m.pauseTill.Sub(now)
	}
	m.readAll(ctx, cfg)
	m.settle()
	wait := cfg.Interval
	if d, failed := m.ship(ctx, cfg); failed {
		wait = max(wait, d)
	}
	m.settle()
	return wait
}

func (m *Manager) backlogFor(key string) bool {
	for _, p := range m.backlog {
		if p.key == key {
			return true
		}
	}
	return false
}

func (m *Manager) readAll(ctx context.Context, cfg *Config) {
	for _, src := range cfg.Sources {
		if ctx.Err() != nil || len(m.backlog) >= backlogCap {
			return
		}
		rd, ok := m.o.Readers[src.Kind]
		key := SourceKey(src)
		if !ok {
			if !m.warned[key] {
				m.warned[key] = true
				m.o.Log.Warn("log source kind not supported on this OS; skipped", "kind", src.Kind)
			}
			continue
		}
		from := m.read[key]
		if from == "" {
			from = m.committed[key]
		}
		res, err := rd.Read(ctx, ReadRequest{Src: src, From: from, Limit: min(readBatch, backlogCap-len(m.backlog))})
		if err != nil {
			m.srcFail[key]++
			if !m.warned[key] {
				m.warned[key] = true
				m.o.Log.Warn("log source read failed; will retry next interval", "kind", src.Kind, "err", err)
			}
			if m.srcFail[key] >= maxFailBeforeReset && from != "" {
				m.o.Log.Warn("log bookmark unusable after repeated failures; restarting that source", "kind", src.Kind)
				delete(m.read, key)
				delete(m.committed, key)
				m.srcFail[key] = 0
				_ = saveBookmarks(m.o.StatePath, m.committed)
			}
			continue
		}
		m.srcFail[key], m.warned[key] = 0, false
		for _, it := range res.Items {
			ev := it.Event
			ev.Message = TruncateUTF8(m.o.Redact(ev.Message), cfg.MaxMessageBytes)
			ev.Source = TruncateUTF8(m.o.Redact(ev.Source), 200)
			m.backlog = append(m.backlog, pendingItem{key: key, pos: it.Pos, ev: ev})
		}
		if res.LastPos != "" {
			m.read[key] = res.LastPos
		}
	}
}

// settle commits the read position of every source that has nothing left
// waiting for an acknowledgement (events that were all filtered out, or that
// were shipped and acknowledged).
func (m *Manager) settle() {
	changed := false
	for key, pos := range m.read {
		if pos == m.committed[key] || m.backlogFor(key) {
			continue
		}
		if _, held := m.consumed[key]; held {
			continue
		}
		m.committed[key] = pos
		changed = true
	}
	if changed {
		if err := saveBookmarks(m.o.StatePath, m.committed); err != nil {
			m.o.Log.Warn("cannot persist log bookmarks", "err", err)
		}
	}
}

func (m *Manager) commit(items []pendingItem) {
	for _, it := range items {
		if it.pos != "" {
			m.committed[it.key] = it.pos
		}
	}
	for k, v := range m.consumed {
		m.committed[k] = v
	}
	m.consumed = map[string]string{}
	if err := saveBookmarks(m.o.StatePath, m.committed); err != nil {
		m.o.Log.Warn("cannot persist log bookmarks", "err", err)
	}
}

// ship posts the backlog in batches within the per-minute budget. Events beyond
// the budget are dropped and counted. It returns the back-off delay on failure.
func (m *Manager) ship(ctx context.Context, cfg *Config) (time.Duration, bool) {
	for ctx.Err() == nil && (len(m.backlog) > 0 || m.dropped > 0) {
		now := m.o.Now()
		if m.winStart.IsZero() || now.Sub(m.winStart) >= time.Minute {
			m.winStart, m.winSent = now, 0
		}
		budget := cfg.RatePerMin - m.winSent
		if budget <= 0 && len(m.backlog) > 0 {
			for _, p := range m.backlog {
				if p.pos != "" {
					m.consumed[p.key] = p.pos
				}
			}
			m.dropped += len(m.backlog)
			m.backlog = nil
			continue
		}
		n := min(cfg.BatchMax, max(budget, 0), len(m.backlog))
		body, n := m.encode(cfg, n)
		ack, err := m.o.Send(ctx, body)
		if err != nil {
			return m.onSendError(cfg, err, n)
		}
		_ = ack
		m.fail = 0
		batch := m.backlog[:n]
		m.backlog = m.backlog[n:]
		m.winSent += n
		m.dropped = 0
		m.commit(batch)
	}
	return 0, false
}

// encode builds the request body for the first n backlog events, shrinking n
// until it is under MaxBodyBytes.
func (m *Manager) encode(cfg *Config, n int) ([]byte, int) {
	for {
		events := make([]api.LogEvent, n)
		for i := 0; i < n; i++ {
			events[i] = m.backlog[i].ev
		}
		body, _ := json.Marshal(api.LogBatch{Events: events, Dropped: m.dropped, ConfigVersion: cfg.Version})
		if len(body) <= MaxBodyBytes || n <= 1 {
			return body, n
		}
		n /= 2
	}
}

func (m *Manager) onSendError(cfg *Config, err error, n int) (time.Duration, bool) {
	var ae *api.APIError
	if !errors.As(err, &ae) {
		m.fail++
		return m.o.Backoff.Delay(m.fail - 1), true
	}
	now := m.o.Now()
	switch {
	case ae.ModuleDisabled():
		d := max(defaultPauseOnOff, ae.RetryAfterRaw)
		m.pauseTill = now.Add(d)
		m.o.Log.Warn("server answered module/feature disabled for log shipping; pausing", "for", d)
		return d, true
	case ae.Revoked():
		if m.o.OnRevoked != nil {
			m.o.OnRevoked(err)
		}
		m.pauseTill = now.Add(time.Minute)
		return time.Minute, true
	case ae.Transient() || ae.AuthRejected():
		m.fail++
		return m.o.Backoff.DelayWithRetryAfter(m.fail-1, ae.RetryAfter), true
	}
	// Any other 4xx will never be accepted: drop this batch so the loop cannot wedge.
	m.o.Log.Warn("server rejected a log batch; dropping it", "events", n, "err", err)
	batch := m.backlog[:n]
	m.backlog = m.backlog[n:]
	m.dropped += n
	for _, p := range batch {
		if p.pos != "" {
			m.consumed[p.key] = p.pos
		}
	}
	m.fail++
	return m.o.Backoff.Delay(m.fail - 1), true
}
