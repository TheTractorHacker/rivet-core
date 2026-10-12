package mesh

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"log/slog"
	"runtime"
	"strings"
	"time"

	"rivetit-agent/internal/remote"
)

// Job type wire names (<= 20 characters) of the pushed Mesh agent install.
const (
	JobInstall   = "mesh_install"
	JobUninstall = "mesh_uninstall"
)

// JobTypes are the job types this package handles.
var JobTypes = []string{JobInstall, JobUninstall}

// IsJobType reports whether typ is one of the Mesh agent job types.
func IsJobType(typ string) bool { return typ == JobInstall || typ == JobUninstall }

// installParams are the PARAMETERS of a mesh_install job. They are exactly what the stamped
// installer payload carries (the Mesh address, the device group id, the optional certificate pin and the
// agent-id and install-argument tables), taken by the server from its stored Mesh settings when the job was
// created; there is no free-form member, no command, no URL other than the Mesh address. The two tables
// are JSON text inside a string because a job parameter is a scalar. `force` re-runs the installer on a
// device that already has a Mesh agent.
type installParams struct {
	URL         string `json:"url"`
	MeshID      string `json:"meshid"`
	Pin         string `json:"pin"`
	AgentIDs    string `json:"agent_ids"`
	InstallArgs string `json:"install_args"`
	Force       bool   `json:"force"`
}

// SpecFromParams turns the signed job parameters into a validated Spec (the same validation as the
// installer payload gets). Unknown members, wrong types and unusable values are an error.
func SpecFromParams(raw json.RawMessage) (*Spec, bool, error) {
	var p installParams
	dec := json.NewDecoder(bytes.NewReader(raw))
	dec.DisallowUnknownFields()
	if err := dec.Decode(&p); err != nil {
		return nil, false, errors.New("the job parameters are not the expected object")
	}
	m := map[string]any{"url": p.URL, "meshid": p.MeshID}
	if p.Pin != "" {
		m["pin"] = p.Pin
	}
	if p.AgentIDs != "" {
		var ids map[string]int
		if err := json.Unmarshal([]byte(p.AgentIDs), &ids); err != nil {
			return nil, false, errors.New("agent_ids is not a JSON object of platform to number")
		}
		m["agent_ids"] = ids
	}
	if p.InstallArgs != "" {
		var args map[string][]string
		if err := json.Unmarshal([]byte(p.InstallArgs), &args); err != nil {
			return nil, false, errors.New("install_args is not a JSON object of platform to argument list")
		}
		m["install_args"] = args
	}
	b, _ := json.Marshal(m)
	s, err := ParseSpec(json.RawMessage(`true`), b)
	if err != nil {
		return nil, false, err
	}
	return s, p.Force, nil
}

// JobDeps are the dependencies (and test hooks) of the Mesh agent jobs.
type JobDeps struct {
	StateDir string
	// GOOS / GOARCH default to the running platform.
	GOOS, GOARCH string
	// Probe finds the installed Mesh agent and reads its node id.
	Probe func() Probe
	// Install replaces the real downloader (nil = Install).
	Install func(ctx context.Context, s *Spec, o InstallOptions) Result
	// Options adjusts the install options (tests: HTTP client, size cap, program runner).
	Options func(*InstallOptions)
	// OnChange is called after the saved Mesh state changed so the next check-in reports it.
	OnChange func()
	// Settle is how long a successful install waits for the node id to appear (default 20 s).
	Settle time.Duration
	Log    *slog.Logger
}

func (d JobDeps) goos() string {
	if d.GOOS != "" {
		return d.GOOS
	}
	return runtime.GOOS
}

func (d JobDeps) goarch() string {
	if d.GOARCH != "" {
		return d.GOARCH
	}
	return runtime.GOARCH
}

func (d JobDeps) log() *slog.Logger {
	if d.Log != nil {
		return d.Log
	}
	return slog.Default()
}

func (d JobDeps) probe() Probe {
	if d.Probe != nil {
		return d.Probe()
	}
	return Probe{GOOS: d.goos()}
}

// Handlers returns the job handlers by job type.
func (d JobDeps) Handlers() map[string]remote.Handler {
	return map[string]remote.Handler{JobInstall: d.install, JobUninstall: d.uninstall}
}

func (d JobDeps) changed() {
	if d.OnChange != nil {
		d.OnChange()
	}
}

func doc(ok bool, detail string, extra map[string]any) string {
	m := map[string]any{"v": 1, "ok": ok, "detail": detail}
	for k, v := range extra {
		m[k] = v
	}
	b, err := json.Marshal(m)
	if err != nil {
		return `{"v":1,"ok":false,"detail":"internal_error"}`
	}
	return string(b)
}

func fit(maxOut int, s string, drop func() string) string {
	if maxOut > 0 && len(s) > maxOut {
		return drop()
	}
	return s
}

// install is the mesh_install job: the SAME pinned download-and-install routine the installer runs (Install).
// It never fails the agent: every outcome, including a panic, is a job result and a saved Mesh state.
func (d JobDeps) install(ctx context.Context, params json.RawMessage, maxOut int) (res remote.Result) {
	defer func() {
		if r := recover(); r != nil {
			res = remote.Result{OK: false, Detail: "internal_error", Output: doc(false, "internal_error", nil)}
		}
	}()
	spec, force, err := SpecFromParams(params)
	if err != nil {
		return remote.Result{OK: false, Cannot: true, Detail: "invalid_params", Output: doc(false, "invalid_params", map[string]any{"error": clip(err.Error(), 200)})}
	}
	log := d.log()
	now := time.Now().UTC().Format(time.RFC3339)
	probe := d.probe()
	if found := probe.Found(); found != "" && !force {
		log.Info("a MeshCentral agent is already installed; the install job leaves it alone", "path", found)
		_ = SaveResult(d.StateDir, Result{AttemptedAt: now, Installed: true, Host: spec.Host()})
		d.changed()
		return remote.Result{OK: true, Detail: "already_installed", Output: doc(true, "already_installed", map[string]any{"installed": true, "host": spec.Host(), "node_id": d.nodeID(ctx, probe, 0)})}
	}
	opts := InstallOptions{GOOS: d.goos(), GOARCH: d.goarch(), WorkDir: d.StateDir, Log: log}
	if d.Options != nil {
		d.Options(&opts)
	}
	run := d.Install
	if run == nil {
		run = Install
	}
	r := run(ctx, spec, opts)
	_ = SaveResult(d.StateDir, r)
	d.changed()
	extra := map[string]any{"installed": r.Installed, "host": r.Host}
	if r.AgentID != 0 {
		extra["agent_id"] = r.AgentID
	}
	if r.SHA256 != "" {
		extra["sha256"] = r.SHA256
	}
	if r.ExitCode != 0 {
		extra["exit_code"] = r.ExitCode
	}
	if !r.Installed {
		extra["error"] = r.Error
		if r.Log != "" {
			extra["log"] = r.Log
		}
		out := fit(maxOut, doc(false, "install_failed", extra), func() string {
			delete(extra, "log")
			return doc(false, "install_failed", extra)
		})
		return remote.Result{OK: false, Detail: "install_failed", Output: out}
	}
	settle := d.Settle
	if settle == 0 {
		settle = 20 * time.Second
	}
	if id := d.nodeID(ctx, d.probe(), settle); id != "" {
		extra["node_id"] = id
	}
	if r.Log != "" {
		extra["log"] = r.Log
	}
	out := fit(maxOut, doc(true, "installed", extra), func() string {
		delete(extra, "log")
		return doc(true, "installed", extra)
	})
	return remote.Result{OK: true, Detail: "installed", Output: out}
}

// nodeID reads the local node id, waiting up to settle for a freshly installed agent to create it.
func (d JobDeps) nodeID(ctx context.Context, p Probe, settle time.Duration) string {
	deadline := time.Now().Add(settle)
	for {
		if id, _ := p.NodeID(ctx); id != "" {
			return id
		}
		if time.Now().Add(time.Second).After(deadline) || ctx.Err() != nil {
			return ""
		}
		select {
		case <-ctx.Done():
			return ""
		case <-time.After(time.Second):
		}
	}
}

// UninstallResult is what running the Mesh agent's own uninstaller learned.
type UninstallResult struct {
	Found    bool   // an agent was there
	Removed  bool   // its uninstaller ran and exited 0
	ExitCode int    // the uninstaller's exit code
	Error    string // set when it could not run or exited non-zero
	Log      string
}

// Uninstall runs the Mesh agent's own uninstaller (Windows -fulluninstall, Linux -uninstall). The flags are from the
// MeshAgent documentation as remembered and were NOT run against a real agent. It never touches the RivetIT agent.
func Uninstall(ctx context.Context, p Probe, goos string) UninstallResult {
	exe := p.Found()
	if exe == "" {
		return UninstallResult{}
	}
	arg := "-uninstall"
	if goos == "windows" {
		arg = "-fulluninstall"
	}
	run := p.Run
	if run == nil {
		run = RunProgram
	}
	rctx, cancel := context.WithTimeout(ctx, 2*time.Minute)
	defer cancel()
	exit, out, err := run(rctx, "", exe, []string{arg})
	r := UninstallResult{Found: true, ExitCode: exit, Log: clip(out, logTail)}
	switch {
	case err != nil:
		r.Error = "the Mesh uninstaller could not run: " + clip(err.Error(), 200)
	case exit != 0:
		r.Error = fmt.Sprintf("the Mesh uninstaller exited with code %d", exit)
	default:
		r.Removed = true
	}
	return r
}

// uninstall is the mesh_uninstall job: it takes no parameters.
func (d JobDeps) uninstall(ctx context.Context, params json.RawMessage, maxOut int) (res remote.Result) {
	defer func() {
		if r := recover(); r != nil {
			res = remote.Result{OK: false, Detail: "internal_error", Output: doc(false, "internal_error", nil)}
		}
	}()
	if t := strings.TrimSpace(string(params)); t != "" && t != "{}" && t != "null" {
		return remote.Result{OK: false, Cannot: true, Detail: "invalid_params", Output: doc(false, "invalid_params", map[string]any{"error": "mesh_uninstall takes no parameters"})}
	}
	r := Uninstall(ctx, d.probe(), d.goos())
	now := time.Now().UTC().Format(time.RFC3339)
	switch {
	case !r.Found:
		_ = SaveResult(d.StateDir, Result{AttemptedAt: now})
		d.changed()
		return remote.Result{OK: true, Detail: "not_installed", Output: doc(true, "not_installed", map[string]any{"installed": false})}
	case !r.Removed:
		// the agent is still there: report what is true
		_ = SaveResult(d.StateDir, Result{AttemptedAt: now, Installed: true})
		d.changed()
		extra := map[string]any{"installed": true, "error": r.Error, "exit_code": r.ExitCode}
		if r.Log != "" {
			extra["log"] = r.Log
		}
		return remote.Result{OK: false, Detail: "uninstall_failed", Output: fit(maxOut, doc(false, "uninstall_failed", extra), func() string {
			return doc(false, "uninstall_failed", map[string]any{"installed": true, "error": r.Error})
		})}
	}
	_ = SaveResult(d.StateDir, Result{AttemptedAt: now})
	d.changed()
	return remote.Result{OK: true, Detail: "uninstalled", Output: doc(true, "uninstalled", map[string]any{"installed": d.probe().Found() != ""})}
}
