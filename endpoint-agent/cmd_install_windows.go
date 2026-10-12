//go:build windows

// UNVERIFIED on real Windows: compiled and vetted only.
package main

import (
	"context"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"syscall"

	"rivetit-agent/internal/svc"
)

func cmdInstall(args []string) int {
	fs, dir := newFlags("install")
	var f enrollFlags
	fs.StringVar(&f.server, "server", "", "RivetIT base URL")
	fs.StringVar(&f.token, "token", "", "enrollment token (prefer --token-file or RIVETIT_ENROLL_TOKEN)")
	fs.StringVar(&f.tokenFile, "token-file", "", "file containing the enrollment token")
	fs.StringVar(&f.ca, "ca", "", "extra CA certificate (PEM)")
	fs.StringVar(&f.pin, "pin-spki", "", "optional hex SHA-256 of the server SPKI")
	fs.StringVar(&f.department, "department", "", "informational department label")
	installDir := fs.String("install-dir", defaultInstallDir(), "binary directory")
	level := fs.String("log-level", "info", "log level")
	var mf meshFlags
	mf.register(fs)
	if err := fs.Parse(args); err != nil {
		return 2
	}
	self, err := os.Executable()
	if err != nil {
		fmt.Fprintln(os.Stderr, "error:", err)
		return 1
	}
	ops, log := realOps(), consoleLog(*level)
	res := performInstall(installParams{flags: f, stateDir: *dir, installDir: *installDir, self: self, version: version}, ops, log)
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
	fmt.Println("RivetIT Agent installed and started")
	if mi, ms := mf.raw(); mi != nil {
		if line := installMeshAgent(context.Background(), ops, mi, ms, *dir, log); line != "" {
			fmt.Println(line)
		}
	}
	st, err := openStore(*dir)
	if err != nil {
		return 1
	}
	return printStatus(st)
}

func cmdUninstall(args []string) int {
	fs, dir := newFlags("uninstall")
	purge := fs.Bool("purge", false, "also delete local state (config, credential, buffers)")
	meshAgent := fs.Bool("remove-meshagent", false, "ALSO uninstall a separately managed MeshCentral agent (default: leave it untouched)")
	installDir := fs.String("install-dir", defaultInstallDir(), "binary directory")
	elevated := fs.Bool("elevated", false, "internal: this process was relaunched elevated")
	fromMSI := fs.Bool("from-msi", false, "run by the RivetIT MSI package (internal): Windows Installer removes the files and its own Add/Remove Programs entry")
	if err := fs.Parse(args); err != nil {
		return 2
	}
	// Add/Remove Programs starts "uninstall" unelevated (the manifest is
	// asInvoker): relaunch with an allowlisted rebuild of the parsed flags.
	if !isElevatedWindows() {
		if *elevated {
			fmt.Fprintln(os.Stderr, "error: uninstall needs administrator rights")
			return exitNotElevated
		}
		// Only the default locations may be handed to the elevated child (an unelevated
		// user must not steer an administrator-approved process at paths of their choosing).
		if *dir != defaultStateDir() || *installDir != defaultInstallDir() {
			fmt.Fprintln(os.Stderr, "error: custom --state-dir/--install-dir need an already elevated run")
			return exitNotElevated
		}
		a := []string{"uninstall", "--elevated"}
		if *purge {
			a = append(a, "--purge")
		}
		if *meshAgent {
			a = append(a, "--remove-meshagent")
		}
		code, err := relaunchElevated(a)
		if err != nil {
			fmt.Fprintln(os.Stderr, "error: uninstall needs administrator rights:", err)
			return exitNotElevated
		}
		return code
	}
	if err := svc.RemoveService(); err != nil {
		fmt.Fprintln(os.Stderr, "error: removing service:", err)
		return 1
	}
	fmt.Println("service stopped and removed")
	if !*fromMSI { // the MSI owns its entry and its files
		if err := removeARPEntry(); err != nil {
			fmt.Fprintln(os.Stderr, "warning: removing the Add/Remove Programs entry:", err)
		}
	}
	if *meshAgent {
		removeMeshAgentIfPresent()
	} else {
		fmt.Println("MeshCentral agent (if any) left untouched; use --remove-meshagent to remove it")
	}
	if *purge {
		if err := os.RemoveAll(*dir); err != nil {
			fmt.Fprintln(os.Stderr, "warning: purging state:", err)
		} else {
			fmt.Println("local state purged")
		}
	} else {
		fmt.Println("local state kept in", *dir, "(use --purge to delete)")
	}
	if !*fromMSI {
		removeInstallDir(*installDir)
	}
	return 0
}

// removeInstallDir deletes only the agent's own binaries. The running exe
// cannot delete itself, so a detached cmd.exe finishes the job after exit.
func removeInstallDir(dir string) {
	if dir == "" {
		return
	}
	for _, n := range []string{exeName + ".prev", exeName + ".new", exeName + ".failed", exeName + ".old"} {
		_ = os.Remove(filepath.Join(dir, n))
	}
	self, _ := os.Executable()
	if filepath.Dir(self) != filepath.Clean(dir) {
		_ = os.Remove(filepath.Join(dir, exeName))
		_ = os.Remove(dir) // only succeeds when empty
		return
	}
	cmdline := fmt.Sprintf(`/c ping -n 4 127.0.0.1 >nul & del /f /q "%s" & rmdir "%s"`, self, dir)
	c := exec.Command(filepath.Join(os.Getenv("SystemRoot"), "System32", "cmd.exe"))
	c.SysProcAttr = &syscall.SysProcAttr{HideWindow: true, CmdLine: `cmd.exe ` + cmdline, CreationFlags: 0x00000008} // DETACHED_PROCESS
	_ = c.Start()
	fmt.Println("agent binary scheduled for deletion")
}
