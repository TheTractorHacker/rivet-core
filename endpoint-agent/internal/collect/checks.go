package collect

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"sort"
	"strings"
	"sync"
	"time"

	"rivetit-agent/internal/api"
	"rivetit-agent/internal/jobs"
	"rivetit-agent/internal/store"
)

// Check statuses.
const (
	OK      = "ok"
	Warn    = "warn"
	Fail    = "fail"
	Unknown = "unknown"
)

// CheckTypes are the check types RunCheck evaluates (reported as capabilities).
var CheckTypes = []string{"disk", "pending_reboot", "script", "service"}

const (
	defaultCheckInterval = 300 * time.Second
	minCheckInterval     = 30 * time.Second
	maxDetail            = 300
)

func res(key, status, detail string) api.CheckResult {
	if len(detail) > maxDetail {
		detail = detail[:maxDetail]
	}
	return api.CheckResult{Key: key, Status: status, Detail: detail}
}

// RunCheck evaluates one check. Any collector error yields `unknown`.
func (c *Collector) RunCheck(ctx context.Context, spec store.CheckSpec) api.CheckResult {
	key := spec.Key
	if spec.Signature != "" || spec.Type == "script" {
		// Scripts execute code, so they must be signed by the instance key.
		// Other types are verified only when a signature is present.
		if c.VerifyCheck == nil {
			if spec.Type == "script" {
				return res(key, Unknown, "script check refused: no signing key to verify it")
			}
		} else if err := c.VerifyCheck(spec); err != nil {
			return res(key, Unknown, "check definition refused: "+err.Error())
		}
	}
	switch spec.Type {
	case "service":
		return c.checkService(ctx, key, spec.Params)
	case "disk":
		return c.checkDisk(ctx, key, spec.Params)
	case "pending_reboot":
		return c.checkPendingReboot(ctx, key)
	case "script":
		return c.checkScript(ctx, key, spec.Params)
	}
	return res(key, Unknown, "unsupported check type "+spec.Type)
}

func (c *Collector) checkService(ctx context.Context, key string, raw json.RawMessage) api.CheckResult {
	var p struct {
		Name     string `json:"name"`
		Expected string `json:"expected"`
		Startup  string `json:"startup"`
	}
	if err := json.Unmarshal(raw, &p); err != nil || strings.TrimSpace(p.Name) == "" {
		return res(key, Unknown, "invalid service check parameters")
	}
	if p.Expected == "" {
		p.Expected = "running"
	}
	info, err := call(c, ctx, "svc:"+p.Name, 15*time.Second, func(ctx context.Context) (ServiceInfo, error) {
		return c.P.ServiceState(ctx, p.Name)
	})
	if errors.Is(err, ErrNotFound) {
		return res(key, Fail, fmt.Sprintf("service %s not found", p.Name))
	}
	if err != nil {
		return res(key, Unknown, err.Error())
	}
	if !strings.EqualFold(info.State, p.Expected) {
		return res(key, Fail, fmt.Sprintf("service %s is %s (expected %s)", p.Name, info.State, p.Expected))
	}
	if p.Startup != "" && !strings.EqualFold(info.Startup, p.Startup) {
		return res(key, Warn, fmt.Sprintf("service %s startup is %s (expected %s)", p.Name, info.Startup, p.Startup))
	}
	return res(key, OK, fmt.Sprintf("service %s is %s", p.Name, info.State))
}

func (c *Collector) checkDisk(ctx context.Context, key string, raw json.RawMessage) api.CheckResult {
	var p struct {
		Mount      string   `json:"mount"`
		WarnFreePc *float64 `json:"warn_free_pct"`
		FailFreePc *float64 `json:"fail_free_pct"`
		WarnFreeGB *float64 `json:"warn_free_gb"`
		FailFreeGB *float64 `json:"fail_free_gb"`
	}
	if len(raw) > 0 {
		if err := json.Unmarshal(raw, &p); err != nil {
			return res(key, Unknown, "invalid disk check parameters")
		}
	}
	if p.WarnFreePc == nil && p.FailFreePc == nil && p.WarnFreeGB == nil && p.FailFreeGB == nil {
		w, f := 20.0, 10.0
		p.WarnFreePc, p.FailFreePc = &w, &f
	}
	ds, err := call(c, ctx, "disks", 0, c.P.Disks)
	if err != nil {
		return res(key, Unknown, err.Error())
	}
	worst, detail, matched := OK, "", false
	rank := map[string]int{OK: 0, Warn: 1, Fail: 2}
	for _, d := range ds {
		if p.Mount != "" && !strings.EqualFold(strings.TrimRight(d.Mount, `\/`), strings.TrimRight(p.Mount, `\/`)) {
			continue
		}
		if d.Total == 0 {
			continue
		}
		matched = true
		freePct := 100 * float64(d.Free) / float64(d.Total)
		freeGB := float64(d.Free) / (1 << 30)
		st := OK
		if (p.WarnFreePc != nil && freePct < *p.WarnFreePc) || (p.WarnFreeGB != nil && freeGB < *p.WarnFreeGB) {
			st = Warn
		}
		if (p.FailFreePc != nil && freePct < *p.FailFreePc) || (p.FailFreeGB != nil && freeGB < *p.FailFreeGB) {
			st = Fail
		}
		if detail == "" || rank[st] > rank[worst] {
			worst = st
			detail = fmt.Sprintf("%s %.1f%% free (%.1f GB)", d.Mount, freePct, freeGB)
		}
	}
	if !matched {
		return res(key, Unknown, "no matching disk")
	}
	return res(key, worst, detail)
}

func (c *Collector) checkPendingReboot(ctx context.Context, key string) api.CheckResult {
	type pr struct {
		b bool
		r []string
	}
	r, err := call(c, ctx, "pendingreboot", 0, func(ctx context.Context) (pr, error) {
		b, why, err := c.P.PendingReboot(ctx)
		return pr{b, why}, err
	})
	if err != nil {
		return res(key, Unknown, err.Error())
	}
	if r.b {
		return res(key, Warn, "reboot pending: "+strings.Join(r.r, ", "))
	}
	return res(key, OK, "no reboot pending")
}

func (c *Collector) checkScript(ctx context.Context, key string, raw json.RawMessage) api.CheckResult {
	if c.Cfg().DisableScriptChk {
		return res(key, Unknown, "script checks disabled on this endpoint")
	}
	var p struct {
		Script         string `json:"script"`
		TimeoutS       int    `json:"timeout_s"`
		MaxOutputBytes int    `json:"max_output_bytes"`
	}
	if err := json.Unmarshal(raw, &p); err != nil || strings.TrimSpace(p.Script) == "" || len(p.Script) > jobs.MaxScriptBytes {
		return res(key, Unknown, "invalid script check parameters")
	}
	if p.TimeoutS <= 0 || p.TimeoutS > 120 {
		p.TimeoutS = 30
	}
	if p.MaxOutputBytes <= 0 || p.MaxOutputBytes > 4096 {
		p.MaxOutputBytes = 4096
	}
	sc, err := jobs.BuildScriptCommand(p.Script, nil)
	if err != nil {
		return res(key, Unknown, err.Error())
	}
	r := jobs.RunBounded(ctx, jobs.ExecSpec{Name: sc.Name, Args: sc.Args, Env: sc.Env, Cleanup: sc.Cleanup,
		Timeout: time.Duration(p.TimeoutS) * time.Second, MaxOutput: p.MaxOutputBytes})
	line := firstLine(r.Output)
	switch {
	case r.TimedOut:
		return res(key, Unknown, fmt.Sprintf("script timed out after %ds", p.TimeoutS))
	case r.StartErr != nil && !r.HaveExit:
		return res(key, Unknown, "script could not run")
	case !r.HaveExit:
		return res(key, Unknown, "script did not complete")
	case r.ExitCode == 0:
		return res(key, OK, line)
	case r.ExitCode == 1:
		return res(key, Warn, line)
	default:
		return res(key, Fail, fmt.Sprintf("exit %d: %s", r.ExitCode, line))
	}
}

func firstLine(s string) string {
	for _, l := range strings.Split(s, "\n") {
		if l = strings.TrimSpace(l); l != "" {
			return l
		}
	}
	return ""
}

// CheckRunner schedules checks at their own intervals and returns the latest
// result of every configured check on each call.
type CheckRunner struct {
	C    *Collector
	mu   sync.Mutex
	last map[string]api.CheckResult
	at   map[string]time.Time
}

func NewCheckRunner(c *Collector) *CheckRunner {
	return &CheckRunner{C: c, last: map[string]api.CheckResult{}, at: map[string]time.Time{}}
}

// Run executes due checks (sequentially, each hard-bounded) and returns a
// snapshot ordered by key. Checks that were removed from the config vanish.
func (r *CheckRunner) Run(ctx context.Context, specs []store.CheckSpec, now time.Time) []api.CheckResult {
	r.mu.Lock()
	keep := map[string]bool{}
	for _, s := range specs {
		keep[s.Key] = true
	}
	for k := range r.last {
		if !keep[k] {
			delete(r.last, k)
			delete(r.at, k)
		}
	}
	r.mu.Unlock()
	for _, s := range specs {
		if s.Key == "" {
			continue
		}
		iv := time.Duration(s.IntervalS) * time.Second
		if iv <= 0 {
			iv = defaultCheckInterval
		}
		if iv < minCheckInterval {
			iv = minCheckInterval
		}
		r.mu.Lock()
		due := r.at[s.Key].IsZero() || now.Sub(r.at[s.Key]) >= iv
		r.mu.Unlock()
		if !due || ctx.Err() != nil {
			continue
		}
		out := r.C.RunCheck(ctx, s)
		r.mu.Lock()
		r.last[s.Key], r.at[s.Key] = out, now
		r.mu.Unlock()
	}
	r.mu.Lock()
	defer r.mu.Unlock()
	out := make([]api.CheckResult, 0, len(r.last))
	for _, v := range r.last {
		out = append(out, v)
	}
	sort.Slice(out, func(i, j int) bool { return out[i].Key < out[j].Key })
	return out
}
