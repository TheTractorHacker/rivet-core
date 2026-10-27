//go:build linux

package svc

import (
	"context"
	"errors"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"time"
)

// ServiceName is the systemd unit name (without ".service").
const ServiceName = "rivetit-agent"

var ErrUnsupported = errors.New("services are not supported on this OS")

// These variables are the test seams: a test points UnitDir at a temp dir and
// Systemctl at a recording shim, so no real systemd is ever touched.
var (
	UnitDir    = "/etc/systemd/system"
	Systemctl  = "systemctl"
	defaultDir = "/var/lib/rivetit-agent" // StateDirectory= is only used for the default state dir
)

func IsWindowsService() bool                           { return false }
func RunAsService(func(ctx context.Context) int) error { return ErrUnsupported }

// UnitPath is the unit file location.
func UnitPath() string { return filepath.Join(UnitDir, ServiceName+".service") }

// systemdQuote quotes one ExecStart word. Inside double quotes systemd expands
// C-style escapes, and % and $ are specifiers/variables, so they are doubled.
func systemdQuote(s string) string {
	r := strings.NewReplacer(`\`, `\\`, `"`, `\"`, "%", "%%", "$", "$$", "\n", `\n`, "\t", `\t`)
	return `"` + r.Replace(s) + `"`
}

// UnitFile renders the unit. The agent runs as root on purpose: it executes
// administrator-signed jobs (the Windows agent does the same as SYSTEM), so
// sandboxing options that would break arbitrary jobs (ProtectSystem,
// NoNewPrivileges, PrivateTmp, ProtectHome, a syscall filter) are NOT set; that
// would give a false sense of containment. The hardening that does hold is the
// root-owned binary and unit, a 0700 state directory and no core dumps (the
// device credential lives in the process).
func UnitFile(exePath string, args []string) string {
	var b strings.Builder
	b.WriteString("[Unit]\n")
	b.WriteString("Description=RivetIT endpoint agent\n")
	b.WriteString("After=network-online.target\nWants=network-online.target\n")
	b.WriteString("StartLimitIntervalSec=0\n\n")
	b.WriteString("[Service]\nType=simple\nUser=root\n")
	words := []string{systemdQuote(exePath)}
	stateDir := ""
	for i, a := range args {
		words = append(words, systemdQuote(a))
		if a == "--state-dir" && i+1 < len(args) {
			stateDir = args[i+1]
		}
	}
	b.WriteString("ExecStart=" + strings.Join(words, " ") + "\n")
	b.WriteString("Restart=always\nRestartSec=10\nTimeoutStopSec=30\nKillMode=control-group\n")
	if stateDir == defaultDir {
		b.WriteString("StateDirectory=rivetit-agent\nStateDirectoryMode=0700\n")
	}
	b.WriteString("LimitCORE=0\nLockPersonality=yes\nSyslogIdentifier=rivetit-agent\n\n")
	b.WriteString("[Install]\nWantedBy=multi-user.target\n")
	return b.String()
}

func systemctl(args ...string) error {
	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Minute)
	defer cancel()
	out, err := exec.CommandContext(ctx, Systemctl, args...).CombinedOutput()
	if err != nil {
		return fmt.Errorf("systemctl %s: %v: %s", strings.Join(args, " "), err, strings.TrimSpace(string(out)))
	}
	return nil
}

func unitExists() bool { _, err := os.Stat(UnitPath()); return err == nil }

// InstallService writes the unit atomically, reloads systemd and enables it
// (starting is StartService's job so install can order it after the binary).
func InstallService(exePath string, args []string) error {
	if !filepath.IsAbs(exePath) {
		return fmt.Errorf("service executable must be an absolute path: %q", exePath)
	}
	if err := os.MkdirAll(UnitDir, 0o755); err != nil {
		return err
	}
	tmp := UnitPath() + ".tmp"
	if err := os.WriteFile(tmp, []byte(UnitFile(exePath, args)), 0o644); err != nil {
		return err
	}
	if err := os.Chmod(tmp, 0o644); err != nil { // WriteFile is subject to the umask
		return err
	}
	if err := os.Rename(tmp, UnitPath()); err != nil {
		os.Remove(tmp)
		return err
	}
	if err := systemctl("daemon-reload"); err != nil {
		return err
	}
	return systemctl("enable", ServiceName+".service")
}

// RemoveService stops, disables and deletes the unit; a missing unit is success.
func RemoveService() error {
	if !unitExists() {
		return nil
	}
	_ = systemctl("stop", ServiceName+".service")
	_ = systemctl("disable", ServiceName+".service")
	if err := os.Remove(UnitPath()); err != nil && !os.IsNotExist(err) {
		return err
	}
	_ = systemctl("daemon-reload")
	_ = systemctl("reset-failed", ServiceName+".service")
	return nil
}

// StartService (re)starts the unit so a replaced binary is the one that runs.
func StartService() error { return systemctl("restart", ServiceName+".service") }

// StopService stops the unit; with no unit installed there is nothing to stop.
func StopService() error {
	if !unitExists() {
		return nil
	}
	return systemctl("stop", ServiceName+".service")
}

// ServiceExists reports whether the unit file is installed.
func ServiceExists() (bool, error) { return unitExists(), nil }
