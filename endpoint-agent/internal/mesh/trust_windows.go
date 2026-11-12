//go:build windows

package mesh

import (
	"os"
	"path/filepath"

	"rivetit-agent/internal/winacl"
)

// trustedExe: the agent runs the Mesh agent executable as SYSTEM, and mesh_agent_path comes from config.json, so the path is checked before every run
// (CORE-16): below Program Files only, and each component owned by SYSTEM/Administrators/TrustedInstaller without write access for anyone else.
// UNVERIFIED on real Windows (built and vetted, not run); the decision itself is tested in trust_decide_test.go.
func trustedExe(path string) bool {
	err := meshTrust(filepath.Clean(path), programFilesRoots(), winacl.Inspect)
	if err != nil {
		reportUntrusted(path, err)
		return false
	}
	return true
}

func programFilesRoots() []string {
	var out []string
	for _, e := range []string{"ProgramFiles", "ProgramW6432", "ProgramFiles(x86)"} {
		if r := os.Getenv(e); r != "" {
			out = append(out, r)
		}
	}
	return out
}
