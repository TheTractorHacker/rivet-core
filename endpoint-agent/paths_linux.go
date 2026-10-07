//go:build linux

package main

import "os"

// exeName is the installed binary name (self-update replaces it by this name).
const exeName = "rivetit-agent"

// Default locations of the supported Linux install. RIVETIT_AGENT_STATE_DIR
// overrides the state directory (used by the tests and by `--state-dir`-less
// manual runs); the install directory is /opt/rivetit-agent.
const (
	linuxStateDir   = "/var/lib/rivetit-agent"
	linuxInstallDir = "/opt/rivetit-agent"
)

func defaultStateDir() string {
	if d := os.Getenv("RIVETIT_AGENT_STATE_DIR"); d != "" {
		return d
	}
	return linuxStateDir
}

func defaultInstallDir() string { return linuxInstallDir }
