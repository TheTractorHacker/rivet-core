// Package agent is the orchestration layer: enrollment, the sample/check-in
// loop, job polling, updates and the revoked/dormant state machine. All OS
// access is behind collect.Platform, jobs.Rebooter and the injected clock, so
// the whole loop is exercised on Linux in tests.
package agent

import (
	"context"
	"crypto/ed25519"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"log/slog"
	"math/rand/v2"
	"net/url"
	"os"
	"runtime"
	"slices"
	"sort"
	"strings"
	"sync"
	"time"

	"rivetit-agent/internal/api"
	"rivetit-agent/internal/buffer"
	"rivetit-agent/internal/collect"
	"rivetit-agent/internal/jobs"
	"rivetit-agent/internal/store"
	"rivetit-agent/internal/update"
)

// ErrRestartRequested is returned by Run when the process must exit so the
// supervisor (SCM recovery action / systemd) starts a different binary.
var ErrRestartRequested = errors.New("restart requested (update applied or rolled back)")

// ExitRestart is the process exit code used for ErrRestartRequested.
const ExitRestart = 75

const (
	defaultCheckIn     = 300 * time.Second
	defaultCollect     = 60 * time.Second
	minInterval        = 10 * time.Second
	maxInterval        = time.Hour
	lowRateInterval    = 300 * time.Second // pending_approval / ambiguous
	inventoryEvery     = 15 * time.Minute
	inventoryMaxAge    = 24 * time.Hour
	dormantPoll        = 60 * time.Second
	maxBuffered        = 100
	safetyJobPollEvery = 10
	revokedMessage     = "device credential REVOKED by the server: the agent is dormant, job execution stopped and the credential was wiped; re-enroll with a new enrollment token to resume"
)

// Options configure an Agent.
type Options struct {
	Store    *store.Store
	Version  string
	Exe      string // installed binary path; "" disables self-update
	Platform collect.Platform
	Rebooter jobs.Rebooter
	Log      *slog.Logger
	Now      func() time.Time
	// Test hooks
	MinInterval time.Duration // lower clamp for intervals (default 10s)
	// PendingInterval overrides the low re-check rate used while the device is
	// pending_approval/ambiguous (default 300s); for tests and e2e runs.
	PendingInterval time.Duration
	WaitFn          func(ctx context.Context, d time.Duration) bool
	Updater         *update.Manager
	// Rand returns a value in [0,1) for interval jitter and the module_disabled
	// back-off. nil = math/rand/v2; tests pin it for deterministic delays.
	Rand func() float64
}

// Agent is the endpoint agent runtime.
type Agent struct {
	o   Options
	log *slog.Logger

	mu       sync.Mutex
	cfg      store.Config
	client   *api.Client
	col      *collect.Collector
	runner   *collect.CheckRunner
	ring     *buffer.Ring
	exec     *jobs.Executor
	verifier *jobs.Verifier
	upd      *update.Manager
	skew     time.Duration

	tokCache struct {
		mtime time.Time
		size  int64
		val   string
	}
	loggedDormant bool
	loggedNoToken bool
	// module_disabled bookkeeping (server answered 503 module_disabled)
	disabledN      int       // consecutive disabled answers (drives the back-off growth)
	disabledLogged time.Time // last time the disabled state was logged (once per hour)
	invAt          time.Time
	invPending     *api.Inventory
	sw             swState
	checkins       int
	restart        bool
	runCtx         context.Context
}

// New builds an Agent (no I/O besides loading config).
func New(o Options) (*Agent, error) {
	if o.Log == nil {
		o.Log = slog.Default()
	}
	if o.Now == nil {
		o.Now = time.Now
	}
	if o.MinInterval == 0 {
		o.MinInterval = minInterval
	}
	if o.PendingInterval == 0 {
		o.PendingInterval = lowRateInterval
	}
	cfg, err := o.Store.LoadConfig()
	if err != nil {
		return nil, err
	}
	a := &Agent{o: o, log: o.Log, cfg: cfg}
	a.col = collect.New(o.Platform, func() store.Config { a.mu.Lock(); defer a.mu.Unlock(); return a.cfg })
	a.col.SetStateDir(o.Store.Dir)
	a.col.VerifyCheck = func(c store.CheckSpec) error {
		if len(c.Raw) == 0 {
			return errors.New("unsigned")
		}
		a.mu.Lock()
		v := &jobs.Verifier{PublicKey: a.verifier.PublicKey}
		a.mu.Unlock()
		return v.VerifyObject(c.Raw, c.Signature)
	}
	a.runner = collect.NewCheckRunner(a.col)
	a.ring = buffer.Open(o.Store.BufferPath(), cfg.BufferMaxSamples, cfg.BufferMaxBytes)
	a.verifier = &jobs.Verifier{Now: a.now}
	if o.Updater != nil {
		a.upd = o.Updater
	} else if o.Exe != "" {
		a.upd = &update.Manager{Paths: update.PathsFor(o.Exe), Version: o.Version, StatePath: o.Store.UpdatePath(), Now: o.Now}
	}
	return a, nil
}

// now is the skew-corrected clock (server time as best known).
func (a *Agent) now() time.Time {
	a.mu.Lock()
	s := a.skew
	a.mu.Unlock()
	return a.o.Now().Add(s)
}

func (a *Agent) stamp() string { return a.now().UTC().Format(time.RFC3339) }

// token returns the device token, re-reading the file only when it changed.
func (a *Agent) token() string {
	fi, err := os.Stat(a.o.Store.TokenPath())
	if err != nil {
		return ""
	}
	a.mu.Lock()
	defer a.mu.Unlock()
	if a.tokCache.val != "" && fi.ModTime().Equal(a.tokCache.mtime) && fi.Size() == a.tokCache.size {
		return a.tokCache.val
	}
	v, err := a.o.Store.LoadToken()
	if err != nil {
		a.log.Error("cannot read device token", "err", err)
		return ""
	}
	a.tokCache.mtime, a.tokCache.size, a.tokCache.val = fi.ModTime(), fi.Size(), v
	return v
}

func (a *Agent) invalidateToken() {
	a.mu.Lock()
	a.tokCache.val = ""
	a.mu.Unlock()
}

// buildClient (re)creates the HTTP client from the current config.
func (a *Agent) buildClient() error {
	cfg, err := a.o.Store.LoadConfig()
	if err != nil {
		return err
	}
	if cfg.ServerURL == "" {
		return errors.New("no server_url configured")
	}
	c, err := api.New(api.Options{ServerURL: cfg.ServerURL, CAFile: cfg.CAFile, PinSPKISHA256: cfg.PinSPKISHA256,
		Token: a.token, UserAgent: "rivetit-agent/" + a.o.Version, Now: a.now})
	if err != nil {
		return err
	}
	a.mu.Lock()
	a.cfg, a.client = cfg, c
	a.mu.Unlock()
	return nil
}

func (a *Agent) api() *api.Client {
	a.mu.Lock()
	defer a.mu.Unlock()
	return a.client
}

// ReportJob implements jobs.Reporter.
func (a *Agent) ReportJob(ctx context.Context, r api.JobReport) error {
	return a.api().ReportJob(ctx, r)
}

func (a *Agent) syncVerifier(st store.State) {
	a.mu.Lock()
	defer a.mu.Unlock()
	if st.SigningPublicKey != "" {
		if b, err := base64.StdEncoding.DecodeString(st.SigningPublicKey); err == nil && len(b) == ed25519.PublicKeySize {
			a.verifier.PublicKey = ed25519.PublicKey(b)
		} else {
			a.verifier.PublicKey = nil
		}
	} else {
		a.verifier.PublicKey = nil
	}
	a.verifier.DeviceID = st.DeviceID
}

func (a *Agent) newExecutor(ctx context.Context) {
	a.mu.Lock()
	cfg := a.cfg
	a.mu.Unlock()
	e := &jobs.Executor{
		Store: jobs.OpenStore(a.o.Store.JobsPath()), Verifier: a.verifier, Reporter: a,
		Rebooter: a.o.Rebooter, Concurrency: cfg.MaxConcurrentJobs, Now: a.now, Log: a.log,
		Disabled: cfg.DisableJobs,
		Secrets: func() []string {
			s := []string{a.token()}
			if et, _ := a.o.Store.LoadEnrollToken(); et != "" {
				s = append(s, et)
			}
			return s
		},
		Collect: func(ctx context.Context) (string, error) {
			b, err := json.Marshal(a.col.Inventory(ctx))
			return string(b), err
		},
	}
	e.OnRevoked = func(err error) { a.goDormant(revokedMessage + " (" + err.Error() + ")") }
	e.Recover()
	e.Start(ctx)
	a.mu.Lock()
	a.exec = e
	a.mu.Unlock()
}

func (a *Agent) executor() *jobs.Executor {
	a.mu.Lock()
	defer a.mu.Unlock()
	return a.exec
}

// goDormant is the revoked state: stop jobs, wipe the credential, persist,
// and stop talking to the server until a human re-enrolls.
func (a *Agent) goDormant(reason string) {
	if e := a.executor(); e != nil {
		e.Halt()
	}
	if err := a.o.Store.WipeToken(); err != nil {
		a.log.Error("could not wipe device token", "err", err)
	}
	a.invalidateToken()
	a.o.Store.ClearInflight()
	_ = a.ring.RemoveThrough(^uint64(0))
	_ = a.o.Store.Update(func(st *store.State) error {
		st.Dormant, st.DormantReason, st.Status = true, reason, store.StatusDormant
		return nil
	})
	a.syncVerifier(store.State{})
	a.log.Error(reason)
}

// ---- enrollment ----

// Enroll exchanges an enrollment token for a device credential. Re-running it
// with the same install_id rotates the credential and keeps the identity.
func (a *Agent) Enroll(ctx context.Context, token string) (*api.EnrollResponse, error) {
	if err := a.buildClient(); err != nil {
		return nil, err
	}
	id, err := a.o.Store.InstallID()
	if err != nil {
		return nil, err
	}
	dev := a.col.Device(ctx, id, a.o.Version)
	resp, err := a.api().Enroll(ctx, api.EnrollRequest{EnrollmentToken: token, Device: dev})
	if err != nil {
		return nil, err
	}
	if err := a.o.Store.SaveToken(resp.DeviceToken); err != nil {
		return nil, fmt.Errorf("store device token: %w", err)
	}
	a.invalidateToken()
	if _, err := base64.StdEncoding.DecodeString(resp.SigningPublicKey); err != nil || resp.SigningPublicKey == "" {
		a.log.Warn("enrollment returned no usable signing_public_key: jobs and updates will be refused")
		resp.SigningPublicKey = ""
	}
	status := resp.Status
	if status == "" {
		status = store.StatusLinked
	}
	err = a.o.Store.Update(func(st *store.State) error {
		st.DeviceID, st.Status, st.MatchedAssetID = resp.DeviceID.String(), status, resp.MatchedAssetID
		st.SigningKeyID = resp.SigningKeyID
		st.SigningPublicKey = resp.SigningPublicKey
		st.CheckInIntervalS = resp.CheckInIntervalS
		st.CollectIntervalS = resp.Config.CollectIntervalS
		st.Checks = toStoreChecks(resp.Config.Checks)
		st.Dormant, st.DormantReason, st.LastError = false, "", ""
		st.EnrolledAt = a.o.Now().UTC()
		st.ServerFeatures, st.SoftwareHash = nil, "" // unknown until the new server says so
		return nil
	})
	if err != nil {
		return nil, err
	}
	a.applySkew(resp.ServerTime)
	st, _ := a.o.Store.LoadState()
	a.syncVerifier(st)
	a.o.Store.DropEnrollToken()
	a.log.Info("enrolled", "device_id", resp.DeviceID.String(), "status", status)
	return resp, nil
}

func toStoreChecks(in []api.CheckSpec) []store.CheckSpec {
	out := make([]store.CheckSpec, 0, len(in))
	for _, c := range in {
		out = append(out, store.CheckSpec{Key: c.Key, Type: c.Type, Params: c.Params, IntervalS: c.IntervalS, Signature: c.Signature, Raw: c.Raw})
	}
	return out
}

func (a *Agent) applySkew(t api.ServerTime) {
	if !t.OK {
		return
	}
	s := time.Until(t.T)
	a.mu.Lock()
	a.skew = s
	a.mu.Unlock()
	_ = a.o.Store.Update(func(st *store.State) error { st.ClockSkewS = s.Seconds(); return nil })
}

// ---- sampling ----

func (a *Agent) sample(ctx context.Context) {
	st, _ := a.o.Store.LoadState()
	m := a.col.Metrics(ctx)
	checks := a.runner.Run(ctx, st.Checks, a.now())
	checks = append(checks, updateCheck(st, a.o.Version))
	if _, err := a.ring.Append(a.stamp(), &m, checks); err != nil {
		a.log.Warn("could not persist sample", "err", err)
	}
}

func updateCheck(st store.State, version string) api.CheckResult {
	if f := st.UpdateFailure; f != nil {
		d := fmt.Sprintf("update to %s failed: %s", f.Version, f.Reason)
		if len(d) > 300 {
			d = d[:300]
		}
		return api.CheckResult{Key: "agent_update", Status: collect.Fail, Detail: d}
	}
	return api.CheckResult{Key: "agent_update", Status: collect.OK, Detail: "agent " + version}
}

// ---- check-in ----

type checkinOutcome struct {
	delay    time.Duration
	ok       bool
	disabled bool // the server is switched off (503 module_disabled): not an error, do not grow the failure counter
}

// Capabilities is what this agent reports in every check-in so a server can
// choose job types and update binaries: "job:<type>" for each runnable job
// type and "check:<type>" for each check type, sorted.
func Capabilities() []string {
	var out []string
	for _, t := range jobs.JobTypes() {
		out = append(out, "job:"+t)
	}
	for _, t := range collect.CheckTypes {
		out = append(out, "check:"+t)
	}
	if collect.SoftwareSupported() {
		out = append(out, featureSoftware)
	}
	sort.Strings(out)
	return out
}

func (a *Agent) rand() float64 {
	if a.o.Rand != nil {
		return a.o.Rand()
	}
	return rand.Float64()
}

// intervalJitter is the +/-10 % spread applied to the check-in interval so a fleet
// that started together (or recovered from an outage together) drifts apart.
const intervalJitter = 0.1

// moduleDisabled handles the 503 module_disabled answer: the credential, the
// in-flight request and the ring buffer all stay; the agent keeps sampling
// locally (the buffer caps at maxBuffered), polls no jobs and checks no
// updates (both only happen after a successful check-in), and waits
// DisabledBackoff. The state is logged at most once per hour.
func (a *Agent) moduleDisabled(ae *api.APIError) checkinOutcome {
	d := api.DisabledBackoff(a.disabledN, ae.RetryAfterRaw, a.rand)
	a.disabledN++
	if now := a.o.Now(); a.disabledLogged.IsZero() || now.Sub(a.disabledLogged) >= time.Hour {
		a.disabledLogged = now
		a.log.Warn("server answered module_disabled: the RMM module is switched off there; keeping the credential and buffered data, will retry",
			"code", ae.Code, "next_attempt_in", d.Round(time.Second))
	}
	return checkinOutcome{delay: d, disabled: true}
}

func clampDur(s int, def, lo time.Duration) time.Duration {
	d := time.Duration(s) * time.Second
	if s <= 0 {
		d = def
	}
	if d < lo {
		d = lo
	}
	if d > maxInterval {
		d = maxInterval
	}
	return d
}

// buildInflight allocates the next seq and persists the exact request.
func (a *Agent) buildInflight(ctx context.Context) (*store.Inflight, error) {
	entries := a.ring.Snapshot()
	if len(entries) == 0 {
		a.sample(ctx)
		entries = a.ring.Snapshot()
	}
	req := api.CheckinRequest{AgentVersion: a.o.Version, Buffered: []api.Buffered{}}
	req.Platform, req.Arch, req.Capabilities = runtime.GOOS, runtime.GOARCH, Capabilities()
	var through uint64
	if n := len(entries); n > 0 {
		latest := entries[n-1]
		through = latest.ID
		req.CollectedAt, req.Metrics, req.Checks = latest.CollectedAt, latest.Metrics, latest.Checks
		older := entries[:n-1]
		if len(older) > maxBuffered {
			older = older[len(older)-maxBuffered:]
		}
		for _, e := range older {
			req.Buffered = append(req.Buffered, api.Buffered{CollectedAt: e.CollectedAt, Metrics: e.Metrics, Checks: e.Checks})
		}
	} else {
		req.CollectedAt = a.stamp()
	}
	if req.Checks == nil {
		req.Checks = []api.CheckResult{}
	}
	in := &store.Inflight{ThroughID: through}
	st, _ := a.o.Store.LoadState()
	if ur := st.UpdateResult; ur != nil {
		req.UpdateResult = &api.UpdateResult{Version: ur.Version, State: ur.State, Detail: ur.Detail}
		in.WithUpdate = true
	}
	if inv := a.inventoryIfDue(ctx, st); inv != nil {
		req.Inventory = inv
		in.WithInventory, in.InventoryHash = true, collect.InventoryHash(*inv)
	}
	a.sw.pending = nil
	if rep, plan := a.softwareIfDue(ctx, st); rep != nil {
		req.Software = rep
		in.WithSoftware, in.SoftwareHash, in.SoftwareFull = true, plan.hash, plan.full
		a.sw.pending = plan
		if plan.full {
			a.sw.resync = false
		}
	}
	var seq uint64
	if err := a.o.Store.Update(func(s *store.State) error { s.Seq++; seq = s.Seq; return nil }); err != nil {
		return nil, err
	}
	req.Seq = seq
	body, err := json.Marshal(req)
	if err != nil {
		return nil, err
	}
	in.Seq, in.Body = seq, body
	if err := a.o.Store.SaveInflight(*in); err != nil {
		return nil, err
	}
	return in, nil
}

func (a *Agent) inventoryIfDue(ctx context.Context, st store.State) *api.Inventory {
	now := a.o.Now()
	if a.invPending == nil && !a.invAt.IsZero() && now.Sub(a.invAt) < inventoryEvery {
		return nil
	}
	if a.invPending == nil || now.Sub(a.invAt) >= inventoryEvery {
		inv := a.col.Inventory(ctx)
		a.invAt = now
		h := collect.InventoryHash(inv)
		if h == st.InventoryHash && now.Sub(st.InventorySentAt) < inventoryMaxAge {
			a.invPending = nil
			return nil
		}
		a.invPending = &inv
	}
	return a.invPending
}

// checkIn performs one check-in attempt. attempt is the consecutive failure
// count (for backoff).
func (a *Agent) checkIn(ctx context.Context, attempt int) checkinOutcome {
	bo := api.DefaultBackoff()
	if a.token() == "" {
		return checkinOutcome{delay: a.o.MinInterval}
	}
	in, err := a.o.Store.LoadInflight()
	if err != nil || in == nil {
		in, err = a.buildInflight(ctx)
		if err != nil {
			a.log.Error("cannot build check-in", "err", err)
			return checkinOutcome{delay: bo.Delay(attempt)}
		}
	}
	resp, err := a.api().Checkin(ctx, in.Body)
	if err != nil {
		return a.checkinFailed(in, err, attempt, bo)
	}
	return a.checkinSucceeded(ctx, in, resp)
}

func (a *Agent) checkinFailed(in *store.Inflight, err error, attempt int, bo api.Backoff) checkinOutcome {
	_ = a.o.Store.Update(func(st *store.State) error { st.LastError = trunc(err.Error(), 300); return nil })
	var ae *api.APIError
	if !errors.As(err, &ae) {
		return checkinOutcome{delay: bo.Delay(attempt)}
	}
	switch {
	case ae.ModuleDisabled():
		return a.moduleDisabled(ae)
	case ae.Revoked():
		a.goDormant(revokedMessage)
		return checkinOutcome{delay: dormantPoll}
	case ae.AuthRejected():
		a.log.Error("server rejected the device credential; re-enroll may be required", "code", ae.Code)
		return checkinOutcome{delay: bo.DelayWithRetryAfter(attempt+3, ae.RetryAfter)}
	case ae.Status == 426:
		a.log.Error("server answered 426 tls_required: use the https:// URL (check proxies that terminate TLS); will retry", "code", ae.Code)
		return checkinOutcome{delay: max(bo.DelayWithRetryAfter(attempt+3, ae.RetryAfter), time.Minute)}
	case ae.Status == 409:
		// Duplicate seq: the server already has it. Treat as acknowledged.
		a.log.Info("server reports check-in already received", "seq", in.Seq)
		a.sw.pending = nil
		a.finishInflight(in)
		return checkinOutcome{delay: a.o.MinInterval}
	case ae.Transient():
		a.log.Warn("check-in failed; will retry same seq", "seq", in.Seq, "err", err)
		return checkinOutcome{delay: bo.DelayWithRetryAfter(attempt, ae.RetryAfter)}
	default:
		// 4xx: this exact body will never be accepted; drop it so the agent
		// is not wedged, and carry on with fresh data.
		a.log.Error("server rejected check-in payload; dropping it", "seq", in.Seq, "err", err)
		a.sw.pending = nil
		a.finishInflight(in)
		return checkinOutcome{delay: bo.DelayWithRetryAfter(attempt, ae.RetryAfter)}
	}
}

func (a *Agent) finishInflight(in *store.Inflight) {
	a.o.Store.ClearInflight()
	if err := a.ring.RemoveThrough(in.ThroughID); err != nil {
		a.log.Warn("ring cleanup", "err", err)
	}
}

func trunc(s string, n int) string {
	if len(s) > n {
		return s[:n]
	}
	return s
}

func (a *Agent) checkinSucceeded(ctx context.Context, in *store.Inflight, resp *api.CheckinResponse) checkinOutcome {
	if a.disabledN > 0 {
		a.log.Info("server answers again after module_disabled", "after_attempts", a.disabledN)
		a.disabledN, a.disabledLogged = 0, time.Time{}
	}
	a.finishInflight(in)
	a.checkins++
	a.applySkew(resp.ServerTime)
	probation := a.upd != nil && a.upd.InProbation()
	if probation {
		if err := a.upd.Confirm(); err != nil {
			a.log.Warn("confirming update", "err", err)
		} else {
			a.log.Info("new agent version confirmed healthy", "version", a.o.Version)
			_ = a.o.Store.Update(func(st *store.State) error {
				st.UpdateResult = &store.UpdateResultS{Version: a.o.Version, State: "ok"}
				return nil
			})
		}
	}
	now := a.o.Now().UTC()
	_ = a.o.Store.Update(func(st *store.State) error {
		st.LastCheckIn, st.LastError = now, ""
		if in.WithInventory {
			st.InventoryHash, st.InventorySentAt = in.InventoryHash, now
		}
		st.ServerFeatures = slices.Clone(resp.Features) // the last word of the server; absence = none
		if resp.Config != nil {
			st.Checks = toStoreChecks(resp.Config.Checks)
			if resp.Config.CollectIntervalS > 0 {
				st.CollectIntervalS = resp.Config.CollectIntervalS
			}
		}
		if resp.NextCheckInS > 0 {
			st.CheckInIntervalS = resp.NextCheckInS
		}
		if resp.Status != "" {
			st.Status = resp.Status
		}
		if in.WithUpdate {
			st.UpdateResult = nil
		}
		if resp.SigningKeyID != "" {
			if st.SigningKeyID != "" && st.SigningKeyID != resp.SigningKeyID {
				a.log.Error("server signing key changed: jobs and updates will be refused until this device re-enrolls", "was", st.SigningKeyID, "now", resp.SigningKeyID)
				st.LastError = "signing key rotated on the server; re-enroll required"
			}
			if st.SigningKeyID == "" {
				st.SigningKeyID = resp.SigningKeyID
			}
		}
		if resp.MatchedAssetI != nil {
			st.MatchedAssetID = resp.MatchedAssetI
		}
		if probation && st.UpdateFailure != nil && st.UpdateFailure.Version != a.o.Version {
			// healthy on the new version: an older recorded failure is stale
			st.UpdateFailure = nil
		}
		return nil
	})
	if in.WithInventory {
		a.invPending = nil
	}
	if in.WithSoftware {
		a.ackSoftware(in, now)
	}
	a.sw.resync = hasFeature(resp.Features, featureSoftware) && hasFeature(resp.Resync, resyncSoftware)
	st, _ := a.o.Store.LoadState()

	// jobs: immediately when pending, otherwise a periodic safety poll
	if resp.JobsPending == nil || *resp.JobsPending > 0 || a.checkins%safetyJobPollEvery == 0 {
		a.pollJobs(ctx)
	}
	if e := a.executor(); e != nil {
		e.Flush(ctx)
	}
	a.maybeUpdate(ctx, resp.Update, st)

	d := clampDur(resp.NextCheckInS, clampDur(st.CheckInIntervalS, defaultCheckIn, a.o.MinInterval), a.o.MinInterval)
	if st.Status == store.StatusPending || st.Status == store.StatusAmbiguous {
		if d < a.o.PendingInterval {
			d = a.o.PendingInterval
		}
	}
	if resp.JobsPending != nil && *resp.JobsPending > 0 {
		d = a.o.MinInterval // keep draining the queue
	} else if d = api.Jitter(d, intervalJitter, a.rand); d < a.o.MinInterval {
		d = a.o.MinInterval
	}
	return checkinOutcome{delay: d, ok: true}
}

func (a *Agent) pollJobs(ctx context.Context) {
	if a.cfg.DisableJobs {
		return
	}
	list, err := a.api().Jobs(ctx)
	if err != nil {
		var ae *api.APIError
		if errors.As(err, &ae) && ae.Revoked() {
			a.goDormant(revokedMessage)
			return
		}
		a.log.Warn("job poll failed", "err", err)
		return
	}
	if e := a.executor(); e != nil && len(list) > 0 {
		e.Submit(list)
	}
}

func (a *Agent) maybeUpdate(ctx context.Context, man *api.UpdateManifest, st store.State) {
	if man == nil || a.upd == nil || a.upd.InProbation() {
		return
	}
	if f := st.UpdateFailure; f != nil && f.Version == man.Version {
		return // do not retry a version that already failed
	}
	pub, err := base64.StdEncoding.DecodeString(st.SigningPublicKey)
	if err != nil {
		return
	}
	a.mu.Lock()
	cfg, c := a.cfg, a.client
	a.mu.Unlock()
	hosts := append([]string{c.Host()}, cfg.UpdateHosts...)
	restart, err := a.upd.Apply(ctx, *man, ed25519.PublicKey(pub), c.HTTPClient(), c.Host(), a.token(), hosts)
	if err != nil {
		a.log.Error("update failed", "version", man.Version, "err", err)
		_ = a.o.Store.Update(func(s *store.State) error {
			s.UpdateFailure = &store.UpdateFailure{Version: man.Version, Reason: trunc(err.Error(), 250), At: a.o.Now().UTC()}
			s.UpdateResult = &store.UpdateResultS{Version: man.Version, State: "failed", Detail: trunc(err.Error(), 200)}
			return nil
		})
		return
	}
	if restart {
		a.log.Info("update staged and activated; restarting", "version", man.Version)
		a.mu.Lock()
		a.restart = true
		a.mu.Unlock()
	}
}

func (a *Agent) restartWanted() bool {
	a.mu.Lock()
	defer a.mu.Unlock()
	return a.restart
}

// ---- main loop ----

func (a *Agent) wait(ctx context.Context, d time.Duration) bool {
	if a.o.WaitFn != nil {
		return a.o.WaitFn(ctx, d)
	}
	t := time.NewTimer(d)
	defer t.Stop()
	select {
	case <-ctx.Done():
		return false
	case <-t.C:
		return true
	}
}

// Run is the agent main loop. It returns nil on ctx cancellation and
// ErrRestartRequested when an update/rollback needs the process replaced.
func (a *Agent) Run(ctx context.Context) error {
	a.runCtx = ctx
	if a.upd != nil {
		rolled, fail, err := a.upd.OnStart()
		if err != nil {
			a.log.Error("update state", "err", err)
		}
		if fail != nil {
			a.log.Error("agent update failed and was rolled back", "version", fail.Version, "reason", fail.Reason)
			_ = a.o.Store.Update(func(s *store.State) error {
				s.UpdateFailure = fail
				s.UpdateResult = &store.UpdateResultS{Version: fail.Version, State: "rolled_back", Detail: trunc(fail.Reason, 200)}
				return nil
			})
		}
		if rolled {
			a.log.Error("new version failed its health check; rolled back to the last good binary; restarting")
			return ErrRestartRequested
		}
	}
	a.log.Info("agent starting", "version", a.o.Version, "state_dir", a.o.Store.Dir)
	if err := a.buildClient(); err != nil {
		a.log.Error("configuration error (agent will keep retrying)", "err", err)
	}
	st, _ := a.o.Store.LoadState()
	a.syncVerifier(st)
	a.newExecutor(ctx)
	defer func() {
		if e := a.executor(); e != nil {
			e.Halt()
			e.Wait()
		}
	}()

	var nextSample, nextCheckin time.Time
	attempt, enrollAttempt := 0, 0
	for ctx.Err() == nil {
		st, err := a.o.Store.LoadState()
		if err != nil {
			a.log.Error("state unreadable", "err", err)
			a.wait(ctx, dormantPoll)
			continue
		}
		if st.Dormant {
			if !a.loggedDormant {
				a.log.Error(revokedMessage, "reason", st.DormantReason)
				a.loggedDormant = true
			}
			// no network traffic, no spinning: re-read local state once a minute
			a.wait(ctx, dormantPoll)
			continue
		}
		if a.loggedDormant { // a human re-enrolled
			a.loggedDormant = false
			if e := a.executor(); e != nil {
				e.Halt()
				e.Wait()
			}
			a.syncVerifier(st)
			a.newExecutor(ctx)
			nextSample, nextCheckin = time.Time{}, time.Time{}
		}
		if a.token() == "" {
			if d := a.tryPendingEnroll(ctx, &enrollAttempt); d > 0 {
				a.wait(ctx, d)
				continue
			}
			if a.token() == "" {
				if !a.loggedNoToken {
					a.log.Warn("not enrolled: run `rivetit-agent enroll --token ...`; waiting")
					a.loggedNoToken = true
				}
				a.wait(ctx, dormantPoll)
				continue
			}
		}
		a.loggedNoToken = false
		if a.api() == nil {
			if err := a.buildClient(); err != nil {
				a.wait(ctx, dormantPoll)
				continue
			}
		}

		now := a.o.Now()
		if !now.Before(nextSample) {
			a.sample(ctx)
			cd := clampDur(st.CollectIntervalS, defaultCollect, a.o.MinInterval)
			if st.Status == store.StatusPending || st.Status == store.StatusAmbiguous {
				cd = max(cd, a.o.PendingInterval)
			}
			nextSample = now.Add(cd)
		}
		if !now.Before(nextCheckin) {
			out := a.checkIn(ctx, attempt)
			if out.ok || out.disabled {
				attempt = 0
			} else {
				attempt++
			}
			nextCheckin = a.o.Now().Add(out.delay)
			if a.restartWanted() {
				return ErrRestartRequested
			}
		}
		if a.upd != nil {
			if rolled, err := a.upd.Watchdog(); err != nil {
				a.log.Error("watchdog", "err", err)
			} else if rolled {
				a.log.Error("health deadline passed without a successful check-in; rolled back; restarting")
				return ErrRestartRequested
			}
		}
		next := nextSample
		if nextCheckin.Before(next) {
			next = nextCheckin
		}
		d := next.Sub(a.o.Now())
		if d < 0 {
			d = 0
		}
		a.wait(ctx, min(d, dormantPoll))
	}
	return nil
}

// tryPendingEnroll uses a stored one-shot enrollment token (install ran while
// offline). Returns a delay when the caller should wait before continuing.
func (a *Agent) tryPendingEnroll(ctx context.Context, attempt *int) time.Duration {
	et, err := a.o.Store.LoadEnrollToken()
	if err != nil || et == "" {
		return 0
	}
	if _, err := a.Enroll(ctx, et); err != nil {
		var ae *api.APIError
		if errors.As(err, &ae) && !ae.Transient() {
			a.log.Error("enrollment token rejected; discarding it", "err", err)
			a.o.Store.DropEnrollToken()
			return 0
		}
		if ae != nil && ae.ModuleDisabled() {
			o := a.moduleDisabled(ae)
			return max(o.delay, a.o.MinInterval)
		}
		*attempt++
		bo := api.DefaultBackoff()
		var ra time.Duration
		if ae != nil {
			ra = ae.RetryAfter
		}
		d := bo.DelayWithRetryAfter(*attempt, ra)
		a.log.Warn("enrollment failed; will retry", "in", d.Round(time.Second), "err", err)
		return max(d, a.o.MinInterval)
	}
	*attempt = 0
	return 0
}

// ---- status ----

// Describe renders a human status for the `status` command.
func Describe(st store.State, cfg store.Config, tokenPresent bool) string {
	var b strings.Builder
	host := cfg.ServerURL
	if u, err := url.Parse(cfg.ServerURL); err == nil && u.Host != "" {
		host = u.Host
	}
	fmt.Fprintf(&b, "server:      %s\n", host)
	fmt.Fprintf(&b, "install id:  %s\n", orNone(st.InstallID))
	fmt.Fprintf(&b, "device id:   %s\n", orNone(st.DeviceID))
	fmt.Fprintf(&b, "state:       %s\n", statusText(st, tokenPresent))
	if st.MatchedAssetID != nil {
		fmt.Fprintf(&b, "asset:       #%d\n", *st.MatchedAssetID)
	}
	if !st.LastCheckIn.IsZero() {
		fmt.Fprintf(&b, "last check-in: %s (%s ago)\n", st.LastCheckIn.Format(time.RFC3339), time.Since(st.LastCheckIn).Round(time.Second))
	} else {
		fmt.Fprintf(&b, "last check-in: never\n")
	}
	fmt.Fprintf(&b, "check-in seq: %d\n", st.Seq)
	if st.LastError != "" {
		fmt.Fprintf(&b, "last error:  %s\n", st.LastError)
	}
	if st.UpdateFailure != nil {
		fmt.Fprintf(&b, "update:      FAILED %s: %s\n", st.UpdateFailure.Version, st.UpdateFailure.Reason)
	}
	return b.String()
}

func orNone(s string) string {
	if s == "" {
		return "(none)"
	}
	return s
}

func statusText(st store.State, tokenPresent bool) string {
	switch {
	case st.Dormant:
		return "DORMANT - credential revoked by the server. " + st.DormantReason + " Re-enroll with a new token."
	case !tokenPresent:
		return "not enrolled"
	}
	switch st.Status {
	case store.StatusLinked:
		return "enrolled and linked to an asset"
	case store.StatusPending:
		return "enrolled, WAITING FOR ADMIN APPROVAL (an administrator must approve this device in RivetIT); checking in at a low rate"
	case store.StatusAmbiguous:
		return "enrolled, AMBIGUOUS asset match (an administrator must choose the correct asset in RivetIT); checking in at a low rate"
	}
	return "enrolled (" + st.Status + ")"
}
