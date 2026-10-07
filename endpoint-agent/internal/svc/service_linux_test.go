//go:build linux

package svc

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// shim points the package at a temp unit dir and a systemctl that only records its arguments.
func shim(t *testing.T) (log string) {
	t.Helper()
	dir := t.TempDir()
	log = filepath.Join(dir, "calls.log")
	sc := filepath.Join(dir, "systemctl")
	script := "#!/bin/sh\necho \"$*\" >> " + log + "\nexit ${SHIM_EXIT:-0}\n"
	if err := os.WriteFile(sc, []byte(script), 0o755); err != nil {
		t.Fatal(err)
	}
	oldD, oldS := UnitDir, Systemctl
	UnitDir, Systemctl = filepath.Join(dir, "units"), sc
	t.Cleanup(func() { UnitDir, Systemctl = oldD, oldS })
	return log
}

func calls(t *testing.T, log string) []string {
	b, _ := os.ReadFile(log)
	return strings.Split(strings.TrimSpace(string(b)), "\n")
}

func TestSystemdQuote(t *testing.T) {
	for in, want := range map[string]string{
		`/opt/x`:        `"/opt/x"`,
		`/opt/a b/x`:    `"/opt/a b/x"`,
		`a"b`:           `"a\"b"`,
		`a\b`:           `"a\\b"`,
		`100%`:          `"100%%"`,
		`$HOME/x`:       `"$$HOME/x"`,
		"a\nb":          `"a\nb"`,
		"; rm -rf /":    `"; rm -rf /"`,
		"x\" ; evil=\"": `"x\" ; evil=\""`,
	} {
		if got := systemdQuote(in); got != want {
			t.Errorf("systemdQuote(%q)=%s want %s", in, got, want)
		}
	}
}

func TestUnitFileContent(t *testing.T) {
	u := UnitFile("/opt/rivetit-agent/rivetit-agent", []string{"run", "--state-dir", "/var/lib/rivetit-agent"})
	for _, want := range []string{
		"[Unit]", "After=network-online.target", "Wants=network-online.target", "StartLimitIntervalSec=0",
		"[Service]", "User=root", `ExecStart="/opt/rivetit-agent/rivetit-agent" "run" "--state-dir" "/var/lib/rivetit-agent"`,
		"Restart=always", "RestartSec=10", "KillMode=control-group",
		"StateDirectory=rivetit-agent", "StateDirectoryMode=0700", "LimitCORE=0",
		"[Install]", "WantedBy=multi-user.target",
	} {
		if !strings.Contains(u, want) {
			t.Errorf("unit lacks %q:\n%s", want, u)
		}
	}
	// A job runs arbitrary admin-signed scripts as root: these would break them and must not appear.
	for _, bad := range []string{"ProtectSystem", "NoNewPrivileges", "PrivateTmp", "ProtectHome", "SystemCallFilter", "ReadOnlyPaths"} {
		if strings.Contains(u, bad) {
			t.Errorf("unit sets %s, which breaks job execution as root", bad)
		}
	}
	custom := UnitFile("/opt/a b/agent", []string{"run", "--state-dir", "/srv/state dir"})
	if strings.Contains(custom, "StateDirectory=") {
		t.Fatalf("StateDirectory= must only be set for the default state dir:\n%s", custom)
	}
	if !strings.Contains(custom, `"/opt/a b/agent"`) || !strings.Contains(custom, `"/srv/state dir"`) {
		t.Fatalf("paths with spaces not quoted:\n%s", custom)
	}
}

func TestInstallStartStopRemoveLifecycle(t *testing.T) {
	log := shim(t)
	if ok, _ := ServiceExists(); ok {
		t.Fatal("exists before install")
	}
	// nothing installed: stop and remove are no-ops that must not fail an install/uninstall
	if err := StopService(); err != nil {
		t.Fatal(err)
	}
	if err := RemoveService(); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(log); err == nil {
		t.Fatalf("systemctl was called with no unit installed: %v", calls(t, log))
	}
	if err := InstallService("/opt/rivetit-agent/rivetit-agent", []string{"run", "--state-dir", "/var/lib/rivetit-agent"}); err != nil {
		t.Fatal(err)
	}
	fi, err := os.Stat(UnitPath())
	if err != nil || fi.Mode().Perm() != 0o644 {
		t.Fatalf("unit file %v %v", fi, err)
	}
	if _, err := os.Stat(UnitPath() + ".tmp"); err == nil {
		t.Fatal("temp unit left behind")
	}
	if ok, _ := ServiceExists(); !ok {
		t.Fatal("not found after install")
	}
	if err := StartService(); err != nil {
		t.Fatal(err)
	}
	if err := StopService(); err != nil {
		t.Fatal(err)
	}
	if err := RemoveService(); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(UnitPath()); err == nil {
		t.Fatal("unit still present after remove")
	}
	got := strings.Join(calls(t, log), "|")
	want := "daemon-reload|enable rivetit-agent.service|restart rivetit-agent.service|stop rivetit-agent.service|stop rivetit-agent.service|disable rivetit-agent.service|daemon-reload|reset-failed rivetit-agent.service"
	if got != want {
		t.Fatalf("systemctl calls:\n got %s\nwant %s", got, want)
	}
}

func TestInstallRefusesRelativeExeAndReportsSystemctlFailure(t *testing.T) {
	shim(t)
	if err := InstallService("rivetit-agent", nil); err == nil {
		t.Fatal("relative executable accepted")
	}
	t.Setenv("SHIM_EXIT", "1")
	err := InstallService("/opt/x/agent", []string{"run"})
	if err == nil || !strings.Contains(err.Error(), "systemctl daemon-reload") {
		t.Fatalf("systemctl failure not reported: %v", err)
	}
	if err := StartService(); err == nil {
		t.Fatal("start failure swallowed")
	}
}
