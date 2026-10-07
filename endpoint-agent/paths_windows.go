//go:build windows

package main

import (
	"os"
	"path/filepath"
)

// exeName is the installed binary name (updates replace it by this name).
const exeName = "rivetit-agent.exe"

func defaultStateDir() string {
	pd := os.Getenv("ProgramData")
	if pd == "" {
		pd = `C:\ProgramData`
	}
	return filepath.Join(pd, "RivetIT", "Agent")
}

func defaultInstallDir() string {
	pf := os.Getenv("ProgramFiles")
	if pf == "" {
		pf = `C:\Program Files`
	}
	return filepath.Join(pf, "RivetIT", "Agent")
}
