package remote

import (
	"context"
	"encoding/json"
	"errors"
	"runtime"
	"sort"
	"strings"
)

// MaxServices bounds svc_list.
const MaxServices = 1500

// Service states and startup modes of the wire contract.
const (
	StateRunning  = "running"
	StateStopped  = "stopped"
	StateStarting = "starting"
	StateStopping = "stopping"
	StatePaused   = "paused"
	StateFailed   = "failed"
	StateUnknown  = "unknown"

	StartupAuto     = "auto"
	StartupManual   = "manual"
	StartupDisabled = "disabled"
	StartupStatic   = "static"
	StartupUnknown  = "unknown"
)

// Service is one row of svc_list.
type Service struct {
	Name    string `json:"name"`
	Display string `json:"display"`
	State   string `json:"state"`
	Startup string `json:"startup"`
}

// ErrServiceNotFound is returned by a backend for an unknown service.
var ErrServiceNotFound = errors.New("service not found")

// ServiceBackend is the operating-system side of the service tools.
type ServiceBackend interface {
	List(ctx context.Context) ([]Service, error)
	// Status reports the current state and startup mode of one (normalised) service.
	Status(ctx context.Context, name string) (state, startup string, err error)
	// Apply performs one of start|stop|restart|enable|disable|manual.
	Apply(ctx context.Context, name, action string) error
}

// Services implements the svc_list and svc_control jobs.
type Services struct {
	GOOS string // "" = runtime.GOOS
	B    ServiceBackend
}

func (s Services) goos() string {
	if s.GOOS == "" {
		return runtime.GOOS
	}
	return s.GOOS
}

// actions that exist on the wire.
var svcActions = map[string]bool{"start": true, "stop": true, "restart": true, "enable": true, "disable": true, "manual": true}

// The agent's own service: never stopped, restarted or disabled by a job.
func isSelfService(goos, canonical string) bool {
	n := strings.ToLower(canonical)
	n = strings.TrimSuffix(n, ".service")
	switch n {
	case "rivetit-agent", "rivetitagent", "rivetit endpoint agent", "rivetit agent":
		return true
	}
	return false
}

// Protected services and the actions refused for them.
var linuxProtected = map[string]map[string]bool{
	"dbus.service":             {"stop": true, "restart": true, "disable": true},
	"dbus-broker.service":      {"stop": true, "restart": true, "disable": true},
	"systemd-journald.service": {"stop": true, "disable": true},
	"systemd-logind.service":   {"stop": true, "disable": true},
	"systemd-udevd.service":    {"stop": true, "disable": true},
}

var windowsProtected = map[string]map[string]bool{
	"rpcss":                {"stop": true, "restart": true, "disable": true},
	"rpceptmapper":         {"stop": true, "restart": true, "disable": true},
	"dcomlaunch":           {"stop": true, "restart": true, "disable": true},
	"lsm":                  {"stop": true, "restart": true, "disable": true},
	"brokerinfrastructure": {"stop": true, "restart": true, "disable": true},
	"samss":                {"stop": true, "restart": true, "disable": true},
	"eventlog":             {"stop": true, "disable": true}, // may be restarted, never stopped or disabled
}

func isProtected(goos, canonical, action string) bool {
	if goos == "windows" {
		return windowsProtected[strings.ToLower(canonical)][action]
	}
	return linuxProtected[canonical][action]
}

// CanonicalService validates a service name for goos and returns the form the
// backend works with.
func CanonicalService(goos, name string) (string, bool) {
	if goos == "windows" {
		return name, ValidWindowsService(name)
	}
	return NormalizeUnit(name)
}

// CheckServiceAction applies every static check of svc_control and returns the
// canonical name, or a stable detail code.
func CheckServiceAction(goos, name, action string) (string, string) {
	if !svcActions[action] {
		return "", DetailInvalidAction
	}
	canon, ok := CanonicalService(goos, name)
	if !ok {
		return "", DetailInvalidName
	}
	if goos != "windows" && action == "manual" {
		return canon, DetailUnsupportedAct
	}
	if action == "stop" || action == "restart" || action == "disable" {
		if isSelfService(goos, canon) {
			return canon, DetailRefusedSelf
		}
	}
	if isProtected(goos, canon, action) {
		return canon, DetailRefusedProtect
	}
	return canon, ""
}

type svcListOut struct {
	V         int       `json:"v"`
	Platform  string    `json:"platform"`
	Truncated bool      `json:"truncated"`
	Count     int       `json:"count"`
	Services  []Service `json:"services"`
}

// List is svc_list.
func (s Services) List(ctx context.Context, _ json.RawMessage, maxOut int) Result {
	rows, err := s.B.List(ctx)
	if err != nil {
		return failure("list_failed: "+err.Error(), nil)
	}
	sort.Slice(rows, func(i, j int) bool { return rows[i].Name < rows[j].Name })
	trunc := false
	if len(rows) > MaxServices {
		rows, trunc = rows[:MaxServices], true
	}
	platform := s.goos()
	out, ok := fitList(len(rows), trunc, maxOut, func(n int, t bool) string {
		return marshal(svcListOut{V: 1, Platform: platform, Truncated: t, Count: n, Services: nonNil(rows[:n])})
	})
	if !ok {
		return failure("output_cap_too_small", nil)
	}
	return Result{OK: true, Output: out}
}

func nonNil[T any](in []T) []T {
	if in == nil {
		return []T{}
	}
	return in
}

type svcControlParams struct {
	Name   string `json:"name"`
	Action string `json:"action"`
}

type svcControlOut struct {
	V       int    `json:"v"`
	Name    string `json:"name"`
	Action  string `json:"action"`
	Before  string `json:"before"`
	After   string `json:"after"`
	Startup string `json:"startup"`
	OK      bool   `json:"ok"`
	Detail  string `json:"detail"`
}

// Control is svc_control.
func (s Services) Control(ctx context.Context, raw json.RawMessage, _ int) Result {
	var p svcControlParams
	if err := decodeParams(raw, &p); err != nil {
		return cannotLaunch(DetailBadParams)
	}
	fail := func(canon, before, after, startup, detail string) Result {
		name := canon
		if name == "" {
			name = clip(p.Name, 200)
		}
		o := svcControlOut{V: 1, Name: name, Action: clip(p.Action, 20), Before: before, After: after, Startup: startup, Detail: sanitizeDetail(detail)}
		return Result{OK: false, Detail: o.Detail, Output: marshal(o)}
	}
	canon, detail := CheckServiceAction(s.goos(), p.Name, p.Action)
	if detail != "" {
		return fail(canon, "", "", "", detail)
	}
	before, startup, err := s.B.Status(ctx, canon)
	if err != nil {
		if errors.Is(err, ErrServiceNotFound) {
			return fail(canon, "", "", "", DetailNotFound)
		}
		return fail(canon, "", "", "", "status_failed: "+err.Error())
	}
	if err := s.B.Apply(ctx, canon, p.Action); err != nil {
		after, st2, _ := s.B.Status(ctx, canon)
		if st2 == "" {
			st2 = startup
		}
		return fail(canon, before, after, st2, err.Error())
	}
	after, startup2, err := s.B.Status(ctx, canon)
	if err != nil {
		after, startup2 = StateUnknown, startup
	}
	o := svcControlOut{V: 1, Name: canon, Action: p.Action, Before: before, After: after, Startup: startup2, OK: true}
	return Result{OK: true, Output: marshal(o)}
}

func clip(s string, n int) string {
	if len(s) > n {
		return strings.ToValidUTF8(s[:n], "")
	}
	return s
}
