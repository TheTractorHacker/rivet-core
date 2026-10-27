package remote

import (
	"context"
	"errors"
	"fmt"
	"strings"
	"time"
)

const (
	systemctlTimeout = 60 * time.Second
	systemctlMaxOut  = 4 << 20
)

// Systemd is the Linux ServiceBackend: it drives systemctl through a Runner with
// argv lists only (no shell, no string building around names).
type Systemd struct {
	R Runner
	// Bin is the systemctl binary ("systemctl" when empty).
	Bin string
}

func (s Systemd) bin() string {
	if s.Bin == "" {
		return "systemctl"
	}
	return s.Bin
}

// ListUnitsArgv and UnitFilesArgv are the exact argument lists of svc_list.
func ListUnitsArgv() []string {
	return []string{"list-units", "--type=service", "--all", "--no-legend", "--no-pager", "--plain"}
}

func UnitFilesArgv() []string {
	return []string{"list-unit-files", "--type=service", "--no-legend", "--no-pager"}
}

// ShowArgv reads the state of one unit. The unit follows "--".
func ShowArgv(unit string) []string {
	return []string{"show", "--property=LoadState", "--property=ActiveState", "--property=UnitFileState", "--", unit}
}

// ControlArgv is the argv of a control verb: `systemctl <verb> -- <unit>`, so a
// hostile name can never become an option. ok is false for an action systemd has
// no verb for.
func ControlArgv(action, unit string) ([]string, bool) {
	switch action {
	case "start", "stop", "restart", "enable", "disable":
		return []string{action, "--", unit}, true
	}
	return nil, false
}

// unitRow is one parsed line of list-units.
type unitRow struct {
	Name, Load, Active, Sub, Description string
}

// ParseListUnits reads `systemctl list-units --plain --no-legend` output.
func ParseListUnits(out string) []unitRow {
	var rows []unitRow
	for _, line := range strings.Split(out, "\n") {
		line = strings.TrimSpace(line)
		for strings.HasPrefix(line, "●") || strings.HasPrefix(line, "*") || strings.HasPrefix(line, "×") || strings.HasPrefix(line, "○") {
			line = strings.TrimSpace(strings.TrimLeft(line, "●*×○"))
		}
		f := strings.Fields(line)
		if len(f) < 4 || !strings.HasSuffix(f[0], ".service") {
			continue
		}
		r := unitRow{Name: f[0], Load: f[1], Active: f[2], Sub: f[3]}
		if len(f) > 4 {
			r.Description = strings.Join(f[4:], " ")
		}
		rows = append(rows, r)
	}
	return rows
}

// ParseUnitFiles reads `systemctl list-unit-files` output into name -> state.
func ParseUnitFiles(out string) map[string]string {
	m := map[string]string{}
	for _, line := range strings.Split(out, "\n") {
		f := strings.Fields(line)
		if len(f) < 2 || !strings.HasSuffix(f[0], ".service") {
			continue
		}
		m[f[0]] = f[1]
	}
	return m
}

// MapActive maps a systemd ActiveState to the wire state.
func MapActive(active string) string {
	switch active {
	case "active", "reloading":
		return StateRunning
	case "inactive":
		return StateStopped
	case "activating":
		return StateStarting
	case "deactivating":
		return StateStopping
	case "failed":
		return StateFailed
	case "maintenance":
		return StatePaused
	}
	return StateUnknown
}

// MapUnitFileState maps a systemd unit file state to the wire startup mode.
func MapUnitFileState(s string) string {
	switch s {
	case "enabled", "enabled-runtime":
		return StartupAuto
	case "disabled", "masked", "masked-runtime":
		return StartupDisabled
	case "static", "indirect", "generated", "transient", "alias":
		return StartupStatic
	case "linked", "linked-runtime":
		return StartupManual
	}
	return StartupUnknown
}

func (s Systemd) run(ctx context.Context, args []string) (RunResult, error) {
	return s.R.Run(ctx, s.bin(), args, systemctlMaxOut, systemctlTimeout)
}

// List implements ServiceBackend.
func (s Systemd) List(ctx context.Context) ([]Service, error) {
	lu, err := s.run(ctx, ListUnitsArgv())
	if err != nil {
		return nil, err
	}
	if lu.ExitCode != 0 {
		return nil, fmt.Errorf("systemctl list-units exited %d", lu.ExitCode)
	}
	files := map[string]string{}
	if uf, err := s.run(ctx, UnitFilesArgv()); err == nil && uf.ExitCode == 0 {
		files = ParseUnitFiles(string(uf.Stdout))
	}
	seen := map[string]bool{}
	var out []Service
	for _, r := range ParseListUnits(string(lu.Stdout)) {
		if r.Load == "not-found" || strings.Contains(r.Name, "@.") {
			continue
		}
		seen[r.Name] = true
		st := StartupUnknown
		if fs, ok := files[r.Name]; ok {
			st = MapUnitFileState(fs)
		}
		out = append(out, Service{Name: r.Name, Display: clip(r.Description, 160), State: MapActive(r.Active), Startup: st})
	}
	for name, fs := range files { // installed but never loaded: stopped
		if seen[name] || strings.Contains(name, "@.") {
			continue
		}
		out = append(out, Service{Name: name, Display: "", State: StateStopped, Startup: MapUnitFileState(fs)})
	}
	return out, nil
}

// Status implements ServiceBackend.
func (s Systemd) Status(ctx context.Context, unit string) (string, string, error) {
	res, err := s.run(ctx, ShowArgv(unit))
	if err != nil {
		return "", "", err
	}
	if res.ExitCode != 0 {
		return "", "", fmt.Errorf("systemctl show exited %d", res.ExitCode)
	}
	kv := map[string]string{}
	for _, line := range strings.Split(string(res.Stdout), "\n") {
		if k, v, ok := strings.Cut(strings.TrimSpace(line), "="); ok {
			kv[k] = v
		}
	}
	if kv["LoadState"] == "not-found" || kv["LoadState"] == "" {
		return "", "", ErrServiceNotFound
	}
	return MapActive(kv["ActiveState"]), MapUnitFileState(kv["UnitFileState"]), nil
}

// Apply implements ServiceBackend.
func (s Systemd) Apply(ctx context.Context, unit, action string) error {
	argv, ok := ControlArgv(action, unit)
	if !ok {
		return errors.New(DetailUnsupportedAct)
	}
	res, err := s.run(ctx, argv)
	if err != nil {
		return fmt.Errorf("systemctl_failed: %v", err)
	}
	if res.ExitCode != 0 {
		msg := strings.TrimSpace(string(res.Stderr))
		if i := strings.IndexByte(msg, '\n'); i >= 0 {
			msg = msg[:i]
		}
		return fmt.Errorf("systemctl_failed: exit %d: %s", res.ExitCode, clip(msg, 160))
	}
	return nil
}
