//go:build linux

package main

import (
	"bytes"
	"os"
	"path/filepath"
	"strings"
	"syscall"
	"testing"
	"time"

	"rivetit-agent/internal/svc"
)

// shimSystemd points internal/svc at a temp unit dir and a recording systemctl.
func shimSystemd(t *testing.T) (unitDir, log string) {
	t.Helper()
	dir := t.TempDir()
	log = filepath.Join(dir, "calls.log")
	sc := filepath.Join(dir, "systemctl")
	if err := os.WriteFile(sc, []byte("#!/bin/sh\necho \"$*\" >> "+log+"\n"), 0o755); err != nil {
		t.Fatal(err)
	}
	oldD, oldS := svc.UnitDir, svc.Systemctl
	svc.UnitDir, svc.Systemctl = filepath.Join(dir, "units"), sc
	t.Cleanup(func() { svc.UnitDir, svc.Systemctl = oldD, oldS })
	return svc.UnitDir, log
}

// The whole Linux install path with the REAL service layer (only systemctl is a shim).
func TestLinuxSetupInstallsUnitBinaryAndPermissions(t *testing.T) {
	t.Setenv(allowNonRootEnv, "1")
	unitDir, log := shimSystemd(t)
	srv := newEnrollSrv(t)
	e := newEnv(t)
	e.stampWith(srv, nil)
	ops := realOps()
	ops.executable = func() (string, error) { return e.exe, nil }
	inst := filepath.Join(e.dir, "opt", "rivetit-agent")
	args := []string{"--silent", "--state-dir", e.state, "--install-dir", inst}
	if c := runSetup(args, ops, time.Now); c != 0 {
		t.Fatalf("setup exit %d", c)
	}
	bin := filepath.Join(inst, exeName)
	got, err := os.ReadFile(bin)
	if err != nil || !bytes.Equal(got, fakeExeBytes) {
		t.Fatalf("installed binary must be the unstamped bytes: %v", err)
	}
	if fi, _ := os.Stat(bin); fi.Mode().Perm() != 0o755 {
		t.Fatalf("binary mode %v", fi.Mode())
	}
	if fi, _ := os.Stat(e.state); fi.Mode().Perm() != 0o700 {
		t.Fatalf("state dir mode %v, want 0700", fi.Mode().Perm())
	}
	for _, f := range []string{"device.token", "config.json"} {
		if fi, err := os.Stat(filepath.Join(e.state, f)); err == nil && fi.Mode().Perm()&0o077 != 0 {
			t.Fatalf("%s readable by others: %v", f, fi.Mode())
		}
	}
	unit, err := os.ReadFile(filepath.Join(unitDir, "rivetit-agent.service"))
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(string(unit), `ExecStart="`+bin+`" "run" "--state-dir" "`+e.state+`"`) {
		t.Fatalf("unit ExecStart wrong:\n%s", unit)
	}
	if strings.Contains(string(unit), setupToken) {
		t.Fatal("enrollment token in the unit file")
	}
	b, _ := os.ReadFile(log)
	if calls := strings.Join(strings.Fields(strings.ReplaceAll(string(b), "\n", "|")), " "); calls != "daemon-reload|enable rivetit-agent.service|restart rivetit-agent.service|" {
		t.Fatalf("systemctl calls %q", calls)
	}
	// run again: idempotent, the service is stopped before the binary is replaced
	if c := runSetup(args, ops, time.Now); c != 0 {
		t.Fatalf("rerun exit %d", c)
	}
	b, _ = os.ReadFile(log)
	if !strings.Contains(string(b), "stop rivetit-agent.service") {
		t.Fatalf("existing unit was not stopped before replacing the binary:\n%s", b)
	}
	if srv.enrolls.Load() != 1 {
		t.Fatalf("rerun enrolled again: %d", srv.enrolls.Load())
	}
}

func TestLinuxUninstallRemovesUnitAndBinaries(t *testing.T) {
	t.Setenv(allowNonRootEnv, "1")
	unitDir, _ := shimSystemd(t)
	srv := newEnrollSrv(t)
	e := newEnv(t)
	e.stampWith(srv, nil)
	ops := realOps()
	ops.executable = func() (string, error) { return e.exe, nil }
	inst := filepath.Join(e.dir, "opt", "rivetit-agent")
	if c := runSetup([]string{"--silent", "--state-dir", e.state, "--install-dir", inst}, ops, time.Now); c != 0 {
		t.Fatal(c)
	}
	if c := cmdUninstall([]string{"--state-dir", e.state, "--install-dir", inst}); c != 0 {
		t.Fatalf("uninstall %d", c)
	}
	if _, err := os.Stat(filepath.Join(unitDir, "rivetit-agent.service")); err == nil {
		t.Fatal("unit left behind")
	}
	if _, err := os.Stat(inst); err == nil {
		t.Fatal("install dir (now empty) left behind")
	}
	if _, err := os.Stat(filepath.Join(e.state, "device.token")); err != nil {
		t.Fatal("state must be kept without --purge")
	}
	if c := cmdUninstall([]string{"--purge", "--state-dir", e.state, "--install-dir", inst}); c != 0 {
		t.Fatal(c)
	}
	if _, err := os.Stat(e.state); err == nil {
		t.Fatal("state survived --purge")
	}
	// uninstalling twice is harmless
	if c := cmdUninstall([]string{"--purge", "--state-dir", e.state, "--install-dir", inst}); c != 0 {
		t.Fatalf("second uninstall %d", c)
	}
}

func TestLinuxInstallAndUninstallRefuseNonRoot(t *testing.T) {
	if os.Geteuid() == 0 {
		t.Skip("running as root")
	}
	t.Setenv(allowNonRootEnv, "")
	shimSystemd(t)
	if c := cmdInstall([]string{"--server", "https://x", "--token", "t", "--state-dir", t.TempDir(), "--install-dir", t.TempDir()}); c != exitNotElevated {
		t.Fatalf("install as non-root: %d", c)
	}
	if c := cmdUninstall([]string{"--state-dir", t.TempDir(), "--install-dir", t.TempDir()}); c != exitNotElevated {
		t.Fatalf("uninstall as non-root: %d", c)
	}
	// a non-root interactive setup says so and does not try to elevate
	e := newEnv(t)
	e.stampWith(newEnrollSrv(t), nil)
	ops := realOps()
	ops.executable = func() (string, error) { return e.exe, nil }
	if c := runSetup([]string{"--silent"}, ops, time.Now); c != exitNotElevated {
		t.Fatalf("setup as non-root: %d", c)
	}
}

func TestLinuxInstallCommandNoService(t *testing.T) {
	t.Setenv(allowNonRootEnv, "1")
	unitDir, _ := shimSystemd(t)
	srv := newEnrollSrv(t)
	// cmdInstall copies os.Executable(); here the test binary itself is the "agent".
	state, inst := filepath.Join(t.TempDir(), "state"), filepath.Join(t.TempDir(), "inst")
	tokf := filepath.Join(t.TempDir(), "tok")
	os.WriteFile(tokf, []byte(setupToken), 0o600)
	caf := filepath.Join(t.TempDir(), "ca.pem")
	os.WriteFile(caf, []byte(srv.caPEM()), 0o600)
	c := cmdInstall([]string{"--server", srv.URL, "--token-file", tokf, "--ca", caf, "--state-dir", state, "--install-dir", inst, "--no-service"})
	if c != 0 {
		t.Fatalf("install --no-service: %d", c)
	}
	if _, err := os.Stat(filepath.Join(unitDir, "rivetit-agent.service")); err == nil {
		t.Fatal("--no-service created a unit")
	}
	if _, err := os.Stat(filepath.Join(inst, exeName)); err != nil {
		t.Fatal("binary not installed")
	}
	if srv.enrolls.Load() != 1 {
		t.Fatal("not enrolled")
	}
	if c := cmdInstall([]string{"--server", srv.URL, "--state-dir", filepath.Join(t.TempDir(), "s2"), "--install-dir", inst, "--no-service"}); c != 2 {
		t.Fatalf("install without a token must exit 2, got %d", c)
	}
}

// The install script runs with umask 077 for its temp files; the program directory, binary and unit must
// still come out world-readable while the state directory and credential stay private.
func TestLinuxInstallModesUnderRestrictiveUmask(t *testing.T) {
	t.Setenv(allowNonRootEnv, "1")
	unitDir, _ := shimSystemd(t)
	srv := newEnrollSrv(t)
	e := newEnv(t)
	e.stampWith(srv, nil)
	ops := realOps()
	ops.executable = func() (string, error) { return e.exe, nil }
	old := syscall.Umask(0o077)
	defer syscall.Umask(old)
	inst := filepath.Join(e.dir, "opt", "rivetit-agent")
	if c := runSetup([]string{"--silent", "--state-dir", e.state, "--install-dir", inst}, ops, time.Now); c != 0 {
		t.Fatal(c)
	}
	mode := func(p string) os.FileMode {
		fi, err := os.Stat(p)
		if err != nil {
			t.Fatal(err)
		}
		return fi.Mode().Perm()
	}
	if m := mode(inst); m != 0o755 {
		t.Errorf("install dir %v", m)
	}
	if m := mode(filepath.Join(inst, exeName)); m != 0o755 {
		t.Errorf("binary %v", m)
	}
	if m := mode(filepath.Join(unitDir, "rivetit-agent.service")); m != 0o644 {
		t.Errorf("unit %v", m)
	}
	if m := mode(e.state); m != 0o700 {
		t.Errorf("state dir %v", m)
	}
	if m := mode(filepath.Join(e.state, "device.token")); m != 0o600 {
		t.Errorf("credential %v", m)
	}
}
