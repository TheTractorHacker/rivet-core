//go:build !windows && !linux

package main

import "os"

const exeName = "rivetit-agent"

// Unsupported unix flavours: the state dir must be given explicitly (flag or
// RIVETIT_AGENT_STATE_DIR) so the agent never writes somewhere unexpected.
func defaultStateDir() string { return os.Getenv("RIVETIT_AGENT_STATE_DIR") }

// No standard program directory: setup stages under <state-dir>/bin (see parseSetup).
func defaultInstallDir() string { return "" }
