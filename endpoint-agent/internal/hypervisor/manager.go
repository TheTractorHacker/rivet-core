package hypervisor

import (
	"context"
	"encoding/json"
	"errors"
	"log/slog"
	"os"
	"runtime"
	"sort"
	"strconv"
	"strings"
	"sync"
	"time"

	"rivetit-agent/internal/api"
	"rivetit-agent/internal/store"
)

// Supported reports whether this build can collect hypervisor data at all.
func Supported() bool { return runtime.GOOS == "linux" || runtime.GOOS == "windows" }

// Platform is the platform name the server uses in the signed configuration.
func GOOS() string { return runtime.GOOS }

const (
	notHostRecheck = 10 * time.Minute
	errorRetry     = 5 * time.Minute
	workerTimeout  = 4 * time.Minute
)

// ManagerOptions wire a Manager to the machine.
type ManagerOptions struct {
	Detect Detector
	Verify VerifyFunc
	GOOS   string
	Now    func() time.Time
	Log    *slog.Logger
	// SeqPath is where the highest configuration seq ever accepted is kept (hypervisor_seq, 0600). "" = kept in memory only. The seq survives a restart and a
	// Configure(nil), so an older signed configuration cannot be replayed after the agent restarts or the feature was switched off and on again.
	SeqPath string
}

// Manager decides when to collect and what to put in the next check-in. It
// does nothing until Configure has been given a verified, signed
// configuration, and it stops (and forgets) when Configure(nil) is called. The
// slow commands run in one background goroutine, never inside the check-in.
type Manager struct {
	o ManagerOptions

	mu          sync.Mutex
	cfg         *Config
	gen         int // bumped by every Configure(nil) and every new configuration; a stale worker's result is dropped
	cancel      context.CancelFunc
	coll        Collector
	notHost     time.Time // no recheck before
	running     bool
	lastFullAt  time.Time // last time a full collection finished (success or not)
	lastProbeAt time.Time
	baseline    map[string]string // vm id -> state, as of the last acknowledged report
	ready       *api.HypervisorReport
	readyStates map[string]string
	pending     *api.HypervisorReport
	pendStates  map[string]string
	highSeq     int64 // the highest configuration seq accepted, kept across reset() and (with SeqPath) across restarts
}

func New(o ManagerOptions) *Manager {
	if o.Now == nil {
		o.Now = time.Now
	}
	if o.Log == nil {
		o.Log = slog.Default()
	}
	m := &Manager{o: o}
	m.highSeq = readSeq(o.SeqPath)
	return m
}

// readSeq reads the persisted seq (0 when there is none or it is unreadable: a missing file means this agent never accepted one).
func readSeq(path string) int64 {
	if path == "" {
		return 0
	}
	b, err := os.ReadFile(path)
	if err != nil {
		return 0
	}
	n, err := strconv.ParseInt(strings.TrimSpace(string(b)), 10, 64)
	if err != nil || n < 0 {
		return 0
	}
	return n
}

func (m *Manager) persistSeq() {
	if m.o.SeqPath == "" {
		return
	}
	if err := store.WriteFileAtomic(m.o.SeqPath, []byte(strconv.FormatInt(m.highSeq, 10)+"\n"), 0o600); err != nil {
		m.o.Log.Warn("could not save the hypervisor configuration seq", "err", err)
	}
}

// Configure applies config.hypervisor (raw, signed). nil switches collection off.
func (m *Manager) Configure(raw json.RawMessage) error {
	if raw == nil {
		m.reset()
		return nil
	}
	cfg, err := Parse(raw, m.o.GOOS, m.o.Verify)
	if err != nil {
		if errors.Is(err, ErrNoConfig) {
			m.reset()
			return nil
		}
		m.o.Log.Warn("hypervisor configuration refused; the last good one stays in force", "err", err)
		return err
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	if cfg.Seq < m.highSeq {
		m.o.Log.Warn("hypervisor configuration is older than one already accepted (a replay); ignored", "seq", cfg.Seq, "have", m.highSeq)
		return errors.New("stale configuration")
	}
	if cfg.Seq > m.highSeq {
		m.highSeq = cfg.Seq
		m.persistSeq()
	}
	if m.cfg == nil || m.cfg.Version != cfg.Version {
		m.lastFullAt, m.lastProbeAt = time.Time{}, time.Time{} // a changed configuration collects at once
		m.gen++
	}
	m.cfg = cfg
	return nil
}

func (m *Manager) reset() {
	m.mu.Lock()
	defer m.mu.Unlock()
	if m.cancel != nil {
		m.cancel()
		m.cancel = nil
	}
	m.gen++
	m.cfg, m.coll, m.ready, m.pending, m.baseline, m.readyStates, m.pendStates = nil, nil, nil, nil, nil, nil, nil
	m.lastFullAt, m.lastProbeAt, m.notHost = time.Time{}, time.Time{}, time.Time{}
}

// Active reports whether a configuration is in force.
func (m *Manager) Active() bool {
	m.mu.Lock()
	defer m.mu.Unlock()
	return m.cfg != nil
}

// Build returns the report the next check-in should carry, or nil. It never
// blocks on a command: when a collection is due it starts one in the background
// and the result rides a later check-in.
func (m *Manager) Build() *api.HypervisorReport {
	m.mu.Lock()
	defer m.mu.Unlock()
	if m.cfg == nil {
		return nil
	}
	if m.pending != nil {
		return m.pending // an unacknowledged one is re-offered until it is acked or abandoned
	}
	if m.ready != nil {
		m.pending, m.pendStates, m.ready, m.readyStates = m.ready, m.readyStates, nil, nil
		return m.pending
	}
	now := m.o.Now()
	if m.running || (!m.notHost.IsZero() && now.Before(m.notHost)) {
		return nil
	}
	fullDue := m.lastFullAt.IsZero() || now.Sub(m.lastFullAt) >= m.cfg.FullInterval
	probeDue := m.baseline != nil && !fullDue && now.Sub(m.lastProbeAt) >= m.cfg.SummaryInterval
	if !fullDue && !probeDue {
		return nil
	}
	m.running = true
	ctx, cancel := context.WithTimeout(context.Background(), workerTimeout)
	m.cancel = cancel
	go m.work(ctx, m.gen, fullDue, *m.cfg)
	return nil
}

func (m *Manager) work(ctx context.Context, gen int, full bool, cfg Config) {
	defer func() {
		if r := recover(); r != nil {
			m.o.Log.Error("hypervisor collector panicked; it will retry later", "panic", r)
			m.finish(gen, nil, nil, true)
		}
	}()
	m.mu.Lock()
	coll := m.coll
	m.mu.Unlock()
	if coll == nil {
		c, err := m.o.Detect(ctx)
		if err != nil || c == nil {
			m.mu.Lock()
			if m.gen == gen {
				m.notHost = m.o.Now().Add(notHostRecheck)
				m.running = false
			}
			m.mu.Unlock()
			if err != nil && !errors.Is(err, ErrNotHost) {
				m.o.Log.Warn("hypervisor detection failed", "err", err)
			}
			return
		}
		coll = c
		m.mu.Lock()
		if m.gen == gen {
			m.coll = c
		}
		m.mu.Unlock()
	}
	opt := Options{MaxVMs: cfg.MaxVMs, SnapshotLookups: cfg.SnapshotLookups}
	if full {
		rep, err := coll.Full(ctx, opt)
		if rep == nil {
			rep = &api.HypervisorReport{Mode: "full", Platform: coll.Platform(), CollectedAt: m.o.Now().UTC().Format(time.RFC3339), Error: cleanText(errText(err), MaxErrorLen)}
		}
		rep.CfgVersion = cfg.Version
		fit(rep)
		m.finish(gen, rep, statesOf(rep), true)
		return
	}
	st, err := coll.States(ctx, opt)
	if err != nil {
		m.finish(gen, nil, nil, false) // a failed probe is silent; the next full collection reports problems
		return
	}
	m.mu.Lock()
	base := m.baseline
	m.mu.Unlock()
	rep := summary(coll.Platform(), base, st, m.o.Now(), cfg.Version)
	m.finish(gen, rep, st, false)
}

func (m *Manager) finish(gen int, rep *api.HypervisorReport, states map[string]string, wasFull bool) {
	m.mu.Lock()
	defer m.mu.Unlock()
	if m.gen != gen {
		return // the configuration changed or was switched off meanwhile
	}
	m.running = false
	now := m.o.Now()
	if wasFull {
		m.lastFullAt = now
		if rep != nil && rep.Error != "" && len(rep.VMs) == 0 {
			m.lastFullAt = now.Add(errorRetry - m.cfgFull()) // retry sooner than a whole interval after a failure
		}
	}
	m.lastProbeAt = now
	if rep != nil {
		m.ready, m.readyStates = rep, states
	}
}

func (m *Manager) cfgFull() time.Duration {
	if m.cfg == nil {
		return 0
	}
	return m.cfg.FullInterval
}

// Ack records that the check-in carrying the pending report was accepted.
func (m *Manager) Ack() {
	m.mu.Lock()
	defer m.mu.Unlock()
	if m.pending == nil {
		return
	}
	if m.pending.Mode == "summary" || len(m.pending.VMs) > 0 {
		m.baseline = m.pendStates
	}
	m.pending, m.pendStates = nil, nil
}

// Defer puts the pending report back to wait for a smaller check-in.
func (m *Manager) Defer() {
	m.mu.Lock()
	if m.pending != nil && m.ready == nil {
		m.ready, m.readyStates = m.pending, m.pendStates
	}
	m.pending, m.pendStates = nil, nil
	m.mu.Unlock()
}

// Abandon forgets the pending report (the in-flight request was dropped without an answer).
func (m *Manager) Abandon() {
	m.mu.Lock()
	m.pending, m.pendStates = nil, nil
	m.mu.Unlock()
}

// ---- report shaping ----

func statesOf(rep *api.HypervisorReport) map[string]string {
	if rep == nil || len(rep.VMs) == 0 {
		return nil
	}
	m := make(map[string]string, len(rep.VMs))
	for _, v := range rep.VMs {
		m[v.ID] = v.State
	}
	return m
}

func summary(platform string, base, cur map[string]string, now time.Time, cfgVersion string) *api.HypervisorReport {
	var ch []api.HypervisorChange
	for id, st := range cur {
		if old, ok := base[id]; ok && old != st {
			ch = append(ch, api.HypervisorChange{ID: id, State: st})
		}
	}
	if len(ch) == 0 {
		return nil
	}
	sort.Slice(ch, func(i, j int) bool { return ch[i].ID < ch[j].ID })
	if len(ch) > MaxChanges {
		ch = ch[:MaxChanges]
	}
	return &api.HypervisorReport{Mode: "summary", Platform: platform, CollectedAt: now.UTC().Format(time.RFC3339), Changes: ch, VMCount: len(cur), CfgVersion: cfgVersion}
}

// fit enforces the caps: VM and storage counts and the byte budget (VMs are cut from the tail).
func fit(rep *api.HypervisorReport) {
	if len(rep.VMs) > MaxVMs {
		rep.VMs, rep.Truncated = rep.VMs[:MaxVMs], true
	}
	if len(rep.Storage) > MaxStorage {
		rep.Storage, rep.Truncated = rep.Storage[:MaxStorage], true
	}
	for {
		b, err := json.Marshal(rep)
		if err == nil && len(b) <= MaxReportBytes {
			return
		}
		if len(rep.VMs) == 0 {
			rep.Storage, rep.Cluster = nil, nil
			return
		}
		rep.VMs, rep.Truncated = rep.VMs[:len(rep.VMs)*9/10], true
	}
}
