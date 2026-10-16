package patch

import "embed"

// Scripts holds the two fixed PowerShell scripts exactly as the agent runs them (and as a server-side runner may read them: scan.ps1 also works
// standalone as a plain `powershell` job, with no environment set it scans for every category, 500 items). This file has no build tag so the Linux
// tests and tools can read the same text the Windows agent executes.
//
//go:embed scripts/scan.ps1 scripts/install.ps1
var Scripts embed.FS

// ScanScript returns the text of scripts/scan.ps1.
func ScanScript() string { return mustScript("scripts/scan.ps1") }

// InstallScript returns the text of scripts/install.ps1.
func InstallScript() string { return mustScript("scripts/install.ps1") }

func mustScript(name string) string {
	b, err := Scripts.ReadFile(name)
	if err != nil {
		panic("patch: embedded script missing: " + name)
	}
	return string(b)
}
