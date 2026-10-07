//go:build linux

package main

import (
	"fmt"
	"os"
	"path/filepath"

	"rivetit-agent/internal/svc"
)

func cmdInstall(args []string) int {
	fs, dir := newFlags("install")
	var f enrollFlags
	fs.StringVar(&f.server, "server", "", "RivetIT/RMM base URL")
	fs.StringVar(&f.token, "token", "", "enrollment token (prefer --token-file or RIVETIT_ENROLL_TOKEN: a flag is visible in the process list)")
	fs.StringVar(&f.tokenFile, "token-file", "", "file containing the enrollment token")
	fs.StringVar(&f.ca, "ca", "", "extra CA certificate (PEM)")
	fs.StringVar(&f.pin, "pin-spki", "", "optional hex SHA-256 of the server SPKI")
	fs.StringVar(&f.department, "department", "", "informational department label")
	installDir := fs.String("install-dir", defaultInstallDir(), "binary directory")
	noService := fs.Bool("no-service", false, "install files and enroll but do not create or start the systemd unit")
	level := fs.String("log-level", "info", "log level")
	if err := fs.Parse(args); err != nil {
		return 2
	}
	ops := realOps()
	if !ops.isElevated() {
		fmt.Fprintln(os.Stderr, "error: install needs root; re-run with sudo")
		return exitNotElevated
	}
	self, err := ops.executable()
	if err != nil {
		fmt.Fprintln(os.Stderr, "error:", err)
		return 1
	}
	res := performInstall(installParams{flags: f, stateDir: *dir, installDir: *installDir, noService: *noService, self: self, version: version},
		ops, consoleLog(*level))
	switch res.Kind {
	case instNoToken:
		fmt.Fprintln(os.Stderr, "error:", res.Msg)
		return 2
	case instRejected:
		fmt.Fprintln(os.Stderr, res.Msg)
		return 3
	case instConfig, instFail:
		fmt.Fprintln(os.Stderr, "error:", res.Msg)
		return 1
	}
	if *noService {
		fmt.Println("RivetIT Agent installed (no service created)")
	} else {
		fmt.Println("RivetIT Agent installed and started (systemd unit " + svc.ServiceName + ".service)")
	}
	st, err := openStore(*dir)
	if err != nil {
		return 1
	}
	return printStatus(st)
}

// cmdUninstall stops and removes the systemd unit and the binaries; --purge
// also deletes the state directory (credential, buffers, logs).
func cmdUninstall(args []string) int {
	fs, dir := newFlags("uninstall")
	purge := fs.Bool("purge", false, "also delete local state (config, credential, buffers)")
	installDir := fs.String("install-dir", defaultInstallDir(), "binary directory")
	if err := fs.Parse(args); err != nil {
		return 2
	}
	if !realOps().isElevated() {
		fmt.Fprintln(os.Stderr, "error: uninstall needs root; re-run with sudo")
		return exitNotElevated
	}
	if err := svc.RemoveService(); err != nil {
		fmt.Fprintln(os.Stderr, "error: removing the systemd unit:", err)
		return 1
	}
	fmt.Println("systemd unit stopped and removed")
	if *purge {
		if *dir == "" {
			fmt.Fprintln(os.Stderr, "error: --state-dir is required for --purge")
			return 1
		}
		if err := os.RemoveAll(*dir); err != nil {
			fmt.Fprintln(os.Stderr, "warning: purging state:", err)
		} else {
			fmt.Println("local state purged")
		}
	} else {
		fmt.Println("local state kept in", *dir, "(use --purge to delete)")
	}
	removeInstallDir(*installDir)
	return 0
}

// removeInstallDir deletes only the agent's own binaries (a running binary may
// be unlinked on Linux) and the directory when nothing else is in it.
func removeInstallDir(dir string) {
	if dir == "" {
		return
	}
	for _, n := range []string{exeName, exeName + ".prev", exeName + ".new", exeName + ".failed", exeName + ".old", exeName + ".tmp"} {
		_ = os.Remove(filepath.Join(dir, n))
	}
	_ = os.Remove(dir) // only succeeds when empty
	fmt.Println("agent binaries removed from", dir)
}
